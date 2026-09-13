<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\RefreshPolicy;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The budget is what makes a default-on refresh safe. Every branch here is a
 * promise that a query cannot be held open longer than the caller's client will
 * wait, and the degraded answer is always better than a timed-out one.
 */
final class RefreshPolicyTest extends KnossosTestCase
{
    /** 10 ms/file times a small drift lands well inside the default budget. */
    #[Group('query')]
    public function testASmallDriftRefreshes(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_000, files: 1000);

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(5, 0, 0))->refresh, '10 ms per file times 5 files is 50 ms, well under budget.');
    }

    /** Over budget, the caller needs the drift count to decide whether to rescan itself. */
    #[Group('query')]
    public function testALargeDriftDeclinesWithAReason(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_000, files: 1000);
        $decision = (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(900, 0, 0));

        self::assertFalse($decision->refresh);
        self::assertStringContainsString('900', (string) $decision->reason, 'The caller needs the drift count to decide whether to rescan itself.');
    }

    /** A scan too fast to time is a scan too cheap to worry about repeating; zero is an answer, not an absence. */
    #[Group('query')]
    public function testAnInstantScanAlwaysFitsTheBudget(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 0, files: 1000);

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(900, 0, 0))->refresh, 'A scan too fast to time is a scan that costs nothing to repeat.');
    }

    /** Without a scan to learn cost from, the policy must decline rather than guess or divide by zero. */
    #[Group('query')]
    public function testAProjectWithNoScanHistoryDeclines(): void
    {
        $pdo = $this->freshTestDatabase();
        $decision = (new RefreshPolicy($pdo))->decide('unknown-project', new DriftCounts(5, 0, 0));

        self::assertFalse($decision->refresh, 'A cost that cannot be measured cannot be capped.');
    }

    /**
     * A graph built before the duration column existed knows nothing about
     * what it cost. Unknown is not free, and substituting a guess is how an
     * unbounded scan gets back inside a query someone is waiting on.
     */
    #[Group('query')]
    public function testAScanThatRecordedNoDurationDeclines(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: null, files: 1000);

        self::assertFalse((new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(1, 0, 0))->refresh, 'An unrecorded cost is unknown, not zero.');
    }

    /**
     * The defect the duration column exists for. finished_at is restamped
     * every time a rescan finds no change, so a policy reading it as a
     * duration watched its own estimate grow with the clock and disabled the
     * feature after one refresh.
     */
    #[Group('query')]
    public function testAMovedCompletionTimestampDoesNotChangeTheEstimate(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_000, files: 1000);
        $pdo->prepare('UPDATE scans SET finished_at = :finished')->execute(['finished' => gmdate('Y-m-d\TH:i:s\Z', time() + 86_400)]);

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(5, 0, 0))->refresh, 'When the graph last agreed with the tree says nothing about what rebuilding it costs.');
    }

    /**
     * An incremental rescan pays discovery, worker startup and reconciliation
     * whatever the change set, so the estimate carries a fixed term. Without
     * it a 460-file drift on a 10 ms/file project read as comfortably
     * affordable, and the scan it bought was not.
     */
    #[Group('query')]
    public function testTheFixedOverheadIsPartOfTheEstimate(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_000, files: 1000);
        $policy = new RefreshPolicy($pdo);

        self::assertTrue($policy->decide($projectId, new DriftCounts(450, 0, 0))->refresh, '4500 ms of files plus 500 ms of overhead is exactly the budget.');
        self::assertFalse($policy->decide($projectId, new DriftCounts(460, 0, 0))->refresh, '4600 ms of files plus the same overhead is over it.');
    }

    /**
     * A drift of deletions alone keeps the historical cap, which is the whole
     * reason the cap exists: a large drift on a cheap project must not be
     * modelled out of reach when the rescan really is bounded by the scan it
     * is compared against.
     *
     * Deletions are where that bound genuinely holds. Nothing is read, so
     * nothing can have grown since it was last read, and what is left to scan
     * is strictly less than what the 4800 ms already paid for.
     */
    #[Group('query')]
    public function testADeletionOnlyDriftKeepsTheHistoricalCap(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 4800, files: 1000);

        self::assertTrue(
            (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(0, 0, 1000))->refresh,
            'Nothing has to be read, so the rescan cannot be dearer than the 4800 ms scan it is a subset of.',
        );
    }

    /**
     * A saturated addition count is a floor, and a floor cannot be costed. The
     * arithmetic here fits to the millisecond: 9 ms a file times the reported
     * 500, plus the 500 ms overhead, is exactly the 5000 ms budget, so the
     * reported number is allowed while the 501st file the walk never counted
     * would have put it over. Declining is the only answer that does not
     * depend on how much the walk happened not to see.
     */
    #[Group('query')]
    public function testASaturatedAdditionCountIsDeclinedThoughItsArithmeticFits(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 9000, files: 1000);
        $policy = new RefreshPolicy($pdo);

        self::assertTrue(
            $policy->decide($projectId, new DriftCounts(0, 500, 0))->refresh,
            'An exact 500 costs exactly the budget and is allowed, which is what makes the truncated case below a decision about the truncation and not about the arithmetic.',
        );
        $decision = $policy->decide($projectId, new DriftCounts(0, 500, 0, true));
        self::assertFalse($decision->refresh, 'The same number, known to be a floor, cannot be costed at all.');
        self::assertStringContainsString('At least 500', (string) $decision->reason, 'The caller is told the count is a floor rather than being handed it as a size.');
    }

    /**
     * The historical cap is an upper bound only while the rescan is a subset
     * of the scan it is compared against. Additions break that: a small
     * project that has gained thousands of files has a next scan far larger
     * than its last, so capping at the last one's duration shrank a correctly
     * large estimate down to the cost of the smaller old graph and allowed an
     * over-budget refresh to run inside a query the caller was waiting on.
     *
     * Ten files costing 100 ms is 10 ms/file, so two thousand added files
     * estimate at 20500 ms — four times the 5000 ms budget — while the cap
     * would shrink the same estimate to the old scan's own 100 ms and wave it
     * through.
     */
    #[Group('query')]
    public function testAnAdditionHeavyDriftIsNotCappedAtTheOldScanDuration(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 100, files: 10);

        $decision = (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(0, 2000, 0));

        self::assertFalse($decision->refresh, 'A scan of two thousand new files is not bounded by what ten files cost to scan.');
        self::assertStringContainsString('20500 ms', (string) $decision->reason, 'The estimate the caller is told about must be the uncapped one it was actually declined on.');
    }

    /**
     * One addition beside a large set of changes is still an addition: the
     * next scan covers files the last one never saw, so the cap does not
     * hold for the set as a whole. Pinned because a cap keyed on the
     * majority, or on additions outnumbering changes, would read as
     * reasonable and reopen the same hole for a mixed change set.
     */
    #[Group('query')]
    public function testASingleAdditionAmongChangesAlsoLiftsTheCap(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 100, files: 10);

        self::assertFalse(
            (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(1999, 1, 0))->refresh,
            'A change set holding any addition is not a subset of the previous scan, so the previous scan bounds nothing.',
        );
    }

    /**
     * A changed file lifts the cap the way an addition does, because the
     * previous scan's duration says what those files cost when they held
     * different bytes.
     *
     * The file count is a subset and the cost is not: a changed file that has
     * grown, or gained the construct its analyzer is slowest on, is dearer
     * than the average the old duration divides into, and nothing has
     * measured it. Even setting that aside the cap is not an upper bound, as
     * this fixture shows: a rescan costing exactly the old 4800 ms still pays
     * the 500 ms of fixed overhead the cap discards, so the capped estimate
     * of 4800 waved a 5300 ms refresh through a 5000 ms budget.
     */
    #[Group('query')]
    public function testAChangedFileLiftsTheHistoricalCapTheWayAnAdditionDoes(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 4800, files: 1000);

        $decision = (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(600, 0, 400));

        self::assertFalse($decision->refresh, 'The old duration bounds a rescan of bytes that are no longer there.');
        self::assertStringContainsString('5300 ms', (string) $decision->reason, 'And the caller is told the uncapped estimate it was actually declined on.');
    }

    /**
     * decide() compares with strict `>`, so an estimate exactly equal to the
     * budget is allowed, not declined. Pinned on its own budget rather than
     * riding the default one so a later change to FIXED_OVERHEAD_MS or
     * DEFAULT_BUDGET_MS cannot make this test's boundary drift along with it.
     */
    #[Group('query')]
    public function testAnEstimateExactlyAtTheBudgetIsAllowed(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_000, files: 1000);

        self::assertTrue(
            (new RefreshPolicy($pdo, budgetMs: 550))->decide($projectId, new DriftCounts(5, 0, 0))->refresh,
            '500 ms overhead plus 10 ms/file times 5 files is exactly 550 ms; a tie must fall on the side of allowing the refresh.',
        );
        self::assertFalse(
            (new RefreshPolicy($pdo, budgetMs: 549))->decide($projectId, new DriftCounts(5, 0, 0))->refresh,
            'One ms over the budget must still decline.',
        );
    }

    /**
     * round() rounds to nearest, so a true cost of 5000.45 ms would round down
     * to 5000 and read as fitting a 5000 ms budget it never actually fit.
     * Rounding the estimate up instead keeps the error on the safe side.
     */
    #[Group('query')]
    public function testAFractionalMillisecondOverBudgetIsNotRoundedAway(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_001, files: 1000);

        self::assertFalse(
            (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(450, 0, 0))->refresh,
            'perFile is 10.001 ms/file; 450 files plus the 500 ms overhead cost 5000.45 ms, over the 5000 ms budget even though round() alone would hide the excess.',
        );
    }

    /** A drift of zero declines without even reading scan cost, because there is nothing to refresh. */
    #[Group('query')]
    public function testZeroDriftDeclinesWithoutMeasuring(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 600_000, files: 1000);

        self::assertFalse((new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(0, 0, 0))->refresh, 'Nothing drifted, so there is nothing to refresh.');
    }

    /**
     * `scanCost()` guards its file count with `$files < 1`, mutable to `<=`.
     * A scan whose active files count is exactly one is a legitimate,
     * measurable scan, not the divide-by-zero case that guard exists to
     * catch: it must still produce a cost estimate. Flipping the guard to
     * `<=` would treat a one-file project as unmeasurable and decline with
     * "No recorded scan duration", which this test would catch since it
     * asserts an allow instead.
     */
    #[Group('query')]
    public function testExactlyOneTrackedFileStillEstimatesACost(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 100, files: 1);

        $decision = (new RefreshPolicy($pdo))->decide($projectId, new DriftCounts(1, 0, 0));

        self::assertTrue($decision->refresh, 'One tracked file is a measurable scan (100 ms / 1 file), well under budget; it must not be declined as unmeasurable.');
        self::assertNull($decision->reason);
    }

    /**
     * `scanCost()` floors a recorded duration at `max(0.0, ...)`, mutable to
     * `max(1.0, ...)`. A scan that timed at exactly zero milliseconds is a
     * genuine, cheap-to-repeat answer and must stay zero, not become a
     * phantom one millisecond: the class docblock calls this distinction
     * load-bearing, separate from the null case that means "unknown".
     * Pinned with a zero budget so the two costs (0 ms vs 1 ms) fall on
     * opposite sides of the `> budget` decision instead of both fitting, and
     * on a deletion-only drift because the historical cap is what carries the
     * recorded duration into the decision at all: any other change set is
     * estimated from the per-file rate plus the fixed overhead, where a
     * difference of one millisecond in a total nobody caps is invisible.
     */
    #[Group('query')]
    public function testARecordedZeroDurationStaysZeroNotOneMillisecond(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 0, files: 1);

        $decision = (new RefreshPolicy($pdo, budgetMs: 0))->decide($projectId, new DriftCounts(0, 0, 1));

        self::assertTrue($decision->refresh, 'A true zero-cost scan estimates 0 ms, which fits even a 0 ms budget; max(1.0, ...) would estimate 1 ms and be declined instead.');
    }

    /**
     * Seeds a project whose completed scan recorded $durationMs over $files
     * files, so RefreshPolicy's cost estimate is a fixed, known quantity for
     * the arithmetic each test asserts against. A null duration stands for a
     * graph built before the scan recorded one.
     *
     * @return array{0: PDO, 1: string}
     */
    private function seedScanCosting(?int $durationMs, int $files): array
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $this->removeTempTree($root);

        $scanIdStatement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $scanIdStatement->execute(['id' => $projectId]);
        $scanId = (string) $scanIdStatement->fetchColumn();

        $pdo->prepare('UPDATE scans SET duration_ms = :duration WHERE id = :id')->execute([
            'duration' => $durationMs,
            'id' => $scanId,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) ' .
            'VALUES (:id, :project, :path, :hash, 1, 1, :language, :version, :scan)',
        );
        for ($index = 1; $index < $files; ++$index) {
            $insert->execute([
                'id' => 'f' . $index, 'project' => $projectId, 'path' => 'src/f' . $index . '.php',
                'hash' => hash('sha256', (string) $index), 'language' => 'php', 'version' => '0.1.0', 'scan' => $scanId,
            ]);
        }

        return [$pdo, $projectId];
    }
}
