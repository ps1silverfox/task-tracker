<?php

declare(strict_types=1);

namespace TaskTracker\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;
use TaskTracker\Util\IsoTime;
use TaskTracker\Util\Uuid7;

/**
 * Read/write access to tasks.csv with two-write audit semantics (CLAUDE-CONTEXT invariant 3).
 *
 * Every state-changing method:
 *   1. Mutates tasks.csv via CsvStore::txn (authoritative, atomic, flock-guarded).
 *   2. Appends one or more NDJSON events via EventLog::append (corroborative).
 *
 * If the NDJSON append throws, the CSV is NOT rolled back; the failure is forwarded to
 * error_log() so it can be reconciled by tools/reconcile.php. This matches the spec's
 * "log an alarm" wording (§9 / CLAUDE-CONTEXT).
 *
 * Assignment timestamp semantics (spec §5):
 *   - FIRST_ASSIGNED_AT  — set once when ASSIGNEE_ID goes null → non-null; never overwritten.
 *   - LAST_ASSIGNMENT_CHANGE_AT — bumped on every ASSIGNEE_ID change (including → null).
 *
 * Completion semantics (spec §5):
 *   - COMPLETED_AT set when STATUS transitions to `done`; cleared on any transition away.
 *
 * Soft-delete (spec §6) sets STATUS = `deleted`; the row is retained so that
 * dependency/history references stay resolvable.
 */
final class TaskRepository
{
    public function __construct(
        private readonly CsvStore $csv,
        private readonly EventLog $events,
        private readonly string $csvPath,
    ) {
        if ($csvPath === '') {
            throw new InvalidArgumentException('csvPath must not be empty');
        }
    }

    /** @return list<Task> */
    public function listAll(): array
    {
        return array_map(
            static fn(array $row): Task => Task::fromCsvRow($row),
            $this->csv->readAll($this->csvPath),
        );
    }

    /** @return list<Task> */
    public function listLive(): array
    {
        return array_values(array_filter(
            $this->listAll(),
            static fn(Task $t): bool => $t->status !== Enums::STATUS_DELETED,
        ));
    }

    public function find(string $id): ?Task
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['ID'] ?? '') === $id) {
                return Task::fromCsvRow($row);
            }
        }
        return null;
    }

    /**
     * Slug uniqueness applies across non-deleted rows only (spec §5). Soft-deleted rows
     * are intentionally skipped so a freed slug can be reused.
     */
    public function findBySlug(string $slug): ?Task
    {
        if ($slug === '') {
            return null;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['STATUS'] ?? '') === Enums::STATUS_DELETED) {
                continue;
            }
            if (($row['SLUG'] ?? '') === $slug) {
                return Task::fromCsvRow($row);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input   Accepts: slug, title, body, status, priority,
     *                                       dueDate, effortHours, url, parentId, assigneeId, teamId.
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     */
    public function create(array $input, array $actorCtx = []): Task
    {
        $now = IsoTime::now();
        $assignee = self::optString($input, 'assigneeId');
        $status = self::optString($input, 'status') ?? Enums::STATUS_OPEN;

        $task = new Task(
            id: Uuid7::generate(),
            slug: self::requireString($input, 'slug'),
            title: self::requireString($input, 'title'),
            body: self::optString($input, 'body'),
            status: $status,
            priority: self::optString($input, 'priority') ?? Enums::PRIORITY_DEFAULT,
            dueDate: self::optString($input, 'dueDate'),
            effortHours: self::optFloat($input, 'effortHours'),
            url: self::optString($input, 'url'),
            parentId: self::optString($input, 'parentId'),
            assigneeId: $assignee,
            teamId: self::optString($input, 'teamId'),
            createdAt: $now,
            updatedAt: $now,
            firstAssignedAt: $assignee !== null ? $now : null,
            lastAssignmentChangeAt: $assignee !== null ? $now : null,
            completedAt: $status === Enums::STATUS_DONE ? $now : null,
        );

        $this->csv->txn($this->csvPath, Task::HEADERS, function (array $rows) use ($task): array {
            foreach ($rows as $r) {
                if (($r['STATUS'] ?? '') === Enums::STATUS_DELETED) {
                    continue;
                }
                if (($r['SLUG'] ?? '') === $task->slug) {
                    throw new RuntimeException("slug not unique: {$task->slug}");
                }
                if (($r['ID'] ?? '') === $task->id) {
                    throw new RuntimeException("id collision: {$task->id}");
                }
            }
            $rows[] = $task->toCsvRow();
            return $rows;
        });

        $envelope = self::snapshotEnvelope($task, $now, $actorCtx);

        $this->safeEmit($envelope + [
            'action' => 'task.created',
            'data' => ['slug' => $task->slug, 'title' => $task->title],
        ]);

        if ($task->assigneeId !== null) {
            $this->safeEmit($envelope + [
                'action' => 'task.assigned',
                'data' => ['from' => null, 'to' => $task->assigneeId],
            ]);
        }
        if ($task->status === Enums::STATUS_DONE) {
            $this->safeEmit($envelope + [
                'action' => 'task.completed',
                'data' => ['completed_at' => $task->completedAt],
            ]);
        }

        return $task;
    }

    /**
     * Patch one task. Pass only the fields to change. Returns the new Task.
     *
     * Unknown keys are ignored; identical-value writes are a no-op for that field (still
     * legal — just not emitted as a change). If no field actually changes, the call is a
     * no-op (no CSV write, no events).
     *
     * @param array<string, mixed> $changes  Subset of: slug, title, body, status, priority,
     *                                        dueDate, effortHours, url, parentId, assigneeId, teamId.
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     */
    public function update(string $id, array $changes, array $actorCtx = []): Task
    {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }

        $now = IsoTime::now();
        /** @var array{0:Task,1:Task,2:list<string>}|null $captured */
        $captured = null;

        $this->csv->txn(
            $this->csvPath,
            Task::HEADERS,
            function (array $rows) use ($id, $changes, $now, &$captured): array {
                $index = null;
                $current = null;
                foreach ($rows as $i => $r) {
                    if (($r['ID'] ?? '') === $id) {
                        $index = $i;
                        $current = Task::fromCsvRow($r);
                        break;
                    }
                }
                if ($current === null || $index === null) {
                    throw new RuntimeException("task not found: {$id}");
                }

                [$next, $changed] = self::applyChanges($current, $changes, $now);
                if ($changed === []) {
                    $captured = [$current, $current, []];
                    return $rows;
                }

                // slug uniqueness across non-deleted rows (skip self).
                if (in_array('slug', $changed, true)) {
                    foreach ($rows as $j => $r) {
                        if ($j === $index) {
                            continue;
                        }
                        if (($r['STATUS'] ?? '') === Enums::STATUS_DELETED) {
                            continue;
                        }
                        if (($r['SLUG'] ?? '') === $next->slug) {
                            throw new RuntimeException("slug not unique: {$next->slug}");
                        }
                    }
                }

                $rows[$index] = $next->toCsvRow();
                $captured = [$current, $next, $changed];
                return $rows;
            },
        );

        if ($captured === null) {
            // Defensive: txn ran without populating $captured. CsvStore::txn always invokes
            // the mutator exactly once, so reaching here means the closure was bypassed.
            throw new RuntimeException("update did not execute: {$id}");
        }
        [$before, $after, $changed] = $captured;

        if ($changed === []) {
            return $after;
        }

        $envelope = self::snapshotEnvelope($after, $now, $actorCtx);

        $this->safeEmit($envelope + [
            'action' => 'task.updated',
            'data' => ['fields' => $changed],
        ]);

        if (in_array('assigneeId', $changed, true)) {
            $action = match (true) {
                $before->assigneeId === null && $after->assigneeId !== null => 'task.assigned',
                $before->assigneeId !== null && $after->assigneeId === null => 'task.unassigned',
                default => 'task.reassigned',
            };
            $this->safeEmit($envelope + [
                'action' => $action,
                'data' => ['from' => $before->assigneeId, 'to' => $after->assigneeId],
            ]);
        }

        if (in_array('status', $changed, true)) {
            $this->safeEmit($envelope + [
                'action' => 'task.status_changed',
                'data' => ['from' => $before->status, 'to' => $after->status],
            ]);
            if ($after->status === Enums::STATUS_DONE && $before->status !== Enums::STATUS_DONE) {
                $this->safeEmit($envelope + [
                    'action' => 'task.completed',
                    'data' => ['completed_at' => $after->completedAt],
                ]);
            }
        }

        if (in_array('priority', $changed, true)) {
            $this->safeEmit($envelope + [
                'action' => 'task.priority_changed',
                'data' => ['from' => $before->priority, 'to' => $after->priority],
            ]);
        }

        if (in_array('dueDate', $changed, true)) {
            $this->safeEmit($envelope + [
                'action' => 'task.due_date_changed',
                'data' => ['from' => $before->dueDate, 'to' => $after->dueDate],
            ]);
        }

        return $after;
    }

    /**
     * Soft-delete: STATUS → `deleted`. The row stays so dependency references and
     * historical events remain resolvable.
     *
     * Idempotent: deleting an already-deleted task is a no-op (returns the row, emits nothing).
     *
     * @param array<string, mixed> $actorCtx
     */
    public function softDelete(string $id, array $actorCtx = []): Task
    {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }

        $now = IsoTime::now();
        /** @var array{0:Task,1:Task}|null $captured */
        $captured = null;

        $this->csv->txn(
            $this->csvPath,
            Task::HEADERS,
            function (array $rows) use ($id, $now, &$captured): array {
                foreach ($rows as $i => $r) {
                    if (($r['ID'] ?? '') !== $id) {
                        continue;
                    }
                    $current = Task::fromCsvRow($r);
                    if ($current->status === Enums::STATUS_DELETED) {
                        $captured = [$current, $current];
                        return $rows;
                    }
                    $next = self::rebuild($current, [
                        'STATUS' => Enums::STATUS_DELETED,
                        'UPDATED_AT' => $now,
                    ]);
                    $rows[$i] = $next->toCsvRow();
                    $captured = [$current, $next];
                    return $rows;
                }
                throw new RuntimeException("task not found: {$id}");
            },
        );

        if ($captured === null) {
            throw new RuntimeException("softDelete did not execute: {$id}");
        }
        [$before, $after] = $captured;

        if ($before->status === Enums::STATUS_DELETED) {
            return $after;
        }

        $envelope = self::snapshotEnvelope($after, $now, $actorCtx);
        $this->safeEmit($envelope + [
            'action' => 'task.deleted',
            'data' => ['previous_status' => $before->status],
        ]);

        return $after;
    }

    /**
     * Resolve sparse $changes into a (newTask, changedFieldList) pair.
     *
     * Field semantics:
     *   - assigneeId change: bump LAST_ASSIGNMENT_CHANGE_AT; set FIRST_ASSIGNED_AT iff
     *     transitioning from null and the prior value was never assigned.
     *   - status → done: set COMPLETED_AT.
     *   - status leaves done: clear COMPLETED_AT.
     *   - any change: bump UPDATED_AT.
     *
     * @param array<string, mixed> $changes
     * @return array{0:Task,1:list<string>}
     */
    private static function applyChanges(Task $current, array $changes, string $now): array
    {
        $row = $current->toCsvRow();
        $changed = [];

        $fieldMap = [
            'slug' => 'SLUG',
            'title' => 'TITLE',
            'body' => 'BODY',
            'status' => 'STATUS',
            'priority' => 'PRIORITY',
            'dueDate' => 'DUE_DATE',
            'url' => 'URL',
            'parentId' => 'PARENT_ID',
            'teamId' => 'TEAM_ID',
        ];
        foreach ($fieldMap as $inputKey => $col) {
            if (!array_key_exists($inputKey, $changes)) {
                continue;
            }
            $new = $changes[$inputKey];
            $newStr = $new === null ? '' : (string) $new;
            if ($row[$col] !== $newStr) {
                $row[$col] = $newStr;
                $changed[] = $inputKey;
            }
        }

        if (array_key_exists('effortHours', $changes)) {
            $raw = $changes['effortHours'];
            $newStr = $raw === null || $raw === ''
                ? ''
                : number_format((float) $raw, 2, '.', '');
            if ($row['EFFORT_HOURS'] !== $newStr) {
                $row['EFFORT_HOURS'] = $newStr;
                $changed[] = 'effortHours';
            }
        }

        if (array_key_exists('assigneeId', $changes)) {
            $raw = $changes['assigneeId'];
            $newStr = $raw === null || $raw === '' ? '' : (string) $raw;
            if ($row['ASSIGNEE_ID'] !== $newStr) {
                $row['ASSIGNEE_ID'] = $newStr;
                $row['LAST_ASSIGNMENT_CHANGE_AT'] = $now;
                if ($newStr !== '' && $row['FIRST_ASSIGNED_AT'] === '') {
                    $row['FIRST_ASSIGNED_AT'] = $now;
                }
                $changed[] = 'assigneeId';
            }
        }

        if (in_array('status', $changed, true)) {
            if ($row['STATUS'] === Enums::STATUS_DONE && $current->status !== Enums::STATUS_DONE) {
                $row['COMPLETED_AT'] = $now;
            } elseif ($row['STATUS'] !== Enums::STATUS_DONE && $current->status === Enums::STATUS_DONE) {
                $row['COMPLETED_AT'] = '';
            }
        }

        if ($changed !== []) {
            $row['UPDATED_AT'] = $now;
        }

        return [Task::fromCsvRow($row), $changed];
    }

    /**
     * @param array<string, string> $overrides  Column-name keyed overrides.
     */
    private static function rebuild(Task $task, array $overrides): Task
    {
        $row = $task->toCsvRow();
        foreach ($overrides as $col => $value) {
            $row[$col] = $value;
        }
        return Task::fromCsvRow($row);
    }

    /**
     * @param array<string, mixed> $actorCtx
     * @return array<string, mixed>
     */
    private static function snapshotEnvelope(Task $task, string $ts, array $actorCtx): array
    {
        $env = [
            'ts' => $ts,
            'task_id' => $task->id,
            'assignee_id_snapshot' => $task->assigneeId,
            'team_id_snapshot' => $task->teamId,
        ];
        foreach (['actor', 'ip', 'user_agent'] as $key) {
            if (isset($actorCtx[$key]) && $actorCtx[$key] !== '') {
                $env[$key] = (string) $actorCtx[$key];
            }
        }
        return $env;
    }

    /**
     * @param array<string, mixed> $event
     */
    private function safeEmit(array $event): void
    {
        try {
            $this->events->append($event);
        } catch (Throwable $e) {
            // CSV write already committed; per invariant, do NOT roll back. Surface the
            // failure as an error_log alarm so reconcile.php can detect the drift.
            error_log(sprintf(
                'task-tracker: NDJSON append failed for action=%s task=%s: %s',
                (string) ($event['action'] ?? 'unknown'),
                (string) ($event['task_id'] ?? ''),
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function requireString(array $input, string $key): string
    {
        if (!isset($input[$key])) {
            throw new InvalidArgumentException("missing required field: {$key}");
        }
        $value = (string) $input[$key];
        if ($value === '') {
            throw new InvalidArgumentException("field must not be empty: {$key}");
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function optString(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        $value = $input[$key];
        if ($value === null || $value === '') {
            return null;
        }
        return (string) $value;
    }

    /**
     * @param array<string, mixed> $input
     */
    private static function optFloat(array $input, string $key): ?float
    {
        if (!array_key_exists($key, $input)) {
            return null;
        }
        $value = $input[$key];
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }
}
