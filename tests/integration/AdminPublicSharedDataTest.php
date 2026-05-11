<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap as AdminBootstrap;
use TaskTracker\Config\Env;
use TaskTracker\Public\Bootstrap as PublicBootstrap;
use TaskTracker\Repositories\TaskRepository;

/**
 * INT-01 — admin write surfaces through the public read surface via shared
 * data/ + logs/. Boots both Slim apps in-process against one Env so a POST
 * to the admin app and a GET on the public app traverse the same CSV file
 * and NDJSON event log — the cross-bind contract from spec §6.
 *
 * No HTTP, no Apache: each test drives `$app->handle()` directly. This is
 * the canonical way to exercise the bind-level invariant in a unit-test-
 * shaped harness; the real two-bind topology is validated at deploy time
 * by `deploy/smoke.ps1` (INT-05).
 */
#[CoversNothing]
final class AdminPublicSharedDataTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $adminApp;
    private App $publicApp;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-int-shared-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;

        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://127.0.0.1',
            timezone: 'UTC',
        );

        // Order matters only insofar as AppFactory's static container is
        // last-set-wins; each Bootstrap::boot() rebinds it before create(),
        // and the returned App keeps its own container reference — so the
        // admin app stays wired to the admin container after we boot the
        // public app.
        $this->adminApp  = AdminBootstrap::boot($this->env);
        $this->publicApp = PublicBootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testAdminPostTaskAppearsInPublicBacklog(): void
    {
        $id = $this->adminCreateTask('shared-1', 'Shared backlog task');

        $body = (string) $this->get($this->publicApp, '/')->getBody();

        self::assertStringContainsString('shared-1',             $body);
        self::assertStringContainsString('Shared backlog task',  $body);
        self::assertStringContainsString($id,                    $body);
    }

    public function testAdminPostTaskIsReadableViaPublicDetail(): void
    {
        $id = $this->adminCreateTask('shared-2', 'Shared detail task');

        $response = $this->get($this->publicApp, '/tasks/' . $id);
        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        self::assertStringContainsString('Shared detail task', $body);
        self::assertStringContainsString($id,                  $body);
    }

    public function testAdminWriteSurfacesInPublicActivityFeed(): void
    {
        $id = $this->adminCreateTask('shared-3', 'Audited task');

        $body = (string) $this->get($this->publicApp, '/tasks/' . $id)->getBody();

        // PUB-03 renders <code>{action}</code> per event — task.created must
        // be reconstructable from the shared NDJSON the admin POST emitted.
        self::assertStringContainsString('<code>task.created</code>', $body);
    }

    public function testAdminUpdateIsReflectedInPublicDetail(): void
    {
        $id = $this->adminCreateTask('shared-4', 'Original title');

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/' . $id)
            ->withParsedBody(['title' => 'Updated title']);
        $update  = $this->adminApp->handle($request);
        self::assertSame(303, $update->getStatusCode(), 'admin update must PRG-redirect on success');

        $body = (string) $this->get($this->publicApp, '/tasks/' . $id)->getBody();

        // The <h1> and <title> elements reflect the current state — the past
        // title only survives inside the activity-feed event payload, which
        // is correct (immutable audit log per spec §8).
        self::assertMatchesRegularExpression('~<h1[^>]*>Updated title</h1>~', $body);
        self::assertMatchesRegularExpression('~<title>Updated title</title>~', $body);
    }

    public function testAdminSoftDeleteHidesTaskFromPublicSurface(): void
    {
        $id = $this->adminCreateTask('shared-5', 'Soft-deleted task');

        $request = (new ServerRequestFactory())
            ->createServerRequest('DELETE', '/tasks/' . $id);
        $delete  = $this->adminApp->handle($request);
        self::assertContains($delete->getStatusCode(), [200, 204, 303]);

        // Public list omits it.
        $list = (string) $this->get($this->publicApp, '/')->getBody();
        self::assertStringNotContainsString('Soft-deleted task', $list);

        // Public detail returns 404 (per PUB-03 — tombstones aren't shown).
        $detail = $this->get($this->publicApp, '/tasks/' . $id);
        self::assertSame(404, $detail->getStatusCode());
    }

    public function testBothAppsResolveTheSameTasksCsvPath(): void
    {
        // Bind-level separation does NOT mean storage separation: the two
        // containers must wire TaskRepository against the same data/tasks.csv.
        $this->adminCreateTask('shared-6', 'Shared file check');

        /** @var TaskRepository $publicRepo */
        $publicRepo = $this->publicApp->getContainer()->get(TaskRepository::class);
        $hits = array_filter(
            $publicRepo->listAll(),
            static fn($t): bool => $t->slug === 'shared-6',
        );
        self::assertCount(1, $hits, 'public app must see the row the admin app just wrote');

        // Filesystem cross-check: the admin POST must have created tasks.csv
        // under the shared DATA_DIR, and the NDJSON event must live under the
        // shared LOG_DIR.
        self::assertFileExists($this->env->dataDir . DIRECTORY_SEPARATOR . 'tasks.csv');
        self::assertNotEmpty(
            glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [],
            'shared LOG_DIR must contain the admin app\'s NDJSON audit line',
        );
    }

    /**
     * POST /tasks against the admin app, assert the 303, return the new id
     * parsed out of the Location header (/tasks/{id}).
     */
    private function adminCreateTask(string $slug, string $title): string
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody(['slug' => $slug, 'title' => $title]);
        $response = $this->adminApp->handle($request);

        self::assertSame(303, $response->getStatusCode(), 'admin POST /tasks must redirect via 303 See Other');
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/tasks/', $location);

        $id = substr($location, strlen('/tasks/'));
        self::assertNotSame('', $id, 'Location header must end with a UUID');
        return $id;
    }

    private function get(App $app, string $path): \Psr\Http\Message\ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return $app->handle($request);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $entries = scandir($dir);
        if ($entries === false) {
            throw new RuntimeException("cannot scan dir: {$dir}");
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
