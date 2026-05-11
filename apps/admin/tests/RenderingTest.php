<?php

declare(strict_types=1);

namespace TaskTracker\Admin\Tests;

use PHPUnit\Framework\TestCase;
use TaskTracker\Admin\Bootstrap;
use TaskTracker\Config\Env;
use TaskTracker\Models\Enums;
use TaskTracker\Models\RosterMember;
use TaskTracker\Models\Task;
use TaskTracker\Models\Team;
use Twig\Environment;

/**
 * ADMIN-09: Twig templates render correctly with the DI-wired environment.
 *
 * Each test renders one of the five real templates via the same Environment
 * the admin app uses at runtime (FilesystemLoader at src/Templates), so the
 * include/extends graph is exercised end-to-end and auto-escape stays observable.
 */
final class RenderingTest extends TestCase
{
    private string $tmpRoot;
    private Environment $twig;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-admin-render-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;

        $env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://127.0.0.1:8080',
            timezone: 'UTC',
        );

        $container = Bootstrap::buildContainer($env);
        $twig = $container->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);
        $this->twig = $twig;
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    public function testBootstrapVendorCssIsPresent(): void
    {
        $path = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . 'public' . DIRECTORY_SEPARATOR
            . 'vendor' . DIRECTORY_SEPARATOR
            . 'bootstrap' . DIRECTORY_SEPARATOR
            . 'bootstrap.min.css';

        self::assertFileExists($path, 'Bootstrap 5 CSS must be vendored locally — no CDN per spec.');
        $head = (string) file_get_contents($path, false, null, 0, 512);
        self::assertStringContainsString('Bootstrap', $head, 'CSS header must identify itself as Bootstrap.');
        self::assertStringContainsString('MIT', $head, 'Bootstrap is MIT — license header must be preserved.');
    }

    public function testLayoutLinksVendoredBootstrapCss(): void
    {
        $html = $this->twig->render('backlog.twig', ['tasks' => []]);
        self::assertStringContainsString(
            '/vendor/bootstrap/bootstrap.min.css',
            $html,
            'layout.twig must link the locally-vendored Bootstrap CSS, not a CDN URL.',
        );
        self::assertStringNotContainsString('cdn.jsdelivr.net', $html);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $html);
        self::assertStringNotContainsString('unpkg.com', $html);
    }

    public function testBacklogRendersEmptyState(): void
    {
        $html = $this->twig->render('backlog.twig', ['tasks' => []]);
        self::assertStringContainsString('<title>Backlog', $html);
        self::assertStringContainsString('No tasks yet', $html);
        // Empty state should not render a tbody row.
        self::assertStringNotContainsString('<tbody>', $html);
    }

    public function testBacklogRendersTaskRowsWithBadges(): void
    {
        $task = self::makeTask([
            'slug'     => 'demo-task',
            'title'    => 'Demonstrate rendering',
            'status'   => Enums::STATUS_IN_PROGRESS,
            'priority' => Enums::PRIORITY_HIGH,
        ]);

        $html = $this->twig->render('backlog.twig', ['tasks' => [$task]]);

        self::assertStringContainsString('demo-task', $html);
        self::assertStringContainsString('Demonstrate rendering', $html);
        self::assertStringContainsString('data-id="' . $task->id . '"', $html);
        // Status `in_progress` → primary; priority `high` → warning.
        self::assertStringContainsString('badge bg-primary', $html);
        self::assertStringContainsString('badge bg-warning', $html);
        self::assertStringContainsString('href="/tasks/' . rawurlencode($task->id) . '"', $html);
    }

    public function testBacklogEscapesHostileContent(): void
    {
        $task = self::makeTask([
            'slug'  => 'xss-canary',
            'title' => '<script>alert("xss")</script>',
        ]);

        $html = $this->twig->render('backlog.twig', ['tasks' => [$task]]);

        // The literal <script> tag must NOT appear in the rendered output.
        self::assertStringNotContainsString('<script>alert("xss")</script>', $html);
        // It must appear in its escaped form instead.
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testTaskEditRendersBlankFormWhenTaskIsNull(): void
    {
        $html = $this->twig->render('task_edit.twig', [
            'task'       => null,
            'statuses'   => Enums::STATUSES_LIVE,
            'priorities' => Enums::PRIORITIES,
            'roster'     => [],
            'teams'      => [],
        ]);

        self::assertStringContainsString('action="/tasks"', $html);
        self::assertStringContainsString('name="slug"', $html);
        self::assertStringContainsString('name="title"', $html);
        self::assertStringContainsString('name="status"', $html);
        self::assertStringContainsString('name="priority"', $html);
        self::assertStringContainsString('name="due_date"', $html);
        // Whitespace-tolerant: the {% if %} block inside the <button> tag emits
        // surrounding indentation, so we anchor on the literal word inside `>…<`.
        self::assertMatchesRegularExpression('/>\s*Create\s*</', $html);
        // The Delete control only appears in edit mode.
        self::assertStringNotContainsString('Delete', $html);
        // Every live status appears as an option.
        foreach (Enums::STATUSES_LIVE as $status) {
            self::assertStringContainsString(sprintf('value="%s"', $status), $html);
        }
    }

    public function testTaskEditPreselectsCurrentStatusAndAssignee(): void
    {
        $member = new RosterMember(
            id: '019f5d80-1111-7000-8000-000000000001',
            name: 'Alex Researcher',
            email: 'alex@example.test',
            teamId: null,
            active: true,
        );
        $task = self::makeTask([
            'slug'       => 'pre-selected',
            'title'      => 'Pre-selected task',
            'status'     => Enums::STATUS_BLOCKED,
            'priority'   => Enums::PRIORITY_CRITICAL,
            'assigneeId' => $member->id,
        ]);

        $html = $this->twig->render('task_edit.twig', [
            'task'       => $task,
            'statuses'   => Enums::STATUSES_LIVE,
            'priorities' => Enums::PRIORITIES,
            'roster'     => [$member],
            'teams'      => [],
        ]);

        self::assertStringContainsString('action="/tasks/' . rawurlencode($task->id) . '"', $html);
        self::assertStringContainsString('value="blocked" selected',  $html);
        self::assertStringContainsString('value="critical" selected', $html);
        self::assertStringContainsString('value="' . $member->id . '" selected', $html);
        self::assertMatchesRegularExpression('/>\s*Save\s*</', $html);
        self::assertStringContainsString('formaction="/tasks/' . rawurlencode($task->id) . '/delete"', $html);
    }

    public function testRosterRendersMemberStatusBadge(): void
    {
        $active = new RosterMember(
            id: '019f5d80-2222-7000-8000-000000000002',
            name: 'Active Person',
            email: 'a@example.test',
            teamId: null,
            active: true,
        );
        $retired = new RosterMember(
            id: '019f5d80-2222-7000-8000-000000000003',
            name: 'Retired Person',
            email: null,
            teamId: null,
            active: false,
        );

        $html = $this->twig->render('roster.twig', [
            'members' => [$active, $retired],
            'teams'   => [],
        ]);

        self::assertStringContainsString('Active Person',  $html);
        self::assertStringContainsString('Retired Person', $html);
        self::assertStringContainsString('bg-success">active<', $html);
        self::assertStringContainsString('bg-secondary">inactive<', $html);
        // Deactivate button is disabled for already-inactive members.
        self::assertMatchesRegularExpression('/data-id="' . $retired->id . '".*disabled/s', $html);
    }

    public function testTeamsRendersTeamAndCreateForm(): void
    {
        $team = new Team(
            id: '019f5d80-3333-7000-8000-000000000004',
            name: 'Platform',
            description: 'Owns infra',
        );

        $html = $this->twig->render('teams.twig', ['teams' => [$team]]);

        self::assertStringContainsString('Platform',  $html);
        self::assertStringContainsString('Owns infra', $html);
        self::assertStringContainsString('action="/teams"', $html);
        self::assertStringContainsString('name="description"', $html);
    }

    public function testEmptyTeamsRendersEmptyState(): void
    {
        $html = $this->twig->render('teams.twig', ['teams' => []]);
        self::assertStringContainsString('No teams yet', $html);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function makeTask(array $overrides): Task
    {
        $defaults = [
            'id'                     => '019f5d80-aaaa-7000-8000-' . str_pad((string) random_int(1, 999_999), 12, '0', STR_PAD_LEFT),
            'slug'                   => 'task-slug',
            'title'                  => 'Sample task',
            'body'                   => null,
            'status'                 => Enums::STATUS_OPEN,
            'priority'               => Enums::PRIORITY_DEFAULT,
            'dueDate'                => null,
            'effortHours'            => null,
            'url'                    => null,
            'parentId'               => null,
            'assigneeId'             => null,
            'teamId'                 => null,
            'createdAt'              => '2026-05-11T00:00:00Z',
            'updatedAt'              => '2026-05-11T00:00:00Z',
            'firstAssignedAt'        => null,
            'lastAssignmentChangeAt' => null,
            'completedAt'            => null,
        ];
        $merged = array_replace($defaults, $overrides);

        return new Task(
            id: $merged['id'],
            slug: $merged['slug'],
            title: $merged['title'],
            body: $merged['body'],
            status: $merged['status'],
            priority: $merged['priority'],
            dueDate: $merged['dueDate'],
            effortHours: $merged['effortHours'],
            url: $merged['url'],
            parentId: $merged['parentId'],
            assigneeId: $merged['assigneeId'],
            teamId: $merged['teamId'],
            createdAt: $merged['createdAt'],
            updatedAt: $merged['updatedAt'],
            firstAssignedAt: $merged['firstAssignedAt'],
            lastAssignmentChangeAt: $merged['lastAssignmentChangeAt'],
            completedAt: $merged['completedAt'],
        );
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
