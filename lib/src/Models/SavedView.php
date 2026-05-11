<?php

declare(strict_types=1);

namespace TaskTracker\Models;

use InvalidArgumentException;
use JsonException;

/**
 * One row of saved_views.csv (spec §5). FILTER_JSON is decoded to an
 * associative array on read and re-encoded on write; absent or empty JSON
 * becomes the empty filter `[]` (which means "no filter on any axis").
 */
final class SavedView
{
    public const HEADERS = ['ID', 'NAME', 'CREATED_AT', 'FILTER_JSON'];

    /**
     * @param array<string, mixed> $filter
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $createdAt,
        public readonly array $filter,
    ) {
        if ($id === '') {
            throw new InvalidArgumentException('id must not be empty');
        }
        if ($name === '') {
            throw new InvalidArgumentException('name must not be empty');
        }
        if ($createdAt === '') {
            throw new InvalidArgumentException('createdAt must not be empty');
        }
    }

    /**
     * @param array<string, string> $row
     */
    public static function fromCsvRow(array $row): self
    {
        $json = $row['FILTER_JSON'] ?? '';
        if ($json === '') {
            $filter = [];
        } else {
            try {
                $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                throw new InvalidArgumentException('FILTER_JSON is not valid JSON: ' . $e->getMessage());
            }
            if (!is_array($decoded)) {
                throw new InvalidArgumentException('FILTER_JSON must decode to an object/array');
            }
            $filter = $decoded;
        }

        return new self(
            id: $row['ID'] ?? '',
            name: $row['NAME'] ?? '',
            createdAt: $row['CREATED_AT'] ?? '',
            filter: $filter,
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
            'CREATED_AT' => $this->createdAt,
            'FILTER_JSON' => json_encode(
                $this->filter,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ),
        ];
    }
}
