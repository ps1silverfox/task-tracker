<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Aggregations;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Aggregations\ExecutiveSummary;
use TaskTracker\Repositories\EventRepository;
use TaskTracker\Storage\EventLog;

final class ExecutiveSummaryTest extends TestCase
{
    private string $logDir;
    private EventLog $log;
    private ExecutiveSummary $summary;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-execsummary-' . bin2hex(random_bytes(6));
        if (!mkdir($this->logDir, 0700, true) && !is_dir($this->logDir)) {
            throw new RuntimeException("cannot create sandbox: {$this->logDir}");
        }
        $this->log = new EventLog($this->logDir);
        $this->summary = new ExecutiveSummary(new EventRepository($this->logDir));
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

    public function testRejectsInvalidPeriod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->summary->summarize('week', '2026-01-01', '2026-01-31');
    }

    public function testRejectsMalformedFromDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->summary->summarize('day', '01/01/2026', '2026-01-31');
    }

    public function testRejectsMalformedToDate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->summary->summarize('day', '2026-01-01', '2026/01/31');
    }

    public function testInvertedRangeReturnsEmpty(): void
    {
        $this->log->append([
            'ts' => '2026-01-15T10:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-1',
        ]);
        $out = $this->summary->summarize('day', '2026-01-31', '2026-01-01');
        self::assertSame([], $out);
    }

    public function testNoEventsReturnsZeroFilledBuckets(): void
    {
        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-03');

        self::assertCount(3, $out);
        self::assertSame('2026-01-01', $out[0]['period']);
        self::assertSame('2026-01-02', $out[1]['period']);
        self::assertSame('2026-01-03', $out[2]['period']);
        foreach ($out as $row) {
            self::assertSame(0, $row['added']);
            self::assertSame(0, $row['completed']);
            self::assertSame(0, $row['assigned']);
            self::assertSame(0, $row['unassigned']);
        }
    }

    public function testDayBucketingAggregatesCorrectMetrics(): void
    {
        $this->log->append(['ts' => '2026-01-01T09:00:00.000Z', 'action' => 'task.created',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-01T10:00:00.000Z', 'action' => 'task.created',   'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-01-01T11:00:00.000Z', 'action' => 'task.completed', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-02T09:00:00.000Z', 'action' => 'task.assigned',  'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-01-02T10:00:00.000Z', 'action' => 'task.unassigned','task_id' => 'T-2']);

        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-02');

        self::assertCount(2, $out);
        self::assertSame(['period' => '2026-01-01', 'added' => 2, 'completed' => 1, 'assigned' => 0, 'unassigned' => 0], $out[0]);
        self::assertSame(['period' => '2026-01-02', 'added' => 0, 'completed' => 0, 'assigned' => 1, 'unassigned' => 1], $out[1]);
    }

    public function testReassignedCountsAsAssigned(): void
    {
        $this->log->append(['ts' => '2026-01-01T09:00:00.000Z', 'action' => 'task.assigned',   'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-01T10:00:00.000Z', 'action' => 'task.reassigned', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-01T11:00:00.000Z', 'action' => 'task.reassigned', 'task_id' => 'T-1']);

        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-01');

        self::assertSame(3, $out[0]['assigned']);
    }

    public function testUntrackedActionsAreIgnored(): void
    {
        $this->log->append(['ts' => '2026-01-01T09:00:00.000Z', 'action' => 'task.updated',          'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-01T10:00:00.000Z', 'action' => 'task.priority_changed','task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-01T11:00:00.000Z', 'action' => 'tag.added',            'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-01T12:00:00.000Z', 'action' => 'roster.added']);
        $this->log->append(['ts' => '2026-01-01T13:00:00.000Z', 'action' => 'task.created',         'task_id' => 'T-2']);

        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-01');

        self::assertSame(1, $out[0]['added']);
        self::assertSame(0, $out[0]['completed']);
        self::assertSame(0, $out[0]['assigned']);
        self::assertSame(0, $out[0]['unassigned']);
    }

    public function testMonthBucketing(): void
    {
        $this->log->append(['ts' => '2026-01-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-20T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-02-05T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-3']);
        $this->log->append(['ts' => '2026-03-10T09:00:00.000Z', 'action' => 'task.completed', 'task_id' => 'T-1']);

        $out = $this->summary->summarize('month', '2026-01-01', '2026-03-31');

        self::assertCount(3, $out);
        self::assertSame('2026-01', $out[0]['period']);
        self::assertSame(2, $out[0]['added']);
        self::assertSame('2026-02', $out[1]['period']);
        self::assertSame(1, $out[1]['added']);
        self::assertSame('2026-03', $out[2]['period']);
        self::assertSame(1, $out[2]['completed']);
    }

    public function testQuarterBucketing(): void
    {
        $this->log->append(['ts' => '2026-01-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-03-31T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-04-01T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-3']);
        $this->log->append(['ts' => '2026-07-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-4']);
        $this->log->append(['ts' => '2026-10-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-5']);

        $out = $this->summary->summarize('quarter', '2026-01-01', '2026-12-31');

        self::assertCount(4, $out);
        self::assertSame('2026-Q1', $out[0]['period']);
        self::assertSame(2, $out[0]['added']);
        self::assertSame('2026-Q2', $out[1]['period']);
        self::assertSame(1, $out[1]['added']);
        self::assertSame('2026-Q3', $out[2]['period']);
        self::assertSame(1, $out[2]['added']);
        self::assertSame('2026-Q4', $out[3]['period']);
        self::assertSame(1, $out[3]['added']);
    }

    public function testYearBucketing(): void
    {
        $this->log->append(['ts' => '2024-06-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2025-06-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-2']);
        $this->log->append(['ts' => '2025-12-31T23:00:00.000Z', 'action' => 'task.completed', 'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-01-01T01:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-3']);

        $out = $this->summary->summarize('year', '2024-01-01', '2026-12-31');

        self::assertCount(3, $out);
        self::assertSame('2024', $out[0]['period']);
        self::assertSame(1, $out[0]['added']);
        self::assertSame('2025', $out[1]['period']);
        self::assertSame(1, $out[1]['added']);
        self::assertSame(1, $out[1]['completed']);
        self::assertSame('2026', $out[2]['period']);
        self::assertSame(1, $out[2]['added']);
    }

    public function testPersonFilterUsesEventTimeSnapshot(): void
    {
        $this->log->append([
            'ts' => '2026-01-01T09:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-1',
            'assignee_id_snapshot' => 'M-alice',
        ]);
        $this->log->append([
            'ts' => '2026-01-01T10:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-2',
            'assignee_id_snapshot' => 'M-bob',
        ]);
        $this->log->append([
            'ts' => '2026-01-02T09:00:00.000Z',
            'action' => 'task.completed',
            'task_id' => 'T-1',
            'assignee_id_snapshot' => 'M-alice',
        ]);

        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-02', person: 'M-alice');

        self::assertSame(1, $out[0]['added']);
        self::assertSame(0, $out[0]['completed']);
        self::assertSame(0, $out[1]['added']);
        self::assertSame(1, $out[1]['completed']);
    }

    public function testTeamFilterUsesEventTimeSnapshot(): void
    {
        $this->log->append([
            'ts' => '2026-01-01T09:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-1',
            'team_id_snapshot' => 'TEAM-platform',
        ]);
        $this->log->append([
            'ts' => '2026-01-01T10:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-2',
            'team_id_snapshot' => 'TEAM-growth',
        ]);
        $this->log->append([
            'ts' => '2026-01-01T11:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-3',
            'team_id_snapshot' => 'TEAM-platform',
        ]);

        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-01', team: 'TEAM-platform');

        self::assertSame(2, $out[0]['added']);
    }

    public function testPersonAndTeamFilterCombined(): void
    {
        $this->log->append([
            'ts' => '2026-01-01T09:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-1',
            'assignee_id_snapshot' => 'M-alice',
            'team_id_snapshot' => 'TEAM-platform',
        ]);
        $this->log->append([
            'ts' => '2026-01-01T10:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-2',
            'assignee_id_snapshot' => 'M-alice',
            'team_id_snapshot' => 'TEAM-growth',
        ]);
        $this->log->append([
            'ts' => '2026-01-01T11:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-3',
            'assignee_id_snapshot' => 'M-bob',
            'team_id_snapshot' => 'TEAM-platform',
        ]);

        $out = $this->summary->summarize(
            'day',
            '2026-01-01',
            '2026-01-01',
            person: 'M-alice',
            team: 'TEAM-platform',
        );

        self::assertSame(1, $out[0]['added']);
    }

    public function testPersonFilterSkipsEventsWithoutSnapshot(): void
    {
        // task.created on an unassigned task → no assignee snapshot → filtered out by person filter.
        $this->log->append([
            'ts' => '2026-01-01T09:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-1',
        ]);
        $this->log->append([
            'ts' => '2026-01-01T10:00:00.000Z',
            'action' => 'task.created',
            'task_id' => 'T-2',
            'assignee_id_snapshot' => 'M-alice',
        ]);

        $out = $this->summary->summarize('day', '2026-01-01', '2026-01-01', person: 'M-alice');

        self::assertSame(1, $out[0]['added']);
    }

    public function testRangeRestrictsFilesRead(): void
    {
        $this->log->append(['ts' => '2026-01-01T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-01-05T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-01-10T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-3']);

        $out = $this->summary->summarize('month', '2026-01-03', '2026-01-08');

        // Single bucket "2026-01"; only the middle event is in the file range.
        self::assertCount(1, $out);
        self::assertSame('2026-01', $out[0]['period']);
        self::assertSame(1, $out[0]['added']);
    }

    public function testSingleDaySingleBucket(): void
    {
        $this->log->append(['ts' => '2026-05-11T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);

        $out = $this->summary->summarize('day', '2026-05-11', '2026-05-11');

        self::assertCount(1, $out);
        self::assertSame('2026-05-11', $out[0]['period']);
        self::assertSame(1, $out[0]['added']);
    }

    public function testQuarterSnapping(): void
    {
        // from mid-Q1, to mid-Q3 → buckets Q1, Q2, Q3.
        $this->log->append(['ts' => '2026-02-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-1']);
        $this->log->append(['ts' => '2026-05-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-2']);
        $this->log->append(['ts' => '2026-08-15T09:00:00.000Z', 'action' => 'task.created', 'task_id' => 'T-3']);

        $out = $this->summary->summarize('quarter', '2026-02-15', '2026-08-15');

        self::assertCount(3, $out);
        self::assertSame(['2026-Q1', '2026-Q2', '2026-Q3'], array_column($out, 'period'));
    }
}
