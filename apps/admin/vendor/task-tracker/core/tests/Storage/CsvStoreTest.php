<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Storage;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Storage\CsvStore;

final class CsvStoreTest extends TestCase
{
    private string $sandbox;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-csvstore-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    private function csv(string $name): string
    {
        return $this->sandbox . DIRECTORY_SEPARATOR . $name;
    }

    public function testReadAllReturnsEmptyForMissingFile(): void
    {
        $store = new CsvStore();
        self::assertSame([], $store->readAll($this->csv('does-not-exist.csv')));
    }

    public function testTxnCreatesNewFileWithHeaderOnlyWhenMutatorReturnsEmpty(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE'], static fn(array $rows): array => $rows);

        self::assertFileExists($path);
        $contents = file_get_contents($path);
        self::assertSame("\"ID\",\"TITLE\"\r\n", $contents);
        self::assertSame([], $store->readAll($path));
    }

    public function testTxnAppendsRowAndPersists(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');
        $headers = ['ID', 'TITLE', 'STATUS'];

        $store->txn($path, $headers, static fn(array $rows): array => [
            ['ID' => 'abc', 'TITLE' => 'first task', 'STATUS' => 'todo'],
        ]);

        $back = $store->readAll($path);
        self::assertCount(1, $back);
        self::assertSame(['ID' => 'abc', 'TITLE' => 'first task', 'STATUS' => 'todo'], $back[0]);
    }

    public function testTxnRewritesEntireFileOnEachCall(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');
        $headers = ['ID', 'TITLE'];

        $store->txn($path, $headers, static fn() => [
            ['ID' => '1', 'TITLE' => 'a'],
            ['ID' => '2', 'TITLE' => 'b'],
        ]);

        $store->txn($path, $headers, static function (array $rows): array {
            // Drop row 1, modify row 2, add row 3.
            return [
                ['ID' => '2', 'TITLE' => 'B'],
                ['ID' => '3', 'TITLE' => 'c'],
            ];
        });

        self::assertSame(
            [
                ['ID' => '2', 'TITLE' => 'B'],
                ['ID' => '3', 'TITLE' => 'c'],
            ],
            $store->readAll($path),
        );
    }

    public function testOutputUsesCrlfTerminatorOnEveryLine(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => 'one'],
            ['ID' => '2', 'TITLE' => 'two'],
        ]);

        $raw = file_get_contents($path);
        self::assertNotFalse($raw);

        $lines = preg_split('/\r\n/', $raw);
        // Trailing CRLF creates an empty final element; that's expected.
        self::assertSame(['"ID","TITLE"', '"1","one"', '"2","two"', ''], $lines);
        self::assertStringNotContainsString("\n\n", $raw, 'bare LFs must not appear without preceding CR');
    }

    public function testEveryFieldIsQuotedEvenWhenNotRequired(): void
    {
        $store = new CsvStore();
        $path = $this->csv('roster.csv');

        $store->txn($path, ['ID', 'NAME', 'ACTIVE'], static fn() => [
            ['ID' => 'simple', 'NAME' => 'nocomma', 'ACTIVE' => 'true'],
        ]);

        $raw = file_get_contents($path);
        self::assertSame(
            "\"ID\",\"NAME\",\"ACTIVE\"\r\n\"simple\",\"nocomma\",\"true\"\r\n",
            $raw,
        );
    }

    public function testEmbeddedQuotesAreDoubledAndRoundTripped(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $payload = 'she said "ship it" today';

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => $payload],
        ]);

        $raw = file_get_contents($path);
        self::assertStringContainsString('"she said ""ship it"" today"', $raw);

        $back = $store->readAll($path);
        self::assertSame($payload, $back[0]['TITLE']);
    }

    public function testEmbeddedCommasArePreservedRoundTrip(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $payload = 'a, b, c';

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => $payload],
        ]);

        $back = $store->readAll($path);
        self::assertSame($payload, $back[0]['TITLE']);
    }

    public function testEmbeddedNewlinesArePreservedRoundTrip(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $payload = "line one\nline two\r\nline three";

        $store->txn($path, ['ID', 'NOTES'], static fn() => [
            ['ID' => '1', 'NOTES' => $payload],
        ]);

        $back = $store->readAll($path);
        self::assertSame($payload, $back[0]['NOTES']);
    }

    public function testUnicodeIsPreservedAndNoBomIsEmitted(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => '日本語タスク — émoji 🚀'],
        ]);

        $raw = (string) file_get_contents($path);
        self::assertFalse(str_starts_with($raw, "\xEF\xBB\xBF"), 'no UTF-8 BOM allowed in output');

        $back = $store->readAll($path);
        self::assertSame('日本語タスク — émoji 🚀', $back[0]['TITLE']);
    }

    public function testLeadingBomIsStrippedOnRead(): void
    {
        $path = $this->csv('with-bom.csv');
        file_put_contents($path, "\xEF\xBB\xBF\"ID\",\"TITLE\"\r\n\"1\",\"hello\"\r\n");

        $store = new CsvStore();
        $back = $store->readAll($path);

        self::assertSame([['ID' => '1', 'TITLE' => 'hello']], $back, 'BOM in header byte 0 must be stripped');
    }

    public function testTxnAbortsLeavingFileUntouchedWhenMutatorThrows(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => 'kept'],
        ]);
        $before = file_get_contents($path);

        try {
            $store->txn($path, ['ID', 'TITLE'], static function (array $rows): array {
                throw new \LogicException('boom');
            });
            self::fail('expected mutator exception to propagate');
        } catch (\LogicException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame($before, file_get_contents($path), 'on-disk file must be unchanged after mutator failure');
    }

    public function testTxnLeavesNoOrphanTmpFilesAfterSuccess(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID'], static fn() => [['ID' => '1']]);

        $tmps = glob($this->sandbox . DIRECTORY_SEPARATOR . 'tasks.csv.tmp.*');
        self::assertNotFalse($tmps);
        self::assertSame([], $tmps, 'tmp files must be renamed away by the end of txn()');
    }

    public function testTxnLeavesNoOrphanTmpFilesAfterMutatorFailure(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID'], static fn() => []);

        try {
            $store->txn($path, ['ID'], static function (): array {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $tmps = glob($this->sandbox . DIRECTORY_SEPARATOR . 'tasks.csv.tmp.*');
        self::assertNotFalse($tmps);
        self::assertSame([], $tmps, 'tmp files must not survive a failed mutator (none should ever be created)');
    }

    public function testRowsWithMissingKeysGetEmptyStringsInOutput(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE', 'STATUS'], static fn() => [
            ['ID' => '1', 'TITLE' => 'has title'],
            ['ID' => '2', 'STATUS' => 'has status'],
        ]);

        self::assertSame(
            [
                ['ID' => '1', 'TITLE' => 'has title', 'STATUS' => ''],
                ['ID' => '2', 'TITLE' => '', 'STATUS' => 'has status'],
            ],
            $store->readAll($path),
        );
    }

    public function testRowKeysNotInHeadersAreDroppedOnWrite(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => 'a', 'INTERNAL_SCRATCH' => 'leaked?'],
        ]);

        $raw = (string) file_get_contents($path);
        self::assertStringNotContainsString('leaked', $raw, 'keys outside the declared header set must not be serialised');
        self::assertStringNotContainsString('INTERNAL_SCRATCH', $raw);
    }

    public function testTxnRejectsEmptyHeaders(): void
    {
        $store = new CsvStore();
        $this->expectException(InvalidArgumentException::class);

        $store->txn($this->csv('tasks.csv'), [], static fn() => []);
    }

    public function testHeaderOnlyFileIsReadableAsEmpty(): void
    {
        $path = $this->csv('seeded.csv');
        file_put_contents($path, "\"ID\",\"TITLE\"\r\n");

        $store = new CsvStore();
        self::assertSame([], $store->readAll($path));
    }

    public function testMutatorReceivesPriorRows(): void
    {
        $store = new CsvStore();
        $path = $this->csv('tasks.csv');

        $store->txn($path, ['ID', 'TITLE'], static fn() => [
            ['ID' => '1', 'TITLE' => 'pre-existing'],
        ]);

        $seen = null;
        $store->txn($path, ['ID', 'TITLE'], function (array $rows) use (&$seen): array {
            $seen = $rows;
            return $rows;
        });

        self::assertSame([['ID' => '1', 'TITLE' => 'pre-existing']], $seen);
    }
}
