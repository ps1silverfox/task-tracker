<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\SavedViewRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Repositories\TeamRepository;
use Twig\Environment;

/**
 * Public backlog listing — GET / with query-string filters (spec §5 FILTER_JSON, §6 public).
 * Renders backlog.twig via Twig (UI-05, UI-06).
 *
 * Filter axes:
 *   ?status=open,in_progress    list<string>  subset of STATUSES_LIVE
 *   ?priority=high,critical     list<string>  subset of PRIORITIES
 *   ?team_id=<uuid>             string        (literal "null" → match teamless rows)
 *   ?assignee_id=<uuid>         string        (literal "null" → unassigned)
 *   ?parent_id=<uuid>           string        (literal "null" → root-level)
 *   ?tags=urgent,bug            list<string>  task must carry ALL (AND semantics)
 *   ?due_within_days=7          int>=0        dueDate non-null AND ≤ now+N days
 *   ?include_done=1             bool          1/true/yes/on; default false
 *
 * `done` is excluded by default unless `status=done` is explicit or `include_done=1`.
 * `deleted` is unconditionally excluded — listLive() is the data source.
 *
 * Invariant: no write operation. Public app routes register only GET.
 */
final class BacklogController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly TagRepository $tagsRepo,
        private readonly RosterRepository $roster,
        private readonly TeamRepository $teams,
        private readonly SavedViewRepository $savedViews,
        private readonly Environment $twig,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $filter = self::parseFilter($params);
        $rows   = $this->applyFilter($this->tasks->listLive(), $filter);

        // Build assignee / team name maps for display
        $assigneeMap = [];
        foreach ($this->roster->listAll() as $m) {
            $assigneeMap[$m->id] = $m->name;
        }
        $teamMap = [];
        $teamList = $this->teams->listAll();
        foreach ($teamList as $t) {
            $teamMap[$t->id] = $t->name;
        }

        // Saved views for nav
        $savedViewsList = $this->savedViews->listAll();

        // Build query string for CSV export link
        $queryString = http_build_query(array_filter(
            $params,
            static fn($v): bool => is_string($v) && $v !== '',
        ), '', '&', PHP_QUERY_RFC3986);

        $html = $this->twig->render('backlog.twig', [
            'tasks'        => $rows,
            'assigneeMap'  => $assigneeMap,
            'teamMap'      => $teamMap,
            'activeFilter' => $filter,
            'filterParams' => $params,
            'roster'       => $this->roster->listAll(),
            'teams'        => $teamList,
            'savedViews'   => $savedViewsList,
            'query_string' => $queryString,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

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
        $statusFilter = $filter['status'];
        $dueCutoff    = self::dueCutoffIso($filter['dueWithinDays']);

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
}
