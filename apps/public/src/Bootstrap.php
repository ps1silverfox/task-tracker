<?php

declare(strict_types=1);

namespace TaskTracker\Public;

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
use Throwable;
use TaskTracker\Aggregations\DependencyGraph;
use TaskTracker\Aggregations\ExecutiveSummary;
use TaskTracker\Aggregations\SubProjectRollup;
use TaskTracker\Config\Env;
use TaskTracker\Public\Controllers\BacklogController;
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
 * Public Slim app bootstrap (PUB-01).
 *
 * Read-only DI: wires the same lib/ primitives as the admin app against the
 * shared DATA_DIR / LOG_DIR, but registers ZERO controllers in this container.
 * Future read-only controllers (PUB-02..PUB-08) will be added by their tasks.
 *
 * EventLog is still wired so that repositories can be constructed (their
 * constructors require it). The bind-level + code-presence separation
 * invariants (spec §6) hold because no write controller exists on this app
 * to invoke any mutating repository method.
 */
final class Bootstrap
{
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
        return self::container(self::factories($env));
    }

    public static function boot(Env $env, bool $displayErrors = false): App
    {
        $container = self::buildContainer($env);
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $errorMiddleware = $app->addErrorMiddleware($displayErrors, true, true);

        // Non-static: Slim's CallableResolver rebinds error handlers via
        // Closure::bind(), which silently returns null for static closures.
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
            BacklogController::class => static fn(ContainerInterface $c): BacklogController => new BacklogController(
                $c->get(TaskRepository::class),
                $c->get(TagRepository::class),
            ),
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
