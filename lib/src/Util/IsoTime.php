<?php

declare(strict_types=1);

namespace TaskTracker\Util;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

final class IsoTime
{
    private const TS_FORMAT = 'Y-m-d\TH:i:s.v\Z';
    private const DATE_FORMAT = 'Y-m-d';

    public static function now(): string
    {
        return self::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    public static function nowDate(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(self::DATE_FORMAT);
    }

    public static function format(DateTimeInterface $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format(self::TS_FORMAT);
    }

    public static function parse(string $iso): DateTimeImmutable
    {
        $dt = DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $iso)
            ?: DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339, $iso);

        if ($dt === false) {
            try {
                $dt = new DateTimeImmutable($iso);
            } catch (\Exception) {
                throw new InvalidArgumentException("not a valid ISO 8601 timestamp: {$iso}");
            }
        }

        return $dt->setTimezone(new DateTimeZone('UTC'));
    }
}
