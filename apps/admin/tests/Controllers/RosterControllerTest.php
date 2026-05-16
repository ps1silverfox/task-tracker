<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Admin\Controllers\RosterController;
use TaskTracker\Config\Env;
use TaskTracker\Repositories\RosterRepository;

#[CoversClass(RosterController::class)]
final class RosterControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-rosterctl-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://127.0.0.1:8084',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testPostRosterCreatesRowAndRedirectsWith303(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/roster')
            ->withParsedBody(['name' => 'Alice', 'email' => 'alice@example.com']);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode(), 'PRG: POST /roster must redirect via 303 See Other.');
        self::assertSame('/roster', $response->getHeaderLine('Location'));

        $csv = (string) file_get_contents($this->env->dataDir . DIRECTORY_SEPARATOR . 'roster.csv');
        self::assertStringContainsString('Alice', $csv);
        self::assertStringContainsString('alice@example.com', $csv);
    }

    public function testPostRosterWritesCorroborativeNdjsonEvent(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/roster')
            ->withParsedBody(['name' => 'Audit Subject']);

        $this->app->handle($request);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty(
            $ndjsonFiles,
            'Two-write audit invariant: every state-changing admin op must append an NDJSON event.',
        );

        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('roster.added', $log);
        self::assertStringContainsString('Audit Subject', $log);
    }

    public function testPostRosterReturns422OnMissingName(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/roster')
            ->withParsedBody([]);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testPostRosterByIdUpdatesAndRedirects(): void
    {
        /** @var RosterRepository $repo */
        $repo = $this->app->getContainer()->get(RosterRepository::class);
        $member = $repo->add(['name' => 'Bob', 'email' => 'bob@example.com']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/roster/' . $member->id)
            ->withParsedBody(['email' => 'bob+new@example.com']);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/roster', $response->getHeaderLine('Location'));

        $after = $repo->find($member->id);
        self::assertNotNull($after);
        self::assertSame('Bob', $after->name);
        self::assertSame('bob+new@example.com', $after->email);
    }

    public function testPostRosterByIdReturns404WhenMissing(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/roster/0192f000-0000-7000-8000-000000000000')
            ->withParsedBody(['name' => 'Whoever']);

        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
    }

    public function testPostRosterByIdReturns422OnEmptyName(): void
    {
        /** @var RosterRepository $repo */
        $repo = $this->app->getContainer()->get(RosterRepository::class);
        $member = $repo->add(['name' => 'Carol']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/roster/' . $member->id)
            ->withParsedBody(['name' => '']);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testDeleteRosterByIdDeactivates(): void
    {
        /** @var RosterRepository $repo */
        $repo = $this->app->getContainer()->get(RosterRepository::class);
        $member = $repo->add(['name' => 'Dave']);

        $request  = (new ServerRequestFactory())->createServerRequest('DELETE', '/roster/' . $member->id);
        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/roster', $response->getHeaderLine('Location'));

        $after = $repo->find($member->id);
        self::assertNotNull($after);
        self::assertFalse($after->active);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty($ndjsonFiles);
        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('roster.deactivated', $log);
    }

    public function testDeleteRosterByIdReturns404WhenMissing(): void
    {
        $request  = (new ServerRequestFactory())->createServerRequest('DELETE', '/roster/0192f000-0000-7000-8000-000000000000');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
    }

    public function testGetRosterListsMembers(): void
    {
        /** @var RosterRepository $repo */
        $repo = $this->app->getContainer()->get(RosterRepository::class);
        $live = $repo->add(['name' => 'Eve']);
        $gone = $repo->add(['name' => 'Frank']);
        $repo->deactivate($gone->id);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/roster');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Eve', $body);
        self::assertStringContainsString('Frank', $body);
        self::assertStringContainsString($live->id, $body);
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
