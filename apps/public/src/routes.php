<?php

declare(strict_types=1);

use Slim\App;
use TaskTracker\Public\Controllers\BacklogController;
use TaskTracker\Public\Controllers\ExecutiveSummaryController;
use TaskTracker\Public\Controllers\ExportController;
use TaskTracker\Public\Controllers\GraphController;
use TaskTracker\Public\Controllers\HealthController;
use TaskTracker\Public\Controllers\SavedViewsController;
use TaskTracker\Public\Controllers\TaskDetailController;

return static function (App $app): void {
    // INVARIANT: only GET routes may ever be registered on this app.
    // Any POST/PUT/PATCH/DELETE route is a violation of bind-level
    // separation; the 405 invariant test (PUB-09) will fail the build.

    // PUB-02 backlog list with query-string filters.
    $app->get('/', [BacklogController::class, 'list']);

    // PUB-03 read-only task detail + activity feed.
    $app->get('/tasks/{id}', [TaskDetailController::class, 'show']);

    // PUB-04 executive time-series summary (HTML + embedded Chart.js).
    $app->get('/summary', [ExecutiveSummaryController::class, 'show']);

    // PUB-05 CSV exports (filtered backlog + summary buckets) with injection guard.
    $app->get('/tasks.csv',   [ExportController::class, 'tasks']);
    $app->get('/summary.csv', [ExportController::class, 'summary']);

    // PUB-06 dependency visualizer page + JSON data endpoint.
    $app->get('/graph',      [GraphController::class, 'page']);
    $app->get('/graph.json', [GraphController::class, 'json']);

    // PUB-07 saved view application — 302 redirect to /?<filter-as-querystring>.
    $app->get('/views/{id}', [SavedViewsController::class, 'apply']);

    // PUB-08 liveness probe — GET only; smoke.ps1 and Apache monitoring poll this.
    $app->get('/health', [HealthController::class, 'check']);
};
