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
use TaskTracker\Models\Enums;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(TasksController::class)]
final class TasksControllerUpdateTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-tasksupd-' . bin2hex(random_bytes(6));
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

    public function testPostTasksIdUpdatesFieldsAndRedirectsWith303(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create(['slug' => 'orig', 'title' => 'Original Title']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/' . $task->id)
            ->withParsedBody(['title' => 'Edited Title', 'priority' => Enums::PRIORITY_HIGH]);

        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode(), 'PRG: POST /tasks/{id} must redirect via 303.');
        self::assertSame('/tasks/' . $task->id, $response->getHeaderLine('Location'));

        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame('Edited Title', $reread->title);
        self::assertSame(Enums::PRIORITY_HIGH, $reread->priority);
        self::assertSame('orig', $reread->slug, 'Omitted fields must remain unchanged.');
    }

    public function testPostTasksIdSparseBodyDoesNotNullOutOmittedFields(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create([
            'slug'  => 'keep',
            'title' => 'Keep My Body',
            'body'  => 'Important description.',
        ]);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/' . $task->id)
            ->withParsedBody(['title' => 'New Title Only']);

        $response = $this->app->handle($request);
        self::assertSame(303, $response->getStatusCode());

        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame('New Title Only', $reread->title);
        self::assertSame('Important description.', $reread->body, 'BODY must survive a partial update.');
    }

    public function testPostTasksIdReturns404WhenTaskMissing(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/0192f000-0000-7000-8000-000000000000')
            ->withParsedBody(['title' => 'Nope']);

        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
    }

    public function testPostTasksIdReturns422OnDuplicateSlug(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $repo->create(['slug' => 'taken',  'title' => 'Owner']);
        $target = $repo->create(['slug' => 'movable', 'title' => 'Target']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/' . $target->id)
            ->withParsedBody(['slug' => 'taken']);

        $response = $this->app->handle($request);

        self::assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('conflict', $payload['error'] ?? null);
    }

    public function testPostTasksIdWritesCorroborativeNdjsonEvent(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create(['slug' => 'audit-upd', 'title' => 'Before']);

        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/' . $task->id)
            ->withParsedBody(['title' => 'After']);
        $this->app->handle($request);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty(
            $ndjsonFiles,
            'Two-write audit invariant: every state-changing admin op must append NDJSON.',
        );

        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('task.updated', $log);
        self::assertStringContainsString($task->id, $log);
    }

    public function testDeleteTasksIdSoftDeletesAndRedirectsToBacklog(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create(['slug' => 'goner', 'title' => 'Soon Soft-Deleted']);

        $request  = (new ServerRequestFactory())->createServerRequest('DELETE', '/tasks/' . $task->id);
        $response = $this->app->handle($request);

        self::assertSame(303, $response->getStatusCode());
        self::assertSame('/', $response->getHeaderLine('Location'));

        // Row is retained so dependency / history references stay resolvable.
        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame(Enums::STATUS_DELETED, $reread->status);

        // Backlog list omits deleted rows.
        self::assertSame([], array_filter(
            $repo->listLive(),
            static fn($t): bool => $t->id === $task->id,
        ));
    }

    public function testDeleteTasksIdReturns404WhenMissing(): void
    {
        $request  = (new ServerRequestFactory())->createServerRequest('DELETE', '/tasks/0192f000-0000-7000-8000-000000000000');
        $response = $this->app->handle($request);

        self::assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame('not_found', $payload['error'] ?? null);
    }

    public function testDeleteTasksIdWritesCorroborativeNdjsonEvent(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create(['slug' => 'audit-del', 'title' => 'For Deletion Audit']);

        $request = (new ServerRequestFactory())->createServerRequest('DELETE', '/tasks/' . $task->id);
        $this->app->handle($request);

        $ndjsonFiles = glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [];
        self::assertNotEmpty($ndjsonFiles);

        $log = (string) file_get_contents($ndjsonFiles[0]);
        self::assertStringContainsString('task.deleted', $log);
        self::assertStringContainsString($task->id, $log);
    }

    public function testDeleteTasksIdIsIdempotent(): void
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        $task = $repo->create(['slug' => 'twice', 'title' => 'Delete Twice']);

        $first  = $this->app->handle((new ServerRequestFactory())->createServerRequest('DELETE', '/tasks/' . $task->id));
        $second = $this->app->handle((new ServerRequestFactory())->createServerRequest('DELETE', '/tasks/' . $task->id));

        self::assertSame(303, $first->getStatusCode());
        self::assertSame(303, $second->getStatusCode(), 'Re-deleting a soft-deleted task must still 303 (idempotent).');

        $reread = $repo->find($task->id);
        self::assertNotNull($reread);
        self::assertSame(Enums::STATUS_DELETED, $reread->status);
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
