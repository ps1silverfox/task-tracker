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
 * Read/write access to tags.csv with two-write audit semantics
 * (CLAUDE-CONTEXT invariant 3).
 *
 * Row schema: (TASK_ID, TAG) where TAG is the lowercase-slug form produced
 * by self::normalize(). Composite key uniqueness is enforced at write time
 * under the CsvStore flock. The closed action enum (spec §8) admits only
 * `tag.added` and `tag.removed`; there are no update events because rows
 * are immutable — re-tagging is remove+add.
 */
final class TagRepository
{
    public const HEADERS = ['TASK_ID', 'TAG'];

    public function __construct(
        private readonly CsvStore $csv,
        private readonly EventLog $events,
        private readonly string $csvPath,
    ) {
        if ($csvPath === '') {
            throw new InvalidArgumentException('csvPath must not be empty');
        }
    }

    /**
     * Canonical on-disk form: lowercase ASCII alphanumeric runs joined by '-',
     * with leading/trailing hyphens stripped. Returns '' for input that contains
     * no normalizable characters — write methods promote that to an exception,
     * read methods treat it as a definite no-match.
     */
    public static function normalize(string $tag): string
    {
        $lower = strtolower(trim($tag));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $lower) ?? '';
        return trim($slug, '-');
    }

    /** @return list<array{taskId: string, tag: string}> */
    public function listAll(): array
    {
        $out = [];
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            $out[] = [
                'taskId' => (string) ($row['TASK_ID'] ?? ''),
                'tag' => (string) ($row['TAG'] ?? ''),
            ];
        }
        return $out;
    }

    /**
     * Tags attached to $taskId, ASCII-ascending.
     *
     * @return list<string>
     */
    public function tagsOf(string $taskId): array
    {
        if ($taskId === '') {
            return [];
        }
        $out = [];
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['TASK_ID'] ?? '') === $taskId) {
                $out[] = (string) ($row['TAG'] ?? '');
            }
        }
        sort($out);
        return $out;
    }

    /**
     * Tasks carrying $tag (after normalization), ASCII-ascending.
     *
     * @return list<string>
     */
    public function tasksWith(string $tag): array
    {
        $norm = self::normalize($tag);
        if ($norm === '') {
            return [];
        }
        $out = [];
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['TAG'] ?? '') === $norm) {
                $out[] = (string) ($row['TASK_ID'] ?? '');
            }
        }
        sort($out);
        return $out;
    }

    public function has(string $taskId, string $tag): bool
    {
        if ($taskId === '') {
            return false;
        }
        $norm = self::normalize($tag);
        if ($norm === '') {
            return false;
        }
        foreach ($this->csv->readAll($this->csvPath) as $row) {
            if (($row['TASK_ID'] ?? '') === $taskId && ($row['TAG'] ?? '') === $norm) {
                return true;
            }
        }
        return false;
    }

    /**
     * Attach $tag to $taskId. $tag is normalized; the slug is what gets stored.
     *
     * Rejects (RuntimeException) on:
     *   - duplicate composite key: "duplicate tag"
     *
     * @param array<string, mixed> $actorCtx Optional event envelope: actor, ip, user_agent.
     * @return string The normalized tag that was stored.
     */
    public function add(string $taskId, string $tag, array $actorCtx = []): string
    {
        if ($taskId === '') {
            throw new InvalidArgumentException('taskId must not be empty');
        }
        $norm = self::normalize($tag);
        if ($norm === '') {
            throw new InvalidArgumentException('tag must not be empty after normalization');
        }

        $this->csv->txn(
            $this->csvPath,
            self::HEADERS,
            function (array $rows) use ($taskId, $norm): array {
                foreach ($rows as $r) {
                    if (($r['TASK_ID'] ?? '') === $taskId && ($r['TAG'] ?? '') === $norm) {
                        throw new RuntimeException('duplicate tag');
                    }
                }
                $rows[] = ['TASK_ID' => $taskId, 'TAG' => $norm];
                return $rows;
            },
        );

        $this->safeEmit(self::envelope($taskId, IsoTime::now(), $actorCtx) + [
            'action' => 'tag.added',
            'data' => ['tag' => $norm],
        ]);

        return $norm;
    }

    /**
     * Detach $tag from $taskId. Idempotent: no CSV write and no event when the
     * pair is absent.
     *
     * @param array<string, mixed> $actorCtx
     */
    public function remove(string $taskId, string $tag, array $actorCtx = []): void
    {
        if ($taskId === '') {
            throw new InvalidArgumentException('taskId must not be empty');
        }
        $norm = self::normalize($tag);
        if ($norm === '') {
            throw new InvalidArgumentException('tag must not be empty after normalization');
        }

        $removed = false;

        $this->csv->txn(
            $this->csvPath,
            self::HEADERS,
            function (array $rows) use ($taskId, $norm, &$removed): array {
                $next = [];
                foreach ($rows as $r) {
                    if (($r['TASK_ID'] ?? '') === $taskId && ($r['TAG'] ?? '') === $norm) {
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
            'action' => 'tag.removed',
            'data' => ['tag' => $norm],
        ]);
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
