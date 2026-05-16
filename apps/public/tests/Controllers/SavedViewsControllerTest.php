<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Public\Controllers\SavedViewsController;
use TaskTracker\Repositories\SavedViewRepository;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(SavedViewsController::class)]
final class SavedViewsControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-views-' . bin2hex(random_bytes(6));
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

    public function testMissingViewReturns404(): void
    {
        $response = $this->get('/views/00000000-0000-0000-0000-000000000000');
        self::assertSame(404, $response->getStatusCode());
    }

    public function testEmptyFilterRedirectsToBareRoot(): void
    {
        $view = $this->viewsRepo()->create(['name' => 'all', 'filter' => []]);

        $response = $this->get('/views/' . $view->id);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));
    }

    public function testStatusAndPriorityListsSerializeAsCommaJoined(): void
    {
        $view = $this->viewsRepo()->create([
            'name'   => 'hot-work',
            'filter' => [
                'status'   => ['open', 'in_progress'],
                'priority' => ['high', 'critical'],
            ],
        ]);

        $location = $this->get('/views/' . $view->id)->getHeaderLine('Location');
        $params   = self::queryParamsOf($location);

        self::assertSame('open,in_progress', $params['status']);
        self::assertSame('high,critical',    $params['priority']);
    }

    public function testTagsListSerializesAsCommaJoined(): void
    {
        $view = $this->viewsRepo()->create([
            'name'   => 'tagged',
            'filter' => ['tags' => ['urgent', 'bug']],
        ]);

        $params = self::queryParamsOf($this->get('/views/' . $view->id)->getHeaderLine('Location'));
        self::assertSame('urgent,bug', $params['tags']);
    }

    public function testTriStateAxesEmitValueOrNullLiteral(): void
    {
        $view = $this->viewsRepo()->create([
            'name'   => 'mine-orphans',
            'filter' => [
                'team_id'     => 'team-uuid-A',
                'assignee_id' => null,
                'parent_id'   => null,
            ],
        ]);

        $params = self::queryParamsOf($this->get('/views/' . $view->id)->getHeaderLine('Location'));

        self::assertSame('team-uuid-A', $params['team_id']);
        self::assertSame('null',        $params['assignee_id']);
        self::assertSame('null',        $params['parent_id']);
    }

    public function testDueWithinDaysAndIncludeDoneEmitWhenPresent(): void
    {
        $view = $this->viewsRepo()->create([
            'name'   => 'soon-and-done',
            'filter' => [
                'due_within_days' => 7,
                'include_done'    => true,
            ],
        ]);

        $params = self::queryParamsOf($this->get('/views/' . $view->id)->getHeaderLine('Location'));
        self::assertSame('7', $params['due_within_days']);
        self::assertSame('1', $params['include_done']);
    }

    public function testIncludeDoneFalseIsOmittedSoBacklogDefaultRules(): void
    {
        $view = $this->viewsRepo()->create([
            'name'   => 'explicit-false',
            'filter' => ['include_done' => false],
        ]);

        $location = $this->get('/views/' . $view->id)->getHeaderLine('Location');
        self::assertStringNotContainsString('include_done', $location);
    }

    public function testUnknownKeysAreDroppedFromRedirectQuery(): void
    {
        $view = $this->viewsRepo()->create([
            'name'   => 'has-garbage',
            'filter' => [
                'status'  => ['open'],
                'evil'    => 'arbitrary',
                'attack'  => '<script>',
            ],
        ]);

        $location = $this->get('/views/' . $view->id)->getHeaderLine('Location');
        self::assertStringNotContainsString('evil',   $location);
        self::assertStringNotContainsString('attack', $location);
        self::assertStringNotContainsString('script', $location);
    }

    public function testFollowingRedirectAppliesFilterEndToEnd(): void
    {
        $tasks = $this->tasksRepo();
        $tasks->create(['slug' => 'a', 'title' => 'Open Task',   'status' => Enums::STATUS_OPEN]);
        $tasks->create(['slug' => 'b', 'title' => 'Blocked Task','status' => Enums::STATUS_BLOCKED]);

        $view = $this->viewsRepo()->create([
            'name'   => 'open-only',
            'filter' => ['status' => ['open']],
        ]);

        $first = $this->get('/views/' . $view->id);
        self::assertSame(302, $first->getStatusCode());

        $followed = $this->get($first->getHeaderLine('Location'));
        self::assertSame(200, $followed->getStatusCode());
        $body = (string) $followed->getBody();
        self::assertStringContainsString('Open Task',          $body);
        self::assertStringNotContainsString('Blocked Task',    $body);
    }

    public function testNonGetMethodsOnViewsRouteReturn405(): void
    {
        $view = $this->viewsRepo()->create(['name' => 'rejection', 'filter' => []]);

        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request  = (new ServerRequestFactory())->createServerRequest($method, '/views/' . $view->id);
            $response = $this->app->handle($request);
            self::assertSame(
                405,
                $response->getStatusCode(),
                "Public app must reject {$method} /views/{id} with 405 (bind-level separation).",
            );
        }
    }

    private function viewsRepo(): SavedViewRepository
    {
        /** @var SavedViewRepository $repo */
        $repo = $this->app->getContainer()->get(SavedViewRepository::class);
        return $repo;
    }

    private function tasksRepo(): TaskRepository
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        return $repo;
    }

    private function get(string $path): ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $uri = parse_url($path);
        if (isset($uri['query'])) {
            parse_str($uri['query'], $params);
            $req = $req->withQueryParams($params);
        }
        return $this->app->handle($req);
    }

    /**
     * @return array<string, string>
     */
    private static function queryParamsOf(string $location): array
    {
        $qs = parse_url($location, PHP_URL_QUERY);
        if (!is_string($qs) || $qs === '') {
            return [];
        }
        $out = [];
        parse_str($qs, $out);
        /** @var array<string, string> $out */
        return $out;
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
