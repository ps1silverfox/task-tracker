<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Controllers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Public\Controllers\TaskDetailController;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(TaskDetailController::class)]
final class TaskDetailControllerTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-detail-' . bin2hex(random_bytes(6));
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

    public function testShowsTaskFieldsForExistingTask(): void
    {
        $task = $this->tasksRepo()->create([
            'slug'     => 'detail-1',
            'title'    => 'Detail Task',
            'body'     => 'Body text for the task',
            'priority' => Enums::PRIORITY_HIGH,
            'dueDate'  => '2026-06-30',
        ]);

        $response = $this->get('/tasks/' . $task->id);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('Detail Task',               $body);
        self::assertStringContainsString('detail-1',                  $body);
        self::assertStringContainsString($task->id,                   $body);
        self::assertStringContainsString(Enums::PRIORITY_HIGH,        $body);
        self::assertStringContainsString('2026-06-30',                $body);
        self::assertStringContainsString('Body text for the task',    $body);
    }

    public function testIncludesActivityFeedFromEventLog(): void
    {
        $repo = $this->tasksRepo();
        $task = $repo->create(['slug' => 'feed-1', 'title' => 'Has History']);
        $repo->update($task->id, ['status' => Enums::STATUS_IN_PROGRESS]);
        $repo->update($task->id, ['status' => Enums::STATUS_DONE]);

        $body = (string) $this->get('/tasks/' . $task->id)->getBody();

        self::assertStringContainsString('task.created',         $body);
        self::assertStringContainsString('task.status_changed',  $body);
        self::assertStringContainsString('task.completed',       $body);
    }

    public function testEmptyActivityRendersEmptyMarkerNotErrors(): void
    {
        $task = $this->tasksRepo()->create(['slug' => 'no-events', 'title' => 'Quiet Task']);
        // Remove all NDJSON files so the feed is empty even though the task exists.
        foreach (glob($this->env->logDir . DIRECTORY_SEPARATOR . 'changes-*.ndjson') ?: [] as $f) {
            @unlink($f);
        }

        $response = $this->get('/tasks/' . $task->id);
        $body = (string) $response->getBody();

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('Quiet Task',          $body);
        self::assertStringContainsString('No activity recorded', $body);
    }

    public function testActivityFeedOrderIsChronologicalOldestFirst(): void
    {
        $repo = $this->tasksRepo();
        $task = $repo->create(['slug' => 'ordered', 'title' => 'Ordered Task']);
        $repo->update($task->id, ['status' => Enums::STATUS_IN_PROGRESS]);
        $repo->update($task->id, ['status' => Enums::STATUS_DONE]);

        $body = (string) $this->get('/tasks/' . $task->id)->getBody();

        $posCreated   = strpos($body, 'task.created');
        $posInProg    = strpos($body, 'task.status_changed');
        $posCompleted = strpos($body, 'task.completed');

        self::assertIsInt($posCreated);
        self::assertIsInt($posInProg);
        self::assertIsInt($posCompleted);
        self::assertLessThan($posInProg,    $posCreated);
        self::assertLessThan($posCompleted, $posInProg);
    }

    public function testMissingTaskReturns404(): void
    {
        $response = $this->get('/tasks/01HXXXX-not-a-real-id');

        self::assertSame(404, $response->getStatusCode());
    }

    public function testSoftDeletedTaskReturns404(): void
    {
        $repo = $this->tasksRepo();
        $task = $repo->create(['slug' => 'goner', 'title' => 'Soon Gone']);
        $repo->softDelete($task->id);

        $response = $this->get('/tasks/' . $task->id);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('Soon Gone', (string) $response->getBody());
    }

    public function testHtmlIsEscaped(): void
    {
        $task = $this->tasksRepo()->create([
            'slug'  => 'xss',
            'title' => '<script>alert(1)</script>',
            'body'  => 'a & b',
        ]);

        $body = (string) $this->get('/tasks/' . $task->id)->getBody();

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        self::assertStringContainsString('a &amp; b', $body);
    }

    public function testNonGetMethodsReturn405(): void
    {
        $task = $this->tasksRepo()->create(['slug' => 'immutable', 'title' => 'Immutable']);
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $req = (new ServerRequestFactory())->createServerRequest($method, '/tasks/' . $task->id);
            $res = $this->app->handle($req);
            self::assertSame(
                405,
                $res->getStatusCode(),
                "Public app must reject {$method} /tasks/{id} with 405 (bind-level separation).",
            );
        }
    }

    private function tasksRepo(): TaskRepository
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        return $repo;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        return $this->app->handle($req);
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
