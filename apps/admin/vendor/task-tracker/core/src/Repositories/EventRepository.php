<?php

declare(strict_types=1);

namespace TaskTracker\Repositories;

use DateTimeImmutable;
use DateTimeZone;
use Generator;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use TaskTracker\Models\Event;

/**
 * Streaming reader over the daily-rotated NDJSON change log (spec §8).
 *
 * EventLog writes; EventRepository reads. Both share the
 * `changes-YYYY-MM-DD.ndjson` filename convention but are otherwise decoupled
 * so each can be tested in isolation.
 *
 * All methods stream line-by-line — no full-file load. This keeps the
 * executive-summary scan (AGG-02) bounded by the iterator consumer, not by
 * total log size. The per-task activity feed (PUB-03) sits on top of the same
 * streams.
 *
 * Chronological ordering: within one file, events are append-order. Across
 * files, sorting `glob()` results lexicographically by `YYYY-MM-DD` yields
 * chronological order (UTC dates).
 */
final class EventRepository
{
    public function __construct(private readonly string $logDir)
    {
        if ($logDir === '') {
            throw new InvalidArgumentException('logDir must not be empty');
        }
    }

    /**
     * Stream events from one UTC date's NDJSON file. Missing file → no yields.
     *
     * @return Generator<int, Event>
     */
    public function streamForDate(string $utcDate): Generator
    {
        $this->assertDate($utcDate);
        yield from $this->streamFile($this->pathForDate($utcDate));
    }

    /**
     * Stream events across an inclusive UTC date range, oldest-first.
     * Inverted range (`from > to`) yields nothing rather than throwing —
     * matches the executive-summary's tolerance for degenerate filters.
     *
     * @return Generator<int, Event>
     */
    public function streamRange(string $fromDate, string $toDate): Generator
    {
        foreach ($this->datesBetween($fromDate, $toDate) as $date) {
            yield from $this->streamForDate($date);
        }
    }

    /**
     * Stream every event in `logDir`, oldest-first, by walking matching files
     * in lexicographic order.
     *
     * @return Generator<int, Event>
     */
    public function streamAll(): Generator
    {
        foreach ($this->logFiles() as $path) {
            yield from $this->streamFile($path);
        }
    }

    /**
     * Per-task activity feed (spec §6, `GET /tasks/{id}`). Returns events
     * oldest-first. If `$limit` is set, returns the most recent `$limit`
     * (still oldest-first within the slice).
     *
     * @return list<Event>
     */
    public function listForTask(string $taskId, ?int $limit = null): array
    {
        if ($taskId === '') {
            return [];
        }
        if ($limit !== null && $limit < 0) {
            throw new InvalidArgumentException('limit must be >= 0');
        }

        $out = [];
        foreach ($this->streamAll() as $event) {
            if ($event->taskId === $taskId) {
                $out[] = $event;
            }
        }
        if ($limit !== null && count($out) > $limit) {
            $out = array_slice($out, -$limit);
        }
        return $out;
    }

    public function pathForDate(string $utcDate): string
    {
        $this->assertDate($utcDate);
        return $this->logDir . DIRECTORY_SEPARATOR . "changes-{$utcDate}.ndjson";
    }

    /**
     * @return Generator<int, Event>
     */
    private function streamFile(string $path): Generator
    {
        if (!is_file($path)) {
            return;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("cannot open ndjson: {$path}");
        }
        try {
            while (($line = fgets($fh)) !== false) {
                $line = rtrim($line, "\r\n");
                if ($line === '') {
                    continue;
                }
                try {
                    $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException $e) {
                    throw new RuntimeException(
                        "malformed ndjson line in {$path}: {$e->getMessage()}",
                        previous: $e,
                    );
                }
                if (!is_array($decoded)) {
                    continue;
                }
                yield Event::fromNdjsonArray($decoded);
            }
        } finally {
            fclose($fh);
        }
    }

    /** @return list<string> sorted ascending */
    private function logFiles(): array
    {
        if (!is_dir($this->logDir)) {
            return [];
        }
        $files = glob($this->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson');
        if ($files === false) {
            return [];
        }
        sort($files);
        return $files;
    }

    /** @return list<string> Y-m-d strings, inclusive */
    private function datesBetween(string $fromDate, string $toDate): array
    {
        $this->assertDate($fromDate);
        $this->assertDate($toDate);
        $from = new DateTimeImmutable($fromDate . 'T00:00:00', new DateTimeZone('UTC'));
        $to = new DateTimeImmutable($toDate . 'T00:00:00', new DateTimeZone('UTC'));
        if ($from > $to) {
            return [];
        }
        $out = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $out[] = $d->format('Y-m-d');
        }
        return $out;
    }

    private function assertDate(string $date): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new InvalidArgumentException("date must be YYYY-MM-DD: {$date}");
        }
    }
}
