<?php

declare(strict_types=1);

namespace TaskTracker\Models;

use InvalidArgumentException;

/**
 * Domain model for one row of tasks.csv (spec §5).
 *
 * Nullable columns serialize as empty strings; fromCsvRow/toCsvRow are exact
 * inverses for any well-formed row. EFFORT_HOURS round-trips through two
 * decimal places of formatting; all timestamps stay as opaque ISO 8601 strings
 * to avoid lossy DateTime conversions inside the value object.
 */
final class Task
{
    public const HEADERS = [
        'ID',
        'SLUG',
        'TITLE',
        'BODY',
        'STATUS',
        'PRIORITY',
        'DUE_DATE',
        'EFFORT_HOURS',
        'URL',
        'PARENT_ID',
        'ASSIGNEE_ID',
        'TEAM_ID',
        'CREATED_AT',
        'UPDATED_AT',
        'FIRST_ASSIGNED_AT',
        'LAST_ASSIGNMENT_CHANGE_AT',
        'COMPLETED_AT',
    ];

    public function __construct(
        public readonly string $id,
        public readonly string $slug,
        public readonly string $title,
        public readonly ?string $body,
        public readonly string $status,
        public readonly string $priority,
        public readonly ?string $dueDate,
        public readonly ?float $effortHours,
        public readonly ?string $url,
        public readonly ?string $parentId,
        public readonly ?string $assigneeId,
        public readonly ?string $teamId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly ?string $firstAssignedAt,
        public readonly ?string $lastAssignmentChangeAt,
        public readonly ?string $completedAt,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }
        if ($slug === '') {
            throw new InvalidArgumentException('slug must not be empty');
        }
        if ($title === '') {
            throw new InvalidArgumentException('title must not be empty');
        }
        Enums::requireStatus($status);
        Enums::requirePriority($priority);
        if ($createdAt === '') {
            throw new InvalidArgumentException('createdAt must not be empty');
        }
        if ($updatedAt === '') {
            throw new InvalidArgumentException('updatedAt must not be empty');
        }
    }

    /**
     * @param array<string, string> $row
     */
    public static function fromCsvRow(array $row): self
    {
        $effortRaw = $row['EFFORT_HOURS'] ?? '';
        $effort = $effortRaw === '' ? null : (float) $effortRaw;

        return new self(
            id: $row['ID'] ?? '',
            slug: $row['SLUG'] ?? '',
            title: $row['TITLE'] ?? '',
            body: self::nullable($row['BODY'] ?? ''),
            status: $row['STATUS'] ?? '',
            priority: $row['PRIORITY'] ?? '',
            dueDate: self::nullable($row['DUE_DATE'] ?? ''),
            effortHours: $effort,
            url: self::nullable($row['URL'] ?? ''),
            parentId: self::nullable($row['PARENT_ID'] ?? ''),
            assigneeId: self::nullable($row['ASSIGNEE_ID'] ?? ''),
            teamId: self::nullable($row['TEAM_ID'] ?? ''),
            createdAt: $row['CREATED_AT'] ?? '',
            updatedAt: $row['UPDATED_AT'] ?? '',
            firstAssignedAt: self::nullable($row['FIRST_ASSIGNED_AT'] ?? ''),
            lastAssignmentChangeAt: self::nullable($row['LAST_ASSIGNMENT_CHANGE_AT'] ?? ''),
            completedAt: self::nullable($row['COMPLETED_AT'] ?? ''),
        );
    }

    /**
     * @return array<string, string>
     */
    public function toCsvRow(): array
    {
        return [
            'ID' => $this->id,
            'SLUG' => $this->slug,
            'TITLE' => $this->title,
            'BODY' => $this->body ?? '',
            'STATUS' => $this->status,
            'PRIORITY' => $this->priority,
            'DUE_DATE' => $this->dueDate ?? '',
            'EFFORT_HOURS' => $this->effortHours === null
                ? ''
                : number_format($this->effortHours, 2, '.', ''),
            'URL' => $this->url ?? '',
            'PARENT_ID' => $this->parentId ?? '',
            'ASSIGNEE_ID' => $this->assigneeId ?? '',
            'TEAM_ID' => $this->teamId ?? '',
            'CREATED_AT' => $this->createdAt,
            'UPDATED_AT' => $this->updatedAt,
            'FIRST_ASSIGNED_AT' => $this->firstAssignedAt ?? '',
            'LAST_ASSIGNMENT_CHANGE_AT' => $this->lastAssignmentChangeAt ?? '',
            'COMPLETED_AT' => $this->completedAt ?? '',
        ];
    }

    private static function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
