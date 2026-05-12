<?php

declare(strict_types=1);

namespace TaskTracker\Util;

final class CsvInjectionGuard
{
    private const SENTINELS = ['=', '+', '-', '@', "\t", "\r"];

    public static function escape(string $cell): string
    {
        if ($cell === '') {
            return $cell;
        }

        return in_array($cell[0], self::SENTINELS, true)
            ? "'" . $cell
            : $cell;
    }

    /**
     * @param array<int|string, string> $row
     * @return array<int|string, string>
     */
    public static function escapeRow(array $row): array
    {
        return array_map(self::escape(...), $row);
    }
}
