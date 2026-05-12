<?php

declare(strict_types=1);

namespace TaskTracker\Storage;

use InvalidArgumentException;
use RuntimeException;

/**
 * RFC 4180 CSV reader/writer with flock-guarded reads and atomic-rename writes.
 *
 * Format invariants:
 *   - CRLF line endings
 *   - Every field quoted (regardless of need)
 *   - UTF-8, no BOM emitted (a leading BOM is stripped on read)
 *   - Header row required; column names are caller-supplied
 *
 * Locking:
 *   - Locks are taken on a sidecar file ("<path>.lock"), not on the data file
 *     itself. Windows NTFS refuses to rename() over a file with any open handle,
 *     so the data file must not be held open during the rename step.
 *   - readAll() acquires LOCK_SH; blocks during a concurrent write.
 *   - txn()    acquires LOCK_EX; serialises against other writers and readers.
 *
 * Crash safety:
 *   - The new payload is written to "<path>.tmp.<pid>.<rand>" and rename()'d into
 *     place. On NTFS this is atomic for same-volume renames via MoveFileEx.
 *   - On any failure before rename, the target is untouched.
 *   - Orphan ".tmp.*" survivors of a crashed process are cleaned by tools/reconcile.php.
 */
final class CsvStore
{
    private const LINE_TERMINATOR = "\r\n";
    private const UTF8_BOM = "\xEF\xBB\xBF";

    /**
     * Read all rows under a shared lock. Returns rows as associative arrays
     * keyed by header. Returns [] for a missing file or a header-only file.
     *
     * @return list<array<string, string>>
     */
    public function readAll(string $csvPath): array
    {
        if (!is_file($csvPath)) {
            return [];
        }

        $lockFh = self::acquireLock($csvPath, LOCK_SH);
        try {
            if (!is_file($csvPath)) {
                return [];
            }

            $dataFh = fopen($csvPath, 'rb');
            if ($dataFh === false) {
                throw new RuntimeException("cannot open csv for read: {$csvPath}");
            }
            try {
                return self::parseStream($dataFh);
            } finally {
                fclose($dataFh);
            }
        } finally {
            self::releaseLock($lockFh);
        }
    }

    /**
     * Read-mutate-write under an exclusive lock. The file is created if missing.
     *
     * @param list<string> $headers Authoritative column order; also seeds a new/empty file.
     * @param callable(list<array<string, string>>): list<array<string, string>> $mutator
     *        Receives current rows, returns the new full row set. Throwing aborts the txn
     *        before any on-disk change.
     */
    public function txn(string $csvPath, array $headers, callable $mutator): void
    {
        if ($headers === []) {
            throw new InvalidArgumentException('headers must not be empty');
        }

        $lockFh = self::acquireLock($csvPath, LOCK_EX);
        try {
            $rows = [];
            if (is_file($csvPath)) {
                $dataFh = fopen($csvPath, 'rb');
                if ($dataFh === false) {
                    throw new RuntimeException("cannot open csv for read: {$csvPath}");
                }
                try {
                    $rows = self::parseStream($dataFh);
                } finally {
                    fclose($dataFh);
                }
            }

            $next = $mutator($rows);
            $payload = self::formatCsv($headers, $next);

            $tmp = $csvPath . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
            $bytes = file_put_contents($tmp, $payload);
            if ($bytes === false) {
                @unlink($tmp);
                throw new RuntimeException("cannot write tmp csv: {$tmp}");
            }

            if (!self::renameReplacing($tmp, $csvPath)) {
                @unlink($tmp);
                throw new RuntimeException("rename failed: {$tmp} -> {$csvPath}");
            }
        } finally {
            self::releaseLock($lockFh);
        }
    }

    /**
     * @return resource
     */
    private static function acquireLock(string $csvPath, int $mode)
    {
        $lockPath = $csvPath . '.lock';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new RuntimeException("cannot open lock file: {$lockPath}");
        }
        if (!flock($fh, $mode)) {
            fclose($fh);
            throw new RuntimeException("lock acquisition failed: {$lockPath}");
        }
        return $fh;
    }

    /**
     * @param resource $fh
     */
    private static function releaseLock($fh): void
    {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    private static function renameReplacing(string $from, string $to): bool
    {
        // First attempt: pure atomic rename (works when target is closed).
        if (@rename($from, $to)) {
            return true;
        }
        // Windows fallback: if target still exists (e.g. ACL/AV interference),
        // unlink-then-rename. Still serialised by our exclusive lock, so no
        // concurrent reader/writer can observe an intermediate empty state on
        // the data path; readers wait on the same lockfile.
        if (is_file($to) && @unlink($to)) {
            return (bool) @rename($from, $to);
        }
        return false;
    }

    /**
     * @param resource $fh
     * @return list<array<string, string>>
     */
    private static function parseStream($fh): array
    {
        // Strip a leading UTF-8 BOM if present, before fgetcsv sees it. fgetcsv
        // treats a non-quote first byte as the start of an unquoted field, which
        // would otherwise contaminate the first header cell with the BOM bytes.
        $first = fread($fh, 3);
        if ($first !== self::UTF8_BOM) {
            rewind($fh);
        }

        $headers = null;
        $rows = [];

        while (($cells = fgetcsv($fh, escape: '')) !== false) {
            if ($cells === [null]) {
                continue;
            }
            if ($headers === null) {
                $headers = array_map(static fn($c) => (string) $c, $cells);
                continue;
            }
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = (string) ($cells[$i] ?? '');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<string> $headers
     * @param list<array<string, string>> $rows
     */
    private static function formatCsv(array $headers, array $rows): string
    {
        $out = self::formatRow($headers);
        foreach ($rows as $row) {
            $cells = [];
            foreach ($headers as $h) {
                $cells[] = (string) ($row[$h] ?? '');
            }
            $out .= self::formatRow($cells);
        }
        return $out;
    }

    /**
     * @param list<string> $cells
     */
    private static function formatRow(array $cells): string
    {
        $parts = [];
        foreach ($cells as $cell) {
            $parts[] = '"' . str_replace('"', '""', $cell) . '"';
        }
        return implode(',', $parts) . self::LINE_TERMINATOR;
    }
}
