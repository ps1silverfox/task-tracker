<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Sanity;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Factory\AppFactory;

#[CoversNothing]
final class BootTest extends TestCase
{
    public function testAutoloadResolvesPublicNamespace(): void
    {
        self::assertSame(
            'TaskTracker\\Public\\Tests\\Sanity\\BootTest',
            self::class,
            'PSR-4 namespace TaskTracker\\Public\\Tests\\ must resolve to apps/public/tests/.',
        );
    }

    public function testStrictTypesAreDeclaredInThisFile(): void
    {
        $source = file_get_contents(__FILE__);
        self::assertIsString($source);
        self::assertStringContainsString(
            'declare(strict_types=1);',
            $source,
            'Every PHP file in apps/public must declare strict_types=1 per project conventions.',
        );
    }

    public function testRoutesLoaderIsCallable(): void
    {
        $loader = require __DIR__ . '/../../src/routes.php';
        self::assertIsCallable(
            $loader,
            'src/routes.php must return a callable that mutates a Slim\\App.',
        );
    }

    public function testSlimAppBootsAndAcceptsRouteLoader(): void
    {
        $app = AppFactory::create();
        self::assertInstanceOf(App::class, $app);

        $loader = require __DIR__ . '/../../src/routes.php';
        $loader($app);

        self::assertInstanceOf(
            App::class,
            $app,
            'Route loader must accept Slim\\App without throwing.',
        );
    }

    public function testCorePathRepositoryIsResolved(): void
    {
        $installedJson = __DIR__ . '/../../vendor/composer/installed.json';
        self::assertFileExists($installedJson);

        $manifest = json_decode((string) file_get_contents($installedJson), true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($manifest);

        $names = array_column($manifest['packages'] ?? [], 'name');
        self::assertContains(
            'task-tracker/core',
            $names,
            'apps/public must resolve task-tracker/core via the ../../lib path repository.',
        );
    }

    public function testNoWriteControllerNamespaceExists(): void
    {
        $controllersDir = __DIR__ . '/../../src/Controllers';
        if (!is_dir($controllersDir)) {
            self::assertTrue(true, 'No Controllers directory yet — scaffold phase.');

            return;
        }

        $files = glob($controllersDir . '/*.php') ?: [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            self::assertDoesNotMatchRegularExpression(
                '/\$app->(post|put|patch|delete|map)\s*\(/i',
                $source,
                sprintf(
                    'Public app must not register write routes (file: %s). Bind-level separation violated.',
                    basename($file),
                ),
            );
        }
    }
}
