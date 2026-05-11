<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Models\Event;
use TaskTracker\Repositories\EventRepository;
use TaskTracker\Storage\EventLog;

final class EventRepositoryTest extends TestCase
{
    private string $logDir;
    private EventLog $log;
    private EventRepository $repo;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-eventrepo-' . bin2hex(random_bytes(6));
        if (!mkdir($this->logDir, 0700, true) && !is_dir($this->logDir)) {
            throw new RuntimeException("cannot create sandbox: {$this->logDir}");
        }
        $this->log = new EventLog($this->logDir);
        $this->repo = new EventRepository($this->logDir);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->logDir)) {
            return;
        }
        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->logDir);
    }

    public function test_constructor_rejects_empty_log_dir(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EventRepository('');
    }

    public function test_stream_for_date_returns_nothing_when_file_absent(): void
    {
        $events = iterator_to_array($this->repo->streamForDate('2026-05-10'), false);
        $this->assertSame([], $events);
    }

    public function test_stream_for_date_rejects_malformed_date(): void
    {
        $this->expectException(InvalidArgumentException::class);
        iterator_to_array($this->repo->streamForDate('05/10/2026'));
    }

    public function test_stream_for_date_yields_each_line_as_event(): void
    {
        $this->log->append([
            'ts'      => '2026-05-10T10:00:00.000Z',
            'action'  => 'task.created',
            'task_id' => 'T-1',
        ]);
        $this->log->append([
            'ts'      => '2026-05-10T11:00:00.000Z',
            'action'  => 'task.updated',
            'task_id' => 'T-1',
            'data'    => ['fields' => ['title']],
        ]);

        $events = iterator_to_array($this->repo->streamForDate('2026-05-10'), false);

        $this->assertCount(2, $events);
        $this->assertInstanceOf(Event::class, $events[0]);
        $this->assertSame('task.created', $events[0]->action);
        $this->assertSame('T-1', $events[0]->taskId);
        $this->assertSame(['fields' => ['title']], $events[1]->data);
    }

    public function test_stream_range_walks_days_in_order(): void
    {
        $this->log->append(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.created',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-11T10:00:00.000Z', 'action' => 'task.updated',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-12T10:00:00.000Z', 'action' => 'task.completed', 'task_id' => 'T-1']);

        $events = iterator_to_array($this->repo->streamRange('2026-05-10', '2026-05-12'), false);
        $actions = array_map(static fn(Event $e): string => $e->action, $events);

        $this->assertSame(['task.created', 'task.updated', 'task.completed'], $actions);
    }

    public function test_stream_range_respects_inclusive_bounds(): void
    {
        $this->log->append(['ts' => '2026-05-09T10:00:00.000Z', 'action' => 'task.created',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.updated',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-13T10:00:00.000Z', 'action' => 'task.completed', 'task_id' => 'T-1']);

        $events = iterator_to_array($this->repo->streamRange('2026-05-10', '2026-05-12'), false);

        $this->assertCount(1, $events);
        $this->assertSame('task.updated', $events[0]->action);
    }

    public function test_stream_range_inverted_returns_nothing(): void
    {
        $this->log->append(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.created']);
        $events = iterator_to_array($this->repo->streamRange('2026-05-12', '2026-05-10'), false);
        $this->assertSame([], $events);
    }

    public function test_stream_all_returns_events_across_files_in_date_order(): void
    {
        $this->log->append(['ts' => '2026-05-11T10:00:00.000Z', 'action' => 'task.updated',  'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-09T10:00:00.000Z', 'action' => 'task.created',  'task_id' => 'T-1']);
        $this->log->append([
            'ts'      => '2026-05-10T10:00:00.000Z',
            'action'  => 'task.assigned',
            'task_id' => 'T-1',
            'data'    => ['from' => null, 'to' => 'M-1'],
        ]);

        $events = iterator_to_array($this->repo->streamAll(), false);
        $actions = array_map(static fn(Event $e): string => $e->action, $events);

        $this->assertSame(['task.created', 'task.assigned', 'task.updated'], $actions);
    }

    public function test_stream_all_with_missing_log_dir_yields_nothing(): void
    {
        @rmdir($this->logDir);
        $events = iterator_to_array($this->repo->streamAll(), false);
        $this->assertSame([], $events);
    }

    public function test_list_for_task_filters_and_preserves_chronology(): void
    {
        $this->log->append(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.created',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-10T10:00:01.000Z', 'action' => 'task.created',   'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-05-10T10:00:02.000Z', 'action' => 'task.updated',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-10T10:00:03.000Z', 'action' => 'task.completed', 'task_id' => 'T-1']);

        $events = $this->repo->listForTask('T-1');

        $this->assertCount(3, $events);
        $actions = array_map(static fn(Event $e): string => $e->action, $events);
        $this->assertSame(['task.created', 'task.updated', 'task.completed'], $actions);
    }

    public function test_list_for_task_unknown_id_is_empty(): void
    {
        $this->log->append(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->assertSame([], $this->repo->listForTask('NOPE'));
    }

    public function test_list_for_task_empty_id_is_empty(): void
    {
        $this->log->append(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->assertSame([], $this->repo->listForTask(''));
    }

    public function test_list_for_task_limit_returns_most_recent_slice(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->log->append([
                'ts'      => sprintf('2026-05-10T10:00:%02d.000Z', $i),
                'action'  => 'task.updated',
                'task_id' => 'T-1',
                'data'    => ['n' => $i],
            ]);
        }

        $events = $this->repo->listForTask('T-1', 3);
        $ns = array_map(static fn(Event $e): int => (int) $e->data['n'], $events);

        $this->assertSame([2, 3, 4], $ns);
    }

    public function test_list_for_task_rejects_negative_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->listForTask('T-1', -1);
    }

    public function test_stream_skips_blank_lines(): void
    {
        $path = $this->repo->pathForDate('2026-05-10');
        $payload =
            json_encode(['ts' => '2026-05-10T10:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1'])
            . "\n\n"
            . json_encode(['ts' => '2026-05-10T11:00:00.000Z', 'action' => 'task.updated', 'task_id' => 'T-1'])
            . "\n";
        file_put_contents($path, $payload);

        $events = iterator_to_array($this->repo->streamForDate('2026-05-10'), false);

        $this->assertCount(2, $events);
    }

    public function test_stream_raises_on_malformed_json_line(): void
    {
        $path = $this->repo->pathForDate('2026-05-10');
        file_put_contents($path, "{not valid json\n");

        $this->expectException(RuntimeException::class);
        iterator_to_array($this->repo->streamForDate('2026-05-10'), false);
    }

    public function test_path_for_date_matches_event_log_convention(): void
    {
        $repoPath = $this->repo->pathForDate('2026-05-10');
        $logPath  = $this->log->pathForDate('2026-05-10');
        $this->assertSame($logPath, $repoPath);
    }
}
