<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/vendor/autoload.php';

use TaskTracker\Util\IsoTime;

/**
 * Default retention window (days). NDJSON change-log files whose filename date
 * is strictly older than the reference date minus this threshold are gzipped
 * and the source removed. Per spec §8 "Append-only at runtime" — rotation is
 * an offline maintenance op, not part of the write path.
 */
const TT_ROTATE_LOGS_DEFAULT_THRESHOLD_DAYS = 30;

/**
 * Rotate (gzip) daily NDJSON change-log files older than a threshold.
 *
 * Implements the offline archival half of spec §8 daily rotation:
 *   - Eligibility key is the filename date (`changes-YYYY-MM-DD.ndjson`), not
 *     mtime. The filename is set by EventLog at append time and is therefore
 *     the authoritative rotation timestamp; mtime can drift via filesystem
 *     ops or backup tools.
 *   - Gzip target: `<source>.gz`. If the gz target already exists we skip the
 *     source (idempotency — a half-run sweep can be safely re-driven).
 *   - Atomic write: stream-gzip into `<gz>.tmp.<pid>.<hex>`, close both
 *     handles, `rename()` into place, then `unlink()` the source. Per
 *     AGENTS.md p:windows-rename-needs-sidecar-lock, we never hold the
 *     destination open across the rename. A crash before rename leaves the
 *     source untouched; a crash after rename but before unlink leaves a
 *     duplicate that the next sweep will catch via the "skip if gz exists"
 *     rule. Orphan `<gz>.tmp.<pid>.<hex>` files match reconcile.php's pattern
 *     and are cleaned by the next reconcile run.
 *
 * Re-entrant and idempotent.
 *
 * @param string      $logDir         Directory containing `changes-*.ndjson` files.
 * @param int         $thresholdDays  Files strictly older than this many days are rotated.
 * @param string|null $referenceDate  YYYY-MM-DD anchor for "today". Defaults to UTC today.
 *                                    Injectable for deterministic testing.
 * @param bool        $dryRun         When true, reports intended work without touching the filesystem.
 *
 * @return array{
 *   log_dir:string,
 *   threshold_days:int,
 *   reference_date:string,
 *   cutoff_date:string,
 *   rotated:list<string>,
 *   skipped:list<string>,
 *   errors:list<array{path:string, message:string}>,
 *   dry_run:bool
 * }
 */
function tt_rotate_logs(
    string $logDir,
    int $thresholdDays = TT_ROTATE_LOGS_DEFAULT_THRESHOLD_DAYS,
    ?string $referenceDate = null,
    bool $dryRun = false,
): array {
    $logs = rtrim($logDir, "/\\");
    if ($logs === '') {
        throw new InvalidArgumentException('logDir must not be empty');
    }
    if ($thresholdDays < 0) {
        throw new InvalidArgumentException('threshold-days must be >= 0');
    }

    $refDate = $referenceDate ?? IsoTime::nowDate();
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $refDate)) {
        throw new InvalidArgumentException("reference-date must be YYYY-MM-DD: {$refDate}");
    }
    // Resolve via DateTimeImmutable in UTC for the day subtraction. Calendar
    // arithmetic on strings is brittle around month/year boundaries.
    $refDt = \DateTimeImmutable::createFromFormat('!Y-m-d', $refDate, new \DateTimeZone('UTC'));
    if ($refDt === false || $refDt->format('Y-m-d') !== $refDate) {
        // createFromFormat is permissive with overflow (e.g. '2026-13-40' rolls
        // to '2027-02-09'); reject when the round-trip disagrees.
        throw new InvalidArgumentException("invalid reference-date calendar value: {$refDate}");
    }
    $cutoff = $refDt->modify("-{$thresholdDays} day")->format('Y-m-d');

    tt_rotate_logs_ensure_dir($logs);

    $rotated = [];
    $skipped = [];
    $errors  = [];

    $candidates = glob($logs . DIRECTORY_SEPARATOR . 'changes-*.ndjson');
    if ($candidates === false) {
        $candidates = [];
    }
    sort($candidates);

    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        $base = basename($path);
        if (!preg_match('/^changes-(\d{4}-\d{2}-\d{2})\.ndjson$/', $base, $m)) {
            // Non-conforming name — refuse to touch (defense-in-depth).
            $skipped[] = $path;
            continue;
        }
        $fileDate = $m[1];

        // strcmp on fixed-width YYYY-MM-DD = chronological compare. Files
        // strictly older than the cutoff get rotated; cutoff-day itself is
        // kept (treated as "within window").
        if (strcmp($fileDate, $cutoff) >= 0) {
            $skipped[] = $path;
            continue;
        }

        $gzPath = $path . '.gz';
        if (is_file($gzPath)) {
            // Prior sweep already produced the archive; treat as done.
            $skipped[] = $path;
            continue;
        }

        if ($dryRun) {
            $rotated[] = $path;
            continue;
        }

        try {
            tt_rotate_logs_gzip_atomic($path, $gzPath);
            $rotated[] = $path;
        } catch (\Throwable $e) {
            $errors[] = ['path' => $path, 'message' => $e->getMessage()];
        }
    }

    return [
        'log_dir'        => $logs,
        'threshold_days' => $thresholdDays,
        'reference_date' => $refDate,
        'cutoff_date'    => $cutoff,
        'rotated'        => $rotated,
        'skipped'        => $skipped,
        'errors'         => $errors,
        'dry_run'        => $dryRun,
    ];
}

/**
 * Stream-gzip $src to $gzTarget atomically, then delete $src.
 *
 * Handles closed before rename (Windows NTFS requirement). Crash safety:
 *   - Crash before rename: leftover `<gzTarget>.tmp.<pid>.<hex>` orphan;
 *     source intact; reconcile.php sweeps the tmp on its next run.
 *   - Crash between rename and unlink: gz + source both exist; next
 *     rotate-logs run skips the source (gz already present).
 */
function tt_rotate_logs_gzip_atomic(string $src, string $gzTarget): void
{
    $tmp = $gzTarget . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));

    $in = fopen($src, 'rb');
    if ($in === false) {
        throw new RuntimeException("cannot open source: {$src}");
    }
    $out = @gzopen($tmp, 'wb9');
    if ($out === false) {
        fclose($in);
        throw new RuntimeException("cannot open tmp gz: {$tmp}");
    }

    try {
        while (!feof($in)) {
            $chunk = fread($in, 65536);
            if ($chunk === false) {
                throw new RuntimeException("read error on {$src}");
            }
            if ($chunk === '') {
                continue;
            }
            if (gzwrite($out, $chunk) === 0 && $chunk !== '') {
                throw new RuntimeException("gzwrite failed for {$tmp}");
            }
        }
    } catch (\Throwable $e) {
        gzclose($out);
        fclose($in);
        @unlink($tmp);
        throw $e;
    }

    // Close both handles before rename — never hold an open handle across a
    // Windows rename (p:windows-rename-needs-sidecar-lock).
    gzclose($out);
    fclose($in);

    if (!@rename($tmp, $gzTarget)) {
        @unlink($tmp);
        throw new RuntimeException("cannot rename {$tmp} -> {$gzTarget}");
    }

    if (!@unlink($src)) {
        // Archive is canonical now; if the source delete fails (e.g. another
        // process holds it open) we'd rather succeed and let the next sweep
        // notice and either retry or report. But if it still exists after the
        // archive succeeded, it's a soft drift: surface it as an error so the
        // caller can decide.
        throw new RuntimeException("archived but cannot delete source: {$src}");
    }
}

function tt_rotate_logs_ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
        throw new RuntimeException("cannot create directory: {$path}");
    }
}

// Dual-mode dispatch (see AGENTS.md p:tools-dual-mode-cli).
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath((string) $_SERVER['argv'][0]) === __FILE__) {
    $opts = getopt('', ['target:', 'threshold-days:', 'reference-date:', 'dry-run', 'quiet', 'help']);
    if (isset($opts['help']) || !isset($opts['target'])) {
        fwrite(
            STDERR,
            "Usage: php tools/rotate-logs.php --target=<log-dir> [--threshold-days=30] [--reference-date=YYYY-MM-DD] [--dry-run] [--quiet]\n"
        );
        exit(isset($opts['help']) ? 0 : 2);
    }
    try {
        $result = tt_rotate_logs(
            (string) $opts['target'],
            isset($opts['threshold-days']) ? (int) $opts['threshold-days'] : TT_ROTATE_LOGS_DEFAULT_THRESHOLD_DAYS,
            isset($opts['reference-date']) ? (string) $opts['reference-date'] : null,
            isset($opts['dry-run']),
        );
        if (!isset($opts['quiet'])) {
            printf(
                "rotate-logs: target=%s cutoff=%s rotated=%d skipped=%d errors=%d%s\n",
                $result['log_dir'],
                $result['cutoff_date'],
                count($result['rotated']),
                count($result['skipped']),
                count($result['errors']),
                $result['dry_run'] ? ' (dry-run)' : '',
            );
        }
        // Exit non-zero only if there were unexpected errors — skipped/no-op is success.
        exit($result['errors'] === [] ? 0 : 1);
    } catch (Throwable $e) {
        fwrite(STDERR, 'rotate-logs: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
