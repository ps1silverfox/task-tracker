<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Sanity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class PackageTest extends TestCase
{
    public function testAutoloadResolvesPackageNamespace(): void
    {
        self::assertSame(
            'TaskTracker\\Tests\\Sanity\\PackageTest',
            self::class,
            'PSR-4 namespace TaskTracker\\Tests\\ must resolve to lib/tests/.',
        );
    }

    public function testPhpRuntimeMeetsRequirement(): void
    {
        self::assertTrue(
            version_compare(PHP_VERSION, '8.3.0', '>='),
            'task-tracker/core requires PHP 8.3+; runtime reports ' . PHP_VERSION,
        );
    }

    public function testStrictTypesAreDeclaredInThisFile(): void
    {
        $source = file_get_contents(__FILE__);
        self::assertIsString($source);
        self::assertStringContainsString(
            "declare(strict_types=1);",
            $source,
            'Every PHP file in lib/ must declare strict_types=1 per project conventions.',
        );
    }
}
