<?php

declare(strict_types=1);

namespace TaskTracker\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TaskTracker\Models\RosterMember;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;
use TaskTracker\Util\IsoTime;
use TaskTracker\Util\Uuid7;

/**
 * Read/write access to roster.csv with two-write audit semantics (CLAUDE-CONTEXT invariant 3).
 *
 * Every state-changing method:
 *   1. Mutates roster.csv via CsvStore::txn (authoritative, atomic, flock-guarded).
 *   2. Appends one NDJSON event via EventLog::append (corroborative).
 *
 * If the NDJSON append throws, the CSV is NOT rolled back; the failure is forwarded to
 * error_log() so it can be reconciled by tools/reconcile.php.
 *
 * Soft-delete (spec §5 roster.csv): ACTIVE flag flipped to false. The row is retained
 * so historical assignee_id_snapshot values in the event log remain resolvable.
 *
 * No reactivation method is exposed by design — the closed action enum in EventLog has
 * no `roster.reactivated` value, so allowing reactivation would create an unauditable
 * state transition. update() only covers name/email/teamId.
 */
final class RosterRepository
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

    /** @return list<RosterMember> */
    public function listAll(): array
    {
        return array_map(
            static fn(array $row): RosterMember => RosterMember::fromCsvRow($row),
            $this->csv->readAll($this->csvPath),
        );
    }

    /** @return list<RosterMember> */
    public function listActive(): array
    {
        return array_values(array_filter(
            $this->listAll(),
            static fn(RosterMember $m): bool => $m->active,
        ));
    }

    public function find(string $id): ?RosterMember
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['ID'] ?? '') === $id) {
                return RosterMember::fromCsvRow($row);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input    Accepts: name (required), email, teamId.
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     */
    public function add(array $input, array $actorCtx = []): RosterMember
    {
        $member = new RosterMember(
            id: Uuid7::generate(),
            name: self::requireString($input, 'name'),
            email: self::optString($input, 'email'),
            teamId: self::optString($input, 'teamId'),
            active: true,
        );

        $this->csv->txn($this->csvPath, RosterMember::HEADERS, function (array $rows) use ($member): array {
            foreach ($rows as $r) {
                if (($r['ID'] ?? '') === $member->id) {
                    throw new RuntimeException("id collision: {$member->id}");
                }
            }
            $rows[] = $member->toCsvRow();
            return $rows;
        });

        $this->safeEmit(self::envelope($member, IsoTime::now(), $actorCtx) + [
            'action' => 'roster.added',
            'data' => [
                'name' => $member->name,
                'email' => $member->email,
                'team_id' => $member->teamId,
            ],
        ]);

        return $member;
    }

    /**
     * Patch one roster member. Pass only the fields to change. Returns the new RosterMember.
     *
     * Mutable fields: name, email, teamId. ACTIVE is intentionally not mutable here —
     * use deactivate(). Unknown keys are ignored. Identical-value writes are no-ops.
     *
     * @param array<string, mixed> $changes  Subset of: name, email, teamId.
     * @param array<string, mixed> $actorCtx Optional event envelope.
     */
    public function update(string $id, array $changes, array $actorCtx = []): RosterMember
    {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }

        /** @var array{0:RosterMember,1:RosterMember,2:list<string>}|null $captured */
        $captured = null;

        $this->csv->txn(
            $this->csvPath,
            RosterMember::HEADERS,
            function (array $rows) use ($id, $changes, &$captured): array {
                $index = null;
                $current = null;
                foreach ($rows as $i => $r) {
                    if (($r['ID'] ?? '') === $id) {
                        $index = $i;
                        $current = RosterMember::fromCsvRow($r);
                        break;
                    }
                }
                if ($current === null || $index === null) {
                    throw new RuntimeException("roster member not found: {$id}");
                }

                [$next, $changed] = self::applyChanges($current, $changes);
                if ($changed === []) {
                    $captured = [$current, $current, []];
                    return $rows;
                }

                $rows[$index] = $next->toCsvRow();
                $captured = [$current, $next, $changed];
                return $rows;
            },
        );

        if ($captured === null) {
            throw new RuntimeException("update did not execute: {$id}");
        }
        [$before, $after, $changed] = $captured;

        if ($changed === []) {
            return $after;
        }

        $this->safeEmit(self::envelope($after, IsoTime::now(), $actorCtx) + [
            'action' => 'roster.updated',
            'data' => [
                'fields' => $changed,
                'before' => self::diffSlice($before, $changed),
                'after' => self::diffSlice($after, $changed),
            ],
        ]);

        return $after;
    }

    /**
     * Soft-delete: ACTIVE → false. Idempotent — deactivating an already-inactive member
     * is a no-op (returns the row, emits no event).
     *
     * @param array<string, mixed> $actorCtx
     */
    public function deactivate(string $id, array $actorCtx = []): RosterMember
    {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }

        /** @var array{0:RosterMember,1:RosterMember}|null $captured */
        $captured = null;

        $this->csv->txn(
            $this->csvPath,
            RosterMember::HEADERS,
            function (array $rows) use ($id, &$captured): array {
                foreach ($rows as $i => $r) {
                    if (($r['ID'] ?? '') !== $id) {
                        continue;
                    }
                    $current = RosterMember::fromCsvRow($r);
                    if (!$current->active) {
                        $captured = [$current, $current];
                        return $rows;
                    }
                    $next = new RosterMember(
                        id: $current->id,
                        name: $current->name,
                        email: $current->email,
                        teamId: $current->teamId,
                        active: false,
                    );
                    $rows[$i] = $next->toCsvRow();
                    $captured = [$current, $next];
                    return $rows;
                }
                throw new RuntimeException("roster member not found: {$id}");
            },
        );

        if ($captured === null) {
            throw new RuntimeException("deactivate did not execute: {$id}");
        }
        [$before, $after] = $captured;

        if (!$before->active) {
            return $after;
        }

        $this->safeEmit(self::envelope($after, IsoTime::now(), $actorCtx) + [
            'action' => 'roster.deactivated',
            'data' => ['name' => $after->name],
        ]);

        return $after;
    }

    /**
     * Resolve sparse $changes into a (newMember, changedFieldList) pair.
     *
     * @param array<string, mixed> $changes
     * @return array{0:RosterMember,1:list<string>}
     */
    private static function applyChanges(RosterMember $current, array $changes): array
    {
        $name = $current->name;
        $email = $current->email;
        $teamId = $current->teamId;
        $changed = [];

        if (array_key_exists('name', $changes)) {
            $raw = $changes['name'];
            $newStr = $raw === null ? '' : (string) $raw;
            if ($newStr === '') {
                throw new InvalidArgumentException('name must not be empty');
            }
            if ($newStr !== $current->name) {
                $name = $newStr;
                $changed[] = 'name';
            }
        }

        if (array_key_exists('email', $changes)) {
            $raw = $changes['email'];
            $newOpt = ($raw === null || $raw === '') ? null : (string) $raw;
            if ($newOpt !== $current->email) {
                $email = $newOpt;
                $changed[] = 'email';
            }
        }

        if (array_key_exists('teamId', $changes)) {
            $raw = $changes['teamId'];
            $newOpt = ($raw === null || $raw === '') ? null : (string) $raw;
            if ($newOpt !== $current->teamId) {
                $teamId = $newOpt;
                $changed[] = 'teamId';
            }
        }

        $next = new RosterMember(
            id: $current->id,
            name: $name,
            email: $email,
            teamId: $teamId,
            active: $current->active,
        );

        return [$next, $changed];
    }

    /**
     * @param list<string> $fields
     * @return array<string, string|null>
     */
    private static function diffSlice(RosterMember $m, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $out[$f] = match ($f) {
                'name' => $m->name,
                'email' => $m->email,
                'teamId' => $m->teamId,
                default => null,
            };
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $actorCtx
     * @return array<string, mixed>
     */
    private static function envelope(RosterMember $m, string $ts, array $actorCtx): array
    {
        $env = [
            'ts' => $ts,
            'roster_id' => $m->id,
            'team_id_snapshot' => $m->teamId,
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
                'task-tracker: NDJSON append failed for action=%s roster=%s: %s',
                (string) ($event['action'] ?? 'unknown'),
                (string) ($event['roster_id'] ?? ''),
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
}
