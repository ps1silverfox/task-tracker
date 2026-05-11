<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Admin\Controllers\TasksController;
use TaskTracker\Config\Env;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(TasksController::class)]
final class TasksControllerCreateTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-tasksctl-' . bin2hex(random_bytes(6));
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

    public function testPostTasksCreatesRowAndRedirectsWith303(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody(['slug' => 'feat-x', 'title' => 'Feature X']);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode(), 'PRG: POST /tasks must redirect via 303 See Other.');
        $location = $response->getHeaderLine('Location');
        self::assertNotSame('', $location, 'POST /tasks must emit a Location header.');
        self::assertStringStartsWith('/tasks/', $location);

        $csvContent = (string) file_get_contents($this->env->dataDir . DIRECTORY_SEPARATOR . 'tasks.csv');
        self::assertStringContainsString('feat-x',    $csvContent);
        self::assertStringContainsString('Feature X', $csvContent);
    }

    public function testPostTasksWritesCorroborativeNdjsonEvent(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody(['slug' => 'audit-x', 'title' => 'Auditable']);

        $this->app->handle($request);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty(
            $ndjsonFiles,
            'Two-write audit invariant: every state-changing admin op must append an NDJSON event.',
        );

        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('task.created', $log);
        self::assertStringContainsString('audit-x',      $log);
    }

    public function testPostTasksReturns422OnMissingRequiredFields(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody([]);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('invalid_field', $payload['error'] ?? null);
        self::assertNotEmpty($payload['message'] ?? '');
    }

    public function testPostTasksReturns422OnDuplicateSlug(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $repo->create(['slug' => 'dupe', 'title' => 'First']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody(['slug' => 'dupe', 'title' => 'Second']);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('conflict', $payload['error'] ?? null);
    }

    public function testGetRootListsLiveTasks(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $repo->create(['slug' => 'list-x', 'title' => 'Listed Task']);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Listed Task', $body);
        self::assertStringContainsString('list-x',      $body);
    }

    public function testGetRootOmitsSoftDeletedTasks(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $live    = $repo->create(['slug' => 'keep-me',  'title' => 'Keep Me']);
        $deleted = $repo->create(['slug' => 'erase-me', 'title' => 'Erase Me']);
        $repo->softDelete($deleted->id);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $this->app->handle($request);
        $body = (string) $response->getBody();

        self::assertStringContainsString('Keep Me', $body);
        self::assertStringNotContainsString('Erase Me', $body);
        // sanity: the live task's id is still present so the table isn't somehow empty.
        self::assertStringContainsString($live->id, $body);
    }

    public function testGetTaskByIdReturnsHtmlDetail(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create(['slug' => 'detail-x', 'title' => 'Detail Task', 'body' => 'Long body text.']);

        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/tasks/' . $task->id);
        $response = $this->app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Detail Task',     $body);
        self::assertStringContainsString('Long body text.', $body);
        self::assertStringContainsString($task->id,         $body);
    }

    public function testGetTaskByIdReturns404WhenMissing(): void
    {
        $request  = (new ServerRequestFactory())->createServerRequest('GET', '/tasks/0192f000-0000-7000-8000-000000000000');
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
