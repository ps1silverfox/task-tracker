<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Admin\Controllers\TasksController;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(TasksController::class)]
final class TasksControllerActionsTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-tasksact-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://127.0.0.1:8084',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    // ─── /assign ────────────────────────────────────────────────────────────

    public function testAssignSetsAssigneeAndRedirects(): void
    {
        $repo = $this->taskRepo();
        $task = $repo->create(['slug' => 'a1', 'title' => 'Assign Me']);
        $personId = '0192f000-0000-7000-8000-000000000010';

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/assign')
                ->withParsedBody(['assignee_id' => $personId]),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/tasks/' . $task->id, $response->getHeaderLine('Location'));

        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame($personId, $reread->assigneeId);
        self::assertNotNull($reread->firstAssignedAt, 'FIRST_ASSIGNED_AT must be set on first assignment.');
        self::assertNotNull($reread->lastAssignmentChangeAt);
    }

    public function testAssignWithNullStringUnassigns(): void
    {
        $repo = $this->taskRepo();
        $personId = '0192f000-0000-7000-8000-000000000020';
        $task = $repo->create(['slug' => 'a2', 'title' => 'Will Unassign', 'assigneeId' => $personId]);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/assign')
                ->withParsedBody(['assignee_id' => 'null']),
        );

        self::assertSame(303, $response->getStatusCode());
        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertNull($reread->assigneeId);
    }

    public function testAssignMissingFieldReturns422(): void
    {
        $task = $this->taskRepo()->create(['slug' => 'a3', 'title' => 'No Body']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/assign')
                ->withParsedBody([]),
        );

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testAssignMissingTaskReturns404(): void
    {
        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/0192f000-0000-7000-8000-000000000000/assign')
                ->withParsedBody(['assignee_id' => '0192f000-0000-7000-8000-000000000099']),
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testAssignWritesCorroborativeNdjsonEvent(): void
    {
        $task = $this->taskRepo()->create(['slug' => 'a4', 'title' => 'Audit Assign']);
        $personId = '0192f000-0000-7000-8000-000000000030';

        $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/assign')
                ->withParsedBody(['assignee_id' => $personId]),
        );

        $log = $this->ndjsonContents();
        self::assertStringContainsString('task.assigned', $log);
        self::assertStringContainsString($task->id, $log);
        self::assertStringContainsString($personId, $log);
    }

    // ─── /status ────────────────────────────────────────────────────────────

    public function testChangeStatusToInProgressRedirects(): void
    {
        $repo = $this->taskRepo();
        $task = $repo->create(['slug' => 's1', 'title' => 'Status Move']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/status')
                ->withParsedBody(['status' => Enums::STATUS_IN_PROGRESS]),
        );

        self::assertSame(303, $response->getStatusCode());
        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame(Enums::STATUS_IN_PROGRESS, $reread->status);
    }

    public function testChangeStatusToDoneSetsCompletedAt(): void
    {
        $repo = $this->taskRepo();
        $task = $repo->create(['slug' => 's2', 'title' => 'Done Task']);

        $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/status')
                ->withParsedBody(['status' => Enums::STATUS_DONE]),
        );

        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame(Enums::STATUS_DONE, $reread->status);
        self::assertNotNull($reread->completedAt, 'COMPLETED_AT must be set when transitioning to done.');
    }

    public function testChangeStatusToDeletedRejectedAs422(): void
    {
        // /status must not be a back-door to soft-delete; that path is DELETE /tasks/{id}.
        $task = $this->taskRepo()->create(['slug' => 's3', 'title' => 'No Back-Door']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/status')
                ->withParsedBody(['status' => Enums::STATUS_DELETED]),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testChangeStatusInvalidValueReturns422(): void
    {
        $task = $this->taskRepo()->create(['slug' => 's4', 'title' => 'Invalid Status']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/status')
                ->withParsedBody(['status' => 'wat']),
        );

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testChangeStatusWritesNdjsonStatusChangedEvent(): void
    {
        $task = $this->taskRepo()->create(['slug' => 's5', 'title' => 'Audit Status']);

        $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/status')
                ->withParsedBody(['status' => Enums::STATUS_BLOCKED]),
        );

        $log = $this->ndjsonContents();
        self::assertStringContainsString('task.status_changed', $log);
        self::assertStringContainsString($task->id, $log);
    }

    // ─── /dependencies ──────────────────────────────────────────────────────

    public function testAddDependencyRecordsEdgeAndRedirects(): void
    {
        $repo = $this->taskRepo();
        $a = $repo->create(['slug' => 'd-a', 'title' => 'Task A']);
        $b = $repo->create(['slug' => 'd-b', 'title' => 'Task B (prereq)']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $a->id . '/dependencies')
                ->withParsedBody(['prereq_id' => $b->id]),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertTrue($this->depRepo()->has($a->id, $b->id));
    }

    public function testAddDependencyDuplicateReturns422(): void
    {
        $repo = $this->taskRepo();
        $a = $repo->create(['slug' => 'd2-a', 'title' => 'A']);
        $b = $repo->create(['slug' => 'd2-b', 'title' => 'B']);
        $this->depRepo()->add($a->id, $b->id);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $a->id . '/dependencies')
                ->withParsedBody(['prereq_id' => $b->id]),
        );

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('conflict', $payload['error'] ?? null);
    }

    public function testAddDependencyCycleReturns422(): void
    {
        $repo = $this->taskRepo();
        $a = $repo->create(['slug' => 'c-a', 'title' => 'A']);
        $b = $repo->create(['slug' => 'c-b', 'title' => 'B']);
        $this->depRepo()->add($a->id, $b->id); // A requires B

        // Adding "B requires A" would close the cycle A → B → A.
        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $b->id . '/dependencies')
                ->withParsedBody(['prereq_id' => $a->id]),
        );

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('conflict', $payload['error'] ?? null);
        self::assertStringContainsString('cycle', strtolower((string) ($payload['message'] ?? '')));
        // CSV must be untouched on cycle rejection (spec §15 / §9).
        self::assertFalse($this->depRepo()->has($b->id, $a->id));
    }

    public function testAddDependencyMissingFieldReturns422(): void
    {
        $task = $this->taskRepo()->create(['slug' => 'd3', 'title' => 'X']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/dependencies')
                ->withParsedBody([]),
        );

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testRemoveDependencyRemovesEdgeAndIsIdempotent(): void
    {
        $repo = $this->taskRepo();
        $a = $repo->create(['slug' => 'r-a', 'title' => 'A']);
        $b = $repo->create(['slug' => 'r-b', 'title' => 'B']);
        $this->depRepo()->add($a->id, $b->id);

        $first = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('DELETE', '/tasks/' . $a->id . '/dependencies/' . $b->id),
        );
        self::assertSame(303, $first->getStatusCode());
        self::assertFalse($this->depRepo()->has($a->id, $b->id));

        $second = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('DELETE', '/tasks/' . $a->id . '/dependencies/' . $b->id),
        );
        self::assertSame(303, $second->getStatusCode(), 'Re-deleting an absent edge must still 303 (idempotent).');
    }

    public function testAddDependencyWritesNdjsonEvent(): void
    {
        $repo = $this->taskRepo();
        $a = $repo->create(['slug' => 'au-a', 'title' => 'A']);
        $b = $repo->create(['slug' => 'au-b', 'title' => 'B']);

        $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $a->id . '/dependencies')
                ->withParsedBody(['prereq_id' => $b->id]),
        );

        $log = $this->ndjsonContents();
        self::assertStringContainsString('dependency.added', $log);
        self::assertStringContainsString($a->id, $log);
        self::assertStringContainsString($b->id, $log);
    }

    // ─── /tags ──────────────────────────────────────────────────────────────

    public function testAddTagNormalizesAndRedirects(): void
    {
        $task = $this->taskRepo()->create(['slug' => 't1', 'title' => 'Tag Me']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/tags')
                ->withParsedBody(['tag' => 'Front End']),
        );

        self::assertSame(303, $response->getStatusCode());
        self::assertSame(['front-end'], $this->tagRepo()->tagsOf($task->id));
    }

    public function testAddTagDuplicateReturns422(): void
    {
        $task = $this->taskRepo()->create(['slug' => 't2', 'title' => 'Tag Twice']);
        $this->tagRepo()->add($task->id, 'urgent');

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/tags')
                ->withParsedBody(['tag' => 'urgent']),
        );

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('conflict', $payload['error'] ?? null);
    }

    public function testAddTagEmptyAfterNormalizationReturns422(): void
    {
        $task = $this->taskRepo()->create(['slug' => 't3', 'title' => 'Garbage Tag']);

        $response = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/tags')
                ->withParsedBody(['tag' => '   ---   ']),
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testRemoveTagRemovesAndIsIdempotent(): void
    {
        $task = $this->taskRepo()->create(['slug' => 't4', 'title' => 'Remove Tag']);
        $this->tagRepo()->add($task->id, 'soon');

        $first = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('DELETE', '/tasks/' . $task->id . '/tags/soon'),
        );
        self::assertSame(303, $first->getStatusCode());
        self::assertSame([], $this->tagRepo()->tagsOf($task->id));

        $second = $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('DELETE', '/tasks/' . $task->id . '/tags/soon'),
        );
        self::assertSame(303, $second->getStatusCode(), 'Re-deleting an absent tag must still 303 (idempotent).');
    }

    public function testAddTagWritesNdjsonEvent(): void
    {
        $task = $this->taskRepo()->create(['slug' => 't5', 'title' => 'Audit Tag']);

        $this->app->handle(
            (new ServerRequestFactory())
                ->createServerRequest('POST', '/tasks/' . $task->id . '/tags')
                ->withParsedBody(['tag' => 'audit']),
        );

        $log = $this->ndjsonContents();
        self::assertStringContainsString('tag.added', $log);
        self::assertStringContainsString($task->id, $log);
        self::assertStringContainsString('audit', $log);
    }

    // ─── helpers ────────────────────────────────────────────────────────────

    private function taskRepo(): TaskRepository
    {
        /** @var TaskRepository $r */
        $r = $this->app->getContainer()->get(TaskRepository::class);
        return $r;
    }

    private function depRepo(): DependencyRepository
    {
        /** @var DependencyRepository $r */
        $r = $this->app->getContainer()->get(DependencyRepository::class);
        return $r;
    }

    private function tagRepo(): TagRepository
    {
        /** @var TagRepository $r */
        $r = $this->app->getContainer()->get(TagRepository::class);
        return $r;
    }

    private function ndjsonContents(): string
    {
        $files = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty(
            $files,
            'Two-write audit invariant: every state-changing admin op must append NDJSON.',
        );
        return (string) file_get_contents($files[0]);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
