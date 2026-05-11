<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Invariants;

use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Public\Bootstrap;

/**
 * PUB-09 — Public-immutability invariant.
 *
 * Three layers, deliberately redundant:
 *   1. Every route registered on the public Slim app declares ONLY the GET
 *      method (introspection via Slim's RouteCollector — catches future
 *      additions automatically).
 *   2. POST/PUT/PATCH/DELETE against every concrete materialised path returns
 *      HTTP 405 (runtime check).
 *   3. The routes.php source contains zero $app->post|put|patch|delete( calls
 *      (static code-presence check — fails even if the dynamic wiring is
 *      buggy enough to swallow the violation at runtime).
 *
 * Together these enforce the bind-level + code-presence separation invariant
 * from the spec (§6).
 */
final class ReadOnlyTest extends TestCase
{
    private const NON_GET_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-readonly-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://localhost',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testRouteCollectorReportsAtLeastOneRoute(): void
    {
        self::assertNotEmpty(
            $this->routes(),
            'Public app must register at least one route — empty router signals a wiring regression.',
        );
    }

    public function testEveryRegisteredRouteDeclaresOnlyGet(): void
    {
        foreach ($this->routes() as $route) {
            self::assertSame(
                ['GET'],
                $route->getMethods(),
                sprintf(
                    'Route %s declares non-GET methods (%s). Public app must register GET routes only.',
                    $route->getPattern(),
                    implode(',', $route->getMethods()),
                ),
            );
        }
    }

    public function testNonGetMethodsAgainstEveryRouteReturn405(): void
    {
        $factory = new ServerRequestFactory();

        foreach ($this->routes() as $route) {
            $path = $this->materialisePath($route->getPattern());

            foreach (self::NON_GET_METHODS as $method) {
                $response = $this->app->handle($factory->createServerRequest($method, $path));
                self::assertSame(
                    405,
                    $response->getStatusCode(),
                    sprintf(
                        'Route %s must reject %s with HTTP 405 (got %d) — bind-level separation.',
                        $path,
                        $method,
                        $response->getStatusCode(),
                    ),
                );
            }
        }
    }

    public function testRoutesFileContainsZeroWriteVerbRegistrations(): void
    {
        $routesPath = __DIR__ . '/../../src/routes.php';
        $contents   = file_get_contents($routesPath);
        self::assertIsString($contents, "Cannot read {$routesPath}.");

        foreach (['post', 'put', 'patch', 'delete'] as $verb) {
            self::assertDoesNotMatchRegularExpression(
                '/\$app\s*->\s*' . $verb . '\s*\(/i',
                $contents,
                sprintf(
                    'routes.php registers $app->%s(...) — write routes are forbidden on the public app.',
                    $verb,
                ),
            );
        }
    }

    /**
     * @return list<RouteInterface>
     */
    private function routes(): array
    {
        return array_values($this->app->getRouteCollector()->getRoutes());
    }

    /**
     * Replace each {placeholder} in a Slim pattern with a concrete path
     * segment so the router resolves the route before evaluating the method.
     */
    private function materialisePath(string $pattern): string
    {
        $resolved = preg_replace('/\{[^}]+\}/', 'x', $pattern);
        return is_string($resolved) ? $resolved : $pattern;
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
