<?php

declare(strict_types=1);

namespace TaskTracker\Tests\Aggregations;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use TaskTracker\Aggregations\SubProjectRollup;
use TaskTracker\Models\Enums;
use TaskTracker\Repositories\TaskRepository;
use TaskTracker\Storage\CsvStore;
use TaskTracker\Storage\EventLog;

final class SubProjectRollupTest extends TestCase
{
    private string $sandbox;
    private string $csvPath;
    private TaskRepository $repo;
    private SubProjectRollup $rollup;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-rollup-' . bin2hex(random_bytes(6));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException("cannot create sandbox: {$base}");
        }
        $this->sandbox = $base;
        $this->csvPath = $base . DIRECTORY_SEPARATOR . 'tasks.csv';
        $logDir = $base . DIRECTORY_SEPARATOR . 'logs';
        mkdir($logDir, 0700, true);

        $events = new EventLog($logDir);
        $this->repo = new TaskRepository(new CsvStore(), $events, $this->csvPath);
        $this->rollup = new SubProjectRollup($this->repo);
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

    public function testEmptyRootIdRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->rollup->rollup('');
    }

    public function testUnknownRootIdReturnsZeros(): void
    {
        $out = $this->rollup->rollup('does-not-exist');
        self::assertSame(0, $out['total_count']);
        self::assertSame(0, $out['descendant_count']);
        self::assertSame(0.0, $out['total_effort_hours']);
        self::assertSame(0, $out['leaf_count']);
        self::assertSame(0, $out['by_status'][Enums::STATUS_OPEN]);
    }

    public function testSingleTaskNoChildrenIsItsOwnLeaf(): void
    {
        $t = $this->repo->create(['slug' => 'a', 'title' => 'A', 'effortHours' => 3.5]);
        $out = $this->rollup->rollup($t->id);

        self::assertSame(1, $out['total_count']);
        self::assertSame(0, $out['descendant_count']);
        self::assertSame(3.5, $out['total_effort_hours']);
        self::assertSame(1, $out['leaf_count']);
        self::assertSame(1, $out['by_status'][Enums::STATUS_OPEN]);
    }

    public function testThreeLevelHierarchyAggregatesAcrossDepths(): void
    {
        // root → c1, c2; c1 → gc
        $root = $this->repo->create(['slug' => 'root', 'title' => 'Root', 'effortHours' => 1.0]);
        $c1   = $this->repo->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id, 'effortHours' => 2.0]);
        $c2   = $this->repo->create(['slug' => 'c2', 'title' => 'C2', 'parentId' => $root->id, 'effortHours' => 4.0, 'status' => Enums::STATUS_DONE]);
        $gc   = $this->repo->create(['slug' => 'gc', 'title' => 'GC', 'parentId' => $c1->id, 'effortHours' => 8.0]);

        $out = $this->rollup->rollup($root->id);

        self::assertSame(4, $out['total_count']);
        self::assertSame(3, $out['descendant_count']);
        self::assertSame(15.0, $out['total_effort_hours']);
        // Leaves in the subtree are c2 and gc.
        self::assertSame(2, $out['leaf_count']);
        self::assertSame(3, $out['by_status'][Enums::STATUS_OPEN]);
        self::assertSame(1, $out['by_status'][Enums::STATUS_DONE]);

        // Subtree of c1 alone: c1 + gc, 2 + 8 hours.
        $sub = $this->rollup->rollup($c1->id);
        self::assertSame(2, $sub['total_count']);
        self::assertSame(1, $sub['descendant_count']);
        self::assertSame(10.0, $sub['total_effort_hours']);
        self::assertSame(1, $sub['leaf_count']);
    }

    public function testSoftDeletedTasksExcluded(): void
    {
        $root = $this->repo->create(['slug' => 'r', 'title' => 'R', 'effortHours' => 1.0]);
        $c1   = $this->repo->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id, 'effortHours' => 2.0]);
        $c2   = $this->repo->create(['slug' => 'c2', 'title' => 'C2', 'parentId' => $root->id, 'effortHours' => 4.0]);

        $this->repo->softDelete($c2->id);

        $out = $this->rollup->rollup($root->id);
        self::assertSame(2, $out['total_count']);
        self::assertSame(1, $out['descendant_count']);
        self::assertSame(3.0, $out['total_effort_hours']);
    }

    public function testNullEffortContributesZero(): void
    {
        $root = $this->repo->create(['slug' => 'r', 'title' => 'R']);
        $c1   = $this->repo->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id, 'effortHours' => 5.0]);

        $out = $this->rollup->rollup($root->id);
        self::assertSame(2, $out['total_count']);
        self::assertSame(5.0, $out['total_effort_hours']);
    }

    public function testAllRollupsCoversEveryParentNode(): void
    {
        $root = $this->repo->create(['slug' => 'r', 'title' => 'R', 'effortHours' => 1.0]);
        $c1   = $this->repo->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id, 'effortHours' => 2.0]);
        $gc   = $this->repo->create(['slug' => 'gc', 'title' => 'GC', 'parentId' => $c1->id, 'effortHours' => 3.0]);

        $all = $this->rollup->allRollups();

        self::assertCount(2, $all);
        self::assertArrayHasKey($root->id, $all);
        self::assertArrayHasKey($c1->id, $all);
        self::assertSame(3, $all[$root->id]['total_count']);
        self::assertSame(6.0, $all[$root->id]['total_effort_hours']);
        self::assertSame(2, $all[$c1->id]['total_count']);
        self::assertSame(5.0, $all[$c1->id]['total_effort_hours']);
    }

    public function testCycleDefensiveDoesNotInfiniteLoop(): void
    {
        // Build a PARENT_ID cycle: a → b → a. The repo doesn't enforce parent
        // FK validity on update, so we can synthesise the corrupt-data case.
        $a = $this->repo->create(['slug' => 'a', 'title' => 'A', 'effortHours' => 1.0]);
        $b = $this->repo->create(['slug' => 'b', 'title' => 'B', 'parentId' => $a->id, 'effortHours' => 2.0]);
        $this->repo->update($a->id, ['parentId' => $b->id]);

        $out = $this->rollup->rollup($a->id);

        self::assertSame(2, $out['total_count']);
        self::assertSame(1, $out['descendant_count']);
        self::assertSame(3.0, $out['total_effort_hours']);
    }

    public function testLeafSumInvariantHoldsForFlatSubtree(): void
    {
        // Spec §13: rollup totals = sum of recursive leaf counts/effort.
        // Parent with zero effort + three childless leaves → effort = sum of leaves.
        $root = $this->repo->create(['slug' => 'r', 'title' => 'R']);
        $this->repo->create(['slug' => 'c1', 'title' => 'C1', 'parentId' => $root->id, 'effortHours' => 1.0]);
        $this->repo->create(['slug' => 'c2', 'title' => 'C2', 'parentId' => $root->id, 'effortHours' => 2.0]);
        $this->repo->create(['slug' => 'c3', 'title' => 'C3', 'parentId' => $root->id, 'effortHours' => 3.0]);

        $out = $this->rollup->rollup($root->id);
        self::assertSame(4, $out['total_count']);
        self::assertSame(3, $out['leaf_count']);
        self::assertSame(6.0, $out['total_effort_hours']);
    }
}
