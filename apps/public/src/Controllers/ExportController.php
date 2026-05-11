<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpBadRequestException;
use TaskTracker\Aggregations\ExecutiveSummary;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Util\CsvInjectionGuard;

/**
 * Public CSV exports — GET /tasks.csv and GET /summary.csv (spec §6 + §10, PUB-05).
 *
 * Both routes emit RFC 4180 CSV (CRLF, every field quoted, UTF-8 no BOM) with
 * the §10 injection guard applied: any cell whose first byte is `=`, `+`, `-`,
 * `@`, tab, or CR is prefixed with a single apostrophe before the RFC 4180
 * quote/escape pass. That ordering matters — quoting alone does not neutralise
 * Excel's formula evaluation for a leading `=`.
 *
 * `/tasks.csv` honours the same query-string filter axes as the public backlog
 * page (see BacklogController docblock for the catalog). The filter parsing /
 * application is mirrored here rather than shared, because the task scope
 * (PUB-05) does not include modifying BacklogController; a future task may
 * extract a BacklogFilter helper once a third call site (PUB-07 saved views)
 * exists.
 *
 * `/summary.csv` reuses ExecutiveSummary::summarize() with the same defaults
 * and validation as ExecutiveSummaryController so the HTML page and the CSV
 * export return identical bucket sequences for identical inputs.
 *
 * Invariant: GET-only. PUB-09 enforces 405 on every other verb.
 */
final class ExportController
{
    private const CRLF = "\r\n";
    private const DEFAULT_LOOKBACK_MONTHS = 11;

    /** @var list<string> */
    private const SUMMARY_HEADERS = [
        'PERIOD',
        'ADDED',
        'COMPLETED',
        'ASSIGNED',
        'UNASSIGNED',
    ];

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TagRepository $tagsRepo,
        private readonly ExecutiveSummary $summary,
    ) {
    }

    public function tasks(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $filter = self::parseFilter($request->getQueryParams());
        $rows   = $this->applyFilter($this->tasks->listLive(), $filter);

        $csv = self::formatRow(Task::HEADERS);
        foreach ($rows as $task) {
            $csv .= self::formatRow(self::taskCells($task));
        }

        return self::csvResponse($response, $csv, 'tasks.csv');
    }

    public function summary(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $period = self::str($params, 'period') ?? ExecutiveSummary::PERIOD_MONTH;
        if (!in_array($period, ExecutiveSummary::PERIODS, true)) {
            throw new HttpBadRequestException(
                $request,
                "invalid period: '{$period}' (expected one of: "
                    . implode(', ', ExecutiveSummary::PERIODS) . ')',
            );
        }

        [$from, $to] = self::resolveRange(
            self::str($params, 'from'),
            self::str($params, 'to'),
            $request,
        );

        $person  = self::str($params, 'person');
        $team    = self::str($params, 'team');
        $buckets = $this->summary->summarize($period, $from, $to, $person, $team);

        $csv = self::formatRow(self::SUMMARY_HEADERS);
        foreach ($buckets as $b) {
            $csv .= self::formatRow([
                (string) $b['period'],
                (string) $b[ExecutiveSummary::METRIC_ADDED],
                (string) $b[ExecutiveSummary::METRIC_COMPLETED],
                (string) $b[ExecutiveSummary::METRIC_ASSIGNED],
                (string) $b[ExecutiveSummary::METRIC_UNASSIGNED],
            ]);
        }

        return self::csvResponse($response, $csv, 'summary.csv');
    }

    // ===== CSV emission =====

    private static function csvResponse(
        ResponseInterface $response,
        string $body,
        string $filename,
    ): ResponseInterface {
        $response->getBody()->write($body);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
    }

    /**
     * @param list<string> $cells
     */
    private static function formatRow(array $cells): string
    {
        $parts = [];
        foreach ($cells as $cell) {
            $guarded = CsvInjectionGuard::escape($cell);
            $parts[] = '"' . str_replace('"', '""', $guarded) . '"';
        }
        return implode(',', $parts) . self::CRLF;
    }

    /**
     * @return list<string>
     */
    private static function taskCells(Task $task): array
    {
        $row = $task->toCsvRow();
        $out = [];
        foreach (Task::HEADERS as $h) {
            $out[] = $row[$h] ?? '';
        }
        return $out;
    }

    // ===== Backlog filter (mirror of BacklogController, see docblock) =====

    /**
     * @param array<string, mixed> $params
     * @return array{
     *   status: list<string>|null,
     *   priority: list<string>|null,
     *   teamId: array{set: bool, value: ?string},
     *   assigneeId: array{set: bool, value: ?string},
     *   parentId: array{set: bool, value: ?string},
     *   tags: list<string>|null,
     *   dueWithinDays: int|null,
     *   includeDone: bool,
     * }
     */
    private static function parseFilter(array $params): array
    {
        return [
            'status'        => self::csvList($params, 'status'),
            'priority'      => self::csvList($params, 'priority'),
            'teamId'        => self::tripleState($params, 'team_id'),
            'assigneeId'    => self::tripleState($params, 'assignee_id'),
            'parentId'      => self::tripleState($params, 'parent_id'),
            'tags'          => self::csvList($params, 'tags'),
            'dueWithinDays' => self::optPositiveInt($params, 'due_within_days'),
            'includeDone'   => self::optBool($params, 'include_done'),
        ];
    }

    /**
     * @param list<Task> $tasks
     * @param array<string, mixed> $filter
     * @return list<Task>
     */
    private function applyFilter(array $tasks, array $filter): array
    {
        $statusFilter  = $filter['status'];
        $dueCutoff     = self::dueCutoffIso($filter['dueWithinDays']);
        $taggedTaskIds = null;
        if ($filter['tags'] !== null && $filter['tags'] !== []) {
            $taggedTaskIds = $this->taskIdsCarryingAllTags($filter['tags']);
        }

        $out = [];
        foreach ($tasks as $t) {
            if ($statusFilter !== null) {
                if (!in_array($t->status, $statusFilter, true)) {
                    continue;
                }
            } elseif (!$filter['includeDone'] && $t->status === Enums::STATUS_DONE) {
                continue;
            }
            if ($filter['priority'] !== null && !in_array($t->priority, $filter['priority'], true)) {
                continue;
            }
            if (!self::tripleMatches($filter['teamId'], $t->teamId)) {
                continue;
            }
            if (!self::tripleMatches($filter['assigneeId'], $t->assigneeId)) {
                continue;
            }
            if (!self::tripleMatches($filter['parentId'], $t->parentId)) {
                continue;
            }
            if ($dueCutoff !== null) {
                if ($t->dueDate === null || $t->dueDate > $dueCutoff) {
                    continue;
                }
            }
            if ($taggedTaskIds !== null && !isset($taggedTaskIds[$t->id])) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param list<string> $tags
     * @return array<string, true>
     */
    private function taskIdsCarryingAllTags(array $tags): array
    {
        $normalized = [];
        foreach ($tags as $raw) {
            $n = TagRepository::normalize($raw);
            if ($n !== '') {
                $normalized[$n] = true;
            }
        }
        if ($normalized === []) {
            return [];
        }

        /** @var array<string, array<string, true>> $byTag */
        $byTag = array_fill_keys(array_keys($normalized), []);
        foreach ($this->tagsRepo->listAll() as $row) {
            $tag = $row['tag'];
            if (isset($byTag[$tag])) {
                $byTag[$tag][$row['taskId']] = true;
            }
        }

        $intersection = null;
        foreach ($byTag as $taskIds) {
            $intersection = $intersection === null
                ? $taskIds
                : array_intersect_key($intersection, $taskIds);
            if ($intersection === []) {
                return [];
            }
        }
        return $intersection ?? [];
    }

    // ===== Parameter parsers =====

    /**
     * @param array<string, mixed> $params
     * @return list<string>|null
     */
    private static function csvList(array $params, string $key): ?array
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $raw = $params[$key];
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $out[] = $piece;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return array{set: bool, value: ?string}
     */
    private static function tripleState(array $params, string $key): array
    {
        if (!array_key_exists($key, $params)) {
            return ['set' => false, 'value' => null];
        }
        $raw = $params[$key];
        if (!is_string($raw) || $raw === '' || $raw === 'null') {
            return ['set' => true, 'value' => null];
        }
        return ['set' => true, 'value' => $raw];
    }

    /**
     * @param array{set: bool, value: ?string} $tri
     */
    private static function tripleMatches(array $tri, ?string $rowValue): bool
    {
        if (!$tri['set']) {
            return true;
        }
        return $tri['value'] === $rowValue;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function optPositiveInt(array $params, string $key): ?int
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $raw = $params[$key];
        if (!is_string($raw) || !preg_match('/^\d+$/', $raw)) {
            return null;
        }
        return (int) $raw;
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function optBool(array $params, string $key): bool
    {
        if (!array_key_exists($key, $params)) {
            return false;
        }
        $raw = $params[$key];
        if (!is_string($raw)) {
            return false;
        }
        return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
    }

    private static function dueCutoffIso(?int $days): ?string
    {
        if ($days === null) {
            return null;
        }
        $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->add(new DateInterval('P' . $days . 'D'));
        return $cutoff->format('Y-m-d') . 'T23:59:59.999Z';
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function str(array $params, string $key): ?string
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $raw = $params[$key];
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        return $raw;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function resolveRange(
        ?string $from,
        ?string $to,
        ServerRequestInterface $request,
    ): array {
        $utc = new DateTimeZone('UTC');
        $todayIso = (new DateTimeImmutable('now', $utc))->format('Y-m-d');

        $to ??= $todayIso;
        if (!self::isIsoDate($to)) {
            throw new HttpBadRequestException($request, "invalid 'to' date (expected YYYY-MM-DD): {$to}");
        }
        if ($from === null) {
            $base = new DateTimeImmutable($to . 'T00:00:00', $utc);
            $from = $base->modify('-' . self::DEFAULT_LOOKBACK_MONTHS . ' months')
                ->format('Y-m-d');
        } elseif (!self::isIsoDate($from)) {
            throw new HttpBadRequestException($request, "invalid 'from' date (expected YYYY-MM-DD): {$from}");
        }
        if ($from > $to) {
            throw new HttpBadRequestException($request, "'from' ({$from}) is after 'to' ({$to})");
        }
        return [$from, $to];
    }

    private static function isIsoDate(string $date): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);
    }
}
