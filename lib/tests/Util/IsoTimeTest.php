<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Util;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TaskTracker\Util\IsoTime;

final class IsoTimeTest extends TestCase
{
    private const TS_REGEX = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/';
    private const DATE_REGEX = '/^\d{4}-\d{2}-\d{2}$/';

    public function testNowMatchesMillisecondZSuffixedShape(): void
    {
        self::assertMatchesRegularExpression(self::TS_REGEX, IsoTime::now());
    }

    public function testNowDateMatchesIsoDateShape(): void
    {
        self::assertMatchesRegularExpression(self::DATE_REGEX, IsoTime::nowDate());
    }

    public function testNowIsAlwaysUtcRegardlessOfDefaultTimezone(): void
    {
        $originalDefault = date_default_timezone_get();
        try {
            date_default_timezone_set('America/Chicago');
            $value = IsoTime::now();
            self::assertStringEndsWith('Z', $value, 'must always end with Z (UTC), not a local offset');
        } finally {
            date_default_timezone_set($originalDefault);
        }
    }

    public function testFormatConvertsNonUtcToUtc(): void
    {
        // 2026-05-11T07:30:00.000-05:00  ==  2026-05-11T12:30:00.000Z
        $dt = new DateTimeImmutable('2026-05-11T07:30:00', new DateTimeZone('America/Chicago'));

        self::assertSame('2026-05-11T12:30:00.000Z', IsoTime::format($dt));
    }

    public function testFormatPreservesMillisecondPrecision(): void
    {
        $dt = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.uP',
            '2026-05-11T12:34:56.789000+00:00'
        );
        self::assertNotFalse($dt);

        self::assertSame('2026-05-11T12:34:56.789Z', IsoTime::format($dt));
    }

    public function testParseAcceptsZSuffixedTimestamp(): void
    {
        $dt = IsoTime::parse('2026-05-11T12:34:56.789Z');

        self::assertSame('UTC', $dt->getTimezone()->getName());
        self::assertSame('2026-05-11T12:34:56.789Z', IsoTime::format($dt));
    }

    public function testParseAcceptsOffsetSuffixedTimestamp(): void
    {
        $dt = IsoTime::parse('2026-05-11T07:34:56-05:00');

        self::assertSame('2026-05-11T12:34:56.000Z', IsoTime::format($dt));
    }

    public function testParseRejectsGarbageInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        IsoTime::parse('not a timestamp');
    }

    public function testRoundTripIsStable(): void
    {
        $original = '2026-05-11T12:34:56.789Z';
        $roundTripped = IsoTime::format(IsoTime::parse($original));

        self::assertSame($original, $roundTripped);
    }
}
