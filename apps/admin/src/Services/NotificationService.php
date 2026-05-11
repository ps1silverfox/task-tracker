<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Services;

use TaskTracker\Config\Env;
use TaskTracker\Mail\SmtpMailer;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\TaskRepository;

/**
 * Admin-side email notifications (spec §2 in-scope item #10).
 *
 * Three trigger paths:
 *   - notifyAssignment($taskId, $assigneeId) — fired from the assign controller
 *     after the CSV write committed. Sends a single email to the new assignee.
 *   - notifyDueSoon($withinDays = 3, $today = null) — scheduled scan; sends a
 *     reminder for every live task whose DUE_DATE falls in [today, today+N].
 *   - notifyOverdue($today = null) — scheduled scan; sends an escalation for
 *     every live, non-done task whose DUE_DATE is strictly before $today.
 *
 * "Live" here is STATUSES_LIVE minus `done` (a completed task is by definition
 * not overdue and doesn't need a reminder). Tasks whose assignee has no email,
 * is missing, or is deactivated are skipped silently — they'd be no-ops at the
 * SmtpMailer layer anyway but skipping early saves the round-trip and keeps
 * the returned recipient list meaningful.
 *
 * Date handling: Task::dueDate is the opaque `Y-m-d` string read straight off
 * the CSV. String comparison on `Y-m-d` is lexicographically correct, so we
 * stay in strings rather than DateTimeImmutable to avoid TZ drift.
 */
final class NotificationService
{
    public function __construct(
        private readonly TaskRepository $tasks,
        private readonly RosterRepository $roster,
        private readonly SmtpMailer $mailer,
        private readonly Env $env,
    ) {
    }

    /**
     * Send the "you've been assigned a task" email. Returns true if the email
     * was dispatched, false on any silent-skip path (task missing, assignee
     * missing/inactive/no email, SMTP disabled).
     */
    public function notifyAssignment(string $taskId, string $assigneeId): bool
    {
        if ($taskId === '' || $assigneeId === '') {
            return false;
        }
        $task = $this->tasks->find($taskId);
        if ($task === null) {
            return false;
        }
        $email = $this->resolveEmail($assigneeId);
        if ($email === null) {
            return false;
        }

        return $this->mailer->send(
            $email,
            sprintf('[task-tracker] Assigned: %s', $task->title),
            $this->renderAssignmentBody($task),
        );
    }

    /**
     * @return list<string> recipient emails actually dispatched to
     */
    public function notifyDueSoon(int $withinDays = 3, ?string $today = null): array
    {
        if ($withinDays < 0) {
            return [];
        }
        $today = $today ?? gmdate('Y-m-d');
        $cutoff = gmdate('Y-m-d', strtotime($today . ' UTC +' . $withinDays . ' days'));

        $sent = [];
        foreach ($this->tasks->listLive() as $task) {
            if ($task->dueDate === null || $task->status === Enums::STATUS_DONE) {
                continue;
            }
            if ($task->dueDate < $today || $task->dueDate > $cutoff) {
                continue;
            }
            $email = $task->assigneeId === null ? null : $this->resolveEmail($task->assigneeId);
            if ($email === null) {
                continue;
            }
            if ($this->mailer->send(
                $email,
                sprintf('[task-tracker] Due soon: %s', $task->title),
                $this->renderDueBody($task, 'is due'),
            )) {
                $sent[] = $email;
            }
        }
        return $sent;
    }

    /**
     * @return list<string> recipient emails actually dispatched to
     */
    public function notifyOverdue(?string $today = null): array
    {
        $today = $today ?? gmdate('Y-m-d');

        $sent = [];
        foreach ($this->tasks->listLive() as $task) {
            if ($task->dueDate === null || $task->status === Enums::STATUS_DONE) {
                continue;
            }
            if ($task->dueDate >= $today) {
                continue;
            }
            $email = $task->assigneeId === null ? null : $this->resolveEmail($task->assigneeId);
            if ($email === null) {
                continue;
            }
            if ($this->mailer->send(
                $email,
                sprintf('[task-tracker] Overdue: %s', $task->title),
                $this->renderDueBody($task, 'was due'),
            )) {
                $sent[] = $email;
            }
        }
        return $sent;
    }

    private function resolveEmail(string $rosterId): ?string
    {
        $member = $this->roster->find($rosterId);
        if ($member === null || !$member->active || $member->email === null || $member->email === '') {
            return null;
        }
        return $member->email;
    }

    private function renderAssignmentBody(Task $task): string
    {
        return sprintf(
            "You have been assigned a task.\n\nTitle:    %s\nPriority: %s\nStatus:   %s\nDue:      %s\n\n%s\n",
            $task->title,
            $task->priority,
            $task->status,
            $task->dueDate ?? '(no due date)',
            $this->taskUrl($task->id),
        );
    }

    private function renderDueBody(Task $task, string $verb): string
    {
        return sprintf(
            "Task %s on %s.\n\nTitle:    %s\nPriority: %s\nStatus:   %s\n\n%s\n",
            $verb,
            $task->dueDate ?? '(unknown)',
            $task->title,
            $task->priority,
            $task->status,
            $this->taskUrl($task->id),
        );
    }

    private function taskUrl(string $taskId): string
    {
        return rtrim($this->env->baseUrl, '/') . '/tasks/' . rawurlencode($taskId);
    }
}
