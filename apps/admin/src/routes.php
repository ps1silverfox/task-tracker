<?php

declare(strict_types=1);

use Slim\App;
use TaskTracker\Admin\Controllers\HealthController;
use TaskTracker\Admin\Controllers\RosterController;
use TaskTracker\Admin\Controllers\SavedViewsController;
use TaskTracker\Admin\Controllers\TasksController;
use TaskTracker\Admin\Controllers\TeamsController;

return static function (App $app): void {
    $app->get('/',              [TasksController::class, 'list']);
    $app->post('/tasks',        [TasksController::class, 'create']);
    // GET /tasks/new must be registered before GET /tasks/{id} so FastRoute
    // resolves the literal segment 'new' before the parameterised {id} pattern.
    $app->get('/tasks/new',     [TasksController::class, 'newForm']);
    $app->get('/tasks/{id}',    [TasksController::class, 'show']);
    $app->post('/tasks/{id}',   [TasksController::class, 'update']);
    $app->delete('/tasks/{id}', [TasksController::class, 'delete']);
    // HTML-form soft-delete: browsers cannot emit DELETE, so the edit form uses
    // formaction="/tasks/{id}/delete" with formmethod="post".
    $app->post('/tasks/{id}/delete', [TasksController::class, 'delete']);

    // Action routes (ADMIN-04). Registered after the catch-all /tasks/{id} pair
    // for readability — Slim's FastRoute dispatcher matches by segment count, so
    // these 3- and 4-segment patterns never collide with the 2-segment ones above.
    $app->post('/tasks/{id}/assign',                      [TasksController::class, 'assign']);
    $app->post('/tasks/{id}/status',                      [TasksController::class, 'changeStatus']);
    $app->post('/tasks/{id}/dependencies',                [TasksController::class, 'addDependency']);
    $app->delete('/tasks/{id}/dependencies/{prereq_id}',  [TasksController::class, 'removeDependency']);
    $app->post('/tasks/{id}/tags',                        [TasksController::class, 'addTag']);
    $app->delete('/tasks/{id}/tags/{tag}',                [TasksController::class, 'removeTag']);

    // ADMIN-05 roster routes.
    $app->get('/roster',              [RosterController::class, 'list']);
    $app->post('/roster',             [RosterController::class, 'create']);
    $app->post('/roster/{id}',        [RosterController::class, 'update']);
    $app->delete('/roster/{id}',      [RosterController::class, 'deactivate']);
    // HTML-form deactivate: browsers cannot emit DELETE from a plain <form>.
    $app->post('/roster/{id}/deactivate', [RosterController::class, 'deactivate']);

    // ADMIN-06 teams routes. No DELETE: closed event enum has no team.deleted.
    $app->get('/teams',               [TeamsController::class, 'list']);
    $app->post('/teams',              [TeamsController::class, 'create']);
    $app->post('/teams/{id}',         [TeamsController::class, 'update']);

    // ADMIN-07 saved-views routes. No update verb: closed event enum admits only
    // saved_view.created and saved_view.deleted (spec §381-382).
    $app->get('/saved-views',                    [SavedViewsController::class, 'list']);
    $app->post('/saved-views',                   [SavedViewsController::class, 'create']);
    $app->delete('/saved-views/{id}',            [SavedViewsController::class, 'delete']);
    // HTML-form delete alias (browsers cannot emit DELETE from a plain <form>).
    $app->post('/saved-views/{id}/delete',       [SavedViewsController::class, 'delete']);

    // ADMIN-08 liveness probe. The 404 handler for unmapped routes is installed
    // on the ErrorMiddleware in Bootstrap (not as a route) so it also catches
    // requests whose path doesn't match any registered pattern.
    $app->get('/health',              [HealthController::class, 'check']);
};
