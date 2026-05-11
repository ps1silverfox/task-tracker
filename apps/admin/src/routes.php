<?php

declare(strict_types=1);

use Slim\App;
use TaskTracker\Admin\Controllers\TasksController;

return static function (App $app): void {
    $app->get('/',           [TasksController::class, 'list']);
    $app->post('/tasks',     [TasksController::class, 'create']);
    $app->get('/tasks/{id}', [TasksController::class, 'show']);
};
