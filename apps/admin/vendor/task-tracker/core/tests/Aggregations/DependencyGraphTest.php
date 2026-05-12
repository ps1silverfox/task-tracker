<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Aggregations;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Aggregations\DependencyGraph;
use TaskTracker\Models\Enums;
use TaskTracker\Repositories\DependencyRepository;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class DependencyGraphTest extends TestCase
{
    private string $sandbox;
    private TaskRepository $tasks;
    private DependencyRepository $deps;
    private DependencyGraph $graph;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-graph-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($logDir, 0700, true);

        $events = new EventLog($logDir);
        $store = new CsvStore();
        $this->tasks = new TaskRepository($store, $events, $base . DIRECTORY_SEPARATOR . 'tasks.csv');
        $this->deps = new DependencyRepository($store, $events, $base . DIRECTORY_SEPARATOR . 'dependencies.csv');
        $this->graph = new DependencyGraph($this->tasks, $this->deps);
    }

    protected function tearDown(): void
    {
        $this->rmrf($this->sandbox);
    }

    private function rmrf(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rmrf($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    public function testEmptyRepoReturnsEmptyGraph(): void
    {
        $out = $this->graph->serialize();
        self::assertSame([], $out['nodes']);
        self::assertSame([], $out['edges']);
        self::assertSame(0, $out['meta']['node_count']);
        self::assertSame(0, $out['meta']['edge_count']);
        self::assertFalse($out['meta']['exceeds_threshold']);
    }

    public function testTasksWithoutDependenciesProduceNodesOnly(): void
    {
        $this->tasks->create(['slug' => 'a', 'title' => 'A']);
        $this->tasks->create(['slug' => 'b', 'title' => 'B']);

        $out = $this->graph->serialize();
        self::assertCount(2, $out['nodes']);
        self::assertSame([], $out['edges']);
        self::assertSame(2, $out['meta']['node_count']);
    }

    public function testEdgeArrowFlowsPrereqToBlocked(): void
    {
        // Edge semantics (spec §5): (TASK_ID=A, PREREQ_ID=B) means "B is a prerequisite
        // of A". The visual arrow must point B → A (prereq blocks the dependent).
        $a = $this->tasks->create(['slug' => 'a', 'title' => 'A']);
        $b = $this->tasks->create(['slug' => 'b', 'title' => 'B']);
        $this->deps->add($a->id, $b->id);

        $out = $this->graph->serialize();
        self::assertCount(2, $out['nodes']);
        self::assertCount(1, $out['edges']);
        self::assertSame($b->id, $out['edges'][0]['data']['source']);
        self::assertSame($a->id, $out['edges'][0]['data']['target']);
        self::assertSame($b->id . '->' . $a->id, $out['edges'][0]['data']['id']);
    }

    public function testNodeDataCarriesVisualEncodingFields(): void
    {
        $a = $this->tasks->create([
            'slug' => 'a',
            'title' => 'A',
            'priority' => Enums::PRIORITY_HIGH,
            'status' => Enums::STATUS_BLOCKED,
            'teamId' => 'team-7',
            'assigneeId' => 'mem-1',
        ]);

        $out = $this->graph->serialize();
        $node = $out['nodes'][0]['data'];
        self::assertSame($a->id, $node['id']);
        self::assertSame('A', $node['label']);
        self::assertSame('A', $node['title']);
        self::assertSame(Enums::STATUS_BLOCKED, $node['status']);
        self::assertSame(Enums::PRIORITY_HIGH, $node['priority']);
        self::assertSame('team-7', $node['team_id']);
        self::assertSame('mem-1', $node['assignee_id']);
    }

    public function testLongTitleTruncatedToFortyChars(): void
    {
        $longTitle = str_repeat('x', 60);
        $this->tasks->create(['slug' => 'a', 'title' => $longTitle]);

        $out = $this->graph->serialize();
        $node = $out['nodes'][0]['data'];
        self::assertSame($longTitle, $node['title']);
        self::assertSame(40, mb_strlen($node['label']));
        self::assertStringEndsWith('…', $node['label']);
    }

    public function testTitleAtBoundaryNotTruncated(): void
    {
        $title = str_repeat('y', 40);
        $this->tasks->create(['slug' => 'a', 'title' => $title]);

        $out = $this->graph->serialize();
        self::assertSame($title, $out['nodes'][0]['data']['label']);
    }

    public function testSoftDeletedTasksExcludedAndIncidentEdgesDropped(): void
    {
        $a = $this->tasks->create(['slug' => 'a', 'title' => 'A']);
        $b = $this->tasks->create(['slug' => 'b', 'title' => 'B']);
        $this->deps->add($a->id, $b->id);
        $this->tasks->softDelete($b->id);

        $out = $this->graph->serialize();
        self::assertCount(1, $out['nodes']);
        self::assertSame($a->id, $out['nodes'][0]['data']['id']);
        self::assertSame([], $out['edges']);
    }

    public function testStatusFilterKeepsOnlyMatchingNodesAndDropsIncidentEdges(): void
    {
        $a = $this->tasks->create(['slug' => 'a', 'title' => 'A', 'status' => Enums::STATUS_OPEN]);
        $b = $this->tasks->create(['slug' => 'b', 'title' => 'B', 'status' => Enums::STATUS_DONE]);
        $this->deps->add($b->id, $a->id);

        $out = $this->graph->serialize(['status' => [Enums::STATUS_OPEN]]);
        self::assertCount(1, $out['nodes']);
        self::assertSame($a->id, $out['nodes'][0]['data']['id']);
        self::assertSame([], $out['edges']);
    }

    public function testTeamFilterScopesNodes(): void
    {
        $a = $this->tasks->create(['slug' => 'a', 'title' => 'A', 'teamId' => 'team-1']);
        $this->tasks->create(['slug' => 'b', 'title' => 'B', 'teamId' => 'team-2']);

        $out = $this->graph->serialize(['team_id' => 'team-1']);
        self::assertCount(1, $out['nodes']);
        self::assertSame($a->id, $out['nodes'][0]['data']['id']);
    }

    public function testRootSubtreeFilterIncludesDescendants(): void
    {
        $root = $this->tasks->create(['slug' => 'root', 'title' => 'R']);
        $c1 = $this->tasks->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id]);
        $gc = $this->tasks->create(['slug' => 'gc', 'title' => 'GC', 'parentId' => $c1->id]);
        $this->tasks->create(['slug' => 'other', 'title' => 'Other']);

        $out = $this->graph->serialize(['root' => $root->id]);
        $ids = array_map(static fn(array $n) => $n['data']['id'], $out['nodes']);
        sort($ids);
        $expected = [$root->id, $c1->id, $gc->id];
        sort($expected);
        self::assertSame($expected, $ids);
    }

    public function testRootSubtreePreservesEdgesWhollyInside(): void
    {
        $root = $this->tasks->create(['slug' => 'root', 'title' => 'R']);
        $c1 = $this->tasks->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id]);
        $other = $this->tasks->create(['slug' => 'other', 'title' => 'Other']);

        $this->deps->add($c1->id, $root->id);    // inside
        $this->deps->add($c1->id, $other->id);   // half-outside

        $out = $this->graph->serialize(['root' => $root->id]);
        self::assertCount(1, $out['edges']);
        self::assertSame($root->id, $out['edges'][0]['data']['source']);
        self::assertSame($c1->id, $out['edges'][0]['data']['target']);
    }

    public function testUnknownRootReturnsEmpty(): void
    {
        $this->tasks->create(['slug' => 'a', 'title' => 'A']);
        $out = $this->graph->serialize(['root' => 'does-not-exist']);
        self::assertSame([], $out['nodes']);
        self::assertSame([], $out['edges']);
    }

    public function testInvalidStatusFilterRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->graph->serialize(['status' => ['bogus']]);
    }

    public function testDeletedStatusNotAcceptedAsFilter(): void
    {
        // STATUSES_LIVE excludes 'deleted'; the visualizer can never render a
        // soft-deleted task, so accepting the filter would be a footgun.
        $this->expectException(InvalidArgumentException::class);
        $this->graph->serialize(['status' => [Enums::STATUS_DELETED]]);
    }

    public function testEmptyFilterStringsTreatedAsAbsent(): void
    {
        $a = $this->tasks->create(['slug' => 'a', 'title' => 'A']);
        $out = $this->graph->serialize(['team_id' => '', 'root' => '']);
        self::assertCount(1, $out['nodes']);
        self::assertSame($a->id, $out['nodes'][0]['data']['id']);
    }

    public function testThresholdFlagFalseAtTypicalSizes(): void
    {
        $this->tasks->create(['slug' => 'a', 'title' => 'A']);
        $out = $this->graph->serialize();
        self::assertFalse($out['meta']['exceeds_threshold']);
        self::assertSame(500, DependencyGraph::RECOMMENDED_NODE_CEILING);
    }
}
