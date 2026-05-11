<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Admin\Bootstrap as AdminBootstrap;
use TaskTracker\Config\Env;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\EventRepository;

/**
 * INT-03 — cycle-prevention invariant (spec §9 / REPO-04).
 *
 * `DependencyRepository::add` runs an iterative DFS over the `requires`
 * adjacency *inside* the CsvStore exclusive lock; any edge that would close
 * a cycle raises `RuntimeException("would create cycle")`. `TasksController::
 * addDependency` catches RuntimeException on the POST /tasks/{id}/dependencies
 * route and maps it to a 422 + `{"error":"conflict"}` JSON body
 * (apps/admin/src/Controllers/TasksController.php — `addDependency`).
 *
 * Three cycle shapes are exercised end-to-end through the admin HTTP surface:
 *   - self-edge          (A requires A)
 *   - direct 2-cycle     (A requires B, then B requires A)
 *   - transitive cycle   (A → B → C, then C requires A)
 *
 * Plus two negative-control cases that the cycle check must NOT trip on:
 *   - a valid linear chain   (A → B → C → D succeeds)
 *   - a diamond, no cycle    (A requires B, A requires C, B requires D,
 *                             C requires D — D-reaching from two paths is
 *                             not a cycle)
 *
 * For every rejected attempt we additionally assert (a) the dependencies.csv
 * does not contain the offending edge after the failed POST, and (b) no
 * `dependency.added` NDJSON line was appended — the two-write audit must
 * stay silent when the authoritative CSV write was aborted (CLAUDE-CONTEXT
 * invariant 3, contrapositive: no CSV write ⇒ no audit row).
 */
#[CoversNothing]
final class DependencyCycleTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $adminApp;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-int-cycle-' . bin2hex(random_bytes(6));
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

        $this->adminApp = AdminBootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testSelfEdgeReturns422AndIsRejected(): void
    {
        $a = $this->createTask('self-1', 'Self-loop attempt');

        $response = $this->postDependency($a, $a);

        $this->assertCycleConflict($response);
        $this->assertNoEdgePersisted($a, $a);
        $this->assertNoDependencyAddedEvent();
    }

    public function testDirectTwoCycleReturns422AndIsRejected(): void
    {
        // A requires B is fine. Attempting B requires A afterwards closes a
        // 2-cycle and must be rejected with the second-edge unwritten.
        $a = $this->createTask('two-1', 'A');
        $b = $this->createTask('two-2', 'B');

        self::assertSame(303, $this->postDependency($a, $b)->getStatusCode());

        $response = $this->postDependency($b, $a);

        $this->assertCycleConflict($response);
        $this->assertEdgePersisted($a, $b);
        $this->assertNoEdgePersisted($b, $a);
        // First add emits one dependency.added; the rejected second must not.
        self::assertSame(1, $this->countAction('dependency.added'));
    }

    public function testTransitiveCycleReturns422AndIsRejected(): void
    {
        // Chain: A requires B, B requires C. Adding C requires A closes the
        // A → B → C → A cycle. The DFS must reach A from C through the
        // already-stored edges and reject.
        $a = $this->createTask('trans-1', 'A');
        $b = $this->createTask('trans-2', 'B');
        $c = $this->createTask('trans-3', 'C');

        self::assertSame(303, $this->postDependency($a, $b)->getStatusCode());
        self::assertSame(303, $this->postDependency($b, $c)->getStatusCode());

        $response = $this->postDependency($c, $a);

        $this->assertCycleConflict($response);
        $this->assertEdgePersisted($a, $b);
        $this->assertEdgePersisted($b, $c);
        $this->assertNoEdgePersisted($c, $a);
        self::assertSame(2, $this->countAction('dependency.added'));
    }

    public function testValidLinearChainSucceeds(): void
    {
        // Negative control: a 4-node linear chain has no back-edge, so each
        // add must 303 and the CSV must hold all three edges. Guards against
        // false-positive cycle detection swallowing legitimate dependencies.
        $a = $this->createTask('lin-1', 'A');
        $b = $this->createTask('lin-2', 'B');
        $c = $this->createTask('lin-3', 'C');
        $d = $this->createTask('lin-4', 'D');

        self::assertSame(303, $this->postDependency($a, $b)->getStatusCode());
        self::assertSame(303, $this->postDependency($b, $c)->getStatusCode());
        self::assertSame(303, $this->postDependency($c, $d)->getStatusCode());

        $this->assertEdgePersisted($a, $b);
        $this->assertEdgePersisted($b, $c);
        $this->assertEdgePersisted($c, $d);
        self::assertSame(3, $this->countAction('dependency.added'));
    }

    public function testDiamondShapeIsNotACycle(): void
    {
        // Diamond: A → B, A → C, B → D, C → D. D is reachable from A by two
        // paths but there is no back-edge — the DFS must not flag this.
        $a = $this->createTask('dmd-1', 'A');
        $b = $this->createTask('dmd-2', 'B');
        $c = $this->createTask('dmd-3', 'C');
        $d = $this->createTask('dmd-4', 'D');

        self::assertSame(303, $this->postDependency($a, $b)->getStatusCode());
        self::assertSame(303, $this->postDependency($a, $c)->getStatusCode());
        self::assertSame(303, $this->postDependency($b, $d)->getStatusCode());
        self::assertSame(303, $this->postDependency($c, $d)->getStatusCode());

        $this->assertEdgePersisted($a, $b);
        $this->assertEdgePersisted($a, $c);
        $this->assertEdgePersisted($b, $d);
        $this->assertEdgePersisted($c, $d);
        self::assertSame(4, $this->countAction('dependency.added'));
    }

    // -------------------------------------------------------------- helpers

    private function postDependency(string $taskId, string $prereqId): ResponseInterface
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks/' . $taskId . '/dependencies')
            ->withParsedBody(['prereq_id' => $prereqId]);
        return $this->adminApp->handle($request);
    }

    private function assertCycleConflict(ResponseInterface $response): void
    {
        self::assertSame(
            422,
            $response->getStatusCode(),
            'cycle-creating dependency must return 422 (spec §6)',
        );
        self::assertStringContainsString(
            'application/json',
            $response->getHeaderLine('Content-Type'),
            'error responses are JSON-encoded per spec §6',
        );

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload, 'error body must be a JSON object');
        self::assertSame('conflict', $payload['error'] ?? null);
        self::assertIsString($payload['message'] ?? null);
        self::assertStringContainsString('cycle', (string) $payload['message']);
    }

    private function assertEdgePersisted(string $taskId, string $prereqId): void
    {
        self::assertTrue(
            $this->dependencyRepo()->has($taskId, $prereqId),
            "expected edge ({$taskId} requires {$prereqId}) in dependencies.csv",
        );
    }

    private function assertNoEdgePersisted(string $taskId, string $prereqId): void
    {
        self::assertFalse(
            $this->dependencyRepo()->has($taskId, $prereqId),
            "edge ({$taskId} requires {$prereqId}) must not be in dependencies.csv "
                . 'after a rejected POST',
        );
    }

    private function assertNoDependencyAddedEvent(): void
    {
        self::assertSame(
            0,
            $this->countAction('dependency.added'),
            'rejected cycle must not produce a dependency.added NDJSON line '
                . '(two-write audit invariant: no CSV write ⇒ no audit row)',
        );
    }

    private function countAction(string $action): int
    {
        $repo = new EventRepository($this->env->logDir);
        $n = 0;
        foreach ($repo->streamAll() as $event) {
            if ($event->action === $action) {
                $n++;
            }
        }
        return $n;
    }

    private function dependencyRepo(): DependencyRepository
    {
        $container = $this->adminApp->getContainer();
        self::assertNotNull($container, 'admin app must expose a container');
        /** @var DependencyRepository $repo */
        $repo = $container->get(DependencyRepository::class);
        return $repo;
    }

    private function createTask(string $slug, string $title): string
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', '/tasks')
            ->withParsedBody(['slug' => $slug, 'title' => $title]);
        $response = $this->adminApp->handle($request);

        self::assertSame(
            303,
            $response->getStatusCode(),
            "admin POST /tasks must 303-redirect; got {$response->getStatusCode()} body=" . (string) $response->getBody(),
        );
        $location = $response->getHeaderLine('Location');
        self::assertStringStartsWith('/tasks/', $location);
        $id = substr($location, strlen('/tasks/'));
        self::assertNotSame('', $id, 'Location header must end with a UUID');
        return $id;
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
