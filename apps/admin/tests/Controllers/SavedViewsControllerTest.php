<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Admin\Controllers\SavedViewsController;
use TaskTracker\Config\Env;
use TaskTracker\Repositories\SavedViewRepository;

#[CoversClass(SavedViewsController::class)]
final class SavedViewsControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-savedviewsctl-' . bin2hex(random_bytes(6));
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

    public function testPostSavedViewsCreatesRowAndRedirectsWith303(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/saved-views')
            ->withParsedBody([
                'name' => 'My Open Tasks',
                'filter' => ['status' => ['open', 'in_progress']],
            ]);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode(), 'PRG: POST /saved-views must redirect via 303 See Other.');
        self::assertSame('/saved-views', $response->getHeaderLine('Location'));

        $csv = (string) file_get_contents($this->env->dataDir . DIRECTORY_SEPARATOR . 'saved_views.csv');
        self::assertStringContainsString('My Open Tasks', $csv);

        // FILTER_JSON column stores the JSON-encoded filter blob; verify via the
        // repository to avoid coupling the assertion to RFC 4180 quote-doubling.
        /** @var SavedViewRepository $repo */
        $repo = $this->app->getContainer()->get(SavedViewRepository::class);
        $persisted = $repo->findByName('My Open Tasks');
        self::assertNotNull($persisted);
        self::assertSame(['status' => ['open', 'in_progress']], $persisted->filter);
    }

    public function testPostSavedViewsWritesCorroborativeNdjsonEvent(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/saved-views')
            ->withParsedBody(['name' => 'Audit View']);

        $this->app->handle($request);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty(
            $ndjsonFiles,
            'Two-write audit invariant: every state-changing admin op must append an NDJSON event.',
        );

        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('saved_view.created', $log);
        self::assertStringContainsString('Audit View', $log);
    }

    public function testPostSavedViewsReturns422OnMissingName(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/saved-views')
            ->withParsedBody([]);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testPostSavedViewsReturns422OnDuplicateName(): void
    {
        /** @var SavedViewRepository $repo */
        $repo = $this->app->getContainer()->get(SavedViewRepository::class);
        $repo->create(['name' => 'Shared View']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/saved-views')
            ->withParsedBody(['name' => 'Shared View']);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('conflict', $payload['error'] ?? null);
    }

    public function testPostSavedViewsReturns422WhenFilterIsNotArray(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/saved-views')
            ->withParsedBody([
                'name' => 'Bad Filter',
                'filter' => 'not-an-array',
            ]);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
    }

    public function testDeleteSavedViewRemovesAndRedirects(): void
    {
        /** @var SavedViewRepository $repo */
        $repo = $this->app->getContainer()->get(SavedViewRepository::class);
        $view = $repo->create(['name' => 'Ephemeral']);

        $request  = (new ServerRequestFactory())->createServerRequest('DELETE', '/saved-views/' . $view->id);
        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/saved-views', $response->getHeaderLine('Location'));
        self::assertNull($repo->find($view->id));

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty($ndjsonFiles);
        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('saved_view.deleted', $log);
        self::assertStringContainsString('Ephemeral', $log);
    }

    public function testDeleteSavedViewReturns404WhenMissing(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/saved-views/0192f000-0000-7000-8000-000000000000');

        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
    }

    public function testGetSavedViewsListsViews(): void
    {
        /** @var SavedViewRepository $repo */
        $repo = $this->app->getContainer()->get(SavedViewRepository::class);
        $alpha = $repo->create(['name' => 'Alpha View', 'filter' => ['priority' => ['high']]]);
        $beta  = $repo->create(['name' => 'Beta View']);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/saved-views');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Alpha View', $body);
        self::assertStringContainsString('Beta View', $body);
        self::assertStringContainsString($alpha->id, $body);
        self::assertStringContainsString($beta->id, $body);
        // The filter blob is rendered for operator inspection.
        self::assertStringContainsString('&quot;priority&quot;', $body);
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
