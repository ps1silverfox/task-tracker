<?php

declare(strict_types=1);

namespace TaskTracker\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;
use TaskTracker\Util\IsoTime;

/**
 * Read/write access to dependencies.csv with two-write audit semantics
 * (CLAUDE-CONTEXT invariant 3).
 *
 * Edge semantics (spec §5): row `(TASK_ID=A, PREREQ_ID=B)` means "B is a prerequisite
 * of A" / "B blocks A". The composite key `(TASK_ID, PREREQ_ID)` is unique.
 *
 * Cycle prevention (spec §9): adding edge `(A requires B)` is rejected if A is already
 * reachable from B via the `requires` adjacency. The check runs *inside* the CsvStore
 * transaction so the read used for the DFS and the write of the new edge happen under
 * the same exclusive lock — no TOCTOU race against a concurrent add.
 *
 * The closed action enum (spec §8) permits only `dependency.added` and
 * `dependency.removed`; there are no update events because edges are immutable.
 */
final class DependencyRepository
{
    public const HEADERS = ['TASK_ID', 'PREREQ_ID'];

    public function __construct(
        private readonly CsvStore $csv,
        private readonly EventLog $events,
        private readonly string $csvPath,
    ) {
        if ($csvPath === '') {
            throw new InvalidArgumentException('csvPath must not be empty');
        }
    }

    /** @return list<array{taskId: string, prereqId: string}> */
    public function listAll(): array
    {
        $out = [];
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            $out[] = [
                'taskId' => $row['TASK_ID'] ?? '',
                'prereqId' => $row['PREREQ_ID'] ?? '',
            ];
        }
        return $out;
    }

    /**
     * Prereq IDs that block the given task (i.e. the things this task requires).
     *
     * @return list<string>
     */
    public function prereqsOf(string $taskId): array
    {
        if ($taskId === '') {
            return [];
        }
        $out = [];
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['TASK_ID'] ?? '') === $taskId) {
                $out[] = (string) ($row['PREREQ_ID'] ?? '');
            }
        }
        return $out;
    }

    /**
     * Task IDs that the given prereq blocks (i.e. the things waiting on it).
     *
     * @return list<string>
     */
    public function blockedBy(string $prereqId): array
    {
        if ($prereqId === '') {
            return [];
        }
        $out = [];
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['PREREQ_ID'] ?? '') === $prereqId) {
                $out[] = (string) ($row['TASK_ID'] ?? '');
            }
        }
        return $out;
    }

    public function has(string $taskId, string $prereqId): bool
    {
        if ($taskId === '' || $prereqId === '') {
            return false;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['TASK_ID'] ?? '') === $taskId && ($row['PREREQ_ID'] ?? '') === $prereqId) {
                return true;
            }
        }
        return false;
    }

    /**
     * Add edge (task requires prereq).
     *
     * Rejects (RuntimeException) on:
     *   - self-edge (task_id === prereq_id): "would create cycle"
     *   - duplicate composite key: "duplicate dependency"
     *   - any cycle: "would create cycle"
     *
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     */
    public function add(string $taskId, string $prereqId, array $actorCtx = []): void
    {
        if ($taskId === '') {
            throw new InvalidArgumentException('taskId must not be empty');
        }
        if ($prereqId === '') {
            throw new InvalidArgumentException('prereqId must not be empty');
        }
        if ($taskId === $prereqId) {
            throw new RuntimeException('would create cycle');
        }

        $this->csv->txn(
            $this->csvPath,
            self::HEADERS,
            function (array $rows) use ($taskId, $prereqId): array {
                foreach ($rows as $r) {
                    if (($r['TASK_ID'] ?? '') === $taskId && ($r['PREREQ_ID'] ?? '') === $prereqId) {
                        throw new RuntimeException('duplicate dependency');
                    }
                }

                if (self::wouldCycle($rows, $taskId, $prereqId)) {
                    throw new RuntimeException('would create cycle');
                }

                $rows[] = ['TASK_ID' => $taskId, 'PREREQ_ID' => $prereqId];
                return $rows;
            },
        );

        $this->safeEmit(self::envelope($taskId, IsoTime::now(), $actorCtx) + [
            'action' => 'dependency.added',
            'data' => ['prereq_id' => $prereqId],
        ]);
    }

    /**
     * Remove edge. Idempotent: removing an absent edge is a no-op (no CSV write, no event).
     *
     * @param array<string, mixed> $actorCtx
     */
    public function remove(string $taskId, string $prereqId, array $actorCtx = []): void
    {
        if ($taskId === '') {
            throw new InvalidArgumentException('taskId must not be empty');
        }
        if ($prereqId === '') {
            throw new InvalidArgumentException('prereqId must not be empty');
        }

        $removed = false;

        $this->csv->txn(
            $this->csvPath,
            self::HEADERS,
            function (array $rows) use ($taskId, $prereqId, &$removed): array {
                $next = [];
                foreach ($rows as $r) {
                    if (
                        ($r['TASK_ID'] ?? '') === $taskId
                        && ($r['PREREQ_ID'] ?? '') === $prereqId
                    ) {
                        $removed = true;
                        continue;
                    }
                    $next[] = $r;
                }
                return $next;
            },
        );

        if (!$removed) {
            return;
        }

        $this->safeEmit(self::envelope($taskId, IsoTime::now(), $actorCtx) + [
            'action' => 'dependency.removed',
            'data' => ['prereq_id' => $prereqId],
        ]);
    }

    /**
     * Iterative DFS over the `requires` adjacency, with the proposed edge added in.
     *
     * Start at $prereqId and walk through its prereqs (i.e. things $prereqId itself
     * requires). If $taskId is reached, then $prereqId already (transitively) requires
     * $taskId — so adding "$taskId requires $prereqId" would close a cycle.
     *
     * @param list<array<string, string>> $rows
     */
    private static function wouldCycle(array $rows, string $taskId, string $prereqId): bool
    {
        /** @var array<string, list<string>> $requires */
        $requires = [];
        foreach ($rows as $r) {
            $t = $r['TASK_ID'] ?? '';
            $p = $r['PREREQ_ID'] ?? '';
            if ($t === '' || $p === '') {
                continue;
            }
            $requires[$t][] = $p;
        }
        $requires[$taskId][] = $prereqId;

        $stack = [$prereqId];
        /** @var array<string, true> $seen */
        $seen = [];
        while ($stack !== []) {
            $node = array_pop($stack);
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            if ($node === $taskId) {
                return true;
            }
            foreach ($requires[$node] ?? [] as $next) {
                if (!isset($seen[$next])) {
                    $stack[] = $next;
                }
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $actorCtx
     * @return array<string, mixed>
     */
    private static function envelope(string $taskId, string $ts, array $actorCtx): array
    {
        $env = [
            'ts' => $ts,
            'task_id' => $taskId,
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
            error_log(sprintf(
                'task-tracker: NDJSON append failed for action=%s task=%s: %s',
                (string) ($event['action'] ?? 'unknown'),
                (string) ($event['task_id'] ?? ''),
                $e->getMessage(),
            ));
        }
    }
}
