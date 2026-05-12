<?php

declare(strict_types=1);

namespace TaskTracker\Models;

use InvalidArgumentException;

final class Team
{
    public const HEADERS = ['ID', 'NAME', 'DESCRIPTION'];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $description,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }
        if ($name === '') {
            throw new InvalidArgumentException('name must not be empty');
        }
    }

    /**
     * @param array<string, string> $row
     */
    public static function fromCsvRow(array $row): self
    {
        return new self(
            id: $row['ID'] ?? '',
            name: $row['NAME'] ?? '',
            description: ($row['DESCRIPTION'] ?? '') === '' ? null : $row['DESCRIPTION'],
        );
    }

    /**
     * @return array<string, string>
     */
    public function toCsvRow(): array
    {
        return [
            'ID' => $this->id,
            'NAME' => $this->name,
            'DESCRIPTION' => $this->description ?? '',
        ];
    }
}
