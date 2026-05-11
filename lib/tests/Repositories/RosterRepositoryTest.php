<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Repositories;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Models\RosterMember;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class RosterRepositoryTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private string $logDir;
    private RosterRepository $repo;
    private EventLog $events;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'task-tracker-rosterrepo-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'roster.csv';
        $this->logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($this->logDir, 0700, true);

        $this->events = new EventLog($this->logDir);
        $this->repo = new RosterRepository(new CsvStore(), $this->events, $this->csvPath);
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

    public function testAddPersistsActiveMemberAndEmitsRosterAdded(): void
    {
        $m = $this->repo->add([
            'name' => 'Alice',
            'email' => 'alice@example',
            'teamId' => 'team-a',
        ]);

        self::assertNotSame('', $m->id);
        self::assertSame('Alice', $m->name);
        self::assertSame('alice@example', $m->email);
        self::assertSame('team-a', $m->teamId);
        self::assertTrue($m->active);

        $persisted = $this->repo->find($m->id);
        self::assertNotNull($persisted);
        self::assertSame('Alice', $persisted->name);
        self::assertTrue($persisted->active);

        self::assertSame(['roster.added'], $this->eventActions());
        $e = $this->readEvents()[0];
        self::assertSame($m->id, $e['roster_id']);
        self::assertSame('team-a', $e['team_id_snapshot']);
        self::assertSame('Alice', $e['data']['name']);
        self::assertSame('alice@example', $e['data']['email']);
        self::assertSame('team-a', $e['data']['team_id']);
    }

    public function testAddWithMinimalFieldsLeavesOptionalsNull(): void
    {
        $m = $this->repo->add(['name' => 'Solo']);

        self::assertNull($m->email);
        self::assertNull($m->teamId);
        self::assertTrue($m->active);

        $e = $this->readEvents()[0];
        self::assertNull($e['team_id_snapshot']);
        self::assertNull($e['data']['email']);
        self::assertNull($e['data']['team_id']);
    }

    public function testAddCarriesActorEnvelopeIntoEvent(): void
    {
        $this->repo->add(
            ['name' => 'Bob'],
            ['actor' => 'admin@example', 'ip' => '10.0.0.1', 'user_agent' => 'phpunit/11'],
        );

        $e = $this->readEvents()[0];
        self::assertSame('admin@example', $e['actor']);
        self::assertSame('10.0.0.1', $e['ip']);
        self::assertSame('phpunit/11', $e['user_agent']);
    }

    public function testAddRejectsMissingName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->add(['email' => 'x@example']);
    }

    public function testListActiveExcludesDeactivatedMembers(): void
    {
        $a = $this->repo->add(['name' => 'Alpha']);
        $b = $this->repo->add(['name' => 'Beta']);
        $this->repo->deactivate($a->id);

        $active = $this->repo->listActive();
        self::assertCount(1, $active);
        self::assertSame($b->id, $active[0]->id);

        self::assertCount(2, $this->repo->listAll(), 'deactivated row is retained in raw list');
    }

    public function testFindReturnsNullForUnknownAndEmpty(): void
    {
        self::assertNull($this->repo->find('does-not-exist'));
        self::assertNull($this->repo->find(''));
    }

    public function testUpdateNameEmitsRosterUpdatedWithBeforeAfterSlice(): void
    {
        $m = $this->repo->add(['name' => 'Old', 'email' => 'old@example']);

        $updated = $this->repo->update($m->id, ['name' => 'New']);

        self::assertSame('New', $updated->name);
        self::assertSame('old@example', $updated->email, 'unchanged field preserved');

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'roster.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame(['name'], $found['data']['fields']);
        self::assertSame(['name' => 'Old'], $found['data']['before']);
        self::assertSame(['name' => 'New'], $found['data']['after']);
    }

    public function testUpdateEmailFromNullToValueIsTracked(): void
    {
        $m = $this->repo->add(['name' => 'NoMail']);
        $this->repo->update($m->id, ['email' => 'now@example']);

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'roster.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame(['email'], $found['data']['fields']);
        self::assertNull($found['data']['before']['email']);
        self::assertSame('now@example', $found['data']['after']['email']);
    }

    public function testUpdateEmailFromValueToNullIsTracked(): void
    {
        $m = $this->repo->add(['name' => 'HasMail', 'email' => 'drop@example']);
        $updated = $this->repo->update($m->id, ['email' => null]);

        self::assertNull($updated->email);

        $found = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'roster.updated') {
                $found = $e;
                break;
            }
        }
        self::assertNotNull($found);
        self::assertSame('drop@example', $found['data']['before']['email']);
        self::assertNull($found['data']['after']['email']);
    }

    public function testUpdateTeamIdChangesTeamSnapshotInEnvelope(): void
    {
        $m = $this->repo->add(['name' => 'Mover', 'teamId' => 'team-a']);
        $this->repo->update($m->id, ['teamId' => 'team-b']);

        $updateEvent = null;
        foreach ($this->readEvents() as $e) {
            if ($e['action'] === 'roster.updated') {
                $updateEvent = $e;
                break;
            }
        }
        self::assertNotNull($updateEvent);
        self::assertSame('team-b', $updateEvent['team_id_snapshot'], 'snapshot reflects post-change state');
        self::assertSame('team-a', $updateEvent['data']['before']['teamId']);
        self::assertSame('team-b', $updateEvent['data']['after']['teamId']);
    }

    public function testUpdateWithIdenticalValuesIsNoOpAndEmitsNoEvent(): void
    {
        $m = $this->repo->add(['name' => 'Same', 'email' => 'same@example']);
        $before = $this->eventActions();

        $updated = $this->repo->update($m->id, ['name' => 'Same', 'email' => 'same@example']);

        self::assertSame($m->name, $updated->name);
        self::assertSame($before, $this->eventActions());
    }

    public function testUpdateRejectsEmptyName(): void
    {
        $m = $this->repo->add(['name' => 'Original']);

        $this->expectException(InvalidArgumentException::class);
        $this->repo->update($m->id, ['name' => '']);
    }

    public function testUpdateOnUnknownMemberThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('roster member not found');
        $this->repo->update('00000000-0000-7000-8000-000000000000', ['name' => 'x']);
    }

    public function testUpdateIgnoresActiveFieldSilently(): void
    {
        $m = $this->repo->add(['name' => 'Stable']);
        $this->repo->deactivate($m->id);

        $updated = $this->repo->update($m->id, ['active' => true, 'name' => 'Stable']);

        self::assertFalse($updated->active, 'update cannot reactivate; closed action enum forbids it');
    }

    public function testDeactivateFlipsActiveAndEmitsRosterDeactivated(): void
    {
        $m = $this->repo->add(['name' => 'Goodbye']);

        $gone = $this->repo->deactivate($m->id);

        self::assertFalse($gone->active);
        $row = $this->repo->find($m->id);
        self::assertNotNull($row);
        self::assertFalse($row->active);

        self::assertContains('roster.deactivated', $this->eventActions());
    }

    public function testDeactivateIsIdempotent(): void
    {
        $m = $this->repo->add(['name' => 'Twice']);
        $this->repo->deactivate($m->id);
        $eventsBefore = $this->eventActions();

        $second = $this->repo->deactivate($m->id);

        self::assertFalse($second->active);
        self::assertSame($eventsBefore, $this->eventActions(), 'no additional event on second deactivate');
    }

    public function testDeactivateOnUnknownMemberThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('roster member not found');
        $this->repo->deactivate('00000000-0000-7000-8000-000000000000');
    }

    public function testCsvHeaderRowMatchesRosterMemberHeaders(): void
    {
        $this->repo->add(['name' => 'Header probe']);

        $raw = file_get_contents($this->csvPath);
        self::assertNotFalse($raw);
        $firstLine = strtok($raw, "\r\n");
        self::assertNotFalse($firstLine);

        $expected = '"' . implode('","', RosterMember::HEADERS) . '"';
        self::assertSame($expected, $firstLine);
    }

    public function testEveryEventCarriesRosterIdAndTeamSnapshot(): void
    {
        $m = $this->repo->add(['name' => 'Snap', 'teamId' => 'team-x']);
        $this->repo->update($m->id, ['name' => 'Snap2']);
        $this->repo->deactivate($m->id);

        foreach ($this->readEvents() as $e) {
            self::assertSame($m->id, $e['roster_id']);
            self::assertSame('team-x', $e['team_id_snapshot']);
        }
    }
}
