<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class TagRepositoryTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private string $logDir;
    private TagRepository $repo;
    private EventLog $events;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-tagrepo-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'tags.csv';
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logDir, 0700, true);

        $this->events = new EventLog($this->logDir);
        $this->repo = new TagRepository(new CsvStore(), $this->events, $this->csvPath);
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
        return '00000000-0000-7000-8000-00000000000' . $n;
    }

    public function testNormalizeLowercasesAndSlugifies(): void
    {
        self::assertSame('urgent', TagRepository::normalize('Urgent'));
        self::assertSame('high-priority', TagRepository::normalize('High Priority'));
        self::assertSame('q4-roadmap', TagRepository::normalize('  Q4 / Roadmap!  '));
        self::assertSame('alpha-beta', TagRepository::normalize('---ALPHA___BETA---'));
        self::assertSame('123', TagRepository::normalize('123'));
    }

    public function testNormalizeReturnsEmptyForUnnormalizableInput(): void
    {
        self::assertSame('', TagRepository::normalize(''));
        self::assertSame('', TagRepository::normalize('   '));
        self::assertSame('', TagRepository::normalize('!!!'));
        self::assertSame('', TagRepository::normalize('---'));
    }

    public function testListAllOnEmptyReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->listAll());
    }

    public function testAddPersistsRowAndEmitsTagAdded(): void
    {
        $a = $this->uuid('a');

        $stored = $this->repo->add($a, 'Urgent');

        self::assertSame('urgent', $stored);
        self::assertTrue($this->repo->has($a, 'urgent'));
        self::assertSame([['taskId' => $a, 'tag' => 'urgent']], $this->repo->listAll());

        self::assertSame(['tag.added'], $this->eventActions());
        $e = $this->readEvents()[0];
        self::assertSame($a, $e['task_id']);
        self::assertSame('urgent', $e['data']['tag']);
    }

    public function testAddNormalizesBeforeStoring(): void
    {
        $a = $this->uuid('a');

        $this->repo->add($a, 'High Priority!');

        self::assertSame([['taskId' => $a, 'tag' => 'high-priority']], $this->repo->listAll());
    }

    public function testHasMatchesViaNormalizedForm(): void
    {
        $a = $this->uuid('a');
        $this->repo->add($a, 'Urgent');

        self::assertTrue($this->repo->has($a, 'urgent'));
        self::assertTrue($this->repo->has($a, 'URGENT'));
        self::assertTrue($this->repo->has($a, '  urgent  '));
        self::assertFalse($this->repo->has($a, 'cold'));
    }

    public function testAddCarriesActorEnvelopeIntoEvent(): void
    {
        $a = $this->uuid('a');

        $this->repo->add($a, 'urgent', [
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
        $this->repo->add('', 'urgent');
    }

    public function testAddRejectsEmptyTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->add($this->uuid('a'), '');
    }

    public function testAddRejectsUnnormalizableTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->add($this->uuid('a'), '!!!');
    }

    public function testAddRejectsDuplicateAfterNormalization(): void
    {
        $a = $this->uuid('a');
        $this->repo->add($a, 'Urgent');

        try {
            $this->repo->add($a, 'urgent'); // same normalized form
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('duplicate tag', $e->getMessage());
        }

        self::assertCount(1, $this->repo->listAll(), 'duplicate must not append a row');
        self::assertSame(['tag.added'], $this->eventActions(), 'only the first add emits');
    }

    public function testAddAllowsSameTagOnDifferentTasks(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, 'urgent');
        $this->repo->add($b, 'urgent');

        self::assertCount(2, $this->repo->listAll());
        self::assertSame(['tag.added', 'tag.added'], $this->eventActions());
    }

    public function testRemoveDropsRowAndEmitsEvent(): void
    {
        $a = $this->uuid('a');

        $this->repo->add($a, 'urgent');
        $this->repo->remove($a, 'URGENT'); // re-normalized

        self::assertFalse($this->repo->has($a, 'urgent'));
        self::assertSame([], $this->repo->listAll());
        self::assertSame(['tag.added', 'tag.removed'], $this->eventActions());

        $removed = $this->readEvents()[1];
        self::assertSame($a, $removed['task_id']);
        self::assertSame('urgent', $removed['data']['tag']);
    }

    public function testRemoveOnAbsentPairIsNoOp(): void
    {
        $a = $this->uuid('a');

        $this->repo->remove($a, 'never-added');

        self::assertSame([], $this->repo->listAll());
        self::assertSame([], $this->eventActions(), 'no event for missing pair');
    }

    public function testRemoveOnlyDropsTheMatchingPair(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');

        $this->repo->add($a, 'urgent');
        $this->repo->add($a, 'backend');
        $this->repo->add($b, 'urgent');

        $this->repo->remove($a, 'urgent');

        $all = $this->repo->listAll();
        self::assertCount(2, $all);
        self::assertFalse($this->repo->has($a, 'urgent'));
        self::assertTrue($this->repo->has($a, 'backend'));
        self::assertTrue($this->repo->has($b, 'urgent'));
    }

    public function testRemoveRejectsEmptyTaskId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->remove('', 'urgent');
    }

    public function testRemoveRejectsEmptyTag(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->remove($this->uuid('a'), '');
    }

    public function testTagsOfReturnsSortedTagsForTask(): void
    {
        $a = $this->uuid('a');

        $this->repo->add($a, 'zeta');
        $this->repo->add($a, 'alpha');
        $this->repo->add($a, 'mike');

        self::assertSame(['alpha', 'mike', 'zeta'], $this->repo->tagsOf($a));
        self::assertSame([], $this->repo->tagsOf($this->uuid('b')));
        self::assertSame([], $this->repo->tagsOf(''));
    }

    public function testTasksWithReturnsSortedTaskIds(): void
    {
        $a = $this->uuid('a');
        $b = $this->uuid('b');
        $c = $this->uuid('c');

        $this->repo->add($b, 'urgent');
        $this->repo->add($a, 'urgent');
        $this->repo->add($c, 'urgent');
        $this->repo->add($a, 'backend');

        $tasks = $this->repo->tasksWith('Urgent'); // normalize before lookup
        $expected = [$a, $b, $c];
        sort($expected);
        self::assertSame($expected, $tasks);

        self::assertSame([], $this->repo->tasksWith('nope'));
        self::assertSame([], $this->repo->tasksWith(''));
    }

    public function testHasReturnsFalseForEmptyArgs(): void
    {
        self::assertFalse($this->repo->has('', 'urgent'));
        self::assertFalse($this->repo->has($this->uuid('a'), ''));
        self::assertFalse($this->repo->has($this->uuid('a'), '!!!'));
    }

    public function testCsvHeaderRowMatchesRepositoryHeaders(): void
    {
        $this->repo->add($this->uuid('a'), 'urgent');

        $raw = file_get_contents($this->csvPath);
        self::assertNotFalse($raw);
        $firstLine = strtok($raw, "\r\n");
        self::assertNotFalse($firstLine);

        $expected = '"' . implode('","', TagRepository::HEADERS) . '"';
        self::assertSame($expected, $firstLine);
    }

    public function testFailedDuplicateAddLeavesNoTmpFiles(): void
    {
        $a = $this->uuid('a');
        $this->repo->add($a, 'urgent');

        try {
            $this->repo->add($a, 'urgent');
        } catch (RuntimeException) {
            // expected
        }

        $tmps = glob($this->sandbox . DIRECTORY_SEPARATOR . '*.tmp.*') ?: [];
        self::assertSame([], $tmps, 'aborted txn must not leave tmp files');
    }
}
