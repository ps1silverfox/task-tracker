<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Repositories\TeamRepository;
use Twig\Environment;

/**
 * Admin tasks controller — full CRUD (ADMIN-02, ADMIN-03, ADMIN-04, UI-01, UI-02, UI-03).
 *
 * GET /         → backlog list with optional filter bar (UI-03)
 * GET /tasks/new    → blank create form
 * GET /tasks/{id}   → edit form pre-populated with current task state
 * POST /tasks        → create (PRG → /tasks/{id})
 * POST /tasks/{id}  → sparse update (PRG → /tasks/{id})
 * DELETE /tasks/{id} → soft-delete (PRG → /)
 * POST /tasks/{id}/delete → soft-delete via HTML form (PRG → /)
 * POST /tasks/{id}/assign       → assign/unassign (PRG → /tasks/{id})
 * POST /tasks/{id}/status       → change status (PRG → /tasks/{id})
 * POST /tasks/{id}/dependencies → add prereq edge (PRG → /tasks/{id})
 * DELETE /tasks/{id}/dependencies/{prereq_id} → remove edge (PRG → /tasks/{id})
 * POST /tasks/{id}/tags         → add tag (PRG → /tasks/{id})
 * DELETE /tasks/{id}/tags/{tag} → remove tag (PRG → /tasks/{id})
 */
final class TasksController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly DependencyRepository $dependencies,
        private readonly TagRepository $tagsRepo,
        private readonly RosterRepository $roster,
        private readonly TeamRepository $teams,
        private readonly Environment $twig,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params  = $request->getQueryParams();
        $allLive = $this->tasks->listLive();
        $tasks   = $this->applyFilter($allLive, $params);

        $assigneeMap = [];
        foreach ($this->roster->listAll() as $m) {
            $assigneeMap[$m->id] = $m->name;
        }
        $teamMap = [];
        $teams   = $this->teams->listAll();
        foreach ($teams as $t) {
            $teamMap[$t->id] = $t->name;
        }

        $html = $this->twig->render('backlog.twig', [
            'tasks'         => $tasks,
            'assigneeMap'   => $assigneeMap,
            'teamMap'       => $teamMap,
            'activeFilters' => $params,
            'roster'        => $this->roster->listAll(),
            'teams'         => $teams,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function newForm(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $html = $this->twig->render('task_edit.twig', [
            'task'          => null,
            'statuses'      => Enums::STATUSES_LIVE,
            'priorities'    => Enums::PRIORITIES,
            'roster'        => $this->roster->listAll(),
            'teams'         => $this->teams->listAll(),
            'currentTags'   => [],
            'currentDeps'   => [],
            'parentOptions' => $this->tasks->listLive(),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = (string) ($args['id'] ?? '');
        $task = $this->tasks->find($id);
        if ($task === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $currentTags = $this->tagsRepo->tagsOf($id);
        $currentDeps = [];
        foreach ($this->dependencies->prereqsOf($id) as $prereqId) {
            $dep = $this->tasks->find($prereqId);
            if ($dep !== null) {
                $currentDeps[] = $dep;
            }
        }
        $parentOptions = array_values(array_filter(
            $this->tasks->listLive(),
            static fn(Task $t): bool => $t->id !== $id,
        ));

        $html = $this->twig->render('task_edit.twig', [
            'task'          => $task,
            'statuses'      => Enums::STATUSES_LIVE,
            'priorities'    => Enums::PRIORITIES,
            'roster'        => $this->roster->listAll(),
            'teams'         => $this->teams->listAll(),
            'currentTags'   => $currentTags,
            'currentDeps'   => $currentDeps,
            'parentOptions' => $parentOptions,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $task = $this->tasks->create(self::normalizeInput($body));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($task->id));
    }

    /**
     * POST /tasks/{id} — partial update (sparse: only present keys forwarded).
     *
     * @param array<string, string> $args
     */
    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            $body = [];
        }

        try {
            $task = $this->tasks->update($id, self::normalizeUpdateInput($body));
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return $this->jsonError($response->withStatus(404), 'not_found', $e->getMessage());
            }
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($task->id));
    }

    /**
     * DELETE /tasks/{id} and POST /tasks/{id}/delete — soft-delete.
     *
     * @param array<string, string> $args
     */
    public function delete(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        try {
            $this->tasks->softDelete($id);
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(404), 'not_found', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/');
    }

    /**
     * POST /tasks/{id}/assign — body: {assignee_id|null}.
     *
     * @param array<string, string> $args
     */
    public function assign(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('assignee_id', $body)) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'missing required field: assignee_id');
        }

        $raw      = $body['assignee_id'];
        $assignee = ($raw === null || $raw === '' || $raw === 'null') ? null : (string) $raw;

        try {
            $this->tasks->update($id, ['assigneeId' => $assignee]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(404), 'not_found', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($id));
    }

    /**
     * POST /tasks/{id}/status — body: {status}.
     *
     * @param array<string, string> $args
     */
    public function changeStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('status', $body)) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'missing required field: status');
        }

        $status = (string) $body['status'];
        if (!in_array($status, Enums::STATUSES_LIVE, true)) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', "invalid status: {$status}");
        }

        try {
            $this->tasks->update($id, ['status' => $status]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(404), 'not_found', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($id));
    }

    /**
     * POST /tasks/{id}/dependencies — body: {prereq_id}.
     *
     * @param array<string, string> $args
     */
    public function addDependency(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('prereq_id', $body)) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'missing required field: prereq_id');
        }
        $prereqId = (string) $body['prereq_id'];
        if ($prereqId === '') {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'prereq_id must not be empty');
        }

        try {
            $this->dependencies->add($id, $prereqId);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($id));
    }

    /**
     * DELETE /tasks/{id}/dependencies/{prereq_id}.
     *
     * @param array<string, string> $args
     */
    public function removeDependency(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id       = (string) ($args['id'] ?? '');
        $prereqId = (string) ($args['prereq_id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }
        if ($prereqId === '') {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'prereq_id must not be empty');
        }

        try {
            $this->dependencies->remove($id, $prereqId);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($id));
    }

    /**
     * POST /tasks/{id}/tags — body: {tag}.
     *
     * @param array<string, string> $args
     */
    public function addTag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $body = $request->getParsedBody();
        if (!is_array($body) || !array_key_exists('tag', $body)) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'missing required field: tag');
        }

        try {
            $this->tagsRepo->add($id, (string) $body['tag']);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->jsonError($response->withStatus(422), 'conflict', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($id));
    }

    /**
     * DELETE /tasks/{id}/tags/{tag}.
     *
     * @param array<string, string> $args
     */
    public function removeTag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id  = (string) ($args['id'] ?? '');
        $tag = (string) ($args['tag'] ?? '');
        if ($id === '' || $this->tasks->find($id) === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }
        if ($tag === '') {
            return $this->jsonError($response->withStatus(422), 'invalid_field', 'tag must not be empty');
        }

        try {
            $this->tagsRepo->remove($id, $tag);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response->withStatus(422), 'invalid_field', $e->getMessage());
        }

        return $response
            ->withStatus(303)
            ->withHeader('Location', '/tasks/' . rawurlencode($id));
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Apply simple query-string filters to the live task list.
     * Supports: status, priority, team_id, assignee_id, tags (comma-separated).
     *
     * @param list<Task>           $tasks
     * @param array<string, mixed> $params
     * @return list<Task>
     */
    private function applyFilter(array $tasks, array $params): array
    {
        $status   = self::csvParam($params, 'status');
        $priority = self::csvParam($params, 'priority');
        $teamId   = isset($params['team_id'])    && is_string($params['team_id'])    && $params['team_id']    !== '' ? $params['team_id']    : null;
        $assignee = isset($params['assignee_id']) && is_string($params['assignee_id']) && $params['assignee_id'] !== '' ? $params['assignee_id'] : null;
        $tags     = self::csvParam($params, 'tags');

        // Build tag → taskId set if tag filter active
        $taggedIds = null;
        if ($tags !== null) {
            $taggedIds = [];
            foreach ($this->tagsRepo->listAll() as $row) {
                if (in_array($row['tag'], $tags, true)) {
                    $taggedIds[$row['taskId']] = true;
                }
            }
        }

        $out = [];
        foreach ($tasks as $t) {
            if ($status !== null && !in_array($t->status, $status, true)) {
                continue;
            }
            if ($priority !== null && !in_array($t->priority, $priority, true)) {
                continue;
            }
            if ($teamId !== null && $t->teamId !== $teamId) {
                continue;
            }
            if ($assignee !== null && $t->assigneeId !== $assignee) {
                continue;
            }
            if ($taggedIds !== null && !isset($taggedIds[$t->id])) {
                continue;
            }
            $out[] = $t;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>|null
     */
    private static function csvParam(array $params, string $key): ?array
    {
        if (!array_key_exists($key, $params)) {
            return null;
        }
        $raw = $params[$key];
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $out = [];
        foreach (explode(',', $raw) as $piece) {
            $piece = trim($piece);
            if ($piece !== '') {
                $out[] = $piece;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $body): array
    {
        return [
            'slug'        => $body['slug'