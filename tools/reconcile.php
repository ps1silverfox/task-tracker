<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/vendor/autoload.php';

use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;
use TaskTracker\Util\IsoTime;

/**
 * Default minimum age (seconds) before an orphan `.tmp.*` file is eligible for
 * deletion. Spec §9 mandates 60s — long enough that no in-flight CsvStore::txn()
 * can race the cleanup (typical writes are <50ms), short enough to keep `data/`
 * tidy on the hourly schedule.
 */
const TT_RECONCILE_DEFAULT_TMP_AGE = 60;

/**
 * Boot-time / on-demand reconciliation for a task-tracker data directory.
 *
 * Implements the two recovery actions from spec §9 "Failure modes":
 *
 *   1. **Orphan tmp cleanup.** `CsvStore::txn()` writes
 *      `<csv>.tmp.<pid>.<rand>` then atomic-renames into place. A crash before
 *      the rename leaves the tmp file behind. We delete tmp siblings older than
 *      `$tmpAgeSeconds` (60s default — see TT_RECONCILE_DEFAULT_TMP_AGE).
 *
 *   2. **Drift detection.** If a CSV write succeeded but the NDJSON append
 *      failed (or the process died between them — spec §9 explicitly tolerates
 *      this since audit is corroborative, not authoritative), the audit trail
 *      is one event short. We compare the latest `tasks.UPDATED_AT` against the
 *      latest NDJSON `ts`; if CSV is strictly newer (or NDJSON is empty while
 *      tasks.csv has rows), we append exactly one `system.reconcile` event
 *      recording the gap.
 *
 * Re-entrant and idempotent: a second run on a quiet system reports zero work.
 *
 * @return array{
 *   target:string,
 *   log_dir:string,
 *   tmp_removed:list<string>,
 *   tmp_skipped:list<string>,
 *   drift_detected:bool,
 *   last_csv_update:?string,
 *   last_ndjson_ts:?string,
 *   reconcile_event_appended:bool,
 *   dry_run:bool
 * }
 */
function tt_reconcile(
    string $targetDir,
    ?string $logDir = null,
    int $tmpAgeSeconds = TT_RECONCILE_DEFAULT_TMP_AGE,
    bool $dryRun = false,
): array {
    $target = rtrim($targetDir, "/\\");
    if ($target === '') {
        throw new InvalidArgumentException('target must not be empty');
    }
    if ($tmpAgeSeconds < 0) {
        throw new InvalidArgumentException('tmp-age must be >= 0');
    }
    tt_reconcile_ensure_dir($target);

    $logs = $logDir === null || $logDir === ''
        ? $target . DIRECTORY_SEPARATOR . 'logs'
        : rtrim($logDir, "/\\");

    [$tmpRemoved, $tmpSkipped] = tt_reconcile_sweep_tmp($target, $tmpAgeSeconds, $dryRun);

    $lastCsvUpdate = tt_reconcile_latest_csv_update($target);
    $lastNdjsonTs  = tt_reconcile_latest_ndjson_ts($logs);

    // Strict-newer comparison: CSV ts ordering is lexicographic on ISO 8601 UTC,
    // which matches chronological order at millisecond precision (IsoTime::TS_FORMAT).
    $driftDetected = $lastCsvUpdate !== null
        && ($lastNdjsonTs === null || strcmp($lastCsvUpdate, $lastNdjsonTs) > 0);

    $reconcileAppended = false;
    if ($driftDetected && !$dryRun) {
        (new EventLog($logs))->append([
            'action' => 'system.reconcile',
            'ts'     => IsoTime::now(),
            'actor'  => 'reconcile.php',
            'data'   => [
                'last_csv_update' => $lastCsvUpdate,
                'last_ndjson_ts'  => $lastNdjsonTs,
                'tmp_removed'     => count($tmpRemoved),
            ],
        ]);
        $reconcileAppended = true;
    }

    return [
        'target'                   => $target,
        'log_dir'                  => $logs,
        'tmp_removed'              => $tmpRemoved,
        'tmp_skipped'              => $tmpSkipped,
        'drift_detected'           => $driftDetected,
        'last_csv_update'          => $lastCsvUpdate,
        'last_ndjson_ts'           => $lastNdjsonTs,
        'reconcile_event_appended' => $reconcileAppended,
        'dry_run'                  => $dryRun,
    ];
}

/**
 * @return array{0:list<string>, 1:list<string>} [removed, skipped]
 */
function tt_reconcile_sweep_tmp(string $target, int $tmpAgeSeconds, bool $dryRun): array
{
    $threshold = time() - $tmpAgeSeconds;
    $removed = [];
    $skipped = [];

    $candidates = glob($target . DIRECTORY_SEPARATOR . '*.tmp.*');
    if ($candidates === false) {
        return [[], []];
    }
    foreach ($candidates as $path) {
        if (!is_file($path)) {
            continue;
        }
        // CsvStore::txn and migrate.php both produce `<base>.tmp.<pid>.<8-hex>`.
        // Refuse to touch anything that doesn't match — protects against
        // accidentally clobbering an unrelated file someone dropped in data/.
        if (!preg_match('/\.tmp\.\d+\.[0-9a-f]+$/', basename($path))) {
            $skipped[] = $path;
            continue;
        }
        $mtime = @filemtime($path);
        if ($mtime === false || $mtime > $threshold) {
            $skipped[] = $path;
            continue;
        }
        if (!$dryRun && !@unlink($path)) {
            // Couldn't delete — record as skipped rather than fail the whole sweep.
            $skipped[] = $path;
            continue;
        }
        $removed[] = $path;
    }
    return [$removed, $skipped];
}

function tt_reconcile_latest_csv_update(string $target): ?string
{
    $tasksPath = $target . DIRECTORY_SEPARATOR . 'tasks.csv';
    if (!is_file($tasksPath)) {
        return null;
    }
    $latest = null;
    foreach ((new CsvStore())->readAll($tasksPath) as $row) {
        $ts = $row['UPDATED_AT'] ?? '';
        if ($ts === '') {
            continue;
        }
        if ($latest === null || strcmp($ts, $latest) > 0) {
            $latest = $ts;
        }
    }
    return $latest;
}

function tt_reconcile_latest_ndjson_ts(string $logs): ?string
{
    if (!is_dir($logs)) {
        return null;
    }
    $files = glob($logs . DIRECTORY_SEPARATOR . 'changes-*.ndjson');
    if ($files === false || $files === []) {
        return null;
    }
    sort($files);
    // Walk newest-first across files; within each file scan all lines to find
    // the max ts (append order is chronological, but a backfill could insert
    // an out-of-order line, and the cost is bounded by one day's writes).
    foreach (array_reverse($files) as $path) {
        $ts = tt_reconcile_max_ts_in_file($path);
        if ($ts !== null) {
            return $ts;
        }
    }
    return null;
}

function tt_reconcile_max_ts_in_file(string $path): ?string
{
    if (!is_file($path)) {
        return null;
    }
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return null;
    }
    try {
        $max = null;
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            if (!is_array($decoded)) {
                continue;
            }
            $ts = $decoded['ts'] ?? null;
            if (!is_string($ts) || $ts === '') {
                continue;
            }
            if ($max === null || strcmp($ts, $max) > 0) {
                $max = $ts;
            }
        }
        return $max;
    } finally {
        fclose($fh);
    }
}

function tt_reconcile_ensure_dir(string $path): void
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
    $opts = getopt('', ['target:', 'logs:', 'tmp-age:', 'dry-run', 'quiet', 'help']);
    if (isset($opts['help']) || !isset($opts['target'])) {
        fwrite(
            STDERR,
            "Usage: php tools/reconcile.php --target=<dir> [--logs=<dir>] [--tmp-age=60] [--dry-run] [--quiet]\n"
        );
        exit(isset($opts['help']) ? 0 : 2);
    }
    try {
        $result = tt_reconcile(
            (string) $opts['target'],
            isset($opts['logs']) ? (string) $opts['logs'] : null,
            isset($opts['tmp-age']) ? (int) $opts['tmp-age'] : TT_RECONCILE_DEFAULT_TMP_AGE,
            isset($opts['dry-run']),
        );
        if (!isset($opts['quiet'])) {
            $auditVerb = $result['reconcile_event_appended']
                ? 'appended'
                : ($result['dry_run'] && $result['drift_detected'] ? 'would-append' : 'none');
            printf(
                "reconcile: target=%s tmp_removed=%d tmp_skipped=%d drift=%s audit=%s%s\n",
                $result['target'],
                count($result['tmp_removed']),
                count($result['tmp_skipped']),
                $result['drift_detected'] ? 'yes' : 'no',
                $auditVerb,
                $result['dry_run'] ? ' (dry-run)' : '',
            );
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'reconcile: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
