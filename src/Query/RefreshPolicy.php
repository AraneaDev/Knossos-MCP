<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Query\Drift\DriftCounts;
use PDO;

/**
 * Decides whether refreshing a stale graph fits inside a query the caller is
 * already waiting on.
 *
 * Cost comes from the project's own last scan rather than a tuned constant: a
 * PHP monolith and a small TypeScript package do not cost the same per file,
 * and only the project knows which it is. When there is nothing to measure the
 * policy declines, because a cost that cannot be estimated cannot be capped,
 * and a query held open past the client's timeout returns nothing at all.
 */
final readonly class RefreshPolicy
{
    public const DEFAULT_BUDGET_MS = 5000;

    /**
     * What a rescan costs before it touches a single drifted file.
     *
     * A rescan is not a linear fraction of a full one. Rescanning one file
     * still pays for discovery, for starting the language workers, and for
     * reconciling the result, and those do not shrink with the change set.
     * Modelling the cost as per-file alone made a one-file refresh look free
     * on a large project and unaffordable on a small one. Deliberately small
     * against the default budget, so the overhead term alone never declines a
     * refresh the per-file term would have allowed.
     */
    private const FIXED_OVERHEAD_MS = 500;

    public function __construct(private PDO $pdo, private int $budgetMs = self::DEFAULT_BUDGET_MS) {}

    /**
     * Whether to rescan before answering, given what drifted.
     *
     * Takes the three counts rather than one total because the historical cap
     * below depends on their composition: a change set of additions describes
     * a scan larger than the last one, and the last one's duration is then not
     * an upper bound on anything. A caller that knows only a total must
     * present it as additions, which is the uncapped and therefore
     * conservative side.
     *
     * Wrong in the direction of allowing a rescan holds a query open past the
     * client's own timeout, which returns the caller nothing at all — worse
     * than the stale-but-served answer a correct decline would have given.
     * Wrong the other way costs only a warning and the previous graph, which
     * is why every uncertainty in this method (an unmeasurable cost, a tie at
     * the budget, a fractional millisecond rounded) resolves toward declining
     * rather than toward allowing.
     */
    public function decide(string $projectId, DriftCounts $drift): RefreshDecision
    {
        $driftedFiles = $drift->total();
        if ($driftedFiles < 1) {
            return RefreshDecision::decline('Nothing drifted.');
        }

        $cost = $this->scanCost($projectId);
        if ($cost === null) {
            return RefreshDecision::decline('No recorded scan duration to estimate a rescan against; call scan_project to refresh.');
        }

        // Rounded up, not to nearest: round() turns a true 5000.4 ms estimate
        // into 5000 and lets it slip under a 5000 ms budget it never actually
        // fit. ceil() cannot manufacture a false decline the way round() can
        // manufacture a false allow, so it is the only direction that keeps
        // the estimate a ceiling rather than an approximation.
        $estimateMs = (int) ceil($this->cap($cost, $drift, self::FIXED_OVERHEAD_MS + $cost['perFile'] * $driftedFiles));
        // Strict >, not >=: an estimate that lands exactly on the budget is
        // allowed. The budget is already a deliberately conservative cap (see
        // the class docblock), so a tie is the estimate saying "exactly what
        // you asked for," not "slightly over" — and >= would flip that on any
        // future retuning of FIXED_OVERHEAD_MS or DEFAULT_BUDGET_MS that
        // happens to land the arithmetic on a round number.
        if ($estimateMs > $this->budgetMs) {
            return RefreshDecision::decline(sprintf(
                '%d files drifted, an estimated %d ms to rescan, over the %d ms budget; call scan_project to refresh.',
                $driftedFiles,
                $estimateMs,
                $this->budgetMs,
            ));
        }

        return RefreshDecision::allow();
    }

    /**
     * The estimate, capped at what the whole graph cost to build where that
     * cap actually holds.
     *
     * It holds only when the rescan is a subset of the scan it is compared
     * against: no rescan of part of a graph can be dearer than building all of
     * it. Additions break that. A project of ten files that has gained two
     * thousand is not rescanning a subset of anything — the next scan is far
     * larger than the last — and capping there shrank a correctly large
     * estimate down to the cost of the smaller old graph, which is how an
     * over-budget refresh was allowed to run inside a query the caller was
     * waiting on.
     *
     * So the cap applies only to a change set with no additions in it. Where
     * there are additions the uncapped estimate stands, which is the
     * conservative side: too large an estimate costs a decline and a warning,
     * too small a one costs the caller their whole answer.
     *
     * @param array{total: float, perFile: float} $cost
     */
    private function cap(array $cost, DriftCounts $drift, float $estimate): float
    {
        return $drift->added > 0 ? $estimate : min($cost['total'], $estimate);
    }

    /**
     * What the active scan measured itself as costing, whole and per file.
     *
     * Read from the duration the scan recorded rather than from its
     * timestamps. finished_at is restamped every time a rescan finds no
     * change — it means "when this graph last agreed with the tree" — so
     * subtracting started_at from it measured elapsed wall-clock time since
     * the scan, and grew without bound. A 73 ms scan was costed at 21 seconds.
     *
     * Null is the only signal for "unknown", and it must stay that way: a
     * scan predating the duration column knows nothing about its own cost,
     * and an unknown cost cannot be capped. A recorded zero is a different
     * and legitimate answer, a scan too fast to time being a scan too cheap
     * to worry about repeating, so the caller tests for null strictly.
     *
     * @return array{total: float, perFile: float}|null
     */
    private function scanCost(string $projectId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT s.duration_ms, (SELECT COUNT(*) FROM files f WHERE f.last_scan_id = s.id) AS file_count ' .
            'FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id',
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
        if ($row === false || !is_numeric($row['duration_ms'])) {
            return null;
        }

        $files = (int) $row['file_count'];
        if ($files < 1) {
            return null;
        }
        $total = max(0.0, (float) $row['duration_ms']);

        return ['total' => $total, 'perFile' => $total / $files];
    }
}
