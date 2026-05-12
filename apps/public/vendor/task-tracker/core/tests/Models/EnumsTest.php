<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Models;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TaskTracker\Models\Enums;

final class EnumsTest extends TestCase
{
    public function testStatusesMatchSpecSection5(): void
    {
        self::assertSame(
            ['open', 'in_progress', 'blocked', 'done', 'deleted'],
            Enums::STATUSES,
        );
    }

    public function testStatusesLiveExcludesDeleted(): void
    {
        self::assertNotContains(Enums::STATUS_DELETED, Enums::STATUSES_LIVE);
        self::assertCount(4, Enums::STATUSES_LIVE);
    }

    public function testPrioritiesMatchSpecSection5(): void
    {
        self::assertSame(['low', 'med', 'high', 'critical'], Enums::PRIORITIES);
    }

    public function testPriorityDefaultIsMed(): void
    {
        self::assertSame('med', Enums::PRIORITY_DEFAULT);
    }

    #[DataProvider('validStatusProvider')]
    public function testRequireStatusReturnsValidValueUnchanged(string $status): void
    {
        self::assertSame($status, Enums::requireStatus($status));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function validStatusProvider(): iterable
    {
        return array_map(static fn(string $s) => [$s], Enums::STATUSES);
    }

    public function testRequireStatusRejectsUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Enums::requireStatus('done-deal');
    }

    public function testRequireStatusIsCaseSensitive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Enums::requireStatus('OPEN');
    }

    #[DataProvider('validPriorityProvider')]
    public function testRequirePriorityReturnsValidValueUnchanged(string $priority): void
    {
        self::assertSame($priority, Enums::requirePriority($priority));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function validPriorityProvider(): iterable
    {
        return array_map(static fn(string $p) => [$p], Enums::PRIORITIES);
    }

    public function testRequirePriorityRejectsUnknownValue(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Enums::requirePriority('urgent');
    }
}
