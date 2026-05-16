<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Aggregations\DependencyGraph;
use TaskTracker\Aggregations\ExecutiveSummary;
use TaskTracker\Aggregations\SubProjectRollup;
use TaskTracker\Config\Env;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\EventRepository;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\SavedViewRepository;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Repositories\TeamRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

#[CoversClass(Bootstrap::class)]
final class BootstrapTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-boot-' . bin2hex(random_bytes(6));
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
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testBuildContainerReturnsPsr11Container(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        self::assertInstanceOf(ContainerInterface::class, $c);
    }

    public function testContainerExposesEnvAndStoragePrimitives(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        self::assertSame($this->env, $c->get(Env::class));
        self::assertInstanceOf(CsvStore::class, $c->get(CsvStore::class));
        self::assertInstanceOf(EventLog::class, $c->get(EventLog::class));
    }

    public function testContainerResolvesEveryRepository(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        self::assertInstanceOf(TaskRepository::class,       $c->get(TaskRepository::class));
        self::assertInstanceOf(RosterRepository::class,     $c->get(RosterRepository::class));
        self::assertInstanceOf(TeamRepository::class,       $c->get(TeamRepository::class));
        self::assertInstanceOf(DependencyRepository::class, $c->get(DependencyRepository::class));
        self::assertInstanceOf(TagRepository::class,        $c->get(TagRepository::class));
        self::assertInstanceOf(SavedViewRepository::class,  $c->get(SavedViewRepository::class));
        self::assertInstanceOf(EventRepository::class,      $c->get(EventRepository::class));
    }

    public function testContainerResolvesEveryAggregation(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        self::assertInstanceOf(SubProjectRollup::class, $c->get(SubProjectRollup::class));
        self::assertInstanceOf(ExecutiveSummary::class, $c->get(ExecutiveSummary::class));
        self::assertInstanceOf(DependencyGraph::class,  $c->get(DependencyGraph::class));
    }

    public function testContainerReturnsSingletonsAcrossCalls(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        self::assertSame($c->get(TaskRepository::class), $c->get(TaskRepository::class));
        self::assertSame($c->get(CsvStore::class),       $c->get(CsvStore::class));
        self::assertSame($c->get(EventLog::class),       $c->get(EventLog::class));
    }

    public function testContainerHasFalseAndThrowsForUnknownService(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        self::assertFalse($c->has('NoSuchService'));

        $this->expectException(NotFoundExceptionInterface::class);
        $c->get('NoSuchService');
    }

    public function testBootReturnsSlimAppWithContainer(): void
    {
        $app = Bootstrap::boot($this->env);
        self::assertInstanceOf(App::class, $app);

        $container = $app->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $container);
        self::assertInstanceOf(TaskRepository::class, $container->get(TaskRepository::class));
    }

    public function testBootErrorMiddlewareDoesNotLeakExceptionDetails(): void
    {
        $app = Bootstrap::boot($this->env, displayErrors: false);
        $app->get('/__boom', function (): void {
            throw new RuntimeException('kaboom-secret-marker');
        });

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/__boom');
        $response = $app->handle($request);

        self::assertSame(500, $response->getStatusCode());
        self::assertStringNotContainsString(
            'kaboom-secret-marker',
            (string) $response->getBody(),
            'displayErrors=false must not leak the exception message in the response body.',
        );
    }

    public function testBootReturns404ForUnknownRoute(): void
    {
        $app = Bootstrap::boot($this->env);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/no-such-route');
        $response = $app->handle($request);
        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Invariant: the public container must NOT advertise any admin-side
     * controller class. This is a code-presence check on the service map —
     * a defensive companion to the route-level 405 invariant (PUB-09).
     */
    public function testContainerDoesNotExposeAdminControllers(): void
    {
        $c = Bootstrap::buildContainer($this->env);
        foreach (
            [
                'TaskTracker\\Admin\\Controllers\\TasksController',
                'TaskTracker\\Admin\\Controllers\\RosterController',
                'TaskTracker\\Admin\\Controllers\\TeamsController',
                'TaskTracker\\Admin\\Controllers\\SavedViewsController',
                'TaskTracker\\Admin\\Controllers\\HealthController',
            ] as $adminController
        ) {
            self::assertFalse(
                $c->has($adminController),
                sprintf(
                    'Public container must not expose admin controller %s (bind-level separation).',
                    $adminController,
                ),
            );
        }
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
