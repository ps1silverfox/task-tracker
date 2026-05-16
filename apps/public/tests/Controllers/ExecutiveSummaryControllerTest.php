<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Controllers;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Public\Controllers\ExecutiveSummaryController;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(ExecutiveSummaryController::class)]
final class ExecutiveSummaryControllerTest extends TestCase
{
    private string $tmpRoot;
    private string $today;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-summary-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;
        $this->today   = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');

        $env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://localhost:8083',
            timezone: 'UTC',
        );
        $this->app = Bootstrap::boot($env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testRendersHtmlWithChartCanvasAndTableHeaders(): void
    {
        $response = $this->get('/summary?period=day&from=' . $this->today . '&to=' . $this->today);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        self::assertStringContainsString('<canvas id="summary-chart"', $body);
        self::assertStringContainsString('id="summary-table"', $body);
        self::assertStringContainsString('<th>Added</th>', $body);
        self::assertStringContainsString('<th>Completed</th>', $body);
        self::assertStringContainsString('<th>Assigned</th>', $body);
        self::assertStringContainsString('<th>Unassigned</th>', $body);
        self::assertStringContainsString('/vendor/chartjs/chart.umd.min.js', $body);
    }

    public function testCountsAddedAndAssignedAndCompletedForCurrentDay(): void
    {
        $repo = $this->tasksRepo();

        $t1 = $repo->create(['slug' => 'a', 'title' => 'Alpha']);
        $t2 = $repo->create(['slug' => 'b', 'title' => 'Beta']);

        $repo->update($t1->id, ['assigneeId' => 'roster-1']);
        $repo->update($t2->id, ['assigneeId' => 'roster-2']);

        $repo->update($t1->id, ['status' => Enums::STATUS_DONE]);

        $body = (string) $this->get(
            '/summary?period=day&from=' . $this->today . '&to=' . $this->today,
        )->getBody();

        $rowHtml = self::extractRowHtml($body, $this->today);
        self::assertNotNull($rowHtml, 'expected a table row for ' . $this->today);
        self::assertSame([2, 1, 2, 0], self::extractCounts($rowHtml));
    }

    public function testPersonFilterRestrictsCountsToEventsWithMatchingSnapshot(): void
    {
        $repo = $this->tasksRepo();

        $t1 = $repo->create(['slug' => 'a', 'title' => 'A', 'assigneeId' => 'alice']);
        $t2 = $repo->create(['slug' => 'b', 'title' => 'B', 'assigneeId' => 'bob']);
        $repo->update($t1->id, ['status' => Enums::STATUS_DONE]);
        $repo->update($t2->id, ['status' => Enums::STATUS_DONE]);

        $aliceBody = (string) $this->get(
            '/summary?period=day&from=' . $this->today . '&to=' . $this->today . '&person=alice',
        )->getBody();
        $aliceRow = self::extractRowHtml($aliceBody, $this->today);
        self::assertNotNull($aliceRow);
        // task.created counts under `added` but its assignee_id_snapshot is the
        // post-create state; both create rows had assigneeId set on create.
        // Alice: 1 created, 1 completed, 1 assigned.
        self::assertSame([1, 1, 1, 0], self::extractCounts($aliceRow));

        $bobBody = (string) $this->get(
            '/summary?period=day&from=' . $this->today . '&to=' . $this->today . '&person=bob',
        )->getBody();
        $bobRow = self::extractRowHtml($bobBody, $this->today);
        self::assertNotNull($bobRow);
        self::assertSame([1, 1, 1, 0], self::extractCounts($bobRow));
    }

    public function testEmptyRangeShowsEmptyStateRow(): void
    {
        // No tasks created → no events → empty rollup row.
        $body = (string) $this->get(
            '/summary?period=day&from=' . $this->today . '&to=' . $this->today,
        )->getBody();

        // Either no events fall in range and rows = [today => zeros], or rows
        // is empty entirely. Both are acceptable; we just need the page to
        // render without error and to show the all-zeros row OR the empty
        // placeholder.
        $hasEmptyPlaceholder = str_contains($body, 'No events in range.');
        $rowHtml = self::extractRowHtml($body, $this->today);
        $hasZeroRow = $rowHtml !== null && self::extractCounts($rowHtml) === [0, 0, 0, 0];

        self::assertTrue(
            $hasEmptyPlaceholder || $hasZeroRow,
            'expected empty-state placeholder or a zero-count row for ' . $this->today,
        );
    }

    public function testDefaultsRenderRollingMonthWindowWhenQueryEmpty(): void
    {
        $response = $this->get('/summary');
        self::assertSame(200, $response->getStatusCode());

        $body = (string) $response->getBody();
        // Default period is `month` → the period <select> preselects month.
        self::assertMatchesRegularExpression(
            '/<option value="month"\s+selected>/',
            $body,
        );

        // Default `to` is today (UTC); the value should appear in the form.
        self::assertStringContainsString('value="' . $this->today . '"', $body);
    }

    public function testInvalidPeriodReturns400(): void
    {
        $response = $this->get('/summary?period=fortnight');
        self::assertSame(400, $response->getStatusCode());
    }

    public function testInvalidFromDateReturns400(): void
    {
        $response = $this->get('/summary?period=day&from=not-a-date&to=' . $this->today);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testFromAfterToReturns400(): void
    {
        $response = $this->get('/summary?period=day&from=2026-05-11&to=2026-05-01');
        self::assertSame(400, $response->getStatusCode());
    }

    public function testNonGetMethodsOnSummaryReturn405(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $req = (new ServerRequestFactory())->createServerRequest($method, '/summary');
            self::assertSame(
                405,
                $this->app->handle($req)->getStatusCode(),
                "Public app must reject {$method} /summary with 405 (bind-level separation).",
            );
        }
    }

    public function testXssInPersonFilterIsEscapedInRenderedFilters(): void
    {
        $hostile = '<script>alert(1)</script>';
        $body = (string) $this->get(
            '/summary?period=day&from=' . $this->today . '&to=' . $this->today
                . '&person=' . rawurlencode($hostile),
        )->getBody();

        self::assertStringNotContainsString('<script>alert(1)</script>', $body);
        self::assertStringContainsString('&lt;script&gt;', $body);
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
        $uri = parse_url($path);
        if (isset($uri['query'])) {
            parse_str($uri['query'], $params);
            $req = $req->withQueryParams($params);
        }
        return $this->app->handle($req);
    }

    private static function extractRowHtml(string $body, string $periodKey): ?string
    {
        $pattern = '/<tr data-period="' . preg_quote($periodKey, '/') . '">(.*?)<\/tr>/s';
        return preg_match($pattern, $body, $m) === 1 ? $m[1] : null;
    }

    /**
     * @return list<int>  [added, completed, assigned, unassigned]
     */
    private static function extractCounts(string $rowInner): array
    {
        preg_match_all('/<td>(\d+)<\/td>/', $rowInner, $m);
        return array_map('intval', $m[1]);
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
