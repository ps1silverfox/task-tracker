<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Admin\Controllers\HealthController;
use TaskTracker\Config\Env;

#[CoversClass(HealthController::class)]
#[CoversClass(Bootstrap::class)]
final class HealthControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-healthctl-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://127.0.0.1:8080',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testGetHealthReturnsOk(): void
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/health');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', (string) $response->getBody());
        self::assertStringStartsWith('text/plain', $response->getHeaderLine('Content-Type'));
    }

    public function testGetHealthDoesNotTouchDataDirectory(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/health');
        $this->app->handle($request);

        $entries = array_values(array_diff(
            scandir($this->env->dataDir) ?: [],
            ['.', '..'],
        ));
        self::assertSame(
            [],
            $entries,
            'Health probe must not perform any storage I/O — it would couple liveness to disk health.',
        );

        $logs = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertSame([], $logs, 'Health probe must not write NDJSON events (GET, non-state-changing).');
    }

    public function testUnknownRouteReturnsJsonNotFound(): void
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/definitely-not-a-route');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
        self::assertIsString($payload['message'] ?? null);
        self::assertStringContainsString('/definitely-not-a-route', $payload['message']);
        self::assertStringContainsString('GET', $payload['message']);
    }

    public function testUnknownNestedRouteAlsoReturnsJsonNotFound(): void
    {
        // The handler must match HttpNotFoundException regardless of depth — a
        // nested path that resembles a real resource shouldn't fall through to
        // Slim's default HTML error page.
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/tasks/no-such-id/nope');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
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
