<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TaskTracker\Models\Enums;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;

/**
 * Admin tasks controller — POST /tasks (create), GET / (list), GET /tasks/{id} (read),
 * POST /tasks/{id} (update, ADMIN-03), DELETE /tasks/{id} (soft-delete, ADMIN-03).
 *
 * Twig templates land in ADMIN-09; until then list/show emit a minimal inline HTML
 * skeleton so that routing, container wiring, and the two-write audit can be
 * exercised end-to-end without pulling Twig in prematurely.
 *
 * Error responses follow spec §6: JSON body {error, message}. 422 is used for both
 * missing-required-field (InvalidArgumentException) and conflict (RuntimeException
 * — e.g. duplicate slug) because the spec only enumerates these two failure modes
 * for write endpoints and the distinction is carried in `error`. Repository
 * "not found" RuntimeExceptions map to 404.
 */
final class TasksController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly DependencyRepository $dependencies,
        private readonly TagRepository $tagsRepo,
    ) {
    }

    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tasks = $this->tasks->listLive();

        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
              .  '<title>Backlog</title></head><body>';
        $html .= '<h1>Backlog</h1>';
        $html .= '<table><thead><tr>'
              .  '<th>ID</th><th>Slug</th><th>Title</th><th>Status</th><th>Priority</th>'
              .  '</tr></thead><tbody>';
        foreach ($tasks as $task) {
            $html .= sprintf(
                '<tr data-id="%s"><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
                self::esc($task->id),
                self::esc($task->id),
                self::esc($task->slug),
                self::esc($task->title),
                self::esc($task->status),
                self::esc($task->priority),
            );
        }
        $html .= '</tbody></table></body></html>';

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param array<string, string> $args
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
        $task = $this->tasks->find($id);
        if ($task === null) {
            return $this->jsonError($response->withStatus(404), 'not_found', "task not found: {$id}");
        }

        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
              .  '<title>' . self::esc($task->title) . '</title></head><body>';
        $html .= '<article data-id="' . self::esc($task->id) . '">';
        $html .= '<h1>' . self::esc($task->title) . '</h1>';
        $html .= '<dl>';
        $html .= '<dt>Slug</dt><dd>'     . self::esc($task->slug)     . '</dd>';
        $html .= '<dt>Status</dt><dd>'   . self::esc($task->status)   . '</dd>';
        $html .= '<dt>Priority</dt><dd>' . self::esc($task->priority) . '</dd>';
        if ($task->body !== null) {
            $html .= '<dt>Body</dt><dd>' . self::esc($task->body) . '</dd>';
        }
        $html .= '</dl></article></body></html>';

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
     * POST /tasks/{id} — partial update. Form fields are sparse: only keys actually
     * present in the body are forwarded to the repository, so omitted fields keep
     * their current values (vs. create which defaults missing fields to null).
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
            // TaskRepository::update raises RuntimeException for both "not found" (a
            // race after our find() pre-check) and "slug not unique". The 404 vs 422
            // distinction is duck-typed off the message — see ADMIN-02 docblock.
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
     * DELETE /tasks/{id} — soft-delete (STATUS → `deleted`). Idempotent at the
     * repository layer; controller redirects to the backlog after either a fresh
     * delete or a no-op re-delete.
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
     * `assignee_id` must be present in the body (empty string or "null" → unassign).
     * Goes through TaskRepository::update so the FIRST_ASSIGNED_AT /
     * LAST_ASSIGNMENT_CHANGE_AT semantics and the task.assigned/reassigned/unassigned
     * event emission both happen inside the same transaction as the CSV write.
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

        $raw = $body['assignee_id'];
        // Treat the literal string "null" the same as PHP null so HTML forms (which
        // can't send a true null) can still express "unassign".
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
     * Validates against STATUSES_LIVE so `deleted` cannot be reached through the
     * status endpoint; soft-delete must go through DELETE /tasks/{id}. The
     * repository's COMPLETED_AT semantics fire when the target is `done`.
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
     * POST /tasks/{id}/dependencies — body: {prereq_id}. Adds edge "id requires prereq_id".
     *
     * Cycle prevention and duplicate-edge rejection happen inside
     * DependencyRepository::add under the CsvStore flock; both surface as 422.
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
     * DELETE /tasks/{id}/dependencies/{prereq_id} — idempotent edge removal.
     *
     * @param array<string, string> $args
     */
    public function removeDependency(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
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
     * POST /tasks/{id}/tags — body: {tag}. Tag is normalized to lower-kebab.
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
     * DELETE /tasks/{id}/tags/{tag} — idempotent detach.
     *
     * @param array<string, string> $args
     */
    public function removeTag(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (string) ($args['id'] ?? '');
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

    /**
     * Map spec snake_case form fields to TaskRepository's camelCase input shape.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeInput(array $body): array
    {
        return [
            'slug'        => $body['slug']         ?? null,
            'title'       => $body['title']        ?? null,
            'body'        => $body['body']         ?? null,
            'status'      => $body['status']       ?? null,
            'priority'    => $body['priority']     ?? null,
            'dueDate'     => $body['due_date']     ?? null,
            'effortHours' => $body['effort_hours'] ?? null,
            'url'         => $body['url']          ?? null,
            'parentId'    => $body['parent_id']    ?? null,
            'assigneeId'  => $body['assignee_id']  ?? null,
            'teamId'      => $body['team_id']      ?? null,
        ];
    }

    /**
     * Sparse counterpart to normalizeInput: only forwards keys actually present in
     * the form body. Required because TaskRepository::update interprets every key
     * in its $changes array as an explicit set — including null/empty.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function normalizeUpdateInput(array $body): array
    {
        $map = [
            'slug'         => 'slug',
            'title'        => 'title',
            'body'         => 'body',
            'status'       => 'status',
            'priority'     => 'priority',
            'due_date'     => 'dueDate',
            'effort_hours' => 'effortHours',
            'url'          => 'url',
            'parent_id'    => 'parentId',
            'assignee_id'  => 'assigneeId',
            'team_id'      => 'teamId',
        ];
        $out = [];
        foreach ($map as $formKey => $repoKey) {
            if (array_key_exists($formKey, $body)) {
                $out[$repoKey] = $body[$formKey];
            }
        }
        return $out;
    }

    private function jsonError(ResponseInterface $response, string $code, string $message): ResponseInterface
    {
        $payload = json_encode(['error' => $code, 'message' => $message], JSON_THROW_ON_ERROR);
        $response->getBody()->write($payload);
        return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
