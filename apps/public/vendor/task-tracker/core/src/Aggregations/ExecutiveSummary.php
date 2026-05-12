<?php

declare(strict_types=1);

namespace TaskTracker\Aggregations;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use TaskTracker\Models\Event;
use TaskTracker\Repositories\EventRepository;
use TaskTracker\Util\IsoTime;

/**
 * Time-series rollup of task lifecycle events (spec §7).
 *
 * Buckets the closed-set tracked actions into four metric series — added,
 * completed, assigned, unassigned — over `day` / `month` / `quarter` / `year`
 * granularity, with optional event-time `person` and `team` filters.
 *
 * Why event-time filters instead of current task state: the Event lines carry
 * `assignee_id_snapshot` / `team_id_snapshot` denormalized at write time, so
 * "Alice's velocity in Q1" is reproducible even after the task gets reassigned.
 *
 * Bucket labels are pre-generated for every period in [from, to] so the result
 * has continuous time slots — zero-count buckets stay present so Chart.js
 * renders an unbroken axis. Counts are accumulated in a single streaming pass
 * over the NDJSON files; no full-file load.
 */
final class ExecutiveSummary
{
    public const PERIOD_DAY = 'day';
    public const PERIOD_MONTH = 'month';
    public const PERIOD_QUARTER = 'quarter';
    public const PERIOD_YEAR = 'year';

    public const PERIODS = [
        self::PERIOD_DAY,
        self::PERIOD_MONTH,
        self::PERIOD_QUARTER,
        self::PERIOD_YEAR,
    ];

    public const METRIC_ADDED = 'added';
    public const METRIC_COMPLETED = 'completed';
    public const METRIC_ASSIGNED = 'assigned';
    public const METRIC_UNASSIGNED = 'unassigned';

    public const METRICS = [
        self::METRIC_ADDED,
        self::METRIC_COMPLETED,
        self::METRIC_ASSIGNED,
        self::METRIC_UNASSIGNED,
    ];

    /** @var array<string, string> action → metric */
    private const ACTION_METRIC = [
        'task.created'    => self::METRIC_ADDED,
        'task.completed'  => self::METRIC_COMPLETED,
        'task.assigned'   => self::METRIC_ASSIGNED,
        'task.reassigned' => self::METRIC_ASSIGNED,
        'task.unassigned' => self::METRIC_UNASSIGNED,
    ];

    public function __construct(private readonly EventRepository $events)
    {
    }

    /**
     * @return list<array{period:string,added:int,completed:int,assigned:int,unassigned:int}>
     */
    public function summarize(
        string $period,
        string $from,
        string $to,
        ?string $person = null,
        ?string $team = null,
    ): array {
        if (!in_array($period, self::PERIODS, true)) {
            throw new InvalidArgumentException("invalid period: {$period}");
        }
        $this->assertDate($from);
        $this->assertDate($to);

        $buckets = self::generateBuckets($from, $to, $period);
        if ($buckets === []) {
            return [];
        }

        $counts = [];
        foreach ($buckets as $key) {
            $counts[$key] = [
                self::METRIC_ADDED      => 0,
                self::METRIC_COMPLETED  => 0,
                self::METRIC_ASSIGNED   => 0,
                self::METRIC_UNASSIGNED => 0,
            ];
        }

        foreach ($this->events->streamRange($from, $to) as $event) {
            $metric = self::ACTION_METRIC[$event->action] ?? null;
            if ($metric === null) {
                continue;
            }
            if ($person !== null && $event->assigneeIdSnapshot !== $person) {
                continue;
            }
            if ($team !== null && $event->teamIdSnapshot !== $team) {
                continue;
            }
            $key = self::bucketKey($this->parseTs($event), $period);
            if (!isset($counts[$key])) {
                // Event ts maps outside generated buckets — shouldn't happen
                // since file-range == [from,to], but be defensive.
                continue;
            }
            $counts[$key][$metric]++;
        }

        $out = [];
        foreach ($buckets as $key) {
            $out[] = [
                'period'     => $key,
                self::METRIC_ADDED      => $counts[$key][self::METRIC_ADDED],
                self::METRIC_COMPLETED  => $counts[$key][self::METRIC_COMPLETED],
                self::METRIC_ASSIGNED   => $counts[$key][self::METRIC_ASSIGNED],
                self::METRIC_UNASSIGNED => $counts[$key][self::METRIC_UNASSIGNED],
            ];
        }
        return $out;
    }

    private function parseTs(Event $event): DateTimeImmutable
    {
        return IsoTime::parse($event->ts);
    }

    /**
     * @return list<string>
     */
    private static function generateBuckets(string $from, string $to, string $period): array
    {
        $utc = new DateTimeZone('UTC');
        $start = new DateTimeImmutable($from . 'T00:00:00', $utc);
        $end = new DateTimeImmutable($to . 'T00:00:00', $utc);
        if ($start > $end) {
            return [];
        }

        $start = self::snapToBucketStart($start, $period);
        $step = match ($period) {
            self::PERIOD_DAY     => '+1 day',
            self::PERIOD_MONTH   => '+1 month',
            self::PERIOD_QUARTER => '+3 months',
            self::PERIOD_YEAR    => '+1 year',
        };

        $out = [];
        $seen = [];
        for ($d = $start; $d <= $end; $d = $d->modify($step)) {
            $key = self::bucketKey($d, $period);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $key;
            }
        }
        return $out;
    }

    private static function snapToBucketStart(DateTimeImmutable $dt, string $period): DateTimeImmutable
    {
        $utc = new DateTimeZone('UTC');
        switch ($period) {
            case self::PERIOD_DAY:
                return $dt;
            case self::PERIOD_MONTH:
                return new DateTimeImmutable($dt->format('Y-m-01\T00:00:00'), $utc);
            case self::PERIOD_QUARTER:
                $quarter = (int) ceil(((int) $dt->format('n')) / 3);
                $startMonth = ($quarter - 1) * 3 + 1;
                return new DateTimeImmutable(
                    sprintf('%s-%02d-01T00:00:00', $dt->format('Y'), $startMonth),
                    $utc,
                );
            case self::PERIOD_YEAR:
                return new DateTimeImmutable($dt->format('Y') . '-01-01T00:00:00', $utc);
        }
        // Unreachable: period is validated by the caller.
        throw new InvalidArgumentException("invalid period: {$period}");
    }

    private static function bucketKey(DateTimeImmutable $dt, string $period): string
    {
        $utc = $dt->setTimezone(new DateTimeZone('UTC'));
        return match ($period) {
            self::PERIOD_DAY     => $utc->format('Y-m-d'),
            self::PERIOD_MONTH   => $utc->format('Y-m'),
            self::PERIOD_QUARTER => $utc->format('Y') . '-Q' . (int) ceil(((int) $utc->format('n')) / 3),
            self::PERIOD_YEAR    => $utc->format('Y'),
        };
    }

    private function assertDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException("date must be YYYY-MM-DD: {$date}");
        }
    }
}
