<?php

declare(strict_types=1);

use Slim\App;
use TaskTracker\Public\Controllers\BacklogController;
use TaskTracker\Public\Controllers\TaskDetailController;

return static function (App $app): void {
    // INVARIANT: only GET routes may ever be registered on this app.
    // Any POST/PUT/PATCH/DELETE route is a violation of bind-level
    // separation; the 405 invariant test (PUB-09) will fail the build.

    // PUB-02 backlog list with query-string filters.
    $app->get('/', [BacklogController::class, 'list']);

    // PUB-03 read-only task detail + activity feed.
    $app->get('/tasks/{id}', [TaskDetailController::class, 'show']);
};
