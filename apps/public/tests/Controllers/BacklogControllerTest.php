<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Controllers;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Public\Controllers\BacklogController;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(BacklogController::class)]
final class BacklogControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-backlog-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://localhost:8083',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testGetRootListsLiveTasksAsHtml(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'alpha', 'title' => 'Alpha Task']);
        $repo->create(['slug' => 'beta',  'title' => 'Beta Task']);

        $response = $this->get('/');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Alpha Task', $body);
        self::assertStringContainsString('Beta Task',  $body);
        self::assertStringContainsString('alpha',      $body);
        self::assertStringContainsString('beta',       $body);
    }

    public function testSoftDeletedTasksAreNeverShown(): void
    {
        $repo = $this->tasksRepo();
        $keep   = $repo->create(['slug' => 'keep',   'title' => 'Keep Me']);
        $erase  = $repo->create(['slug' => 'erase',  'title' => 'Erase Me']);
        $repo->softDelete($erase->id);

        $body = (string) $this->get('/')->getBody();

        self::assertStringContainsString('Keep Me',           $body);
        self::assertStringNotContainsString('Erase Me',       $body);
        self::assertStringContainsString($keep->id,           $body);
        self::assertStringNotContainsString($erase->id,       $body);
    }

    public function testDoneTasksAreHiddenByDefault(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'shipped', 'title' => 'Shipped Task', 'status' => Enums::STATUS_DONE]);
        $repo->create(['slug' => 'wip',     'title' => 'WIP Task',     'status' => Enums::STATUS_OPEN]);

        $body = (string) $this->get('/')->getBody();

        self::assertStringContainsString('WIP Task',            $body);
        self::assertStringNotContainsString('Shipped Task',     $body);
    }

    public function testIncludeDoneTruthyShowsDoneTasks(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'shipped', 'title' => 'Shipped Task', 'status' => Enums::STATUS_DONE]);
        $repo->create(['slug' => 'wip',     'title' => 'WIP Task',     'status' => Enums::STATUS_OPEN]);

        $body = (string) $this->get('/?include_done=1')->getBody();

        self::assertStringContainsString('Shipped Task', $body);
        self::assertStringContainsString('WIP Task',     $body);
    }

    public function testExplicitStatusFilterOverridesIncludeDoneDefault(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'shipped', 'title' => 'Shipped Task', 'status' => Enums::STATUS_DONE]);
        $repo->create(['slug' => 'wip',     'title' => 'WIP Task',     'status' => Enums::STATUS_OPEN]);

        // status=done explicitly requested → done shows even without include_done.
        $body = (string) $this->get('/?status=done')->getBody();
        self::assertStringContainsString('Shipped Task',        $body);
        self::assertStringNotContainsString('WIP Task',         $body);
    }

    public function testStatusFilterAcceptsCsvList(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'a', 'title' => 'A Open',     'status' => Enums::STATUS_OPEN]);
        $repo->create(['slug' => 'b', 'title' => 'B Blocked',  'status' => Enums::STATUS_BLOCKED]);
        $repo->create(['slug' => 'c', 'title' => 'C Progress', 'status' => Enums::STATUS_IN_PROGRESS]);

        $body = (string) $this->get('/?status=open,in_progress')->getBody();

        self::assertStringContainsString('A Open',         $body);
        self::assertStringContainsString('C Progress',     $body);
        self::assertStringNotContainsString('B Blocked',   $body);
    }

    public function testPriorityFilterAcceptsCsvList(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'lo', 'title' => 'Low One',  'priority' => Enums::PRIORITY_LOW]);
        $repo->create(['slug' => 'hi', 'title' => 'High One', 'priority' => Enums::PRIORITY_HIGH]);
        $repo->create(['slug' => 'cr', 'title' => 'Crit One', 'priority' => Enums::PRIORITY_CRITICAL]);

        $body = (string) $this->get('/?priority=high,critical')->getBody();

        self::assertStringContainsString('High One',        $body);
        self::assertStringContainsString('Crit One',        $body);
        self::assertStringNotContainsString('Low One',      $body);
    }

    public function testTeamIdFilterMatchesAndNullKeywordSelectsTeamless(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'teamA-1', 'title' => 'Team A Task', 'teamId' => 'team-A']);
        $repo->create(['slug' => 'teamB-1', 'title' => 'Team B Task', 'teamId' => 'team-B']);
        $repo->create(['slug' => 'loose-1', 'title' => 'Loose Task']);

        $bodyA = (string) $this->get('/?team_id=team-A')->getBody();
        self::assertStringContainsString('Team A Task',         $bodyA);
        self::assertStringNotContainsString('Team B Task',      $bodyA);
        self::assertStringNotContainsString('Loose Task',       $bodyA);

        $bodyNull = (string) $this->get('/?team_id=null')->getBody();
        self::assertStringContainsString('Loose Task',          $bodyNull);
        self::assertStringNotContainsString('Team A Task',      $bodyNull);
    }

    public function testAssigneeIdFilterAndNullMatchesUnassigned(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'mine',    'title' => 'Mine',     'assigneeId' => 'roster-1']);
        $repo->create(['slug' => 'theirs',  'title' => 'Theirs',   'assigneeId' => 'roster-2']);
        $repo->create(['slug' => 'orphan',  'title' => 'Orphan']);

        $bodyOne  = (string) $this->get('/?assignee_id=roster-1')->getBody();
        self::assertStringContainsString('Mine',                 $bodyOne);
        self::assertStringNotContainsString('Theirs',            $bodyOne);
        self::assertStringNotContainsString('Orphan',            $bodyOne);

        $bodyNull = (string) $this->get('/?assignee_id=null')->getBody();
        self::assertStringContainsString('Orphan',               $bodyNull);
        self::assertStringNotContainsString('Mine',              $bodyNull);
    }

    public function testParentIdFilterAndNullMatchesRootLevel(): void
    {
        $repo = $this->tasksRepo();
        $parent = $repo->create(['slug' => 'root',  'title' => 'Root Task']);
        $repo->create(['slug' => 'child', 'title' => 'Child Task', 'parentId' => $parent->id]);

        $bodyParent = (string) $this->get('/?parent_id=' . $parent->id)->getBody();
        self::assertStringContainsString('Child Task',          $bodyParent);
        self::assertStringNotContainsString('Root Task',        $bodyParent);

        $bodyNull = (string) $this->get('/?parent_id=null')->getBody();
        self::assertStringContainsString('Root Task',           $bodyNull);
        self::assertStringNotContainsString('Child Task',       $bodyNull);
    }

    public function testTagsFilterRequiresAllTagsOnTask(): void
    {
        $repo = $this->tasksRepo();
        $tags = $this->tagsRepo();

        $bothId = $repo->create(['slug' => 'both', 'title' => 'Has Both'])->id;
        $oneId  = $repo->create(['slug' => 'one',  'title' => 'Has One'])->id;
        $repo->create(['slug' => 'none', 'title' => 'Has None']);

        $tags->add($bothId, 'urgent');
        $tags->add($bothId, 'bug');
        $tags->add($oneId,  'urgent');

        $body = (string) $this->get('/?tags=urgent,bug')->getBody();

        self::assertStringContainsString('Has Both',        $body);
        self::assertStringNotContainsString('Has One',      $body);
        self::assertStringNotContainsString('Has None',     $body);
    }

    public function testDueWithinDaysFilterMatchesInWindowAndExcludesNullAndFarFuture(): void
    {
        $repo = $this->tasksRepo();
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $in3   = $today->add(new DateInterval('P3D'))->format('Y-m-d');
        $in30  = $today->add(new DateInterval('P30D'))->format('Y-m-d');

        $repo->create(['slug' => 'soon',  'title' => 'Due Soon',  'dueDate' => $in3]);
        $repo->create(['slug' => 'later', 'title' => 'Due Later', 'dueDate' => $in30]);
        $repo->create(['slug' => 'open',  'title' => 'No Due']);

        $body = (string) $this->get('/?due_within_days=7')->getBody();

        self::assertStringContainsString('Due Soon',        $body);
        self::assertStringNotContainsString('Due Later',    $body);
        self::assertStringNotContainsString('No Due',       $body);
    }

    public function testCombinedFiltersAreConjunctive(): void
    {
        $repo = $this->tasksRepo();
        $repo->create([
            'slug'     => 'match',
            'title'    => 'Match Me',
            'priority' => Enums::PRIORITY_HIGH,
            'teamId'   => 'team-A',
        ]);
        $repo->create([
            'slug'     => 'wrong-team',
            'title'    => 'Wrong Team',
            'priority' => Enums::PRIORITY_HIGH,
            'teamId'   => 'team-B',
        ]);
        $repo->create([
            'slug'     => 'wrong-prio',
            'title'    => 'Wrong Prio',
            'priority' => Enums::PRIORITY_LOW,
            'teamId'   => 'team-A',
        ]);

        $body = (string) $this->get('/?priority=high&team_id=team-A')->getBody();

        self::assertStringContainsString('Match Me',           $body);
        self::assertStringNotContainsString('Wrong Team',      $body);
        self::assertStringNotContainsString('Wrong Prio',      $body);
    }

    public function testInvalidDueWithinDaysIsIgnoredNotFatal(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'a', 'title' => 'Plain']);

        $response = $this->get('/?due_within_days=not-a-number');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Plain', (string) $response->getBody());
    }

    public function testNonGetMethodsOnRootReturn405(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request  = (new ServerRequestFactory())->createServerRequest($method, '/');
            $response = $this->app->handle($request);
            self::assertSame(
                405,
                $response->getStatusCode(),
                "Public app must reject {$method} / with 405 (bind-level separation).",
            );
        }
    }

    private function tasksRepo(): TaskRepository
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        return $repo;
    }

    private function tagsRepo(): TagRepository
    {
        /** @var TagRepository $repo */
        $repo = $this->app->getContainer()->get(TagRepository::class);
        return $repo;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        $uri  = parse_url($path);
        $req  = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if (isset($uri['query'])) {
            parse_str($uri['query'], $params);
            $req = $req->withQueryParams($params);
        }
        return $this->app->handle($req);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
