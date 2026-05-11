<?php

declare(strict_types=1);

namespace TaskTracker\Storage;

use InvalidArgumentException;
use RuntimeException;
use TaskTracker\Util\IsoTime;

/**
 * Append-only NDJSON change log with daily rotation.
 *
 * Per spec §8:
 *   - Path: <logDir>/changes-YYYY-MM-DD.ndjson (UTC date)
 *   - One JSON object per line, LF terminator, UTF-8 no BOM
 *   - Append-only at runtime; rotation by UTC date (new file on first write of the new day)
 *   - Append via file_put_contents(..., FILE_APPEND | LOCK_EX)
 *
 * Filename date is derived from $event['ts'] when present, otherwise from
 * the current UTC date. This keeps backfill/replay deterministic and makes
 * rotation testable without time mocking.
 *
 * Action validation: ACTIONS is a closed set (spec §8). Adding new actions
 * requires bumping data/_schema_version.txt — handled by tools/migrate.php.
 */
final class EventLog
{
    public const ACTIONS = [
        'task.created',
        'task.updated',
        'task.deleted',
        'task.assigned',
        'task.unassigned',
        'task.reassigned',
        'task.status_changed',
        'task.completed',
        'task.priority_changed',
        'task.due_date_changed',
        'dependency.added',
        'dependency.removed',
        'tag.added',
        'tag.removed',
        'roster.added',
        'roster.updated',
        'roster.deactivated',
        'team.created',
        'team.updated',
        'saved_view.created',
        'saved_view.deleted',
        'system.reconcile',
    ];

    public function __construct(private readonly string $logDir)
    {
        if ($logDir === '') {
            throw new InvalidArgumentException('logDir must not be empty');
        }
    }

    /**
     * Append one event line. Returns the file path written to.
     *
     * @param array<string, mixed> $event
     *        Required: action (must be in ACTIONS).
     *        Auto-filled if missing: ts (IsoTime::now()).
     *        Other keys (actor, ip, user_agent, task_id, data, *_snapshot) pass through.
     */
    public function append(array $event): string
    {
        if (!isset($event['action']) || !is_string($event['action'])) {
            throw new InvalidArgumentException('event.action is required and must be a string');
        }
        if (!in_array($event['action'], self::ACTIONS, true)) {
            throw new InvalidArgumentException("unknown action: {$event['action']}");
        }

        if (!isset($event['ts']) || $event['ts'] === '') {
            $event['ts'] = IsoTime::now();
        } elseif (!is_string($event['ts'])) {
            throw new InvalidArgumentException('event.ts must be an ISO 8601 string');
        }

        $path = $this->pathForTs($event['ts']);
        $this->ensureLogDir();

        $line = json_encode(
            $event,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";

        $bytes = file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($bytes === false) {
            throw new RuntimeException("ndjson append failed: {$path}");
        }

        return $path;
    }

    /**
     * Resolve the NDJSON file path for a given UTC date string (Y-m-d).
     * Defaults to today (UTC) when omitted.
     */
    public function pathForDate(?string $utcDate = null): string
    {
        $date = $utcDate ?? IsoTime::nowDate();
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException("date must be YYYY-MM-DD: {$date}");
        }
        return $this->logDir . DIRECTORY_SEPARATOR . "changes-{$date}.ndjson";
    }

    private function pathForTs(string $ts): string
    {
        $dt = IsoTime::parse($ts);
        return $this->pathForDate($dt->format('Y-m-d'));
    }

    private function ensureLogDir(): void
    {
        if (is_dir($this->logDir)) {
            return;
        }
        if (!@mkdir($this->logDir, 0775, true) && !is_dir($this->logDir)) {
            throw new RuntimeException("cannot create log dir: {$this->logDir}");
        }
    }
}
