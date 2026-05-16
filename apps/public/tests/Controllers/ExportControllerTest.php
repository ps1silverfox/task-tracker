<?php

declare(strict_types=1);

namespace TaskTracker\Public\Tests\Controllers;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Models\Task;
use TaskTracker\Public\Bootstrap;
use TaskTracker\Public\Controllers\ExportController;
use TaskTracker\Repositories\TagRepository;
use TaskTracker\Repositories\TaskRepository;

#[CoversClass(ExportController::class)]
final class ExportControllerTest extends TestCase
{
    private string $tmpRoot;
    private string $today;
    private App $app;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-public-export-' . bin2hex(random_bytes(6));
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

    // ===== /tasks.csv =====

    public function testTasksCsvHasCorrectContentTypeAndAttachmentHeader(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'alpha', 'title' => 'Alpha']);

        $response = $this->get('/tasks.csv');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="tasks.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    public function testTasksCsvUsesCrlfEveryFieldQuoted(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'alpha', 'title' => 'Alpha']);

        $body = (string) $this->get('/tasks.csv')->getBody();

        self::assertStringContainsString("\r\n", $body, 'CSV body must use CRLF line terminators');
        // Header row begins with quoted column name and is comma-separated.
        $lines = self::splitCrlfRows($body);
        $headerCells = self::parseCells($lines[0]);
        self::assertSame(Task::HEADERS, $headerCells);
        // Each header cell must be wrapped in quotes in the raw line.
        self::assertSame(
            '"' . implode('","', Task::HEADERS) . '"',
            $lines[0],
        );
    }

    public function testTasksCsvHeaderRowMatchesTaskHeaders(): void
    {
        $body = (string) $this->get('/tasks.csv')->getBody();
        $lines = self::splitCrlfRows($body);
        self::assertSame(Task::HEADERS, self::parseCells($lines[0]));
    }

    public function testTasksCsvEmitsOneRowPerLiveTask(): void
    {
        $repo = $this->tasksRepo();
        $a = $repo->create(['slug' => 'a', 'title' => 'Alpha']);
        $b = $repo->create(['slug' => 'b', 'title' => 'Beta']);

        $rows = self::dataRows((string) $this->get('/tasks.csv')->getBody());

        self::assertCount(2, $rows);
        $ids = [$rows[0]['ID'], $rows[1]['ID']];
        sort($ids);
        $expected = [$a->id, $b->id];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testTasksCsvExcludesSoftDeletedRowsAlways(): void
    {
        $repo  = $this->tasksRepo();
        $keep  = $repo->create(['slug' => 'keep',  'title' => 'Keep']);
        $erase = $repo->create(['slug' => 'erase', 'title' => 'Erase']);
        $repo->softDelete($erase->id);

        $rows = self::dataRows((string) $this->get('/tasks.csv')->getBody());

        self::assertCount(1, $rows);
        self::assertSame($keep->id, $rows[0]['ID']);
    }

    public function testTasksCsvExcludesDoneByDefaultAndIncludeDoneShowsThem(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'wip',     'title' => 'WIP',     'status' => Enums::STATUS_OPEN]);
        $repo->create(['slug' => 'shipped', 'title' => 'Shipped', 'status' => Enums::STATUS_DONE]);

        $defaultRows = self::dataRows((string) $this->get('/tasks.csv')->getBody());
        self::assertCount(1, $defaultRows);
        self::assertSame('wip', $defaultRows[0]['SLUG']);

        $withDoneRows = self::dataRows((string) $this->get('/tasks.csv?include_done=1')->getBody());
        self::assertCount(2, $withDoneRows);
    }

    public function testTasksCsvAppliesStatusPriorityAndTeamFilters(): void
    {
        $repo = $this->tasksRepo();
        $repo->create([
            'slug'     => 'match',
            'title'    => 'Match',
            'status'   => Enums::STATUS_IN_PROGRESS,
            'priority' => Enums::PRIORITY_HIGH,
            'teamId'   => 'team-A',
        ]);
        $repo->create([
            'slug'     => 'wrong-prio',
            'title'    => 'Wrong Prio',
            'status'   => Enums::STATUS_IN_PROGRESS,
            'priority' => Enums::PRIORITY_LOW,
            'teamId'   => 'team-A',
        ]);
        $repo->create([
            'slug'     => 'wrong-team',
            'title'    => 'Wrong Team',
            'status'   => Enums::STATUS_IN_PROGRESS,
            'priority' => Enums::PRIORITY_HIGH,
            'teamId'   => 'team-B',
        ]);

        $rows = self::dataRows((string) $this->get(
            '/tasks.csv?status=in_progress&priority=high&team_id=team-A',
        )->getBody());

        self::assertCount(1, $rows);
        self::assertSame('match', $rows[0]['SLUG']);
    }

    public function testTasksCsvAppliesTagsFilterWithAndSemantics(): void
    {
        $repo = $this->tasksRepo();
        $tags = $this->tagsRepo();

        $both = $repo->create(['slug' => 'both', 'title' => 'Both']);
        $one  = $repo->create(['slug' => 'one',  'title' => 'One']);
        $repo->create(['slug' => 'none', 'title' => 'None']);
        $tags->add($both->id, 'urgent');
        $tags->add($both->id, 'bug');
        $tags->add($one->id,  'urgent');

        $rows = self::dataRows((string) $this->get('/tasks.csv?tags=urgent,bug')->getBody());

        self::assertCount(1, $rows);
        self::assertSame('both', $rows[0]['SLUG']);
    }

    public function testTasksCsvAppliesDueWithinDaysFilter(): void
    {
        $repo = $this->tasksRepo();
        $today = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $in3   = $today->add(new DateInterval('P3D'))->format('Y-m-d');
        $in30  = $today->add(new DateInterval('P30D'))->format('Y-m-d');

        $repo->create(['slug' => 'soon',  'title' => 'Soon',  'dueDate' => $in3]);
        $repo->create(['slug' => 'later', 'title' => 'Later', 'dueDate' => $in30]);
        $repo->create(['slug' => 'open',  'title' => 'No Due']);

        $rows = self::dataRows((string) $this->get('/tasks.csv?due_within_days=7')->getBody());

        self::assertCount(1, $rows);
        self::assertSame('soon', $rows[0]['SLUG']);
    }

    public function testTasksCsvAppliesInjectionGuardToHostileCellValues(): void
    {
        $repo = $this->tasksRepo();
        // Hostile title that would otherwise evaluate as a formula in Excel.
        // CSV-injection guard must prefix the cell with an apostrophe before
        // RFC 4180 quoting wraps it.
        $repo->create(['slug' => 'hostile', 'title' => "=cmd|' /c calc'!A0"]);

        $body = (string) $this->get('/tasks.csv')->getBody();
        $rows = self::dataRows($body);

        self::assertCount(1, $rows);
        self::assertSame("'=cmd|' /c calc'!A0", $rows[0]['TITLE']);
        // And the leading-byte sentinel must appear AFTER the opening quote,
        // not bare at line-start — verifies ordering: guard, then quote.
        self::assertStringContainsString('"\'=cmd', $body);
    }

    public function testTasksCsvQuotesEmbeddedQuotesByDoubling(): void
    {
        $repo = $this->tasksRepo();
        $repo->create(['slug' => 'quoted', 'title' => 'He said "hi"']);

        $body = (string) $this->get('/tasks.csv')->getBody();
        $rows = self::dataRows($body);

        self::assertSame('He said "hi"', $rows[0]['TITLE']);
        self::assertStringContainsString('"He said ""hi"""', $body);
    }

    // ===== /summary.csv =====

    public function testSummaryCsvHasCorrectContentTypeAndAttachmentHeader(): void
    {
        $response = $this->get('/summary.csv?period=day&from=' . $this->today . '&to=' . $this->today);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/csv; charset=utf-8', $response->getHeaderLine('Content-Type'));
        self::assertSame(
            'attachment; filename="summary.csv"',
            $response->getHeaderLine('Content-Disposition'),
        );
    }

    public function testSummaryCsvHeaderRowAndOneRowPerBucket(): void
    {
        $body = (string) $this->get(
            '/summary.csv?period=day&from=' . $this->today . '&to=' . $this->today,
        )->getBody();

        $lines = self::splitCrlfRows($body);
        self::assertSame(['PERIOD', 'ADDED', 'COMPLETED', 'ASSIGNED', 'UNASSIGNED'], self::parseCells($lines[0]));
        // One bucket for the single-day range.
        self::assertCount(2, $lines, 'expected exactly one bucket row plus header');
        $cells = self::parseCells($lines[1]);
        self::assertSame($this->today, $cells[0]);
        // All four counters present as integer strings.
        for ($i = 1; $i <= 4; $i++) {
            self::assertMatchesRegularExpression('/^\d+$/', $cells[$i]);
        }
    }

    public function testSummaryCsvCountsAddedFromCreatedEvent(): void
    {
        $this->tasksRepo()->create(['slug' => 'a', 'title' => 'Alpha']);

        $body = (string) $this->get(
            '/summary.csv?period=day&from=' . $this->today . '&to=' . $this->today,
        )->getBody();

        $row = self::dataRows($body)[0];
        self::assertSame('1', $row['ADDED']);
    }

    public function testSummaryCsvRejectsInvalidPeriodWith400(): void
    {
        $response = $this->get('/summary.csv?period=fortnight&from=' . $this->today . '&to=' . $this->today);
        self::assertSame(400, $response->getStatusCode());
    }

    public function testSummaryCsvRejectsInvalidDateWith400(): void
    {
        $response = $this->get('/summary.csv?period=day&from=2026/01/01&to=' . $this->today);
        self::assertSame(400, $response->getStatusCode());
    }

    // ===== invariants =====

    public function testTasksCsvNonGetMethodsReturn405(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $this->app->handle(
                (new ServerRequestFactory())->createServerRequest($method, '/tasks.csv'),
            );
            self::assertSame(
                405,
                $response->getStatusCode(),
                "Public app must reject {$method} /tasks.csv with 405 (bind-level separation).",
            );
        }
    }

    public function testSummaryCsvNonGetMethodsReturn405(): void
    {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $response = $this->app->handle(
                (new ServerRequestFactory())->createServerRequest($method, '/summary.csv'),
            );
            self::assertSame(
                405,
                $response->getStatusCode(),
                "Public app must reject {$method} /summary.csv with 405 (bind-level separation).",
            );
        }
    }

    // ===== helpers =====

    private function tasksRepo(): TaskRepository
    {
        /** @var TaskRepository $repo */
        $repo = $this->app->getContainer()->get(TaskRepository::class);
        return $repo;
    }

    private function tagsRepo(): TagRepository
    {
        /** @var TagRepository $repo */
        $repo = $this->app->getContainer()->get(TagRepository::class);
        return $repo;
    }

    private function get(string $path): ResponseInterface
    {
        $uri = parse_url($path);
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        if (isset($uri['query'])) {
            parse_str($uri['query'], $params);
            $req = $req->withQueryParams($params);
        }
        return $this->app->handle($req);
    }

    /**
     * @return list<string>
     */
    private static function splitCrlfRows(string $body): array
    {
        // Trim final CRLF; the file ends with CRLF after the last row.
        if (str_ends_with($body, "\r\n")) {
            $body = substr($body, 0, -2);
        }
        return explode("\r\n", $body);
    }

    /**
     * Parse one CSV row written by ExportController. The writer guarantees
     * every cell is `"..."`-quoted and embedded `"` doubled, so we can use
     * str_getcsv() with default settings.
     *
     * @return list<string>
     */
    private static function parseCells(string $line): array
    {
        $cells = str_getcsv($line, escape: '');
        return array_map(static fn($c): string => (string) $c, $cells);
    }

    /**
     * Parse all rows of a /tasks.csv body into associative arrays keyed by
     * Task::HEADERS. Header row is consumed.
     *
     * @return list<array<string, string>>
     */
    private static function dataRows(string $body): array
    {
        $lines = self::splitCrlfRows($body);
        $headers = self::parseCells(array_shift($lines));
        $out = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $cells = self::parseCells($line);
            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = $cells[$i] ?? '';
            }
            $out[] = $row;
        }
        return $out;
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
