<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Util;

use PHPUnit\Framework\TestCase;
use TaskTracker\Util\Uuid7;

final class Uuid7Test extends TestCase
{
    private const UUID_REGEX = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function testReturnsCanonicalLowercaseUuidString(): void
    {
        $uuid = Uuid7::generate();

        self::assertMatchesRegularExpression(self::UUID_REGEX, $uuid);
    }

    public function testVersionNibbleIsSeven(): void
    {
        $uuid = Uuid7::generate();

        self::assertSame('7', $uuid[14], 'version nibble (char index 14) must be 7');
    }

    public function testVariantNibbleIsRfc4122(): void
    {
        $uuid = Uuid7::generate();

        self::assertContains($uuid[19], ['8', '9', 'a', 'b'], 'variant nibble (char index 19) must be 8/9/a/b');
    }

    public function testEachCallReturnsAUniqueValue(): void
    {
        $values = [];
        for ($i = 0; $i < 1000; $i++) {
            $values[Uuid7::generate()] = true;
        }

        self::assertCount(1000, $values);
    }

    public function testLaterUuidSortsLexicographicallyAfterEarlierUuid(): void
    {
        $earlier = Uuid7::generate();
        usleep(2_000); // > 1 ms — guarantees the embedded ms timestamp advances
        $later = Uuid7::generate();

        self::assertLessThan(0, strcmp($earlier, $later), 'v7 must be time-ordered: later > earlier under strcmp');
    }
}
