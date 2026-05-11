<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../tools/rotate-logs.php';

#[CoversNothing]
final class RotateLogsTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-rotate-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->logDir)) {
            self::rrm($this->logDir);
        }
    }

    public function testEmptyLogDirIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \tt_rotate_logs('');
    }

    public function testNegativeThresholdIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \tt_rotate_logs($this->logDir, -1);
    }

    public function testInvalidReferenceDateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \tt_rotate_logs($this->logDir, 30, 'not-a-date');
    }

    public function testCalendarInvalidReferenceDateIsRejected(): void
    {
        // Pattern matches but the calendar value is impossible.
        $this->expectException(InvalidArgumentException::class);
        \tt_rotate_logs($this->logDir, 30, '2026-13-40');
    }

    public function testFreshDirectoryIsNoOp(): void
    {
        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertSame([], $result['rotated']);
        self::assertSame([], $result['skipped']);
        self::assertSame([], $result['errors']);
        self::assertSame('2026-04-11', $result['cutoff_date']);
        self::assertFalse($result['dry_run']);
        self::assertDirectoryExists($this->logDir);
    }

    public function testFileNewerThanThresholdIsPreserved(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $recent = $this->writeNdjsonForDate('2026-05-01', ['action' => 'task.created', 'task_id' => 't1']);

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertSame([], $result['rotated']);
        self::assertSame([$recent], $result['skipped']);
        self::assertFileExists($recent);
        self::assertFileDoesNotExist($recent . '.gz');
    }

    public function testFileOlderThanThresholdIsGzipped(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $old = $this->writeNdjsonForDate('2026-03-01', ['action' => 'task.created', 'task_id' => 't1']);
        $originalBytes = (string) file_get_contents($old);

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertSame([$old], $result['rotated']);
        self::assertSame([], $result['errors']);
        self::assertFileDoesNotExist($old, 'source must be removed after successful gzip');
        self::assertFileExists($old . '.gz');

        $restored = (string) gzdecode((string) file_get_contents($old . '.gz'));
        self::assertSame($originalBytes, $restored, 'gz must round-trip to the original bytes');
    }

    public function testCutoffDayItselfIsKept(): void
    {
        // cutoff = ref - threshold = 2026-04-11. Files dated exactly on cutoff
        // are inside the window (strict-older-than semantics).
        \tt_rotate_logs_ensure_dir($this->logDir);
        $cutoffDayFile = $this->writeNdjsonForDate('2026-04-11', ['action' => 'task.created', 'task_id' => 't1']);

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertSame([], $result['rotated']);
        self::assertSame([$cutoffDayFile], $result['skipped']);
        self::assertFileExists($cutoffDayFile);
    }

    public function testReRunIsIdempotent(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $this->writeNdjsonForDate('2026-03-01', ['action' => 'task.created', 'task_id' => 't1']);

        $first  = \tt_rotate_logs($this->logDir, 30, '2026-05-11');
        $second = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertCount(1, $first['rotated']);
        self::assertCount(0, $second['rotated'], 'second sweep must be a no-op');
        self::assertSame([], $second['skipped'], 'source is gone, so nothing remains to skip');
    }

    public function testExistingGzPreservesSource(): void
    {
        // Simulate a crash between rename and unlink: both source and .gz present.
        // Next sweep must NOT re-rotate (refuse to overwrite the archive).
        \tt_rotate_logs_ensure_dir($this->logDir);
        $src = $this->writeNdjsonForDate('2026-03-01', ['action' => 'task.created', 'task_id' => 't1']);
        file_put_contents($src . '.gz', gzencode((string) file_get_contents($src)));

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertSame([], $result['rotated']);
        self::assertSame([$src], $result['skipped']);
        self::assertFileExists($src, 'source must NOT be deleted when archive already exists');
        self::assertFileExists($src . '.gz');
    }

    public function testNonConformingFilenameIsSkipped(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $foreign = $this->logDir . DIRECTORY_SEPARATOR . 'changes-not-a-date.ndjson';
        file_put_contents($foreign, "{}\n");

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        self::assertSame([], $result['rotated']);
        self::assertSame([$foreign], $result['skipped']);
        self::assertFileExists($foreign);
    }

    public function testDryRunMakesNoFilesystemChanges(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $old = $this->writeNdjsonForDate('2026-03-01', ['action' => 'task.created', 'task_id' => 't1']);
        $originalBytes = (string) file_get_contents($old);

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11', dryRun: true);

        self::assertSame([$old], $result['rotated']);
        self::assertTrue($result['dry_run']);
        self::assertFileExists($old, 'dry-run must not delete the source');
        self::assertFileDoesNotExist($old . '.gz', 'dry-run must not create the archive');
        self::assertSame($originalBytes, (string) file_get_contents($old));
    }

    public function testZeroThresholdRotatesEverythingBeforeReferenceDay(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $yesterday = $this->writeNdjsonForDate('2026-05-10', ['action' => 'task.created']);
        $today     = $this->writeNdjsonForDate('2026-05-11', ['action' => 'task.created']);

        $result = \tt_rotate_logs($this->logDir, 0, '2026-05-11');

        self::assertSame([$yesterday], $result['rotated']);
        self::assertSame([$today], $result['skipped']);
        self::assertFileExists($today, 'today\'s active log must never be rotated');
    }

    public function testMixedAgesProduceCorrectSplit(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $old1 = $this->writeNdjsonForDate('2026-01-15', ['action' => 'task.created']);
        $old2 = $this->writeNdjsonForDate('2026-02-28', ['action' => 'task.updated']);
        $new1 = $this->writeNdjsonForDate('2026-05-01', ['action' => 'task.created']);
        $new2 = $this->writeNdjsonForDate('2026-05-10', ['action' => 'task.updated']);

        $result = \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        sort($result['rotated']);
        sort($result['skipped']);
        $expectedRotated = [$old1, $old2];
        $expectedSkipped = [$new1, $new2];
        sort($expectedRotated);
        sort($expectedSkipped);

        self::assertSame($expectedRotated, $result['rotated']);
        self::assertSame($expectedSkipped, $result['skipped']);
        self::assertFileExists($old1 . '.gz');
        self::assertFileExists($old2 . '.gz');
        self::assertFileDoesNotExist($old1);
        self::assertFileDoesNotExist($old2);
    }

    public function testGzRoundTripPreservesMultilineNdjson(): void
    {
        \tt_rotate_logs_ensure_dir($this->logDir);
        $path = $this->logDir . DIRECTORY_SEPARATOR . 'changes-2026-02-01.ndjson';
        $lines = '';
        for ($i = 0; $i < 50; $i++) {
            $lines .= json_encode([
                'ts' => sprintf('2026-02-01T%02d:00:00.000Z', $i % 24),
                'action' => 'task.created',
                'task_id' => "t{$i}",
                'data' => ['seq' => $i],
            ], JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $lines);

        \tt_rotate_logs($this->logDir, 30, '2026-05-11');

        $restored = (string) gzdecode((string) file_get_contents($path . '.gz'));
        self::assertSame($lines, $restored);
        self::assertCount(50, array_filter(explode("\n", $restored), fn ($l) => $l !== ''));
    }

    /**
     * @param array<string, mixed> $event
     */
    private function writeNdjsonForDate(string $date, array $event): string
    {
        $path = $this->logDir . DIRECTORY_SEPARATOR . "changes-{$date}.ndjson";
        $event += ['ts' => $date . 'T00:00:00.000Z'];
        file_put_contents($path, json_encode($event, JSON_THROW_ON_ERROR) . "\n");
        return $path;
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
