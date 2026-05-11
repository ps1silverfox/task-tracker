<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Admin\Controllers\TeamsController;
use TaskTracker\Config\Env;
use TaskTracker\Repositories\TeamRepository;

#[CoversClass(TeamsController::class)]
final class TeamsControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-teamsctl-' . bin2hex(random_bytes(6));
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

    public function testPostTeamsCreatesRowAndRedirectsWith303(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/teams')
            ->withParsedBody(['name' => 'Platform', 'description' => 'Owns shared infra']);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode(), 'PRG: POST /teams must redirect via 303 See Other.');
        self::assertSame('/teams', $response->getHeaderLine('Location'));

        $csv = (string) file_get_contents($this->env->dataDir . DIRECTORY_SEPARATOR . 'teams.csv');
        self::assertStringContainsString('Platform', $csv);
        self::assertStringContainsString('Owns shared infra', $csv);
    }

    public function testPostTeamsWritesCorroborativeNdjsonEvent(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/teams')
            ->withParsedBody(['name' => 'Audit Squad']);

        $this->app->handle($request);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty(
            $ndjsonFiles,
            'Two-write audit invariant: every state-changing admin op must append an NDJSON event.',
        );

        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('team.created', $log);
        self::assertStringContainsString('Audit Squad', $log);
    }

    public function testPostTeamsReturns422OnMissingName(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/teams')
            ->withParsedBody([]);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testPostTeamsByIdUpdatesAndRedirects(): void
    {
        /** @var TeamRepository $repo */
        $repo = $this->app->getContainer()->get(TeamRepository::class);
        $team = $repo->create(['name' => 'Data', 'description' => 'orig']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/teams/' . $team->id)
            ->withParsedBody(['description' => 'updated']);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/teams', $response->getHeaderLine('Location'));

        $after = $repo->find($team->id);
        self::assertNotNull($after);
        self::assertSame('Data', $after->name);
        self::assertSame('updated', $after->description);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty($ndjsonFiles);
        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('team.updated', $log);
    }

    public function testPostTeamsByIdReturns404WhenMissing(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/teams/0192f000-0000-7000-8000-000000000000')
            ->withParsedBody(['name' => 'Phantom']);

        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
    }

    public function testPostTeamsByIdReturns422OnEmptyName(): void
    {
        /** @var TeamRepository $repo */
        $repo = $this->app->getContainer()->get(TeamRepository::class);
        $team = $repo->create(['name' => 'Infra']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/teams/' . $team->id)
            ->withParsedBody(['name' => '']);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testGetTeamsListsTeams(): void
    {
        /** @var TeamRepository $repo */
        $repo = $this->app->getContainer()->get(TeamRepository::class);
        $alpha = $repo->create(['name' => 'Alpha']);
        $beta  = $repo->create(['name' => 'Beta', 'description' => 'beta desc']);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/teams');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Alpha', $body);
        self::assertStringContainsString('Beta', $body);
        self::assertStringContainsString('beta desc', $body);
        self::assertStringContainsString($alpha->id, $body);
        self::assertStringContainsString($beta->id, $body);
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
