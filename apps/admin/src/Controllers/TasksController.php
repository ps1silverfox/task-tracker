<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Controllers;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use TaskTracker\Repositories\TaskRepository;

/**
 * Admin tasks controller (ADMIN-02) — POST /tasks (create), GET / (list), GET /tasks/{id} (read).
 *
 * Twig templates land in ADMIN-09; until then list/show emit a minimal inline HTML
 * skeleton so that routing, container wiring, and the two-write audit can be
 * exercised end-to-end without pulling Twig in prematurely.
 *
 * Error responses follow spec §6: JSON body {error, message}. 422 is used for both
 * missing-required-field (InvalidArgumentException) and conflict (RuntimeException
 * — e.g. duplicate slug) because the spec only enumerates these two failure modes
 * for write endpoints and the distinction is carried in `error`.
 */
final class TasksController
{
    public function __construct(
        private readonly TaskRepository $tasks,
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
