<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../tools/migrate.php';

#[CoversNothing]
final class MigrateTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-migrate-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            self::rrm($this->tmpDir);
        }
    }

    public function testFreshDirectoryWritesCurrentSchemaVersion(): void
    {
        $result = \tt_migrate($this->tmpDir);

        self::assertNull($result['from']);
        self::assertSame(\TT_CURRENT_SCHEMA_VERSION, $result['to']);
        self::assertTrue($result['changed']);
        self::assertFalse($result['dry_run']);

        $path = $this->tmpDir . DIRECTORY_SEPARATOR . '_schema_version.txt';
        self::assertFileExists($path);
        self::assertSame((string) \TT_CURRENT_SCHEMA_VERSION, trim((string) file_get_contents($path)));
    }

    public function testCreatesNestedTargetDirIfMissing(): void
    {
        $nested = $this->tmpDir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b';
        \tt_migrate($nested);
        self::assertFileExists($nested . DIRECTORY_SEPARATOR . '_schema_version.txt');
    }

    public function testReRunIsIdempotent(): void
    {
        $first  = \tt_migrate($this->tmpDir);
        $second = \tt_migrate($this->tmpDir);

        self::assertTrue($first['changed']);
        self::assertFalse($second['changed'], 'second migrate on an up-to-date dir must be a no-op');
        self::assertSame(\TT_CURRENT_SCHEMA_VERSION, $second['from']);
        self::assertSame(\TT_CURRENT_SCHEMA_VERSION, $second['to']);
    }

    public function testDryRunDoesNotWriteVersionFile(): void
    {
        $result = \tt_migrate($this->tmpDir, dryRun: true);

        self::assertTrue($result['changed'], 'fresh dir would change');
        self::assertTrue($result['dry_run']);
        self::assertFileDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . '_schema_version.txt');
    }

    public function testRefusesToDowngradeFromNewerVersion(): void
    {
        \tt_migrate_ensure_dir($this->tmpDir);
        file_put_contents(
            $this->tmpDir . DIRECTORY_SEPARATOR . '_schema_version.txt',
            (string) (\TT_CURRENT_SCHEMA_VERSION + 999),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/refusing to downgrade/i');
        \tt_migrate($this->tmpDir);
    }

    public function testCorruptVersionFileIsRejected(): void
    {
        \tt_migrate_ensure_dir($this->tmpDir);
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . '_schema_version.txt', 'not-an-integer');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/corrupt/i');
        \tt_migrate($this->tmpDir);
    }

    public function testEmptyTargetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \tt_migrate('');
    }

    public function testAtomicWriteLeavesNoTmpDebris(): void
    {
        \tt_migrate($this->tmpDir);

        $debris = glob($this->tmpDir . DIRECTORY_SEPARATOR . '_schema_version.txt.tmp.*');
        self::assertIsArray($debris);
        self::assertSame([], $debris, 'atomic rename must leave no orphan .tmp files');
    }

    private static function rrm(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            throw new RuntimeException("cannot scan dir: {$dir}");
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path) && !is_link($path)) {
                self::rrm($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
