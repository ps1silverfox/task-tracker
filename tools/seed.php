<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/vendor/autoload.php';

use TaskTracker\Models\RosterMember;
use TaskTracker\Models\SavedView;
use TaskTracker\Models\Task;
use TaskTracker\Models\Team;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TeamRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

/**
 * Initialize a task-tracker data directory: write empty header-only CSV files for
 * every spec-defined storage table (spec §6 ─ tasks, roster, teams, dependencies,
 * tags, saved_views), then optionally add one example team + one example roster
 * member so the admin UI has something to render on first boot.
 *
 * Re-running is safe: each CSV is left alone if it already contains rows. Example
 * seeding is also skipped on re-run (gated on teams.csv or roster.csv being
 * non-empty). Pass --force on the CLI to wipe a file's body and re-emit headers.
 *
 * @return array{target:string, log_dir:string, files:list<string>, examples:array{team:string,member:string}|null}
 */
function tt_seed(string $targetDir, ?string $logDir = null, bool $withExamples = true, bool $force = false): array
{
    $target = rtrim($targetDir, "/\\");
    if ($target === '') {
        throw new InvalidArgumentException('target must not be empty');
    }
    tt_seed_ensure_dir($target);

    $logs = $logDir === null || $logDir === '' ? $target . DIRECTORY_SEPARATOR . 'logs' : rtrim($logDir, "/\\");
    tt_seed_ensure_dir($logs);

    // (table-kind => [filename, header-row]) — single source of truth for tools/migrate.php
    // and tools/reconcile.php. Keep in sync with the spec §6 ER overview.
    $schema = [
        ['tasks.csv',        Task::HEADERS],
        ['roster.csv',       RosterMember::HEADERS],
        ['teams.csv',        Team::HEADERS],
        ['dependencies.csv', DependencyRepository::HEADERS],
        ['tags.csv',         TagRepository::HEADERS],
        ['saved_views.csv',  SavedView::HEADERS],
    ];

    $csv = new CsvStore();
    $files = [];

    foreach ($schema as [$name, $headers]) {
        $path = $target . DIRECTORY_SEPARATOR . $name;
        $hasRows = $csv->readAll($path) !== [];
        if ($hasRows && !$force) {
            $files[] = $path;
            continue;
        }
        $csv->txn($path, $headers, static fn(): array => []);
        $files[] = $path;
    }

    if (!$withExamples) {
        return ['target' => $target, 'log_dir' => $logs, 'files' => $files, 'examples' => null];
    }

    $teamsPath  = $target . DIRECTORY_SEPARATOR . 'teams.csv';
    $rosterPath = $target . DIRECTORY_SEPARATOR . 'roster.csv';
    if ($csv->readAll($teamsPath) !== [] || $csv->readAll($rosterPath) !== []) {
        return ['target' => $target, 'log_dir' => $logs, 'files' => $files, 'examples' => null];
    }

    $events = new EventLog($logs);

    $team = (new TeamRepository($csv, $events, $teamsPath))->create(
        ['name' => 'Example Team', 'description' => 'Seed example — delete once real teams are entered.'],
        ['actor' => 'seed.php'],
    );

    $member = (new RosterRepository($csv, $events, $rosterPath))->add(
        ['name' => 'Example Member', 'email' => 'example@example.com', 'teamId' => $team->id],
        ['actor' => 'seed.php'],
    );

    return [
        'target' => $target,
        'log_dir' => $logs,
        'files' => $files,
        'examples' => ['team' => $team->id, 'member' => $member->id],
    ];
}

function tt_seed_ensure_dir(string $path): void
{
    if (is_dir($path)) {
        return;
    }
    if (!@mkdir($path, 0o775, true) && !is_dir($path)) {
        throw new RuntimeException("cannot create directory: {$path}");
    }
}

// Distinguish "invoked as script" from "require_once'd by a test". When phpunit
// loads this file, $argv[0] is the phpunit binary, not seed.php, so the CLI
// dispatch is skipped.
if (PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath((string) $_SERVER['argv'][0]) === __FILE__) {
    $opts = getopt('', ['target:', 'logs:', 'force', 'no-examples', 'help']);
    if (isset($opts['help']) || !isset($opts['target'])) {
        fwrite(STDERR, "Usage: php tools/seed.php --target=<dir> [--logs=<dir>] [--force] [--no-examples]\n");
        exit(isset($opts['help']) ? 0 : 2);
    }
    try {
        $result = tt_seed(
            (string) $opts['target'],
            isset($opts['logs']) ? (string) $opts['logs'] : null,
            !isset($opts['no-examples']),
            isset($opts['force']),
        );
        printf("seed: %d csv file(s) in %s\n", count($result['files']), $result['target']);
        printf("seed: log dir %s\n", $result['log_dir']);
        if ($result['examples'] !== null) {
            printf("seed: example team=%s member=%s\n", $result['examples']['team'], $result['examples']['member']);
        } else {
            echo "seed: examples skipped (existing data or --no-examples)\n";
        }
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'seed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}
