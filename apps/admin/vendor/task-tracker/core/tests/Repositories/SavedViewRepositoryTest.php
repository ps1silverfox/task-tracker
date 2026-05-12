<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Repositories\SavedViewRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class SavedViewRepositoryTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private string $logDir;
    private SavedViewRepository $repo;
    private EventLog $events;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-svrepo-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'saved_views.csv';
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logDir, 0700, true);

        $this->events = new EventLog($this->logDir);
        $this->repo = new SavedViewRepository(new CsvStore(), $this->events, $this->csvPath);
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

    public function testListAllOnEmptyReturnsEmpty(): void
    {
        self::assertSame([], $this->repo->listAll());
    }

    public function testFindOnEmptyReturnsNull(): void
    {
        self::assertNull($this->repo->find('nope'));
        self::assertNull($this->repo->find(''));
    }

    public function testCreatePersistsAndEmitsSavedViewCreated(): void
    {
        $view = $this->repo->create([
            'name' => 'My Backlog',
            'filter' => ['status' => ['open', 'in_progress'], 'priority' => 'high'],
        ]);

        self::assertNotSame('', $view->id);
        self::assertSame('My Backlog', $view->name);
        self::assertSame(['status' => ['open', 'in_progress'], 'priority' => 'high'], $view->filter);
        self::assertNotSame('', $view->createdAt);

        $all = $this->repo->listAll();
        self::assertCount(1, $all);
        self::assertSame($view->id, $all[0]->id);
        self::assertSame($view->filter, $all[0]->filter);

        self::assertSame(['saved_view.created'], $this->eventActions());
        $e = $this->readEvents()[0];
        self::assertSame($view->id, $e['saved_view_id']);
        self::assertSame('My Backlog', $e['data']['name']);
        self::assertSame($view->filter, $e['data']['filter']);
    }

    public function testCreateAcceptsEmptyFilter(): void
    {
        $view = $this->repo->create(['name' => 'All Tasks']);

        self::assertSame([], $view->filter);
        $stored = $this->repo->find($view->id);
        self::assertNotNull($stored);
        self::assertSame([], $stored->filter);
    }

    public function testCreateAcceptsExplicitNullFilter(): void
    {
        $view = $this->repo->create(['name' => 'All Tasks', 'filter' => null]);
        self::assertSame([], $view->filter);
    }

    public function testCreateCarriesActorEnvelopeIntoEvent(): void
    {
        $this->repo->create(
            ['name' => 'My View'],
            [
                'actor' => 'admin@example',
                'ip' => '10.0.0.1',
                'user_agent' => 'phpunit/11',
            ],
        );

        $e = $this->readEvents()[0];
        self::assertSame('admin@example', $e['actor']);
        self::assertSame('10.0.0.1', $e['ip']);
        self::assertSame('phpunit/11', $e['user_agent']);
    }

    public function testCreateRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create([]);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create(['name' => '']);
    }

    public function testCreateRejectsNonArrayFilter(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create(['name' => 'X', 'filter' => 'not-an-array']);
    }

    public function testCreateRejectsDuplicateName(): void
    {
        $this->repo->create(['name' => 'My View']);

        try {
            $this->repo->create(['name' => 'My View']);
            self::fail('expected RuntimeException');
        } catch (RuntimeException $e) {
            self::assertSame('duplicate name: My View', $e->getMessage());
        }

        self::assertCount(1, $this->repo->listAll(), 'duplicate must not append');
        self::assertSame(['saved_view.created'], $this->eventActions(), 'only the first create emits');
    }

    public function testFindReturnsViewById(): void
    {
        $a = $this->repo->create(['name' => 'A', 'filter' => ['status' => 'open']]);
        $b = $this->repo->create(['name' => 'B', 'filter' => ['priority' => 'high']]);

        $found = $this->repo->find($a->id);
        self::assertNotNull($found);
        self::assertSame('A', $found->name);
        self::assertSame(['status' => 'open'], $found->filter);

        $found = $this->repo->find($b->id);
        self::assertNotNull($found);
        self::assertSame('B', $found->name);
    }

    public function testFindByNameReturnsView(): void
    {
        $this->repo->create(['name' => 'Alpha']);
        $beta = $this->repo->create(['name' => 'Beta']);

        $found = $this->repo->findByName('Beta');
        self::assertNotNull($found);
        self::assertSame($beta->id, $found->id);

        self::assertNull($this->repo->findByName('Gamma'));
        self::assertNull($this->repo->findByName(''));
    }

    public function testDeleteRemovesRowAndEmitsEvent(): void
    {
        $view = $this->repo->create(['name' => 'Doomed', 'filter' => ['x' => 1]]);

        $this->repo->delete($view->id);

        self::assertNull($this->repo->find($view->id));
        self::assertSame([], $this->repo->listAll());
        self::assertSame(['saved_view.created', 'saved_view.deleted'], $this->eventActions());

        $deleted = $this->readEvents()[1];
        self::assertSame($view->id, $deleted['saved_view_id']);
        self::assertSame('Doomed', $deleted['data']['name']);
    }

    public function testDeleteOnAbsentIdIsNoOp(): void
    {
        $this->repo->delete('00000000-0000-7000-8000-000000000000');

        self::assertSame([], $this->repo->listAll());
        self::assertSame([], $this->eventActions(), 'no event for missing id');
    }

    public function testDeleteOnlyRemovesMatchingRow(): void
    {
        $a = $this->repo->create(['name' => 'Keep']);
        $b = $this->repo->create(['name' => 'Drop']);
        $c = $this->repo->create(['name' => 'Also Keep']);

        $this->repo->delete($b->id);

        $remaining = $this->repo->listAll();
        self::assertCount(2, $remaining);
        $names = array_map(static fn($v) => $v->name, $remaining);
        sort($names);
        self::assertSame(['Also Keep', 'Keep'], $names);

        self::assertNotNull($this->repo->find($a->id));
        self::assertNull($this->repo->find($b->id));
        self::assertNotNull($this->repo->find($c->id));
    }

    public function testDeleteThenRecreateWithSameNameSucceeds(): void
    {
        $first = $this->repo->create(['name' => 'View X']);
        $this->repo->delete($first->id);

        $second = $this->repo->create(['name' => 'View X']);
        self::assertNotSame($first->id, $second->id);
        self::assertSame('View X', $second->name);
    }

    public function testDeleteRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->delete('');
    }

    public function testCsvHeaderRowMatchesModelHeaders(): void
    {
        $this->repo->create(['name' => 'X']);

        $raw = file_get_contents($this->csvPath);
        self::assertNotFalse($raw);
        $firstLine = strtok($raw, "\r\n");
        self::assertNotFalse($firstLine);

        $expected = '"' . implode('","', \TaskTracker\Models\SavedView::HEADERS) . '"';
        self::assertSame($expected, $firstLine);
    }

    public function testFilterJsonRoundTripsComplexShape(): void
    {
        $filter = [
            'status' => ['open', 'blocked'],
            'priority' => 'high',
            'assigned_to' => ['alice', 'bob'],
            'tags' => ['urgent', 'q4-roadmap'],
            'text_search' => 'auth, "quoted", and =formula',
        ];

        $view = $this->repo->create(['name' => 'Complex', 'filter' => $filter]);
        $reloaded = $this->repo->find($view->id);

        self::assertNotNull($reloaded);
        self::assertSame($filter, $reloaded->filter);
    }

    public function testFailedDuplicateCreateLeavesNoTmpFiles(): void
    {
        $this->repo->create(['name' => 'Once']);

        try {
            $this->repo->create(['name' => 'Once']);
        } catch (RuntimeException) {
            // expected
        }

        $tmps = glob($this->sandbox . DIRECTORY_SEPARATOR . '*.tmp.*') ?: [];
        self::assertSame([], $tmps, 'aborted txn must not leave tmp files');
    }
}
