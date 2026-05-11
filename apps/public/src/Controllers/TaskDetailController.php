<?php

declare(strict_types=1);

namespace TaskTracker\Public\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Exception\HttpNotFoundException;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Event;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\EventRepository;
use TaskTracker\Repositories\TaskRepository;

/**
 * Public read-only task detail page — GET /tasks/{id} (spec §6, PUB-03).
 *
 * Renders the task's current state plus its full activity feed reconstructed
 * from the NDJSON change log via EventRepository::listForTask(). Events are
 * shown chronologically oldest-first to match the natural "what happened, in
 * order" reading.
 *
 * Soft-deleted tasks (STATUS=deleted) return 404 — the public surface has no
 * use for tombstones, and exposing them would leak titles/bodies the admin
 * intentionally retired. The CSV row stays for admin/audit lookups.
 *
 * Invariant: this controller never mutates state. The public app routes file
 * registers only the GET binding (the 405 invariant test in PUB-09 enforces
 * the absence of any POST/PUT/PATCH/DELETE handler on this path).
 */
final class TaskDetailController
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly EventRepository $events,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $id = $args['id'] ?? '';
        $task = $this->tasks->find($id);
        if ($task === null || $task->status === Enums::STATUS_DELETED) {
            throw new HttpNotFoundException($request, "task not found: {$id}");
        }

        $feed = $this->events->listForTask($task->id);

        $response->getBody()->write($this->render($task, $feed));
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * @param list<Event> $feed
     */
    private function render(Task $task, array $feed): string
    {
        $html  = '<!doctype html><html lang="en"><head><meta charset="utf-8">';
        $html .= '<title>' . self::esc($task->title) . '</title></head><body>';
        $html .= '<h1 data-id="' . self::esc($task->id) . '">' . self::esc($task->title) . '</h1>';

        $html .= '<dl class="task-fields">';
        $html .= self::field('ID',         $task->id);
        $html .= self::field('Slug',       $task->slug);
        $html .= self::field('Status',     $task->status);
        $html .= self::field('Priority',   $task->priority);
        $html .= self::field('Due',        $task->dueDate ?? '');
        $html .= self::field('Effort',     $task->effortHours === null ? '' : (string) $task->effortHours);
        $html .= self::field('URL',        $task->url ?? '');
        $html .= self::field('Parent',     $task->parentId ?? '');
        $html .= self::field('Assignee',   $task->assigneeId ?? '');
        $html .= self::field('Team',       $task->teamId ?? '');
        $html .= self::field('Created',    $task->createdAt);
        $html .= self::field('Updated',    $task->updatedAt);
        $html .= self::field('Completed',  $task->completedAt ?? '');
        $html .= '</dl>';

        if ($task->body !== null && $task->body !== '') {
            $html .= '<section class="task-body"><h2>Body</h2><pre>'
                  .  self::esc($task->body)
                  .  '</pre></section>';
        }

        $html .= '<section class="activity-feed"><h2>Activity</h2>';
        if ($feed === []) {
            $html .= '<p class="empty">No activity recorded.</p>';
        } else {
            $html .= '<ol>';
            foreach ($feed as $event) {
                $html .= sprintf(
                    '<li data-action="%s" data-ts="%s"><time>%s</time> &mdash; <code>%s</code>%s</li>',
                    self::esc($event->action),
                    self::esc($event->ts),
                    self::esc($event->ts),
                    self::esc($event->action),
                    self::renderEventData($event->data),
                );
            }
            $html .= '</ol>';
        }
        $html .= '</section>';

        $html .= '</body></html>';
        return $html;
    }

    private static function field(string $label, string $value): string
    {
        return '<dt>' . self::esc($label) . '</dt>'
             . '<dd>' . self::esc($value) . '</dd>';
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function renderEventData(array $data): string
    {
        if ($data === []) {
            return '';
        }
        $parts = [];
        foreach ($data as $key => $value) {
            $parts[] = self::esc((string) $key) . '=' . self::esc(self::scalar($value));
        }
        return ' <span class="data">' . implode(' ', $parts) . '</span>';
    }

    private static function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
    }

    private static function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
