<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TaskTracker\Storage\EventLog;

final class EventLogTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-eventlog-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }
        foreach (glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function test_append_writes_one_lf_terminated_line(): void
    {
        $log = new EventLog($this->tmpDir);

        $path = $log->append([
            'ts'      => '2026-05-10T14:23:11.482Z',
            'action'  => 'task.created',
            'task_id' => '018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e21',
            'actor'   => 'localhost',
        ]);

        $raw = file_get_contents($path);
        $this->assertNotFalse($raw);
        $this->assertStringEndsWith("\n", $raw);
        $this->assertStringNotContainsString("\r", $raw, 'NDJSON must be LF-only');
        $this->assertSame(1, substr_count($raw, "\n"));

        $decoded = json_decode(rtrim($raw, "\n"), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('task.created', $decoded['action']);
        $this->assertSame('018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e21', $decoded['task_id']);
    }

    public function test_append_writes_no_utf8_bom(): void
    {
        $log = new EventLog($this->tmpDir);
        $path = $log->append([
            'ts'     => '2026-05-10T14:23:11.482Z',
            'action' => 'task.created',
        ]);

        $first = file_get_contents($path, length: 3);
        $this->assertNotSame("\xEF\xBB\xBF", $first);
    }

    public function test_multiple_appends_same_day_share_file_and_are_ordered(): void
    {
        $log = new EventLog($this->tmpDir);

        $p1 = $log->append(['ts' => '2026-05-10T01:00:00.000Z', 'action' => 'task.created', 'n' => 1]);
        $p2 = $log->append(['ts' => '2026-05-10T02:00:00.000Z', 'action' => 'task.updated', 'n' => 2]);
        $p3 = $log->append(['ts' => '2026-05-10T03:00:00.000Z', 'action' => 'task.completed', 'n' => 3]);

        $this->assertSame($p1, $p2);
        $this->assertSame($p2, $p3);

        $lines = array_values(array_filter(explode("\n", (string) file_get_contents($p1))));
        $this->assertCount(3, $lines);
        $ns = array_map(static fn(string $l): int => json_decode($l, true, 512, JSON_THROW_ON_ERROR)['n'], $lines);
        $this->assertSame([1, 2, 3], $ns);
    }

    public function test_rotation_writes_to_new_file_on_new_utc_date(): void
    {
        $log = new EventLog($this->tmpDir);

        $p1 = $log->append(['ts' => '2026-05-10T23:59:59.999Z', 'action' => 'task.created']);
        $p2 = $log->append(['ts' => '2026-05-11T00:00:00.001Z', 'action' => 'task.updated']);

        $this->assertNotSame($p1, $p2);
        $this->assertStringContainsString('changes-2026-05-10.ndjson', $p1);
        $this->assertStringContainsString('changes-2026-05-11.ndjson', $p2);
        $this->assertFileExists($p1);
        $this->assertFileExists($p2);
    }

    public function test_ts_is_autofilled_when_missing(): void
    {
        $log = new EventLog($this->tmpDir);

        $path = $log->append(['action' => 'task.created']);
        $line = trim((string) file_get_contents($path));
        $event = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        $this->assertArrayHasKey('ts', $event);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/',
            $event['ts'],
        );
    }

    public function test_caller_supplied_ts_is_preserved_verbatim(): void
    {
        $log = new EventLog($this->tmpDir);

        $ts = '2026-05-10T14:23:11.482Z';
        $path = $log->append(['ts' => $ts, 'action' => 'task.created']);
        $event = json_decode(trim((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($ts, $event['ts']);
    }

    public function test_unknown_action_rejected(): void
    {
        $log = new EventLog($this->tmpDir);

        $this->expectException(InvalidArgumentException::class);
        $log->append(['action' => 'task.exploded']);
    }

    public function test_missing_action_rejected(): void
    {
        $log = new EventLog($this->tmpDir);

        $this->expectException(InvalidArgumentException::class);
        $log->append(['task_id' => 'x']);
    }

    public function test_snapshot_fields_round_trip(): void
    {
        $log = new EventLog($this->tmpDir);

        $event = [
            'ts'                   => '2026-05-10T14:23:11.482Z',
            'action'               => 'task.assigned',
            'task_id'              => '018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e21',
            'actor'                => 'localhost',
            'ip'                   => '127.0.0.1',
            'user_agent'           => 'Mozilla/5.0',
            'data'                 => ['from' => null, 'to' => '018e-aaaa'],
            'assignee_id_snapshot' => '018e-aaaa',
            'team_id_snapshot'     => '018e-bbbb',
        ];

        $path = $log->append($event);
        $decoded = json_decode(trim((string) file_get_contents($path)), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($event, $decoded);
    }

    public function test_log_dir_is_created_lazily(): void
    {
        $nested = $this->tmpDir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';
        $log = new EventLog($nested);
        $this->assertDirectoryDoesNotExist($nested);

        $log->append(['action' => 'task.created']);
        $this->assertDirectoryExists($nested);

        // cleanup nested
        foreach (glob($nested . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($nested);
        @rmdir(dirname($nested));
    }

    public function test_path_for_date_returns_expected_filename(): void
    {
        $log = new EventLog($this->tmpDir);
        $this->assertSame(
            $this->tmpDir . DIRECTORY_SEPARATOR . 'changes-2026-05-10.ndjson',
            $log->pathForDate('2026-05-10'),
        );
    }

    public function test_path_for_date_rejects_malformed_date(): void
    {
        $log = new EventLog($this->tmpDir);
        $this->expectException(InvalidArgumentException::class);
        $log->pathForDate('05/10/2026');
    }

    public function test_actions_enum_matches_spec_count(): void
    {
        // Guard: the closed enum has 22 entries per spec §8.
        // Expanding requires bumping _schema_version.txt (per spec).
        $this->assertCount(22, EventLog::ACTIONS);
    }
}
