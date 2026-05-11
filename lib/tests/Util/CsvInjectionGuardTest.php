<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Util;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TaskTracker\Util\CsvInjectionGuard;

final class CsvInjectionGuardTest extends TestCase
{
    public function testEmptyStringPassesThroughUnchanged(): void
    {
        self::assertSame('', CsvInjectionGuard::escape(''));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sentinelProvider(): array
    {
        return [
            'equals'         => ['='],
            'plus'           => ['+'],
            'minus'          => ['-'],
            'at'             => ['@'],
            'tab'            => ["\t"],
            'carriage_return' => ["\r"],
        ];
    }

    #[DataProvider('sentinelProvider')]
    public function testSentinelLeadingCharGetsPrefixedWithSingleQuote(string $sentinel): void
    {
        $payload = $sentinel . 'malicious';
        self::assertSame("'" . $payload, CsvInjectionGuard::escape($payload));
    }

    #[DataProvider('sentinelProvider')]
    public function testBareSentinelByItselfGetsPrefixed(string $sentinel): void
    {
        self::assertSame("'" . $sentinel, CsvInjectionGuard::escape($sentinel));
    }

    public function testExcelDdeAttackPayloadIsNeutralized(): void
    {
        $attack = '=cmd|\'/c calc\'!A1';
        self::assertSame("'" . $attack, CsvInjectionGuard::escape($attack));
    }

    public function testHyperlinkFormulaAttackIsNeutralized(): void
    {
        $attack = '=HYPERLINK("http://evil.example/", "Click me")';
        self::assertSame("'" . $attack, CsvInjectionGuard::escape($attack));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function safeCellProvider(): array
    {
        return [
            'plain_text'        => ['hello world'],
            'starts_with_digit' => ['123 main st'],
            'starts_with_letter' => ['Task: ship it'],
            'starts_with_quote' => ['"already quoted"'],
            'starts_with_apostrophe' => ["'pre-escaped"],
            'starts_with_space' => [' leading space ok'],
            'contains_internal_equals' => ['x = y'],
            'contains_internal_plus'   => ['1 + 2'],
            'unicode'           => ['日本語タスク'],
        ];
    }

    #[DataProvider('safeCellProvider')]
    public function testSafeCellPassesThroughUnchanged(string $cell): void
    {
        self::assertSame($cell, CsvInjectionGuard::escape($cell));
    }

    public function testEscapeIsIdempotentOnAlreadyEscapedCell(): void
    {
        $once = CsvInjectionGuard::escape('=danger');
        $twice = CsvInjectionGuard::escape($once);

        self::assertSame($once, $twice, 'a cell already prefixed with apostrophe must not be re-prefixed');
    }

    public function testEscapeRowProcessesEveryCell(): void
    {
        $row = [
            'safe',
            '=evil',
            '+1',
            'plain',
            "\tinjection",
        ];

        $escaped = CsvInjectionGuard::escapeRow($row);

        self::assertSame(
            ['safe', "'=evil", "'+1", 'plain', "'\tinjection"],
            $escaped
        );
    }

    public function testEscapeRowPreservesStringKeys(): void
    {
        $row = ['title' => '=evil', 'note' => 'safe'];

        $escaped = CsvInjectionGuard::escapeRow($row);

        self::assertSame(['title' => "'=evil", 'note' => 'safe'], $escaped);
    }
}
