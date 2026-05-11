<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Models;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;

final class TaskTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function fullRow(): array
    {
        return [
            'ID' => '018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e21',
            'SLUG' => 'ship-the-thing',
            'TITLE' => 'Ship the thing',
            'BODY' => 'Body text with **markdown**',
            'STATUS' => 'open',
            'PRIORITY' => 'med',
            'DUE_DATE' => '2026-05-15',
            'EFFORT_HOURS' => '4.50',
            'URL' => 'https://example.test/x',
            'PARENT_ID' => '018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e22',
            'ASSIGNEE_ID' => '018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e23',
            'TEAM_ID' => '018f0a73-8b2e-7c5f-9d24-1e8b3a5f0e24',
            'CREATED_AT' => '2026-05-10T12:00:00.000Z',
            'UPDATED_AT' => '2026-05-11T08:30:00.000Z',
            'FIRST_ASSIGNED_AT' => '2026-05-10T13:00:00.000Z',
            'LAST_ASSIGNMENT_CHANGE_AT' => '2026-05-10T14:00:00.000Z',
            'COMPLETED_AT' => '',
        ];
    }

    public function testHeadersMatchSpecSection5Order(): void
    {
        self::assertSame(
            [
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
            ],
            Task::HEADERS,
        );
    }

    public function testFromCsvRowParsesScalarsAndCoercesEffort(): void
    {
        $t = Task::fromCsvRow(self::fullRow());

        self::assertSame('ship-the-thing', $t->slug);
        self::assertSame('Ship the thing', $t->title);
        self::assertSame('Body text with **markdown**', $t->body);
        self::assertSame('open', $t->status);
        self::assertSame('med', $t->priority);
        self::assertSame(4.5, $t->effortHours);
        self::assertSame('2026-05-15', $t->dueDate);
        self::assertNull($t->completedAt);
    }

    public function testToCsvRowIsExactInverseOfFromCsvRow(): void
    {
        $row = self::fullRow();

        self::assertSame($row, Task::fromCsvRow($row)->toCsvRow());
    }

    public function testNullableColumnsRoundTripAsEmptyStrings(): void
    {
        $row = self::fullRow();
        $row['BODY'] = '';
        $row['DUE_DATE'] = '';
        $row['EFFORT_HOURS'] = '';
        $row['URL'] = '';
        $row['PARENT_ID'] = '';
        $row['ASSIGNEE_ID'] = '';
        $row['TEAM_ID'] = '';
        $row['FIRST_ASSIGNED_AT'] = '';
        $row['LAST_ASSIGNMENT_CHANGE_AT'] = '';

        $t = Task::fromCsvRow($row);

        self::assertNull($t->body);
        self::assertNull($t->effortHours);
        self::assertNull($t->dueDate);
        self::assertNull($t->parentId);
        self::assertNull($t->assigneeId);
        self::assertNull($t->teamId);
        self::assertNull($t->firstAssignedAt);
        self::assertNull($t->lastAssignmentChangeAt);
        self::assertSame($row, $t->toCsvRow());
    }

    public function testEffortHoursFormatsToTwoDecimalPlaces(): void
    {
        $row = self::fullRow();
        $row['EFFORT_HOURS'] = '4';

        $t = Task::fromCsvRow($row);
        $serialized = $t->toCsvRow();

        self::assertSame(4.0, $t->effortHours);
        self::assertSame('4.00', $serialized['EFFORT_HOURS']);
    }

    public function testDeletedStatusIsAcceptedForSoftDelete(): void
    {
        $row = self::fullRow();
        $row['STATUS'] = Enums::STATUS_DELETED;

        $t = Task::fromCsvRow($row);

        self::assertSame('deleted', $t->status);
    }

    public function testInvalidStatusRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = self::fullRow();
        $row['STATUS'] = 'nope';
        Task::fromCsvRow($row);
    }

    public function testInvalidPriorityRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = self::fullRow();
        $row['PRIORITY'] = 'urgent';
        Task::fromCsvRow($row);
    }

    public function testEmptyTitleRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = self::fullRow();
        $row['TITLE'] = '';
        Task::fromCsvRow($row);
    }

    public function testEmptyIdRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = self::fullRow();
        $row['ID'] = '';
        Task::fromCsvRow($row);
    }

    public function testEmptySlugRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = self::fullRow();
        $row['SLUG'] = '';
        Task::fromCsvRow($row);
    }

    public function testEmptyCreatedAtRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $row = self::fullRow();
        $row['CREATED_AT'] = '';
        Task::fromCsvRow($row);
    }
}
