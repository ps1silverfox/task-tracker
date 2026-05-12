<?php

declare(strict_types=1);

namespace TaskTracker\Repositories;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use TaskTracker\Models\SavedView;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;
use TaskTracker\Util\IsoTime;
use TaskTracker\Util\Uuid7;

/**
 * Read/write access to saved_views.csv with two-write audit semantics
 * (CLAUDE-CONTEXT invariant 3).
 *
 * Spec §8 admits only `saved_view.created` and `saved_view.deleted` — there
 * is no update event, so this repository exposes create() and delete() only.
 * Renaming or changing a view's filter requires delete + recreate.
 *
 * NAME is unique (spec §216). The uniqueness check happens inside the
 * CsvStore::txn() closure under flock, so concurrent creates can't both win.
 */
final class SavedViewRepository
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

    /** @return list<SavedView> */
    public function listAll(): array
    {
        return array_map(
            static fn(array $row): SavedView => SavedView::fromCsvRow($row),
            $this->csv->readAll($this->csvPath),
        );
    }

    public function find(string $id): ?SavedView
    {
        if ($id === '') {
            return null;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['ID'] ?? '') === $id) {
                return SavedView::fromCsvRow($row);
            }
        }
        return null;
    }

    public function findByName(string $name): ?SavedView
    {
        if ($name === '') {
            return null;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['NAME'] ?? '') === $name) {
                return SavedView::fromCsvRow($row);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $input    Accepts: name (required), filter (optional array).
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     */
    public function create(array $input, array $actorCtx = []): SavedView
    {
        $name = self::requireString($input, 'name');
        $filter = self::optFilter($input);

        $view = new SavedView(
            id: Uuid7::generate(),
            name: $name,
            createdAt: IsoTime::now(),
            filter: $filter,
        );

        $this->csv->txn($this->csvPath, SavedView::HEADERS, function (array $rows) use ($view): array {
            foreach ($rows as $r) {
                if (($r['ID'] ?? '') === $view->id) {
                    throw new RuntimeException("id collision: {$view->id}");
                }
                if (($r['NAME'] ?? '') === $view->name) {
                    throw new RuntimeException("duplicate name: {$view->name}");
                }
            }
            $rows[] = $view->toCsvRow();
            return $rows;
        });

        $this->safeEmit(self::envelope($view->id, IsoTime::now(), $actorCtx) + [
            'action' => 'saved_view.created',
            'data' => [
                'name' => $view->name,
                'filter' => $view->filter,
            ],
        ]);

        return $view;
    }

    /**
     * Remove a saved view. Idempotent: no CSV write and no event when the id is absent.
     *
     * @param array<string, mixed> $actorCtx
     */
    public function delete(string $id, array $actorCtx = []): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }

        $removed = null;

        $this->csv->txn(
            $this->csvPath,
            SavedView::HEADERS,
            function (array $rows) use ($id, &$removed): array {
                $next = [];
                foreach ($rows as $r) {
                    if (($r['ID'] ?? '') === $id) {
                        $removed = SavedView::fromCsvRow($r);
                        continue;
                    }
                    $next[] = $r;
                }
                return $next;
            },
        );

        if ($removed === null) {
            return;
        }

        $this->safeEmit(self::envelope($removed->id, IsoTime::now(), $actorCtx) + [
            'action' => 'saved_view.deleted',
            'data' => [
                'name' => $removed->name,
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $actorCtx
     * @return array<string, mixed>
     */
    private static function envelope(string $viewId, string $ts, array $actorCtx): array
    {
        $env = [
            'ts' => $ts,
            'saved_view_id' => $viewId,
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
                'task-tracker: NDJSON append failed for action=%s saved_view=%s: %s',
                (string) ($event['action'] ?? 'unknown'),
                (string) ($event['saved_view_id'] ?? ''),
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
     * @return array<string, mixed>
     */
    private static function optFilter(array $input): array
    {
        if (!array_key_exists('filter', $input)) {
            return [];
        }
        $raw = $input['filter'];
        if ($raw === null) {
            return [];
        }
        if (!is_array($raw)) {
            throw new InvalidArgumentException('filter must be an array');
        }
        return $raw;
    }
}
