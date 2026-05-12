<?php

declare(strict_types=1);

namespace TaskTracker\Util;

use Ramsey\Uuid\Uuid;

final class Uuid7
{
    public static function generate(): string
    {
        return Uuid::uuid7()->toString();
    }
}
