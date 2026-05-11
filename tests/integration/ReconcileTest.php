<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Integration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/../../lib/vendor/autoload.php';
require_once __DIR__ . '/../../tools/reconcile.php';

#[CoversNothing]
final class ReconcileTest extends TestCase
{
    private string $tmpDir;
    private string $logDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-reconcile-' . bin2hex(random_bytes(4));
        $this->tmpDir = $base;
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            self::rrm($this->tmpDir);
        }
    }

    public function testEmptyTargetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \tt_reconcile('');
    }

    public function testNegativeTmpAgeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        \tt_reconcile($this->tmpDir, null, -1);
    }

    public function testFreshDirectoryIsNoOp(): void
    {
        $result = \tt_reconcile($this->tmpDir);

        self::assertSame([], $result['tmp_removed']);
        self::assertSame([], $result['tmp_skipped']);
        self::assertFalse($result['drift_detected']);
        self::assertNull($result['last_csv_update']);
        self::assertNull($result['last_ndjson_ts']);
        self::assertFalse($result['reconcile_event_appended']);
    }

    public function testOldOrphanTmpFileIsRemoved(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        $orphan = $this->tmpDir . DIRECTORY_SEPARATOR . 'tasks.csv.tmp.12345.abc01234';
        file_put_contents($orphan, 'half-written');
        touch($orphan, time() - 300); // 5 min old

        $result = \tt_reconcile($this->tmpDir, tmpAgeSeconds: 60);

        self::assertSame([$orphan], $result['tmp_removed']);
        self::assertFileDoesNotExist($orphan);
    }

    public function testRecentTmpFileIsPreserved(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        $recent = $this->tmpDir . DIRECTORY_SEPARATOR . 'tasks.csv.tmp.99999.ff001122';
        file_put_contents($recent, 'in-progress');
        // mtime defaults to now; threshold is now-60s — file is too young.

        $result = \tt_reconcile($this->tmpDir, tmpAgeSeconds: 60);

        self::assertSame([], $result['tmp_removed']);
        self::assertSame([$recent], $result['tmp_skipped']);
        self::assertFileExists($recent);
    }

    public function testNonMatchingTmpPatternIsSkipped(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        // Looks tmp-ish but doesn't match `<base>.tmp.<pid>.<hex>` — refuse to touch.
        $foreign = $this->tmpDir . DIRECTORY_SEPARATOR . 'editor.swp.tmp.backup';
        file_put_contents($foreign, 'unrelated');
        touch($foreign, time() - 3600);

        $result = \tt_reconcile($this->tmpDir);

        self::assertSame([], $result['tmp_removed']);
        self::assertSame([$foreign], $result['tmp_skipped']);
        self::assertFileExists($foreign);
    }

    public function testDryRunDeletesNothing(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        $orphan = $this->tmpDir . DIRECTORY_SEPARATOR . 'roster.csv.tmp.1.deadbeef';
        file_put_contents($orphan, 'x');
        touch($orphan, time() - 300);

        $result = \tt_reconcile($this->tmpDir, dryRun: true);

        self::assertSame([$orphan], $result['tmp_removed']);
        self::assertFileExists($orphan, 'dry-run must not delete the orphan');
        self::assertTrue($result['dry_run']);
    }

    public function testCsvNewerThanNdjsonProducesReconcileEvent(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        \tt_reconcile_ensure_dir($this->logDir);

        // Seed tasks.csv with one row whose UPDATED_AT is "later" than the
        // newest NDJSON ts. Lexicographic ordering on fixed-width UTC strings.
        $this->writeTasksCsv([
            ['ID' => 't1', 'UPDATED_AT' => '2026-05-11T12:00:00.000Z'],
        ]);
        $this->appendNdjson('2026-05-11', [
            ['ts' => '2026-05-11T10:00:00.000Z', 'action' => 'task.created', 'task_id' => 't1', 'data' => []],
        ]);

        $result = \tt_reconcile($this->tmpDir);

        self::assertTrue($result['drift_detected']);
        self::assertSame('2026-05-11T12:00:00.000Z', $result['last_csv_update']);
        self::assertSame('2026-05-11T10:00:00.000Z', $result['last_ndjson_ts']);
        self::assertTrue($result['reconcile_event_appended']);

        $reconcileEvents = $this->readReconcileEvents();
        self::assertCount(1, $reconcileEvents);
        self::assertSame('system.reconcile', $reconcileEvents[0]['action']);
        self::assertSame('reconcile.php', $reconcileEvents[0]['actor']);
        self::assertSame('2026-05-11T12:00:00.000Z', $reconcileEvents[0]['data']['last_csv_update']);
        self::assertSame('2026-05-11T10:00:00.000Z', $reconcileEvents[0]['data']['last_ndjson_ts']);
    }

    public function testCsvAndNdjsonInSyncIsNoOp(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        \tt_reconcile_ensure_dir($this->logDir);

        $ts = '2026-05-11T09:30:00.000Z';
        $this->writeTasksCsv([['ID' => 't1', 'UPDATED_AT' => $ts]]);
        $this->appendNdjson('2026-05-11', [
            ['ts' => $ts, 'action' => 'task.created', 'task_id' => 't1', 'data' => []],
        ]);

        $result = \tt_reconcile($this->tmpDir);

        self::assertFalse($result['drift_detected']);
        self::assertFalse($result['reconcile_event_appended']);
        self::assertSame([], $this->readReconcileEvents());
    }

    public function testDryRunWithDriftDoesNotAppendEvent(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        \tt_reconcile_ensure_dir($this->logDir);

        $this->writeTasksCsv([['ID' => 't1', 'UPDATED_AT' => '2026-05-11T12:00:00.000Z']]);
        $this->appendNdjson('2026-05-10', [
            ['ts' => '2026-05-10T08:00:00.000Z', 'action' => 'task.created', 'task_id' => 't1', 'data' => []],
        ]);

        $result = \tt_reconcile($this->tmpDir, dryRun: true);

        self::assertTrue($result['drift_detected']);
        self::assertFalse($result['reconcile_event_appended']);
        self::assertSame([], $this->readReconcileEvents());
    }

    public function testCustomLogDirIsHonoured(): void
    {
        \tt_reconcile_ensure_dir($this->tmpDir);
        $altLogs = $this->tmpDir . DIRECTORY_SEPARATOR . 'audit';
        \tt_reconcile_ensure_dir($altLogs);

        $this->writeTasksCsv([['ID' => 't1', 'UPDATED_AT' => '2026-05-11T12:00:00.000Z']]);

        $result = \tt_reconcile($this->tmpDir, logDir: $altLogs);

        self::assertSame($altLogs, $result['log_dir']);
        self::assertTrue($result['drift_detected']);
        $files = glob($altLogs . DIRECTORY_SEPARATOR . 'changes-*.ndjson');
        self::assertNotEmpty($files, 'reconcile event must land in the custom log dir');
    }

    /**
     * @param list<array{ID:string, UPDATED_AT:string}> $rows
     */
    private function writeTasksCsv(array $rows): void
    {
        $headers = ['ID', 'UPDATED_AT'];
        $out = '"' . implode('","', $headers) . "\"\r\n";
        foreach ($rows as $r) {
            $out .= '"' . $r['ID'] . '","' . $r['UPDATED_AT'] . "\"\r\n";
        }
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . 'tasks.csv', $out);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function appendNdjson(string $date, array $events): void
    {
        $path = $this->logDir . DIRECTORY_SEPARATOR . "changes-{$date}.ndjson";
        $payload = '';
        foreach ($events as $e) {
            $payload .= json_encode($e, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        file_put_contents($path, $payload, FILE_APPEND);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readReconcileEvents(): array
    {
        $files = glob($this->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        $out = [];
        foreach ($files as $path) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded) && ($decoded['action'] ?? null) === 'system.reconcile') {
                    $out[] = $decoded;
                }
            }
        }
        return $out;
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
