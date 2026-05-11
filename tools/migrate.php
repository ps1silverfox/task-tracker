<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/vendor/autoload.php';

/**
 * Schema version of this build. Spec §`_schema_version.txt`: v1.0 = 1. Bumped
 * whenever a backward-incompatible change is made to CSV columns or the closed
 * NDJSON action enum (spec §8). When bumped, add the corresponding from→to
 * migration callable to tt_migrations() below.
 */
const TT_CURRENT_SCHEMA_VERSION = 1;

/**
 * Idempotently advance a task-tracker data directory's `_schema_version.txt` to
 * TT_CURRENT_SCHEMA_VERSION. v1.0 establishes the baseline (no incremental
 * migration steps exist yet) — future versions plug callables into
 * tt_migrations() keyed by from-version.
 *
 * Downgrade is refused: if the file records a version newer than this build,
 * we throw rather than rewrite, because an older build cannot safely read data
 * shaped by a newer schema.
 *
 * @return array{target:string, from:int|null, to:int, changed:bool, dry_run:bool}
 */
function tt_migrate(string $targetDir, bool $dryRun = false): array
{
    $target = rtrim($targetDir, "/\\");
    if ($target === '') {
        throw new InvalidArgumentException('target must not be empty');
    }
    tt_migrate_ensure_dir($target);

    $versionFile = $target . DIRECTORY_SEPARATOR . '_schema_version.txt';
    $current = TT_CURRENT_SCHEMA_VERSION;

    $from = null;
    if (is_file($versionFile)) {
        $raw = trim((string) file_get_contents($versionFile));
        if (!preg_match('/^\d+$/', $raw)) {
            throw new RuntimeException("corrupt _schema_version.txt at {$versionFile}: '{$raw}' (expected a non-negative integer)");
        }
        $from = (int) $raw;
    }

    if ($from !== null && $from > $current) {
        throw new RuntimeException(
            "_schema_version.txt records version {$from}, but this build is at version {$current}. " .
            'Refusing to downgrade — running an older build against newer data is unsafe.'
        );
    }

    if ($from === $current) {
        return ['target' => $target, 'from' => $from, 'to' => $current, 'changed' => false, 'dry_run' => $dryRun];
    }

    if (!$dryRun) {
        $migrations = tt_migrations();
        $startFrom = $from ?? 0;
        for ($v = $startFrom; $v < $current; $v++) {
            if (isset($migrations[$v])) {
                $migrations[$v]($target);
            }
        }

        // Atomic write via tmp + rename (mirrors CsvStore::txn pattern, project-context §atomic-write).
        $tmp = $versionFile . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, (string) $current) === false) {
            throw new RuntimeException("cannot write {$tmp}");
        }
        if (!rename($tmp, $versionFile)) {
            @unlink($tmp);
            throw new RuntimeException("cannot rename {$tmp} -> {$versionFile}");
        }
    }

    return ['target' => $target, 'from' => $from, 'to' => $current, 'changed' => true, 'dry_run' => $dryRun];
}

/**
 * Map of incremental migrations: key = from-version, value = callable(string $target).
 * Empty at v1.0 — there is no pre-v1.0 data shape to migrate from. Future schema
 * bumps register their from→to transformer here.
 *
 * @return array<int, callable(string):void>
 */
function tt_migrations(): array
{
    return [];
}

function tt_migrate_ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
        throw new RuntimeException("cannot create directory: {$path}");
    }
}

// Distinguish "invoked as script" from "require_once'd by a test" — matches seed.php's guard.
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath((string) $_SERVER['argv'][0]) === __FILE__) {
    $opts = getopt('', ['target:', 'dry-run', 'help']);
    if (isset($opts['help']) || !isset($opts['target'])) {
        fwrite(STDERR, "Usage: php tools/migrate.php --target=<dir> [--dry-run]\n");
        exit(isset($opts['help']) ? 0 : 2);
    }
    try {
        $result = tt_migrate((string) $opts['target'], isset($opts['dry-run']));
        $verb = $result['dry_run']
            ? ($result['changed'] ? 'would migrate' : 'would no-op')
            : ($result['changed'] ? 'migrated' : 'no-op');
        printf(
            "migrate: %s %s: from=%s to=%d\n",
            $verb,
            $result['target'],
            $result['from'] === null ? 'fresh' : (string) $result['from'],
            $result['to'],
        );
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'migrate: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
