<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scan's cost is measured and stored, never inferred from its timestamps.
 *
 * finished_at is restamped whenever a rescan finds no change, so it says when
 * the graph last agreed with the tree rather than how long building it took.
 * RefreshPolicy caps an automatic rescan against this column, and a wrong
 * number there either declines every refresh or admits an unbounded one.
 */
final class ScanDurationTest extends KnossosTestCase
{
    /** The measurement the refresh budget is spent against has to survive the write. */
    #[Group('store')]
    public function testItRecordsTheDurationOfACompletedScan(): void
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        $repository->saveProject('p1', 'Fixture', sys_get_temp_dir());
        $repository->createScan('s1', 'p1', 'full', hash('sha256', 'set'));
        $repository->completeScan('p1', 's1');

        $repository->recordScanDuration('p1', 's1', 1234);

        self::assertSame(1234, (int) $pdo->query("SELECT duration_ms FROM scans WHERE id = 's1'")->fetchColumn());
    }

    /** A scan still running has no completed work to describe, so its cost stays unknown rather than becoming a number. */
    #[Group('store')]
    public function testItLeavesARunningScanUnmeasured(): void
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        $repository->saveProject('p1', 'Fixture', sys_get_temp_dir());
        $repository->createScan('s1', 'p1', 'full', hash('sha256', 'set'));

        $repository->recordScanDuration('p1', 's1', 1234);

        self::assertNull($pdo->query("SELECT duration_ms FROM scans WHERE id = 's1'")->fetchColumn());
    }

    /** A graph built before this column existed reports no cost at all, which is what makes the policy decline rather than guess. */
    #[Group('store')]
    public function testAScanThatWasNeverMeasuredHasNoDuration(): void
    {
        $pdo = $this->freshTestDatabase();
        $repository = new SqliteGraphRepository($pdo);
        $repository->saveProject('p1', 'Fixture', sys_get_temp_dir());
        $repository->createScan('s1', 'p1', 'full', hash('sha256', 'set'));
        $repository->completeScan('p1', 's1');

        self::assertNull($pdo->query("SELECT duration_ms FROM scans WHERE id = 's1'")->fetchColumn());
    }
}
