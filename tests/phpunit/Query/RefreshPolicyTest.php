<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

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

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, 5)->refresh, '10 ms per file times 5 files is 50 ms, well under budget.');
    }

    /** Over budget, the caller needs the drift count to decide whether to rescan itself. */
    #[Group('query')]
    public function testALargeDriftDeclinesWithAReason(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 10_000, files: 1000);
        $decision = (new RefreshPolicy($pdo))->decide($projectId, 900);

        self::assertFalse($decision->refresh);
        self::assertStringContainsString('900', (string) $decision->reason, 'The caller needs the drift count to decide whether to rescan itself.');
    }

    /** A scan too fast to time is a scan too cheap to worry about repeating; zero is an answer, not an absence. */
    #[Group('query')]
    public function testAnInstantScanAlwaysFitsTheBudget(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 0, files: 1000);

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, 900)->refresh, 'A scan too fast to time is a scan that costs nothing to repeat.');
    }

    /** Without a scan to learn cost from, the policy must decline rather than guess or divide by zero. */
    #[Group('query')]
    public function testAProjectWithNoScanHistoryDeclines(): void
    {
        $pdo = $this->freshTestDatabase();
        $decision = (new RefreshPolicy($pdo))->decide('unknown-project', 5);

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

        self::assertFalse((new RefreshPolicy($pdo))->decide($projectId, 1)->refresh, 'An unrecorded cost is unknown, not zero.');
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

        self::assertTrue((new RefreshPolicy($pdo))->decide($projectId, 5)->refresh, 'When the graph last agreed with the tree says nothing about what rebuilding it costs.');
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

        self::assertTrue($policy->decide($projectId, 450)->refresh, '4500 ms of files plus 500 ms of overhead is exactly the budget.');
        self::assertFalse($policy->decide($projectId, 460)->refresh, '4600 ms of files plus the same overhead is over it.');
    }

    /** No rescan of part of a graph can cost more than the scan that built all of it, so the estimate is capped there. */
    #[Group('query')]
    public function testTheEstimateIsCappedAtWhatAFullScanCost(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 4800, files: 1000);

        self::assertTrue(
            (new RefreshPolicy($pdo))->decide($projectId, 1000)->refresh,
            'Every file drifted, so the rescan is the full scan, which took 4800 ms and fits.',
        );
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
            (new RefreshPolicy($pdo, budgetMs: 550))->decide($projectId, 5)->refresh,
            '500 ms overhead plus 10 ms/file times 5 files is exactly 550 ms; a tie must fall on the side of allowing the refresh.',
        );
        self::assertFalse(
            (new RefreshPolicy($pdo, budgetMs: 549))->decide($projectId, 5)->refresh,
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
            (new RefreshPolicy($pdo))->decide($projectId, 450)->refresh,
            'perFile is 10.001 ms/file; 450 files plus the 500 ms overhead cost 5000.45 ms, over the 5000 ms budget even though round() alone would hide the excess.',
        );
    }

    /** A drift of zero declines without even reading scan cost, because there is nothing to refresh. */
    #[Group('query')]
    public function testZeroDriftDeclinesWithoutMeasuring(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 600_000, files: 1000);

        self::assertFalse((new RefreshPolicy($pdo))->decide($projectId, 0)->refresh, 'Nothing drifted, so there is nothing to refresh.');
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

        $decision = (new RefreshPolicy($pdo))->decide($projectId, 1);

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
     * opposite sides of the `> budget` decision instead of both fitting.
     */
    #[Group('query')]
    public function testARecordedZeroDurationStaysZeroNotOneMillisecond(): void
    {
        [$pdo, $projectId] = $this->seedScanCosting(durationMs: 0, files: 1);

        $decision = (new RefreshPolicy($pdo, budgetMs: 0))->decide($projectId, 1);

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
