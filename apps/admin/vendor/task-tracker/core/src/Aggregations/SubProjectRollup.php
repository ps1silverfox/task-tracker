<?php

declare(strict_types=1);

namespace TaskTracker\Aggregations;

use InvalidArgumentException;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\TaskRepository;

/**
 * Recursive parent → descendants rollup for the tasks tree (spec §13).
 *
 * Aggregates count, effort, leaf count and a per-status histogram across a
 * root task and every live descendant linked via PARENT_ID. Soft-deleted
 * rows are excluded — they aren't part of the live backlog.
 *
 * Cycle handling is defensive. The tasks tree is acyclic by construction
 * (spec §5: PARENT_ID is a self-FK; cycles are a bug, not a use case), but
 * the repository doesn't enforce parent-cycle prevention on update, so the
 * traversal carries a visited set to bound recursion against corrupt data.
 */
final class SubProjectRollup
{
    public function __construct(private readonly TaskRepository $tasks)
    {
    }

    /**
     * Rollup for the subtree rooted at $rootId.
     *
     * Returns zeroed totals when $rootId is unknown or refers to a soft-deleted task
     * (rather than throwing) so callers rendering a UI need not branch on missing IDs.
     *
     * @return array{
     *   total_count: int,
     *   descendant_count: int,
     *   total_effort_hours: float,
     *   leaf_count: int,
     *   by_status: array<string, int>,
     * }
     */
    public function rollup(string $rootId): array
    {
        if ($rootId === '') {
            throw new InvalidArgumentException('rootId must not be empty');
        }

        $live = $this->tasks->listLive();
        return self::compute($rootId, self::indexByParent($live), self::indexById($live));
    }

    /**
     * Rollups keyed by parent task ID for every live task that has ≥1 live child.
     *
     * Pre-computes one index pair and reuses it across every parent for O(N · depth)
     * total work, instead of re-indexing per call.
     *
     * @return array<string, array{
     *   total_count: int,
     *   descendant_count: int,
     *   total_effort_hours: float,
     *   leaf_count: int,
     *   by_status: array<string, int>,
     * }>
     */
    public function allRollups(): array
    {
        $live = $this->tasks->listLive();
        $byParent = self::indexByParent($live);
        $byId = self::indexById($live);

        $out = [];
        foreach ($byParent as $parentId => $_children) {
            if ($parentId === '' || !isset($byId[$parentId])) {
                continue;
            }
            $out[$parentId] = self::compute($parentId, $byParent, $byId);
        }
        return $out;
    }

    /**
     * @param array<string, list<Task>> $byParent
     * @param array<string, Task>       $byId
     * @return array{total_count:int,descendant_count:int,total_effort_hours:float,leaf_count:int,by_status:array<string,int>}
     */
    private static function compute(string $rootId, array $byParent, array $byId): array
    {
        $totals = [
            'total_count' => 0,
            'descendant_count' => 0,
            'total_effort_hours' => 0.0,
            'leaf_count' => 0,
            'by_status' => [
                Enums::STATUS_OPEN => 0,
                Enums::STATUS_IN_PROGRESS => 0,
                Enums::STATUS_BLOCKED => 0,
                Enums::STATUS_DONE => 0,
            ],
        ];

        if (!isset($byId[$rootId])) {
            return $totals;
        }

        $visited = [];
        self::walk($rootId, $byParent, $byId, $visited, $totals, isRoot: true);
        return $totals;
    }

    /**
     * @param array<string, list<Task>>  $byParent
     * @param array<string, Task>        $byId
     * @param array<string, bool>        $visited
     * @param array{total_count:int,descendant_count:int,total_effort_hours:float,leaf_count:int,by_status:array<string,int>} $totals
     */
    private static function walk(
        string $id,
        array $byParent,
        array $byId,
        array &$visited,
        array &$totals,
        bool $isRoot,
    ): void {
        if (isset($visited[$id])) {
            return;
        }
        $visited[$id] = true;

        $task = $byId[$id] ?? null;
        if ($task === null) {
            return;
        }

        $totals['total_count']++;
        if (!$isRoot) {
            $totals['descendant_count']++;
        }
        if ($task->effortHours !== null) {
            $totals['total_effort_hours'] += $task->effortHours;
        }
        $totals['by_status'][$task->status] = ($totals['by_status'][$task->status] ?? 0) + 1;

        $children = $byParent[$id] ?? [];
        if ($children === []) {
            $totals['leaf_count']++;
            return;
        }

        $unvisitedChildren = 0;
        foreach ($children as $child) {
            if (isset($visited[$child->id])) {
                continue;
            }
            $unvisitedChildren++;
            self::walk($child->id, $byParent, $byId, $visited, $totals, isRoot: false);
        }
        // Under a cycle, every child may already be visited — treat the node as a leaf
        // so leaf_count stays a usable proxy for "terminal nodes in this traversal".
        if ($unvisitedChildren === 0) {
            $totals['leaf_count']++;
        }
    }

    /**
     * @param list<Task> $tasks
     * @return array<string, list<Task>>
     */
    private static function indexByParent(array $tasks): array
    {
        $idx = [];
        foreach ($tasks as $t) {
            $idx[$t->parentId ?? ''][] = $t;
        }
        return $idx;
    }

    /**
     * @param list<Task> $tasks
     * @return array<string, Task>
     */
    private static function indexById(array $tasks): array
    {
        $idx = [];
        foreach ($tasks as $t) {
            $idx[$t->id] = $t;
        }
        return $idx;
    }
}
