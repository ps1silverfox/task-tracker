<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../tools/seed.php';

#[CoversNothing]
final class SeedTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-seed-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            self::rrm($this->tmpDir);
        }
    }

    public function testSeedCreatesAllSixCsvFilesWithHeadersOnly(): void
    {
        $result = \tt_seed($this->tmpDir, withExamples: false);

        $expected = ['tasks.csv', 'roster.csv', 'teams.csv', 'dependencies.csv', 'tags.csv', 'saved_views.csv'];
        foreach ($expected as $file) {
            $path = $this->tmpDir . DIRECTORY_SEPARATOR . $file;
            self::assertFileExists($path, "{$file} should be seeded");
            $body = file_get_contents($path);
            self::assertIsString($body);
            // Header row only ⇒ exactly one CRLF-terminated line.
            self::assertSame(1, substr_count($body, "\r\n"), "{$file} should be header-only");
        }
        self::assertNull($result['examples']);
        self::assertCount(count($expected), $result['files']);
    }

    public function testSeedWithExamplesAddsTeamAndMember(): void
    {
        $result = \tt_seed($this->tmpDir, withExamples: true);

        self::assertNotNull($result['examples']);
        self::assertNotSame('', $result['examples']['team']);
        self::assertNotSame('', $result['examples']['member']);

        $teams = (string) file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'teams.csv');
        self::assertStringContainsString('Example Team', $teams);

        $roster = (string) file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'roster.csv');
        self::assertStringContainsString('Example Member', $roster);
        self::assertStringContainsString($result['examples']['team'], $roster, 'member.TEAM_ID should reference seeded team');
    }

    public function testSeedIsIdempotentForExamples(): void
    {
        $first  = \tt_seed($this->tmpDir, withExamples: true);
        $second = \tt_seed($this->tmpDir, withExamples: true);

        self::assertNotNull($first['examples']);
        self::assertNull($second['examples'], 'second seed must not duplicate example team/member');

        $teams = (string) file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'teams.csv');
        self::assertSame(1, substr_count($teams, 'Example Team'));
    }

    public function testSeedEmitsExampleEventsToLogsDir(): void
    {
        $result = \tt_seed($this->tmpDir, withExamples: true);
        $logFiles = glob($result['log_dir'] . DIRECTORY_SEPARATOR . 'changes-*.ndjson');
        self::assertIsArray($logFiles);
        self::assertNotEmpty($logFiles, 'example seeding must emit NDJSON events (two-write audit)');
    }

    public function testEmptyTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        \tt_seed('', withExamples: false);
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
