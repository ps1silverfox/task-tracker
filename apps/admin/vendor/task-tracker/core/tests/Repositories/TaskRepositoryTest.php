<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class TaskRepositoryTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private string $logDir;
    private TaskRepository $repo;
    private EventLog $events;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-taskrepo-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'tasks.csv';
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logDir, 0700, true);

        $this->events = new EventLog($this->logDir);
        $this->repo = new TaskRepository(new CsvStore(), $this->events, $this->csvPath);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    /** @return list<array<string, mixed>> */
    private function readEvents(): array
    {
        $out = [];
        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [] as $f) {
            $contents = file_get_contents($f);
            if ($contents === false || $contents === '') {
                continue;
            }
            foreach (explode("\n", rtrim($contents, "\n")) as $line) {
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function eventActions(): array
    {
        return array_map(static fn(array $e): string => (string) $e['action'], $this->readEvents());
    }

    public function testCreatePersistsTaskAndEmitsCreatedEvent(): void
    {
        $task = $this->repo->create([
            'slug' => 'first-task',
            'title' => 'First task',
            'body' => 'hello',
            'priority' => Enums::PRIORITY_HIGH,
        ]);

        self::assertNotSame('', $task->id);
        self::assertSame('first-task', $task->slug);
        self::assertSame(Enums::STATUS_OPEN, $task->status);
        self::assertSame(Enums::PRIORITY_HIGH, $task->priority);
        self::assertNull($task->assigneeId);
        self::assertNull($task->firstAssignedAt);
        self::assertNull($task->lastAssignmentChangeAt);
        self::assertNull($task->completedAt);
        self::assertSame($task->createdAt, $task->updatedAt);

        $persisted = $this->repo->find($task->id);
        self::assertNotNull($persisted);
        self::assertSame('first-task', $persisted->slug);

        self::assertSame(['task.created'], $this->eventActions());
        $events = $this->readEvents();
        self::assertSame($task->id, $events[0]['task_id']);
        self::assertArrayHasKey('assignee_id_snapshot', $events[0]);
        self::assertNull($events[0]['assignee_id_snapshot']);
        self::assertNull($events[0]['team_id_snapshot']);
    }

    public function testCreateWithAssigneeStampsAssignmentTimestampsAndEmitsAssignedEvent(): void
    {
        $task = $this->repo->create([
            'slug' => 'assigned',
            'title' => 'Already assigned',
            'assigneeId' => 'user-1',
            'teamId' => 'team-a',
        ]);

        self::assertSame('user-1', $task->assigneeId);
        self::assertNotNull($task->firstAssignedAt);
        self::assertNotNull($task->lastAssignmentChangeAt);
        self::assertSame($task->firstAssignedAt, $task->lastAssignmentChangeAt);

        $actions = $this->eventActions();
        self::assertContains('task.created', $actions);
        self::assertContains('task.assigned', $actions);

        foreach ($this->readEvents() as $e) {
            self::assertSame('user-1', $e['assignee_id_snapshot']);
            self::assertSame('team-a', $e['team_id_snapshot']);
        }
    }

    public function testCreateCarriesActorEnvelopeIntoEvents(): void
    {
        $this->repo->create(
            ['slug' => 'with-actor', 'title' => 'With actor'],
            ['actor' => 'admin@example', 'ip' => '127.0.0.1', 'user_agent' => 'phpunit/11'],
        );

        $e = $this->readEvents()[0];
        self::assertSame('admin@example', $e['actor']);
        self::assertSame('127.0.0.1', $e['ip']);
        self::assertSame('phpunit/11', $e['user_agent']);
    }

    public function testCreateRejectsDuplicateSlugAcrossLiveRows(): void
    {
        $this->repo->create(['slug' => 'dup', 'title' => 'First']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug not unique');
        $this->repo->create(['slug' => 'dup', 'title' => 'Second']);
    }

    public function testCreateAllowsReuseOfSoftDeletedSlug(): void
    {
        $first = $this->repo->create(['slug' => 'reused', 'title' => 'Original']);
        $this->repo->softDelete($first->id);

        $reborn = $this->repo->create(['slug' => 'reused', 'title' => 'Reborn']);

        self::assertSame('reused', $reborn->slug);
        self::assertNotSame($first->id, $reborn->id);
    }

    public function testCreateWithStatusDoneSetsCompletedAtImmediately(): void
    {
        $task = $this->repo->create([
            'slug' => 'born-done',
            'title' => 'Born done',
            'status' => Enums::STATUS_DONE,
        ]);

        self::assertNotNull($task->completedAt);
        self::assertContains('task.completed', $this->eventActions());
    }

    public function testCreateRejectsMissingRequiredFields(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create(['title' => 'No slug']);
    }

    public function testListLiveExcludesSoftDeleted(): void
    {
        $a = $this->repo->create(['slug' => 'a', 'title' => 'A']);
        $b = $this->repo->create(['slug' => 'b', 'title' => 'B']);
        $this->repo->softDelete($a->id);

        $live = $this->repo->listLive();
        self::assertCount(1, $live);
        self::assertSame($b->id, $live[0]->id);

        self::assertCount(2, $this->repo->listAll());
    }

    public function testFindBySlugSkipsDeletedRows(): void
    {
        $a = $this->repo->create(['slug' => 'x', 'title' => 'Original X']);
        $this->repo->softDelete($a->id);
        $b = $this->repo->create(['slug' => 'x', 'title' => 'New X']);

        $found = $this->repo->findBySlug('x');
        self::assertNotNull($found);
        self::assertSame($b->id, $found->id);
    }

    public function testFindReturnsNullForUnknownId(): void
    {
        self::assertNull($this->repo->find('does-not-exist'));
        self::assertNull($this->repo->find(''));
    }

    public function testUpdateBumpsUpdatedAtAndEmitsUpdatedEvent(): void
    {
        $task = $this->repo->create(['slug' => 'u1', 'title' => 'Old title']);
        $createdAt = $task->createdAt;

        // Ensure clock advances enough for IsoTime (millisecond precision).
        usleep(2000);
        $updated = $this->repo->update($task->id, ['title' => 'New title']);

        self::assertSame('New title', $updated->title);
        self::assertSame($createdAt, $updated->createdAt);
        self::assertNotSame($task->updatedAt, $updated->updatedAt);

        $events = $this->readEvents();
        $updateEvent = null;
        foreach ($events as $e) {
            if ($e['action'] === 'task.updated') {
                $updateEvent = $e;
                break;
            }
        }
        self::assertNotNull($updateEvent);
        self::assertSame(['title'], $updateEvent['data']['fields']);
    }

    public function testUpdateWithIdenticalValuesIsNoOpAndEmitsNoEvent(): void
    {
        $task = $this->repo->create(['slug' => 'noop', 'title' => 'Same']);
        $before = $this->eventActions();

        $updated = $this->repo->update($task->id, ['title' => 'Same', 'body' => null]);

        self::assertSame($task->updatedAt, $updated->updatedAt);
        self::assertSame($before, $this->eventActions());
    }

    public function testFirstAssignmentSetsBothTimestampsAndEmitsAssigned(): void
    {
        $task = $this->repo->create(['slug' => 'assign-1', 'title' => 'T']);
        self::assertNull($task->firstAssignedAt);

        $updated = $this->repo->update($task->id, ['assigneeId' => 'user-1']);

        self::assertSame('user-1', $updated->assigneeId);
        self::assertNotNull($updated->firstAssignedAt);
        self::assertNotNull($updated->lastAssignmentChangeAt);
        self::assertSame($updated->firstAssignedAt, $updated->lastAssignmentChangeAt);

        $actions = $this->eventActions();
        self::assertContains('task.assigned', $actions);
        self::assertNotContains('task.reassigned', $actions);
        self::assertNotContains('task.unassigned', $actions);
    }

    public function testReassignmentPreservesFirstAssignedAtAndBumpsLastChange(): void
    {
        $task = $this->repo->create(['slug' => 'reassign', 'title' => 'T', 'assigneeId' => 'user-1']);
        $firstAssignedAt = $task->firstAssignedAt;
        self::assertNotNull($firstAssignedAt);

        usleep(2000);
        $updated = $this->repo->update($task->id, ['assigneeId' => 'user-2']);

        self::assertSame('user-2', $updated->assigneeId);
        self::assertSame($firstAssignedAt, $updated->firstAssignedAt, 'FIRST_ASSIGNED_AT is immutable after first set');
        self::assertNotNull($updated->lastAssignmentChangeAt);
        self::assertNotSame($firstAssignedAt, $updated->lastAssignmentChangeAt);

        $events = $this->readEvents();
        $reassign = null;
        foreach ($events as $e) {
            if ($e['action'] === 'task.reassigned') {
                $reassign = $e;
                break;
            }
        }
        self::assertNotNull($reassign);
        self::assertSame('user-1', $reassign['data']['from']);
        self::assertSame('user-2', $reassign['data']['to']);
    }

    public function testUnassignmentBumpsLastChangeAndPreservesFirstAssignedAt(): void
    {
        $task = $this->repo->create(['slug' => 'unassign', 'title' => 'T', 'assigneeId' => 'user-1']);
        $firstAssignedAt = $task->firstAssignedAt;

        usleep(2000);
        $updated = $this->repo->update($task->id, ['assigneeId' => null]);

        self::assertNull($updated->assigneeId);
        self::assertSame($firstAssignedAt, $updated->firstAssignedAt);
        self::assertNotNull($updated->lastAssignmentChangeAt);
        self::assertNotSame($firstAssignedAt, $updated->lastAssignmentChangeAt);

        $actions = $this->eventActions();
        self::assertContains('task.unassigned', $actions);
    }

    public function testStatusTransitionToDoneSetsCompletedAtAndEmitsCompleted(): void
    {
        $task = $this->repo->create(['slug' => 'finish', 'title' => 'T']);

        $done = $this->repo->update($task->id, ['status' => Enums::STATUS_DONE]);

        self::assertSame(Enums::STATUS_DONE, $done->status);
        self::assertNotNull($done->completedAt);

        $actions = $this->eventActions();
        self::assertContains('task.status_changed', $actions);
        self::assertContains('task.completed', $actions);
    }

    public function testStatusTransitionAwayFromDoneClearsCompletedAt(): void
    {
        $task = $this->repo->create(['slug' => 'reopen', 'title' => 'T', 'status' => Enums::STATUS_DONE]);
        self::assertNotNull($task->completedAt);

        $reopened = $this->repo->update($task->id, ['status' => Enums::STATUS_IN_PROGRESS]);

        self::assertSame(Enums::STATUS_IN_PROGRESS, $reopened->status);
        self::assertNull($reopened->completedAt);
    }

    public function testPriorityChangeEmitsPriorityChangedEvent(): void
    {
        $task = $this->repo->create(['slug' => 'prio', 'title' => 'T']);
        $this->repo->update($task->id, ['priority' => Enums::PRIORITY_CRITICAL]);

        $events = $this->readEvents();
        $found = null;
        foreach ($events as $e) {
            if ($e['action'] === 'task.priority_changed') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame('med', $found['data']['from']);
        self::assertSame('critical', $found['data']['to']);
    }

    public function testDueDateChangeEmitsDueDateChangedEvent(): void
    {
        $task = $this->repo->create(['slug' => 'due', 'title' => 'T']);
        $this->repo->update($task->id, ['dueDate' => '2026-12-31']);

        self::assertContains('task.due_date_changed', $this->eventActions());
    }

    public function testUpdateRejectsSlugCollisionWithLiveRow(): void
    {
        $this->repo->create(['slug' => 'taken', 'title' => 'A']);
        $other = $this->repo->create(['slug' => 'free', 'title' => 'B']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug not unique');
        $this->repo->update($other->id, ['slug' => 'taken']);
    }

    public function testUpdateOnUnknownTaskThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('task not found');
        $this->repo->update('00000000-0000-7000-8000-000000000000', ['title' => 'x']);
    }

    public function testSoftDeleteSetsStatusDeletedAndEmitsDeletedEvent(): void
    {
        $task = $this->repo->create(['slug' => 'goodbye', 'title' => 'T']);

        $deleted = $this->repo->softDelete($task->id);

        self::assertSame(Enums::STATUS_DELETED, $deleted->status);
        $row = $this->repo->find($task->id);
        self::assertNotNull($row);
        self::assertSame(Enums::STATUS_DELETED, $row->status);

        self::assertContains('task.deleted', $this->eventActions());
    }

    public function testSoftDeleteOfAlreadyDeletedIsIdempotent(): void
    {
        $task = $this->repo->create(['slug' => 'idem', 'title' => 'T']);
        $this->repo->softDelete($task->id);
        $eventsBefore = $this->eventActions();

        $second = $this->repo->softDelete($task->id);

        self::assertSame(Enums::STATUS_DELETED, $second->status);
        self::assertSame($eventsBefore, $this->eventActions(), 'no additional event on second delete');
    }

    public function testEveryEventCarriesAssigneeAndTeamSnapshotsWhenSet(): void
    {
        $task = $this->repo->create([
            'slug' => 'snap',
            'title' => 'T',
            'assigneeId' => 'user-1',
            'teamId' => 'team-a',
        ]);
        $this->repo->update($task->id, ['priority' => Enums::PRIORITY_HIGH]);

        foreach ($this->readEvents() as $e) {
            self::assertSame('user-1', $e['assignee_id_snapshot']);
            self::assertSame('team-a', $e['team_id_snapshot']);
        }
    }

    public function testCsvHeaderRowMatchesTaskHeaders(): void
    {
        $this->repo->create(['slug' => 'h', 'title' => 'T']);

        $raw = file_get_contents($this->csvPath);
        self::assertNotFalse($raw);
        $firstLine = strtok($raw, "\r\n");
        self::assertNotFalse($firstLine);

        $expected = '"' . implode('","', Task::HEADERS) . '"';
        self::assertSame($expected, $firstLine);
    }
}
