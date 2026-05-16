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
use TaskTracker\Models\Event;
use TaskTracker\Repositories\EventRepository;
use TaskTracker\Repositories\RosterRepository;
use TaskTracker\Repositories\SavedViewRepository;
use TaskTracker\Repositories\TeamRepository;
use TaskTracker\Storage\EventLog;

/**
 * INT-02 — Two-write audit invariant (CLAUDE-CONTEXT invariant 3 / spec §8).
 *
 * Every state-changing admin route must produce BOTH:
 *   (a) the authoritative CSV row, AND
 *   (b) at least one NDJSON line in changes-YYYY-MM-DD.ndjson.
 *
 * Each test drives one route in a freshly-isolated tmp data/+logs/ pair and
 * asserts the matching action enum value appears in the log exactly once
 * (per write). A regression that silences one branch — e.g. the `task.completed`
 * follow-on emit when status → done — surfaces as a single precise failure
 * rather than a generic "missing audit row" elsewhere.
 *
 * Routes that fan out into multiple action enums (POST /tasks/{id}/assign
 * emits one of task.assigned/reassigned/unassigned depending on the null
 * transition; POST /tasks/{id}/status conditionally emits task.completed in
 * addition to task.status_changed) get one test per branch.
 *
 * The closed-enum sweep at the end fires every admin route once and asserts
 * the *set* of observed actions equals EventLog::ACTIONS minus the one
 * non-admin entry (`system.reconcile` is emitted by tools/reconcile.php).
 */
#[CoversNothing]
final class AuditCompletenessTest extends TestCase
{
    private string $tmpRoot;
    private Env $env;
    private App $adminApp;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'tt-int-audit-' . bin2hex(random_bytes(6));
        mkdir($base . DIRECTORY_SEPARATOR . 'data', 0o755, true);
        mkdir($base . DIRECTORY_SEPARATOR . 'logs', 0o755, true);
        $this->tmpRoot = $base;

        $this->env = new Env(
            dataDir:  $base . DIRECTORY_SEPARATOR . 'data',
            logDir:   $base . DIRECTORY_SEPARATOR . 'logs',
            smtpHost: null,
            smtpPort: 25,
            smtpFrom: 'noreply@localhost',
            baseUrl:  'http://127.0.0.1:8083',
            timezone: 'UTC',
        );

        $this->adminApp = AdminBootstrap::boot($this->env);
    }

    protected function tearDown(): void
    {
        self::rrmdir($this->tmpRoot);
    }

    // ---------------------------------------------------------------- tasks

    public function testCreateTaskEmitsTaskCreated(): void
    {
        self::assertSame(0, $this->countAction('task.created'));

        $id = $this->createTask('alpha', 'Alpha task');

        self::assertSame(1, $this->countAction('task.created'));
        $event = $this->firstEventForAction('task.created');
        self::assertSame($id, $event->taskId, 'task.created must carry the new task_id');
    }

    public function testUpdateTaskTitleEmitsTaskUpdated(): void
    {
        $id = $this->createTask('beta', 'Beta task');
        self::assertSame(0, $this->countAction('task.updated'));

        $response = $this->admin('POST', '/tasks/' . $id, ['title' => 'Beta renamed']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.updated'));
        self::assertSame($id, $this->firstEventForAction('task.updated')->taskId);
    }

    public function testUpdatePriorityEmitsPriorityChanged(): void
    {
        // Default priority is `med` (Enums::PRIORITY_DEFAULT) — bumping to `high`
        // must trigger task.priority_changed in addition to task.updated.
        $id = $this->createTask('gamma', 'Gamma task');
        self::assertSame(0, $this->countAction('task.priority_changed'));

        $response = $this->admin('POST', '/tasks/' . $id, ['priority' => 'high']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.priority_changed'));
    }

    public function testUpdateDueDateEmitsDueDateChanged(): void
    {
        $id = $this->createTask('delta', 'Delta task');
        self::assertSame(0, $this->countAction('task.due_date_changed'));

        $response = $this->admin('POST', '/tasks/' . $id, ['due_date' => '2026-12-31']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.due_date_changed'));
    }

    public function testSoftDeleteEmitsTaskDeleted(): void
    {
        $id = $this->createTask('epsilon', 'Epsilon task');
        self::assertSame(0, $this->countAction('task.deleted'));

        $response = $this->admin('DELETE', '/tasks/' . $id);
        self::assertContains($response->getStatusCode(), [200, 204, 303]);

        self::assertSame(1, $this->countAction('task.deleted'));
        self::assertSame($id, $this->firstEventForAction('task.deleted')->taskId);
    }

    public function testAssignEmitsTaskAssigned(): void
    {
        $id = $this->createTask('zeta', 'Zeta task');
        self::assertSame(0, $this->countAction('task.assigned'));

        $response = $this->admin('POST', '/tasks/' . $id . '/assign', ['assignee_id' => 'member-1']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.assigned'));
    }

    public function testReassignEmitsTaskReassigned(): void
    {
        $id = $this->createTask('eta', 'Eta task');
        $this->admin('POST', '/tasks/' . $id . '/assign', ['assignee_id' => 'member-1']);
        self::assertSame(0, $this->countAction('task.reassigned'));

        $response = $this->admin('POST', '/tasks/' . $id . '/assign', ['assignee_id' => 'member-2']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.reassigned'));
    }

    public function testUnassignEmitsTaskUnassigned(): void
    {
        $id = $this->createTask('theta', 'Theta task');
        $this->admin('POST', '/tasks/' . $id . '/assign', ['assignee_id' => 'member-1']);
        self::assertSame(0, $this->countAction('task.unassigned'));

        // Empty string is the controller's "unassign" form-shaped null.
        $response = $this->admin('POST', '/tasks/' . $id . '/assign', ['assignee_id' => '']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.unassigned'));
    }

    public function testStatusChangeEmitsTaskStatusChanged(): void
    {
        $id = $this->createTask('iota', 'Iota task');
        self::assertSame(0, $this->countAction('task.status_changed'));

        $response = $this->admin('POST', '/tasks/' . $id . '/status', ['status' => 'in_progress']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('task.status_changed'));
    }

    public function testStatusDoneEmitsTaskCompleted(): void
    {
        $id = $this->createTask('kappa', 'Kappa task');
        self::assertSame(0, $this->countAction('task.completed'));

        $response = $this->admin('POST', '/tasks/' . $id . '/status', ['status' => 'done']);
        self::assertSame(303, $response->getStatusCode());

        // Done-transition emits both task.status_changed and task.completed.
        self::assertSame(1, $this->countAction('task.completed'));
        self::assertSame(1, $this->countAction('task.status_changed'));
    }

    // ---------------------------------------------------- dependencies/tags

    public function testAddDependencyEmitsDependencyAdded(): void
    {
        $a = $this->createTask('lambda-1', 'L1');
        $b = $this->createTask('lambda-2', 'L2');
        self::assertSame(0, $this->countAction('dependency.added'));

        $response = $this->admin('POST', '/tasks/' . $a . '/dependencies', ['prereq_id' => $b]);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('dependency.added'));
    }

    public function testRemoveDependencyEmitsDependencyRemoved(): void
    {
        $a = $this->createTask('mu-1', 'M1');
        $b = $this->createTask('mu-2', 'M2');
        $this->admin('POST', '/tasks/' . $a . '/dependencies', ['prereq_id' => $b]);
        self::assertSame(0, $this->countAction('dependency.removed'));

        $response = $this->admin('DELETE', '/tasks/' . $a . '/dependencies/' . $b);
        self::assertContains($response->getStatusCode(), [200, 204, 303]);

        self::assertSame(1, $this->countAction('dependency.removed'));
    }

    public function testAddTagEmitsTagAdded(): void
    {
        $id = $this->createTask('nu', 'Nu task');
        self::assertSame(0, $this->countAction('tag.added'));

        $response = $this->admin('POST', '/tasks/' . $id . '/tags', ['tag' => 'urgent']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('tag.added'));
    }

    public function testRemoveTagEmitsTagRemoved(): void
    {
        $id = $this->createTask('xi', 'Xi task');
        $this->admin('POST', '/tasks/' . $id . '/tags', ['tag' => 'urgent']);
        self::assertSame(0, $this->countAction('tag.removed'));

        $response = $this->admin('DELETE', '/tasks/' . $id . '/tags/urgent');
        self::assertContains($response->getStatusCode(), [200, 204, 303]);

        self::assertSame(1, $this->countAction('tag.removed'));
    }

    // --------------------------------------------------------------- roster

    public function testCreateRosterEmitsRosterAdded(): void
    {
        self::assertSame(0, $this->countAction('roster.added'));

        $response = $this->admin('POST', '/roster', ['name' => 'Alice', 'email' => 'alice@example.com']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('roster.added'));
    }

    public function testUpdateRosterEmitsRosterUpdated(): void
    {
        $this->admin('POST', '/roster', ['name' => 'Bob']);
        $memberId = $this->firstRosterId();
        self::assertSame(0, $this->countAction('roster.updated'));

        $response = $this->admin('POST', '/roster/' . $memberId, ['name' => 'Robert']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('roster.updated'));
    }

    public function testDeactivateRosterEmitsRosterDeactivated(): void
    {
        $this->admin('POST', '/roster', ['name' => 'Carol']);
        $memberId = $this->firstRosterId();
        self::assertSame(0, $this->countAction('roster.deactivated'));

        $response = $this->admin('DELETE', '/roster/' . $memberId);
        self::assertContains($response->getStatusCode(), [200, 204, 303]);

        self::assertSame(1, $this->countAction('roster.deactivated'));
    }

    // ---------------------------------------------------------------- teams

    public function testCreateTeamEmitsTeamCreated(): void
    {
        self::assertSame(0, $this->countAction('team.created'));

        $response = $this->admin('POST', '/teams', ['name' => 'Platform']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('team.created'));
    }

    public function testUpdateTeamEmitsTeamUpdated(): void
    {
        $this->admin('POST', '/teams', ['name' => 'Platform']);
        $teamId = $this->firstTeamId();
        self::assertSame(0, $this->countAction('team.updated'));

        $response = $this->admin('POST', '/teams/' . $teamId, ['name' => 'Platform Eng']);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('team.updated'));
    }

    // ---------------------------------------------------------- saved views

    public function testCreateSavedViewEmitsSavedViewCreated(): void
    {
        self::assertSame(0, $this->countAction('saved_view.created'));

        $response = $this->admin('POST', '/saved-views', [
            'name'   => 'Open high-prio',
            'filter' => ['status' => ['open'], 'priority' => ['high']],
        ]);
        self::assertSame(303, $response->getStatusCode());

        self::assertSame(1, $this->countAction('saved_view.created'));
    }

    public function testDeleteSavedViewEmitsSavedViewDeleted(): void
    {
        $this->admin('POST', '/saved-views', ['name' => 'ToDelete']);
        $viewId = $this->firstSavedViewId();
        self::assertSame(0, $this->countAction('saved_view.deleted'));

        $response = $this->admin('DELETE', '/saved-views/' . $viewId);
        self::assertContains($response->getStatusCode(), [200, 204, 303]);

        self::assertSame(1, $this->countAction('saved_view.deleted'));
    }

    // --------------------------------------------------- closed-enum sweep

    /**
     * Meta-invariant: firing every state-changing admin route once produces
     * NDJSON events covering every "route-attributable" action in
     * EventLog::ACTIONS. `system.reconcile` is excluded because it is emitted
     * by tools/reconcile.php (TOOL-03), not by the admin app.
     *
     * This guards against the failure mode where a new action enum value is
     * added to EventLog::ACTIONS but no admin route is wired to emit it — a
     * silent gap the per-route tests above can't catch since they only assert
     * the actions they already know to look for.
     */
    public function testEveryAdminRouteActionAppearsInNdjsonLog(): void
    {
        // 1. Prerequisites.
        $this->admin('POST', '/roster', ['name' => 'Alice']);
        $memberA = $this->firstRosterId();
        $this->admin('POST', '/teams',  ['name' => 'Team']);
        $teamId = $this->firstTeamId();

        // 2. Task lifecycle, choosing field values such that every conditional
        // emit branch in TaskRepository::update fires at least once.
        $a = $this->createTask('sweep-1', 'Sweep one', priority: 'low', dueDate: '2026-06-01');
        $b = $this->createTask('sweep-2', 'Sweep two');
        $this->admin('POST', '/tasks/' . $a,                   ['title'    => 'Sweep one renamed']);
        $this->admin('POST', '/tasks/' . $a,                   ['priority' => 'high']);
        $this->admin('POST', '/tasks/' . $a,                   ['due_date' => '2026-07-15']);
        $this->admin('POST', '/tasks/' . $a . '/assign',       ['assignee_id' => $memberA]);
        $this->admin('POST', '/tasks/' . $a . '/assign',       ['assignee_id' => 'other-member']);
        $this->admin('POST', '/tasks/' . $a . '/assign',       ['assignee_id' => '']);
        $this->admin('POST', '/tasks/' . $a . '/status',       ['status'   => 'in_progress']);
        $this->admin('POST', '/tasks/' . $a . '/status',       ['status'   => 'done']);
        $this->admin('POST', '/tasks/' . $a . '/dependencies', ['prereq_id' => $b]);
        $this->admin('DELETE', '/tasks/' . $a . '/dependencies/' . $b);
        $this->admin('POST', '/tasks/' . $a . '/tags',         ['tag'      => 'urgent']);
        $this->admin('DELETE', '/tasks/' . $a . '/tags/urgent');
        $this->admin('DELETE', '/tasks/' . $a);

        // 3. Roster + team mutations.
        $this->admin('POST', '/roster/' . $memberA, ['name' => 'Alice II']);
        $this->admin('DELETE', '/roster/' . $memberA);
        $this->admin('POST', '/teams/'  . $teamId,  ['name' => 'Team Renamed']);

        // 4. Saved views.
        $this->admin('POST', '/saved-views', ['name' => 'V']);
        $viewId = $this->firstSavedViewId();
        $this->admin('DELETE', '/saved-views/' . $viewId);

        $observed = $this->observedActions();
        sort($observed);

        $expected = array_values(array_filter(
            EventLog::ACTIONS,
            fn(string $action): bool => $action !== 'system.reconcile',
        ));
        sort($expected);

        $missing = array_values(array_diff($expected, $observed));
        $extras  = array_values(array_diff($observed, $expected));

        self::assertSame(
            $expected,
            $observed,
            'every admin-route action enum value must appear at least once in NDJSON. '
                . 'missing=' . implode(',', $missing) . '; extras=' . implode(',', $extras),
        );
    }

    // -------------------------------------------------------------- helpers

    /**
     * @param array<string, mixed> $body
     */
    private function admin(string $method, string $path, array $body = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $path);
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }
        return $this->adminApp->handle($request);
    }

    private function createTask(
        string $slug,
        string $title,
        ?string $priority = null,
        ?string $dueDate = null,
    ): string {
        $body = ['slug' => $slug, 'title' => $title];
        if ($priority !== null) {
            $body['priority'] = $priority;
        }
        if ($dueDate !== null) {
            $body['due_date'] = $dueDate;
        }
        $response = $this->admin('POST', '/tasks', $body);
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

    private function countAction(string $action): int
    {
        $n = 0;
        foreach ($this->streamEvents() as $event) {
            if ($event->action === $action) {
                $n++;
            }
        }
        return $n;
    }

    private function firstEventForAction(string $action): Event
    {
        foreach ($this->streamEvents() as $event) {
            if ($event->action === $action) {
                return $event;
            }
        }
        self::fail("no NDJSON event with action={$action}");
    }

    /** @return list<string> distinct action values observed, in arbitrary order */
    private function observedActions(): array
    {
        $seen = [];
        foreach ($this->streamEvents() as $event) {
            $seen[$event->action] = true;
        }
        return array_keys($seen);
    }

    /** @return iterable<Event> */
    private function streamEvents(): iterable
    {
        $repo = new EventRepository($this->env->logDir);
        yield from $repo->streamAll();
    }

    private function firstRosterId(): string
    {
        $container = $this->adminApp->getContainer();
        self::assertNotNull($container, 'admin app must expose a container');
        /** @var RosterRepository $repo */
        $repo = $container->get(RosterRepository::class);
        $all = $repo->listAll();
        self::assertNotEmpty($all, 'expected at least one roster member');
        return $all[0]->id;
    }

    private function firstTeamId(): string
    {
        $container = $this->adminApp->getContainer();
        self::assertNotNull($container, 'admin app must expose a container');
        /** @var TeamRepository $repo */
        $repo = $container->get(TeamRepository::class);
        $all = $repo->listAll();
        self::assertNotEmpty($all, 'expected at least one team');
        return $all[0]->id;
    }

    private function firstSavedViewId(): string
    {
        $container = $this->adminApp->getContainer();
        self::assertNotNull($container, 'admin app must expose a container');
        /** @var SavedViewRepository $repo */
        $repo = $container->get(SavedViewRepository::class);
        $all = $repo->listAll();
        self::assertNotEmpty($all, 'expected at least one saved view');
        return $all[0]->id;
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
