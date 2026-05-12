<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Models\Team;
use TaskTracker\Repositories\TeamRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class TeamRepositoryTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private string $logDir;
    private TeamRepository $repo;
    private EventLog $events;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-teamrepo-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'teams.csv';
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logDir, 0700, true);

        $this->events = new EventLog($this->logDir);
        $this->repo = new TeamRepository(new CsvStore(), $this->events, $this->csvPath);
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

    public function testCreatePersistsTeamAndEmitsTeamCreated(): void
    {
        $t = $this->repo->create([
            'name' => 'Platform',
            'description' => 'Infra & build',
        ]);

        self::assertNotSame('', $t->id);
        self::assertSame('Platform', $t->name);
        self::assertSame('Infra & build', $t->description);

        $persisted = $this->repo->find($t->id);
        self::assertNotNull($persisted);
        self::assertSame('Platform', $persisted->name);
        self::assertSame('Infra & build', $persisted->description);

        self::assertSame(['team.created'], $this->eventActions());
        $e = $this->readEvents()[0];
        self::assertSame($t->id, $e['team_id']);
        self::assertSame('Platform', $e['data']['name']);
        self::assertSame('Infra & build', $e['data']['description']);
    }

    public function testCreateWithMinimalFieldsLeavesDescriptionNull(): void
    {
        $t = $this->repo->create(['name' => 'Solo']);

        self::assertNull($t->description);

        $e = $this->readEvents()[0];
        self::assertNull($e['data']['description']);
    }

    public function testCreateCarriesActorEnvelopeIntoEvent(): void
    {
        $this->repo->create(
            ['name' => 'Ops'],
            ['actor' => 'admin@example', 'ip' => '10.0.0.1', 'user_agent' => 'phpunit/11'],
        );

        $e = $this->readEvents()[0];
        self::assertSame('admin@example', $e['actor']);
        self::assertSame('10.0.0.1', $e['ip']);
        self::assertSame('phpunit/11', $e['user_agent']);
    }

    public function testCreateRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create(['description' => 'no name']);
    }

    public function testCreateRejectsEmptyName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->create(['name' => '']);
    }

    public function testFindReturnsNullForUnknownAndEmpty(): void
    {
        self::assertNull($this->repo->find('does-not-exist'));
        self::assertNull($this->repo->find(''));
    }

    public function testListAllReturnsTeamsInInsertionOrder(): void
    {
        $a = $this->repo->create(['name' => 'Alpha']);
        $b = $this->repo->create(['name' => 'Beta']);
        $c = $this->repo->create(['name' => 'Gamma']);

        $all = $this->repo->listAll();
        self::assertCount(3, $all);
        self::assertSame([$a->id, $b->id, $c->id], array_map(static fn(Team $t): string => $t->id, $all));
    }

    public function testUpdateNameEmitsTeamUpdatedWithBeforeAfterSlice(): void
    {
        $t = $this->repo->create(['name' => 'Old', 'description' => 'orig desc']);

        $updated = $this->repo->update($t->id, ['name' => 'New']);

        self::assertSame('New', $updated->name);
        self::assertSame('orig desc', $updated->description, 'unchanged field preserved');

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'team.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame(['name'], $found['data']['fields']);
        self::assertSame(['name' => 'Old'], $found['data']['before']);
        self::assertSame(['name' => 'New'], $found['data']['after']);
    }

    public function testUpdateDescriptionFromNullToValueIsTracked(): void
    {
        $t = $this->repo->create(['name' => 'NoDesc']);
        $this->repo->update($t->id, ['description' => 'now described']);

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'team.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame(['description'], $found['data']['fields']);
        self::assertNull($found['data']['before']['description']);
        self::assertSame('now described', $found['data']['after']['description']);
    }

    public function testUpdateDescriptionFromValueToNullIsTracked(): void
    {
        $t = $this->repo->create(['name' => 'HasDesc', 'description' => 'drop me']);
        $updated = $this->repo->update($t->id, ['description' => null]);

        self::assertNull($updated->description);

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'team.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame('drop me', $found['data']['before']['description']);
        self::assertNull($found['data']['after']['description']);
    }

    public function testUpdateBothFieldsListsAllChangedFields(): void
    {
        $t = $this->repo->create(['name' => 'A', 'description' => 'one']);
        $this->repo->update($t->id, ['name' => 'B', 'description' => 'two']);

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'team.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame(['name', 'description'], $found['data']['fields']);
        self::assertSame(['name' => 'A', 'description' => 'one'], $found['data']['before']);
        self::assertSame(['name' => 'B', 'description' => 'two'], $found['data']['after']);
    }

    public function testUpdateWithIdenticalValuesIsNoOpAndEmitsNoEvent(): void
    {
        $t = $this->repo->create(['name' => 'Same', 'description' => 'same']);
        $before = $this->eventActions();

        $updated = $this->repo->update($t->id, ['name' => 'Same', 'description' => 'same']);

        self::assertSame($t->name, $updated->name);
        self::assertSame($before, $this->eventActions());
    }

    public function testUpdateRejectsEmptyName(): void
    {
        $t = $this->repo->create(['name' => 'Original']);

        $this->expectException(InvalidArgumentException::class);
        $this->repo->update($t->id, ['name' => '']);
    }

    public function testUpdateOnUnknownTeamThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('team not found');
        $this->repo->update('00000000-0000-7000-8000-000000000000', ['name' => 'x']);
    }

    public function testUpdateIgnoresUnknownFieldsSilently(): void
    {
        $t = $this->repo->create(['name' => 'Stable']);

        $updated = $this->repo->update($t->id, ['bogus' => 'value', 'name' => 'Stable']);

        self::assertSame('Stable', $updated->name);
        // No team.updated event because nothing actually changed.
        self::assertSame(['team.created'], $this->eventActions());
    }

    public function testCsvHeaderRowMatchesTeamHeaders(): void
    {
        $this->repo->create(['name' => 'Header probe']);

        $raw = file_get_contents($this->csvPath);
        self::assertNotFalse($raw);
        $firstLine = strtok($raw, "\r\n");
        self::assertNotFalse($firstLine);

        $expected = '"' . implode('","', Team::HEADERS) . '"';
        self::assertSame($expected, $firstLine);
    }

    public function testEveryEventCarriesTeamId(): void
    {
        $t = $this->repo->create(['name' => 'Snap', 'description' => 'd1']);
        $this->repo->update($t->id, ['name' => 'Snap2']);
        $this->repo->update($t->id, ['description' => 'd2']);

        $events = $this->readEvents();
        self::assertCount(3, $events);
        foreach ($events as $e) {
            self::assertSame($t->id, $e['team_id']);
        }
    }

}
