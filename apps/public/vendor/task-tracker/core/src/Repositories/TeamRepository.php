<?php

declare(strict_types=1);

namespace TaskTracker\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TaskTracker\Models\Team;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;
use TaskTracker\Util\IsoTime;
use TaskTracker\Util\Uuid7;

/**
 * Read/write access to teams.csv with two-write audit semantics (CLAUDE-CONTEXT invariant 3).
 *
 * The closed action enum (spec §8) admits only `team.created` and `team.updated` — there
 * is no `team.deleted`. Teams are referenced by historical `team_id_snapshot` values in
 * past events, so destruction would orphan audit data. This repository therefore exposes
 * only create() and update(); no delete/deactivate method exists by design.
 */
final class TeamRepository
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

    /** @return list<Team> */
    public function listAll(): array
    {
        return array_map(
            static fn(array $row): Team => Team::fromCsvRow($row),
            $this->csv->readAll($this->csvPath),
        );
    }

    public function find(string $id): ?Team
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['ID'] ?? '') === $id) {
                return Team::fromCsvRow($row);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input    Accepts: name (required), description.
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     */
    public function create(array $input, array $actorCtx = []): Team
    {
        $team = new Team(
            id: Uuid7::generate(),
            name: self::requireString($input, 'name'),
            description: self::optString($input, 'description'),
        );

        $this->csv->txn($this->csvPath, Team::HEADERS, function (array $rows) use ($team): array {
            foreach ($rows as $r) {
                if (($r['ID'] ?? '') === $team->id) {
                    throw new RuntimeException("id collision: {$team->id}");
                }
            }
            $rows[] = $team->toCsvRow();
            return $rows;
        });

        $this->safeEmit(self::envelope($team, IsoTime::now(), $actorCtx) + [
            'action' => 'team.created',
            'data' => [
                'name' => $team->name,
                'description' => $team->description,
            ],
        ]);

        return $team;
    }

    /**
     * Patch one team. Pass only the fields to change. Returns the new Team.
     *
     * Mutable fields: name, description. Unknown keys are ignored. Identical-value
     * writes are no-ops (no event emitted).
     *
     * @param array<string, mixed> $changes  Subset of: name, description.
     * @param array<string, mixed> $actorCtx Optional event envelope.
     */
    public function update(string $id, array $changes, array $actorCtx = []): Team
    {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }

        /** @var array{0:Team,1:Team,2:list<string>}|null $captured */
        $captured = null;

        $this->csv->txn(
            $this->csvPath,
            Team::HEADERS,
            function (array $rows) use ($id, $changes, &$captured): array {
                $index = null;
                $current = null;
                foreach ($rows as $i => $r) {
                    if (($r['ID'] ?? '') === $id) {
                        $index = $i;
                        $current = Team::fromCsvRow($r);
                        break;
                    }
                }
                if ($current === null || $index === null) {
                    throw new RuntimeException("team not found: {$id}");
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
            'action' => 'team.updated',
            'data' => [
                'fields' => $changed,
                'before' => self::diffSlice($before, $changed),
                'after' => self::diffSlice($after, $changed),
            ],
        ]);

        return $after;
    }

    /**
     * Resolve sparse $changes into a (newTeam, changedFieldList) pair.
     *
     * @param array<string, mixed> $changes
     * @return array{0:Team,1:list<string>}
     */
    private static function applyChanges(Team $current, array $changes): array
    {
        $name = $current->name;
        $description = $current->description;
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

        if (array_key_exists('description', $changes)) {
            $raw = $changes['description'];
            $newOpt = ($raw === null || $raw === '') ? null : (string) $raw;
            if ($newOpt !== $current->description) {
                $description = $newOpt;
                $changed[] = 'description';
            }
        }

        $next = new Team(
            id: $current->id,
            name: $name,
            description: $description,
        );

        return [$next, $changed];
    }

    /**
     * @param list<string> $fields
     * @return array<string, string|null>
     */
    private static function diffSlice(Team $t, array $fields): array
    {
        $out = [];
        foreach ($fields as $f) {
            $out[$f] = match ($f) {
                'name' => $t->name,
                'description' => $t->description,
                default => null,
            };
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $actorCtx
     * @return array<string, mixed>
     */
    private static function envelope(Team $t, string $ts, array $actorCtx): array
    {
        $env = [
            'ts' => $ts,
            'team_id' => $t->id,
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
                'task-tracker: NDJSON append failed for action=%s team=%s: %s',
                (string) ($event['action'] ?? 'unknown'),
                (string) ($event['team_id'] ?? ''),
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
