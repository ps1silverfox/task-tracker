<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class DependencyRepositoryTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private string $logDir;
    private DependencyRepository $repo;
    private EventLog $events;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-deprepo-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'dependencies.csv';
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logDir, 0700, true);

        $this->events = new EventLog($this->logDir);
        $this->repo = new DependencyRepository(new CsvStore(), $this->events, $this->csvPath);
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

    /** @return list<array<string, mixed>> */
    private function readEvents(): array
    {
        $out = [];
        foreach (glob($this->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [] as $f) {
            $contents = file_get_contents($f);
            if ($contents === false || $contents === '') {
                continue;
            }
            foreach (explode("\n", rtrim($contents, "\n")) as $line) {
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $out[] = $decoded;
                }
            }
        }
        return $out;
    }

    /** @return list<string> */
    private function eventActions(): array
    {
        return array_map(static fn(array $e): string => (string) $e['action'], $this->readEvents());
    }

    private function uuid(string $n): string
    {
        // Stable, well-formed UUID-v7-shaped IDs for tests. Only the hex
        // structure matters here; the repository does not validate ID format.
        return '00000000-0000-7000-8000-00000000000' . $n;
    }

    public function testListAllOnEmptyReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->listAll());
    }

    public function testAddPersistsRowAndEmitsDependencyAdded(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, $b);

        self::assertTrue($this->repo->has($a, $b));
        self::assertSame([['taskId' => $a, 'prereqId' => $b]], $this->repo->listAll());

        self::assertSame(['dependency.added'], $this->eventActions());
        $e = $this->readEvents()[0];
        self::assertSame($a, $e['task_id']);
        self::assertSame($b, $e['data']['prereq_id']);
    }

    public function testAddCarriesActorEnvelopeIntoEvent(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, $b, [
            'actor' => 'admin@example',
            'ip' => '10.0.0.1',
            'user_agent' => 'phpunit/11',
        ]);

        $e = $this->readEvents()[0];
        self::assertSame('admin@example', $e['actor']);
        self::assertSame('10.0.0.1', $e['ip']);
        self::assertSame('phpunit/11', $e['user_agent']);
    }

    public function testAddRejectsEmptyTaskId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->add('', $this->uuid('a'));
    }

    public function testAddRejectsEmptyPrereqId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->add($this->uuid('a'), '');
    }

    public function testAddRejectsSelfEdgeAsCycle(): void
    {
        $a = $this->uuid('a');

        try {
            $this->repo->add($a, $a);
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('would create cycle', $e->getMessage());
        }

        self::assertFalse(is_file($this->csvPath), 'no CSV row should have been written');
        self::assertSame([], $this->eventActions());
    }

    public function testAddRejectsDuplicateEdge(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, $b);

        try {
            $this->repo->add($a, $b);
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('duplicate dependency', $e->getMessage());
        }

        self::assertCount(1, $this->repo->listAll(), 'duplicate must not append a row');
        self::assertSame(['dependency.added'], $this->eventActions(), 'only the first add emits');
    }

    public function testAddRejectsDirectCycleAToBThenBToA(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, $b); // A requires B

        try {
            $this->repo->add($b, $a); // would make B require A — cycle
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('would create cycle', $e->getMessage());
        }

        self::assertCount(1, $this->repo->listAll());
        self::assertSame(['dependency.added'], $this->eventActions());
    }

    public function testAddRejectsLongerCycle(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $c = $this->uuid('c');

        $this->repo->add($a, $b); // A requires B
        $this->repo->add($b, $c); // B requires C

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('would create cycle');
        $this->repo->add($c, $a); // C requires A → A→B→C→A cycle
    }

    public function testAddAllowsDiamondShapeWithoutCycle(): void
    {
        // A depends on B and C; both B and C depend on D. No cycle.
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $c = $this->uuid('c');
        $d = $this->uuid('d');

        $this->repo->add($a, $b);
        $this->repo->add($a, $c);
        $this->repo->add($b, $d);
        $this->repo->add($c, $d);

        self::assertCount(4, $this->repo->listAll());
        self::assertSame(
            ['dependency.added', 'dependency.added', 'dependency.added', 'dependency.added'],
            $this->eventActions(),
        );
    }

    public function testRemoveDropsEdgeAndEmitsEvent(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, $b);
        $this->repo->remove($a, $b);

        self::assertFalse($this->repo->has($a, $b));
        self::assertSame([], $this->repo->listAll());
        self::assertSame(['dependency.added', 'dependency.removed'], $this->eventActions());

        $removed = $this->readEvents()[1];
        self::assertSame($a, $removed['task_id']);
        self::assertSame($b, $removed['data']['prereq_id']);
    }

    public function testRemoveOnAbsentEdgeIsNoOp(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->remove($a, $b); // never added

        self::assertSame([], $this->repo->listAll());
        self::assertSame([], $this->eventActions(), 'no event for missing edge');
    }

    public function testRemoveOnlyDropsTheMatchingPair(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $c = $this->uuid('c');

        $this->repo->add($a, $b);
        $this->repo->add($a, $c);
        $this->repo->add($b, $c);

        $this->repo->remove($a, $b);

        $all = $this->repo->listAll();
        self::assertCount(2, $all);
        self::assertTrue($this->repo->has($a, $c));
        self::assertTrue($this->repo->has($b, $c));
        self::assertFalse($this->repo->has($a, $b));
    }

    public function testRemoveRejectsEmptyIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->remove('', $this->uuid('a'));
    }

    public function testPrereqsOfReturnsBlockingTasks(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $c = $this->uuid('c');

        $this->repo->add($a, $b);
        $this->repo->add($a, $c);

        $prereqs = $this->repo->prereqsOf($a);
        sort($prereqs);
        $expected = [$b, $c];
        sort($expected);
        self::assertSame($expected, $prereqs);

        self::assertSame([], $this->repo->prereqsOf($b));
        self::assertSame([], $this->repo->prereqsOf(''));
    }

    public function testBlockedByReturnsWaitingTasks(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $c = $this->uuid('c');
        $d = $this->uuid('d');

        $this->repo->add($a, $d);
        $this->repo->add($b, $d);
        $this->repo->add($c, $d);

        $blocked = $this->repo->blockedBy($d);
        sort($blocked);
        $expected = [$a, $b, $c];
        sort($expected);
        self::assertSame($expected, $blocked);

        self::assertSame([], $this->repo->blockedBy($a));
        self::assertSame([], $this->repo->blockedBy(''));
    }

    public function testHasReturnsFalseForEmptyIds(): void
    {
        self::assertFalse($this->repo->has('', $this->uuid('a')));
        self::assertFalse($this->repo->has($this->uuid('a'), ''));
    }

    public function testCsvHeaderRowMatchesRepositoryHeaders(): void
    {
        $this->repo->add($this->uuid('a'), $this->uuid('b'));

        $raw = file_get_contents($this->csvPath);
        self::assertNotFalse($raw);
        $firstLine = strtok($raw, "\r\n");
        self::assertNotFalse($firstLine);

        $expected = '"' . implode('","', DependencyRepository::HEADERS) . '"';
        self::assertSame($expected, $firstLine);
    }

    public function testFailedCycleCheckLeavesNoTmpFiles(): void
    {
        // The cycle check throws *inside* the txn mutator, before any rename.
        // Verify no '.tmp.*' survivors remain in the directory.
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $this->repo->add($a, $b);

        try {
            $this->repo->add($b, $a);
        } catch (RuntimeException) {
            // expected
        }

        $tmps = glob($this->sandbox . DIRECTORY_SEPARATOR . '*.tmp.*') ?: [];
        self::assertSame([], $tmps, 'aborted txn must not leave tmp files');
    }
}
