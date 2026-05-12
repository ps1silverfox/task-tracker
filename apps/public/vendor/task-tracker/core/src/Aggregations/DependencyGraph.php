<?php

declare(strict_types=1);

namespace TaskTracker\Aggregations;

use InvalidArgumentException;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\TaskRepository;

/**
 * Cytoscape.js JSON serializer for the dependency visualizer (spec §10).
 *
 * Projects live tasks plus the `requires` edge set into Cytoscape's
 * `{nodes: [...], edges: [...]}` shape. Visual encoding (status colour,
 * priority border, label truncation) is data-only — the colour mapping
 * happens client-side in the cytoscape stylesheet — so this layer stays
 * free of presentation concerns.
 *
 * Edge direction follows the spec: arrow from prereq → blocked task, so
 * a row `(TASK_ID=A, PREREQ_ID=B)` becomes an edge `source=B target=A`.
 *
 * Soft-deleted tasks are excluded. An edge whose endpoint is filtered out
 * (deleted, or outside the status/team/root filter) is dropped entirely
 * so Cytoscape never receives a dangling reference.
 */
final class DependencyGraph
{
    public const LABEL_MAX_CHARS = 40;
    public const RECOMMENDED_NODE_CEILING = 500;

    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly DependencyRepository $dependencies,
    ) {
    }

    /**
     * Serialize the dependency graph to a Cytoscape.js-compatible array.
     *
     * Filter keys (spec §10):
     *   - `status`:  list<string> — keep only tasks whose status is in the list
     *   - `team_id`: string       — keep only tasks on this team
     *   - `root`:    string       — keep $root and its PARENT_ID descendants
     *
     * The `meta.exceeds_threshold` flag lets the controller render the
     * "tighten the filter" banner without recounting client-side.
     *
     * @param array{status?: list<string>, team_id?: string, root?: string} $filters
     * @return array{
     *   nodes: list<array{data: array<string, mixed>}>,
     *   edges: list<array{data: array<string, string>}>,
     *   meta: array{node_count: int, edge_count: int, exceeds_threshold: bool},
     * }
     */
    public function serialize(array $filters = []): array
    {
        $allowed = $this->applyFilters($this->tasks->listLive(), $filters);

        $nodes = [];
        foreach ($allowed as $task) {
            $nodes[] = ['data' => self::nodeData($task)];
        }

        $edges = [];
        foreach ($this->dependencies->listAll() as $edge) {
            $taskId = $edge['taskId'];
            $prereqId = $edge['prereqId'];
            if (!isset($allowed[$taskId]) || !isset($allowed[$prereqId])) {
                continue;
            }
            $edges[] = [
                'data' => [
                    'id' => $prereqId . '->' . $taskId,
                    'source' => $prereqId,
                    'target' => $taskId,
                ],
            ];
        }

        return [
            'nodes' => $nodes,
            'edges' => $edges,
            'meta' => [
                'node_count' => count($nodes),
                'edge_count' => count($edges),
                'exceeds_threshold' => count($nodes) > self::RECOMMENDED_NODE_CEILING,
            ],
        ];
    }

    /**
     * @param list<Task>                                                    $tasks
     * @param array{status?: list<string>, team_id?: string, root?: string} $filters
     * @return array<string, Task> id => task
     */
    private function applyFilters(array $tasks, array $filters): array
    {
        $statuses = $filters['status'] ?? null;
        if ($statuses !== null) {
            foreach ($statuses as $s) {
                if (!in_array($s, Enums::STATUSES_LIVE, true)) {
                    throw new InvalidArgumentException("invalid status filter: {$s}");
                }
            }
        }

        $teamId = $filters['team_id'] ?? null;
        if ($teamId === '') {
            $teamId = null;
        }

        $rootId = $filters['root'] ?? null;
        if ($rootId === '') {
            $rootId = null;
        }

        $byId = [];
        foreach ($tasks as $t) {
            $byId[$t->id] = $t;
        }

        if ($rootId !== null) {
            if (!isset($byId[$rootId])) {
                return [];
            }
            $byId = self::descendantSet($byId, $rootId);
        }

        $out = [];
        foreach ($byId as $id => $task) {
            if ($statuses !== null && !in_array($task->status, $statuses, true)) {
                continue;
            }
            if ($teamId !== null && $task->teamId !== $teamId) {
                continue;
            }
            $out[$id] = $task;
        }
        return $out;
    }

    /**
     * Iterative DFS over PARENT_ID. Defensive visited set guards against the
     * corrupt-data parent cycle case the repository doesn't enforce against,
     * matching SubProjectRollup's posture.
     *
     * @param array<string, Task> $byId
     * @return array<string, Task>
     */
    private static function descendantSet(array $byId, string $rootId): array
    {
        /** @var array<string, list<Task>> $children */
        $children = [];
        foreach ($byId as $t) {
            if ($t->parentId !== null && $t->parentId !== '') {
                $children[$t->parentId][] = $t;
            }
        }

        $out = [];
        $stack = [$rootId];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($out[$id])) {
                continue;
            }
            $task = $byId[$id] ?? null;
            if ($task === null) {
                continue;
            }
            $out[$id] = $task;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child->id;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodeData(Task $task): array
    {
        return [
            'id' => $task->id,
            'label' => self::truncate($task->title, self::LABEL_MAX_CHARS),
            'title' => $task->title,
            'status' => $task->status,
            'priority' => $task->priority,
            'team_id' => $task->teamId,
            'assignee_id' => $task->assigneeId,
        ];
    }

    private static function truncate(string $s, int $max): string
    {
        $len = function_exists('mb_strlen') ? mb_strlen($s) : strlen($s);
        if ($len <= $max) {
            return $s;
        }
        $head = function_exists('mb_substr') ? mb_substr($s, 0, $max - 1) : substr($s, 0, $max - 1);
        return $head . '…';
    }
}
