<?php

declare(strict_types=1);

use Slim\App;
use TaskTracker\Admin\Controllers\TasksController;

return static function (App $app): void {
    $app->get('/',              [TasksController::class, 'list']);
    $app->post('/tasks',        [TasksController::class, 'create']);
    $app->get('/tasks/{id}',    [TasksController::class, 'show']);
    $app->post('/tasks/{id}',   [TasksController::class, 'update']);
    $app->delete('/tasks/{id}', [TasksController::class, 'delete']);

    // Action routes (ADMIN-04). Registered after the catch-all /tasks/{id} pair
    // for readability — Slim's FastRoute dispatcher matches by segment count, so
    // these 3- and 4-segment patterns never collide with the 2-segment ones above.
    $app->post('/tasks/{id}/assign',                      [TasksController::class, 'assign']);
    $app->post('/tasks/{id}/status',                      [TasksController::class, 'changeStatus']);
    $app->post('/tasks/{id}/dependencies',                [TasksController::class, 'addDependency']);
    $app->delete('/tasks/{id}/dependencies/{prereq_id}',  [TasksController::class, 'removeDependency']);
    $app->post('/tasks/{id}/tags',                        [TasksController::class, 'addTag']);
    $app->delete('/tasks/{id}/tags/{tag}',                [TasksController::class, 'removeTag']);
};
