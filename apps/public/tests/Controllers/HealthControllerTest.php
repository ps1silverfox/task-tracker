<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Public\Controllers\HealthController;

#[CoversClass(HealthController::class)]
#[CoversClass(Bootstrap::class)]
final class HealthControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-healthctl-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://example.test',
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
            'Public health probe must not perform any storage I/O.',
        );

        $logs = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertSame([], $logs, 'Public health probe must not emit NDJSON events.');
    }

    public function testUnknownRouteReturnsJsonNotFound(): void
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/definitely-not-a-public-route');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
        self::assertIsString($payload['message'] ?? null);
        self::assertStringContainsString('/definitely-not-a-public-route', $payload['message']);
    }

    public function testHealthRouteRejectsNonGetMethods(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $request  = (new ServerRequestFactory())->createServerRequest($method, '/health');
            $response = $this->app->handle($request);
            self::assertSame(
                405,
                $response->getStatusCode(),
                "Public /health must return 405 on {$method} (read-only invariant).",
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
