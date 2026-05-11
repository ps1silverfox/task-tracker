<?php

declare(strict_types=1);

namespace TaskTracker\Models;

use InvalidArgumentException;
use TaskTracker\Storage\EventLog;

/**
 * One NDJSON line from a changes-YYYY-MM-DD.ndjson log (spec §8).
 *
 * The canonical closed action enum lives in EventLog::ACTIONS — Event re-uses
 * it rather than duplicating the list. assigneeIdSnapshot / teamIdSnapshot
 * preserve the at-event-time assignment so the executive summary's person /
 * team filter (spec §7) stays correct under later reassignments.
 */
final class Event
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public readonly string $ts,
        public readonly string $action,
        public readonly ?string $taskId,
        public readonly ?string $actor,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly array $data,
        public readonly ?string $assigneeIdSnapshot,
        public readonly ?string $teamIdSnapshot,
    ) {
        if ($ts === '') {
            throw new InvalidArgumentException('ts must not be empty');
        }
        if (!in_array($action, EventLog::ACTIONS, true)) {
            throw new InvalidArgumentException("invalid action: {$action}");
        }
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromNdjsonArray(array $line): self
    {
        return new self(
            ts: isset($line['ts']) ? (string) $line['ts'] : '',
            action: isset($line['action']) ? (string) $line['action'] : '',
            taskId: isset($line['task_id']) && $line['task_id'] !== '' ? (string) $line['task_id'] : null,
            actor: isset($line['actor']) && $line['actor'] !== '' ? (string) $line['actor'] : null,
            ip: isset($line['ip']) && $line['ip'] !== '' ? (string) $line['ip'] : null,
            userAgent: isset($line['user_agent']) && $line['user_agent'] !== '' ? (string) $line['user_agent'] : null,
            data: isset($line['data']) && is_array($line['data']) ? $line['data'] : [],
            assigneeIdSnapshot: isset($line['assignee_id_snapshot']) && $line['assignee_id_snapshot'] !== ''
                ? (string) $line['assignee_id_snapshot']
                : null,
            teamIdSnapshot: isset($line['team_id_snapshot']) && $line['team_id_snapshot'] !== ''
                ? (string) $line['team_id_snapshot']
                : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toNdjsonArray(): array
    {
        $out = [
            'ts' => $this->ts,
            'action' => $this->action,
        ];
        if ($this->actor !== null) {
            $out['actor'] = $this->actor;
        }
        if ($this->ip !== null) {
            $out['ip'] = $this->ip;
        }
        if ($this->userAgent !== null) {
            $out['user_agent'] = $this->userAgent;
        }
        if ($this->taskId !== null) {
            $out['task_id'] = $this->taskId;
        }
        $out['data'] = $this->data;
        if ($this->assigneeIdSnapshot !== null) {
            $out['assignee_id_snapshot'] = $this->assigneeIdSnapshot;
        }
        if ($this->teamIdSnapshot !== null) {
            $out['team_id_snapshot'] = $this->teamIdSnapshot;
        }
        return $out;
    }
}
