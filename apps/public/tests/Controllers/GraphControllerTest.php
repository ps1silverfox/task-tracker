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
use TaskTracker\Public\Controllers\GraphController;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(GraphController::class)]
final class GraphControllerTest extends TestCase
{
    private string $tmpRoot;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-graph-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;

        $env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://localhost',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testHtmlPageLoadsCytoscapeVendorAssets(): void
    {
        $response = $this->get('/graph');

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('/vendor/cytoscape/cytoscape.min.js', $body);
        self::assertStringContainsString('/vendor/cytoscape/cytoscape-dagre.min.js', $body);
        self::assertStringContainsString('id="graph-canvas"', $body);
        self::assertStringContainsString('/graph.json', $body);
    }

    public function testHtmlPagePassesQueryStringThroughToJsonUrl(): void
    {
        $body = (string) $this->get(
            '/graph?status=open,in_progress&team_id=team-7'
        )->getBody();

        // The shell page should hand the same filter axes to /graph.json so the
        // client-side fetch returns a graph consistent with the form state.
        self::assertMatchesRegularExpression(
            '/data-json-url="[^"]*\/graph\.json[^"]*status=open(?:,|%2C)in_progress[^"]*"/',
            $body,
        );
        self::assertStringContainsString('team_id=team-7', $body);
    }

    public function testJsonEndpointReturnsEmptyGraphForEmptyData(): void
    {
        $response = $this->get('/graph.json');
        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));

        $payload = json_decode((string) $response->getBody(), true);
        self::assertIsArray($payload);
        self::assertSame([], $payload['nodes']);
        self::assertSame([], $payload['edges']);
        self::assertSame(0, $payload['meta']['node_count']);
        self::assertSame(0, $payload['meta']['edge_count']);
        self::assertFalse($payload['meta']['exceeds_threshold']);
    }

    public function testJsonEndpointSerializesNodesAndEdgesFromRepository(): void
    {
        $tasks = $this->tasksRepo();
        $deps  = $this->depsRepo();

        $a = $tasks->create(['slug' => 'a', 'title' => 'A']);
        $b = $tasks->create(['slug' => 'b', 'title' => 'B']);
        $deps->add($a->id, $b->id);  // B prereq of A → edge B → A

        $payload = json_decode(
            (string) $this->get('/graph.json')->getBody(),
            true,
        );

        self::assertCount(2, $payload['nodes']);
        self::assertCount(1, $payload['edges']);
        $edge = $payload['edges'][0]['data'];
        self::assertSame($b->id, $edge['source']);
        self::assertSame($a->id, $edge['target']);
    }

    public function testJsonEndpointAppliesStatusFilter(): void
    {
        $tasks = $this->tasksRepo();
        $tasks->create(['slug' => 'a', 'title' => 'A', 'status' => Enums::STATUS_OPEN]);
        $tasks->create(['slug' => 'b', 'title' => 'B', 'status' => Enums::STATUS_DONE]);

        $payload = json_decode(
            (string) $this->get('/graph.json?status=open')->getBody(),
            true,
        );

        self::assertCount(1, $payload['nodes']);
        self::assertSame(Enums::STATUS_OPEN, $payload['nodes'][0]['data']['status']);
    }

    public function testJsonEndpointAppliesTeamFilter(): void
    {
        $tasks = $this->tasksRepo();
        $tasks->create(['slug' => 'a', 'title' => 'A', 'teamId' => 'team-1']);
        $tasks->create(['slug' => 'b', 'title' => 'B', 'teamId' => 'team-2']);

        $payload = json_decode(
            (string) $this->get('/graph.json?team_id=team-1')->getBody(),
            true,
        );

        self::assertCount(1, $payload['nodes']);
        self::assertSame('team-1', $payload['nodes'][0]['data']['team_id']);
    }

    public function testJsonEndpointAppliesRootSubtreeFilter(): void
    {
        $tasks = $this->tasksRepo();
        $root  = $tasks->create(['slug' => 'r', 'title' => 'R']);
        $child = $tasks->create(['slug' => 'c', 'title' => 'C', 'parentId' => $root->id]);
        $tasks->create(['slug' => 'other', 'title' => 'O']);

        $payload = json_decode(
            (string) $this->get('/graph.json?root=' . $root->id)->getBody(),
            true,
        );

        $ids = array_map(static fn(array $n) => $n['data']['id'], $payload['nodes']);
        sort($ids);
        $expected = [$root->id, $child->id];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testInvalidStatusFilterReturns400(): void
    {
        $response = $this->get('/graph.json?status=bogus');
        self::assertSame(400, $response->getStatusCode());
    }

    public function testDeletedStatusFilterReturns400(): void
    {
        $response = $this->get('/graph.json?status=' . Enums::STATUS_DELETED);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testNonGetMethodsOnGraphReturn405(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            foreach (['/graph', '/graph.json'] as $path) {
                $req = (new ServerRequestFactory())->createServerRequest($method, $path);
                self::assertSame(
                    405,
                    $this->app->handle($req)->getStatusCode(),
                    "Public app must reject {$method} {$path} with 405 (bind-level separation).",
                );
            }
        }
    }

    public function testXssInRootFilterIsEscapedInHtml(): void
    {
        $hostile = '<script>alert(1)</script>';
        $body = (string) $this->get(
            '/graph?root=' . rawurlencode($hostile),
        )->getBody();

        self::assertStringNotContainsString($hostile, $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
    }

    private function tasksRepo(): TaskRepository
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        return $repo;
    }

    private function depsRepo(): DependencyRepository
    {
        /** @var DependencyRepository $repo */
        $repo = $this->app->getContainer()->get(DependencyRepository::class);
        return $repo;
    }

    private function get(string $path): \Psr\Http\Message\ResponseInterface
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $uri = parse_url($path);
        if (isset($uri['query'])) {
            parse_str($uri['query'], $params);
            $req = $req->withQueryParams($params);
        }
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
