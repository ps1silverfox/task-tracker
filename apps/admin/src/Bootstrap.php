<?php

declare(strict_types=1);

namespace TaskTracker\Admin;

use Closure;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;
use Slim\App;
use Slim\Exception\HttpNotFoundException;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ResponseFactory;
use TaskTracker\Admin\Controllers\HealthController;
use TaskTracker\Admin\Controllers\RosterController;
use TaskTracker\Admin\Controllers\SavedViewsController;
use TaskTracker\Admin\Controllers\TasksController;
use TaskTracker\Admin\Controllers\TeamsController;
use Throwable;
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

/**
 * Admin Slim app bootstrap (ADMIN-01).
 *
 * Builds a minimal PSR-11 container that wires the lib/ primitives — CsvStore,
 * EventLog, the seven repositories, the three aggregations — to the
 * canonical CSV filenames from spec §5 under the configured DATA_DIR.
 *
 * Service IDs are the fully-qualified class names; this lets Slim 4 resolve
 * controller-constructor type hints from the container with no extra mapping
 * once the controllers in ADMIN-02..ADMIN-08 are registered.
 */
final class Bootstrap
{
    /** Canonical CSV filenames under DATA_DIR (spec §5). */
    private const CSV_FILES = [
        TaskRepository::class       => 'tasks.csv',
        RosterRepository::class     => 'roster.csv',
        TeamRepository::class       => 'teams.csv',
        DependencyRepository::class => 'dependencies.csv',
        TagRepository::class        => 'tags.csv',
        SavedViewRepository::class  => 'saved_views.csv',
    ];

    public static function buildContainer(Env $env): ContainerInterface
    {
        $factories = self::factories($env);
        return self::container($factories);
    }

    public static function boot(Env $env, bool $displayErrors = false): App
    {
        $container = self::buildContainer($env);
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // Order matters: BodyParsing (innermost) → Routing → Error (outermost).
        // BodyParsing must precede Routing so route handlers see a parsed array
        // for form-urlencoded / JSON POSTs; Error wraps everything so handler
        // throws and Slim's HttpNotFoundException surface uniformly.
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

        // ADMIN-08: emit JSON 404s in the same shape repository-level "not found"
        // already uses ({"error": "not_found", "message": ...}); Slim's HTML
        // default doesn't match the rest of the admin surface.
        // Closure intentionally NOT `static`: Slim's CallableResolver rebinds
        // error handlers to the container via Closure::bind(), which silently
        // returns null for static closures and trips a TypeError downstream.
        $errorMiddleware->setErrorHandler(
            HttpNotFoundException::class,
            function (
                ServerRequestInterface $request,
                Throwable $exception,
                bool $displayErrorDetails,
                bool $logErrors,
                bool $logErrorDetails,
            ): ResponseInterface {
                $payload = json_encode(
                    [
                        'error'   => 'not_found',
                        'message' => sprintf(
                            'route not found: %s %s',
                            $request->getMethod(),
                            $request->getUri()->getPath(),
                        ),
                    ],
                    JSON_THROW_ON_ERROR,
                );
                $response = (new ResponseFactory())->createResponse(404);
                $response->getBody()->write($payload);
                return $response->withHeader('Content-Type', 'application/json; charset=utf-8');
            },
        );

        (require __DIR__ . '/routes.php')($app);

        return $app;
    }

    /**
     * @return array<string, Closure(ContainerInterface): object>
     */
    private static function factories(Env $env): array
    {
        $csv = static fn(ContainerInterface $c, string $name): string =>
            $env->dataDir . DIRECTORY_SEPARATOR . self::CSV_FILES[$name];

        return [
            Env::class             => static fn(): Env => $env,
            CsvStore::class        => static fn(): CsvStore => new CsvStore(),
            EventLog::class        => static fn(): EventLog => new EventLog($env->logDir),
            EventRepository::class => static fn(): EventRepository => new EventRepository($env->logDir),

            TaskRepository::class => static fn(ContainerInterface $c): TaskRepository => new TaskRepository(
                $c->get(CsvStore::class),
                $c->get(EventLog::class),
                $csv($c, TaskRepository::class),
            ),
            RosterRepository::class => static fn(ContainerInterface $c): RosterRepository => new RosterRepository(
                $c->get(CsvStore::class),
                $c->get(EventLog::class),
                $csv($c, RosterRepository::class),
            ),
            TeamRepository::class => static fn(ContainerInterface $c): TeamRepository => new TeamRepository(
                $c->get(CsvStore::class),
                $c->get(EventLog::class),
                $csv($c, TeamRepository::class),
            ),
            DependencyRepository::class => static fn(ContainerInterface $c): DependencyRepository => new DependencyRepository(
                $c->get(CsvStore::class),
                $c->get(EventLog::class),
                $csv($c, DependencyRepository::class),
            ),
            TagRepository::class => static fn(ContainerInterface $c): TagRepository => new TagRepository(
                $c->get(CsvStore::class),
                $c->get(EventLog::class),
                $csv($c, TagRepository::class),
            ),
            SavedViewRepository::class => static fn(ContainerInterface $c): SavedViewRepository => new SavedViewRepository(
                $c->get(CsvStore::class),
                $c->get(EventLog::class),
                $csv($c, SavedViewRepository::class),
            ),

            SubProjectRollup::class => static fn(ContainerInterface $c): SubProjectRollup
                => new SubProjectRollup($c->get(TaskRepository::class)),
            ExecutiveSummary::class => static fn(ContainerInterface $c): ExecutiveSummary
                => new ExecutiveSummary($c->get(EventRepository::class)),
            DependencyGraph::class => static fn(ContainerInterface $c): DependencyGraph => new DependencyGraph(
                $c->get(TaskRepository::class),
                $c->get(DependencyRepository::class),
            ),

            // Controllers — registered so Slim's CallableResolver can hydrate
            // [Class::class, 'method'] route handlers via $container->get($id).
            TasksController::class => static fn(ContainerInterface $c): TasksController => new TasksController(
                $c->get(TaskRepository::class),
                $c->get(DependencyRepository::class),
                $c->get(TagRepository::class),
            ),
            RosterController::class => static fn(ContainerInterface $c): RosterController => new RosterController(
                $c->get(RosterRepository::class),
            ),
            TeamsController::class => static fn(ContainerInterface $c): TeamsController => new TeamsController(
                $c->get(TeamRepository::class),
            ),
            SavedViewsController::class => static fn(ContainerInterface $c): SavedViewsController => new SavedViewsController(
                $c->get(SavedViewRepository::class),
            ),
            HealthController::class => static fn(): HealthController => new HealthController(),
        ];
    }

    /**
     * @param array<string, Closure(ContainerInterface): object> $factories
     */
    private static function container(array $factories): ContainerInterface
    {
        return new class($factories) implements ContainerInterface {
            /** @var array<string, Closure(ContainerInterface): object> */
            private array $factories;
            /** @var array<string, object> */
            private array $cache = [];

            /** @param array<string, Closure(ContainerInterface): object> $factories */
            public function __construct(array $factories)
            {
                $this->factories = $factories;
            }

            public function has(string $id): bool
            {
                return isset($this->factories[$id]);
            }

            public function get(string $id): object
            {
                if (array_key_exists($id, $this->cache)) {
                    return $this->cache[$id];
                }
                if (!isset($this->factories[$id])) {
                    throw new class("service not found: {$id}")
                        extends RuntimeException
                        implements NotFoundExceptionInterface {};
                }
                return $this->cache[$id] = ($this->factories[$id])($this);
            }
        };
    }
}
