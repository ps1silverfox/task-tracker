<?php

declare(strict_types=1);

namespace TaskTracker\Models;

use InvalidArgumentException;

final class RosterMember
{
    public const HEADERS = ['ID', 'NAME', 'EMAIL', 'TEAM_ID', 'ACTIVE'];

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $email,
        public readonly ?string $teamId,
        public readonly bool $active,
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
        $rawActive = $row['ACTIVE'] ?? 'true';
        if ($rawActive !== 'true' && $rawActive !== 'false') {
            throw new InvalidArgumentException("ACTIVE must be 'true' or 'false', got: {$rawActive}");
        }

        return new self(
            id: $row['ID'] ?? '',
            name: $row['NAME'] ?? '',
            email: ($row['EMAIL'] ?? '') === '' ? null : $row['EMAIL'],
            teamId: ($row['TEAM_ID'] ?? '') === '' ? null : $row['TEAM_ID'],
            active: $rawActive === 'true',
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
            'EMAIL' => $this->email ?? '',
            'TEAM_ID' => $this->teamId ?? '',
            'ACTIVE' => $this->active ? 'true' : 'false',
        ];
    }
}
