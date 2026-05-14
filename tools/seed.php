<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/vendor/autoload.php';

use TaskTracker\Models\RosterMember;
use TaskTracker\Models\SavedView;
use TaskTracker\Models\Task;
use TaskTracker\Models\Team;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\SavedViewRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Repositories\TeamRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

/**
 * Initialize a task-tracker data directory: write empty header-only CSV files for
 * every spec-defined storage table (spec §6 ─ tasks, roster, teams, dependencies,
 * tags, saved_views), then optionally add demo data so the admin UI has something
 * to render on first boot.
 *
 * Demo set (UI-10):
 *   - 2 teams: "Example Team" (engineering), "Product"
 *   - 4 roster members: 2 per team
 *   - 8 tasks: mix of statuses, priorities, parent/child relationships,
 *              dependencies, tags
 *   - 2 saved views
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
    $ctx    = ['actor' => 'seed.php'];

    // ── Teams ──────────────────────────────────────────────────────────────
    $teamRepo = new TeamRepository($csv, $events, $teamsPath);

    $team = $teamRepo->create(
        ['name' => 'Example Team', 'description' => 'Engineering — seed example.'],
        $ctx,
    );
    $teamProduct = $teamRepo->create(
        ['name' => 'Product', 'description' => 'Product & design — seed example.'],
        $ctx,
    );

    // ── Roster ─────────────────────────────────────────────────────────────
    $rosterRepo = new RosterRepository($csv, $events, $rosterPath);

    $member = $rosterRepo->add(
        ['name' => 'Example Member', 'email' => 'example@example.com', 'teamId' => $team->id],
        $ctx,
    );
    $alice = $rosterRepo->add(
        ['name' => 'Alice Chen', 'email' => 'alice@example.com', 'teamId' => $team->id],
        $ctx,
    );
    $bob = $rosterRepo->add(
        ['name' => 'Bob Smith', 'email' => 'bob@example.com', 'teamId' => $teamProduct->id],
        $ctx,
    );
    $carol = $rosterRepo->add(
        ['name' => 'Carol Davis', 'email' => 'carol@example.com', 'teamId' => $teamProduct->id],
        $ctx,
    );

    // ── Tasks ──────────────────────────────────────────────────────────────
    $tasksPath = $target . DIRECTORY_SEPARATOR . 'tasks.csv';
    $taskRepo  = new TaskRepository($csv, $events, $tasksPath);

    // 1. Done: CI pipeline (engineering)
    $taskCi = $taskRepo->create([
        'slug'        => 'ci-pipeline',
        'title'       => 'Set up CI pipeline',
        'body'        => 'Configure GitHub Actions with lint, test, and build steps.',
        'status'      => 'done',
        'priority'    => 'high',
        'effortHours' => 4.0,
        'assigneeId'  => $alice->id,
        'teamId'      => $team->id,
    ], $ctx);

    // 2. Done: Database schema (product)
    $taskDb = $taskRepo->create([
        'slug'        => 'db-schema',
        'title'       => 'Design database schema',
        'body'        => 'ERD for users, tasks, teams, dependencies, and audit log.',
        'status'      => 'done',
        'priority'    => 'high',
        'effortHours' => 6.0,
        'assigneeId'  => $bob->id,
        'teamId'      => $teamProduct->id,
    ], $ctx);

    // 3. In-progress: Authentication (engineering) — critical
    $taskAuth = $taskRepo->create([
        'slug'        => 'auth',
        'title'       => 'Implement authentication',
        'body'        => 'JWT-based login, refresh tokens, and session management.',
        'status'      => 'in_progress',
        'priority'    => 'critical',
        'effortHours' => 12.0,
        'assigneeId'  => $member->id,
        'teamId'      => $team->id,
    ], $ctx);

    // 4. In-progress: REST API (engineering) — parent for subtasks 5 & 6
    $taskApi = $taskRepo->create([
        'slug'        => 'rest-api',
        'title'       => 'Build REST API',
        'body'        => 'CRUD endpoints for tasks, roster, teams, and saved views.',
        'status'      => 'in_progress',
        'priority'    => 'high',
        'effortHours' => 20.0,
        'assigneeId'  => $alice->id,
        'teamId'      => $team->id,
    ], $ctx);

    // 5. Open: User dashboard (product) — child of task 4
    $taskDashboard = $taskRepo->create([
        'slug'        => 'user-dashboard',
        'title'       => 'Create user dashboard',
        'body'        => 'Overview page showing assigned tasks, progress, and deadlines.',
        'status'      => 'open',
        'priority'    => 'med',
        'effortHours' => 8.0,
        'assigneeId'  => $carol->id,
        'teamId'      => $teamProduct->id,
        'parentId'    => $taskApi->id,
    ], $ctx);

    // 6. Open: API documentation (product) — child of task 4
    $taskDocs = $taskRepo->create([
        'slug'        => 'api-docs',
        'title'       => 'Write API documentation',
        'body'        => 'OpenAPI 3.1 spec with examples for every endpoint.',
        'status'      => 'open',
        'priority'    => 'low',
        'effortHours' => 5.0,
        'assigneeId'  => $bob->id,
        'teamId'      => $teamProduct->id,
        'parentId'    => $taskApi->id,
    ], $ctx);

    // 7. Blocked: Performance testing — depends on auth (3) and API (4)
    $taskPerf = $taskRepo->create([
        'slug'        => 'perf-testing',
        'title'       => 'Performance testing',
        'body'        => 'Load tests with k6; target p99 < 200ms under 500 RPS.',
        'status'      => 'blocked',
        'priority'    => 'high',
        'effortHours' => 10.0,
        'assigneeId'  => $member->id,
        'teamId'      => $team->id,
    ], $ctx);

    // 8. Open: Deploy to production — depends on perf testing (7)
    $taskDeploy = $taskRepo->create([
        'slug'        => 'deploy-prod',
        'title'       => 'Deploy to production',
        'body'        => 'Blue/green deploy via Kubernetes; smoke tests post-deploy.',
        'status'      => 'open',
        'priority'    => 'critical',
        'effortHours' => 3.0,
        'assigneeId'  => $alice->id,
        'teamId'      => $team->id,
    ], $ctx);

    // ── Tags ───────────────────────────────────────────────────────────────
    $tagsPath = $target . DIRECTORY_SEPARATOR . 'tags.csv';
    $tagRepo  = new TagRepository($csv, $events, $tagsPath);

    foreach (['auth', 'security'] as $tag) {
        $tagRepo->add($taskAuth->id, $tag, $ctx);
    }
    foreach (['api', 'backend'] as $tag) {
        $tagRepo->add($taskApi->id, $tag, $ctx);
    }
    foreach (['testing', 'performance'] as $tag) {
        $tagRepo->add($taskPerf->id, $tag, $ctx);
    }
    foreach (['deployment', 'critical'] as $tag) {
        $tagRepo->add($taskDeploy->id, $tag, $ctx);
    }

    // ── Dependencies ───────────────────────────────────────────────────────
    $depsPath = $target . DIRECTORY_SEPARATOR . 'dependencies.csv';
    $depsRepo = new DependencyRepository($csv, $events, $depsPath);

    // perf-testing blocked by auth and API
    $depsRepo->add($taskPerf->id,   $taskAuth->id, $ctx);
    $depsRepo->add($taskPerf->id,   $taskApi->id,  $ctx);
    // deploy blocked by perf-testing
    $depsRepo->add($taskDeploy->id, $taskPerf->id, $ctx);

    // ── Saved views ────────────────────────────────────────────────────────
    $viewsPath = $target . DIRECTORY_SEPARATOR . 'saved_views.csv';
    $viewRepo  = new SavedViewRepository($csv, $events, $viewsPath);

    $viewRepo->create([
        'name'   => 'Open & In Progress',
        'filter' => ['status' => 'open,in_progress'],
    ], $ctx);
    $viewRepo->create([
        'name'   => 'Critical Priority',
        'filter' => ['priority' => 'critical'],
    ], $ctx);

    return [
        'target'  => $target,
        'log_dir' => $logs,
        'files'   => $files,
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
