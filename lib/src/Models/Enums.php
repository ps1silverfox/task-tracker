<?php

declare(strict_types=1);

namespace TaskTracker\Models;

use InvalidArgumentException;

/**
 * Closed sets for STATUS and PRIORITY columns (spec §5).
 *
 * STATUSES has five values because `deleted` is a status (soft-delete), not a
 * separate flag. STATUSES_LIVE is the four-value subset callers want when
 * rendering the active backlog.
 */
final class Enums
{
    public const STATUS_OPEN = 'open';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_DONE = 'done';
    public const STATUS_DELETED = 'deleted';

    public const STATUSES = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_DONE,
        self::STATUS_DELETED,
    ];

    public const STATUSES_LIVE = [
        self::STATUS_OPEN,
        self::STATUS_IN_PROGRESS,
        self::STATUS_BLOCKED,
        self::STATUS_DONE,
    ];

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_MED = 'med';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_CRITICAL = 'critical';

    public const PRIORITIES = [
        self::PRIORITY_LOW,
        self::PRIORITY_MED,
        self::PRIORITY_HIGH,
        self::PRIORITY_CRITICAL,
    ];

    public const PRIORITY_DEFAULT = self::PRIORITY_MED;

    public static function requireStatus(string $value): string
    {
        if (!in_array($value, self::STATUSES, true)) {
            throw new InvalidArgumentException("invalid status: {$value}");
        }
        return $value;
    }

    public static function requirePriority(string $value): string
    {
        if (!in_array($value, self::PRIORITIES, true)) {
            throw new InvalidArgumentException("invalid priority: {$value}");
        }
        return $value;
    }
}
