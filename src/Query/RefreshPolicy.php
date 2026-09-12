<?php

declare(strict_types=1);

namespace Knossos\Query;

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

    public function __construct(private PDO $pdo, private int $budgetMs = self::DEFAULT_BUDGET_MS) {}

    /** Whether to rescan before answering, given how many files drifted. */
    public function decide(string $projectId, int $driftedFiles): RefreshDecision
    {
        if ($driftedFiles < 1) {
            return RefreshDecision::decline('Nothing drifted.');
        }

        $perFileMs = $this->perFileCostMs($projectId);
        if ($perFileMs === null) {
            return RefreshDecision::decline('No scan history to estimate a rescan against; call scan_project to refresh.');
        }

        $estimateMs = (int) round($perFileMs * $driftedFiles);
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
     * Milliseconds per file, from the active scan's own wall time.
     *
     * Null when the project has no completed scan to learn from. Zero is a
     * legitimate answer and not the same as null: scan timestamps have
     * second resolution, so a scan that finished inside one second is
     * genuinely too cheap to worry about repeating.
     */
    private function perFileCostMs(string $projectId): ?float
    {
        $statement = $this->pdo->prepare(
            'SELECT s.started_at, s.finished_at, (SELECT COUNT(*) FROM files f WHERE f.last_scan_id = s.id) AS file_count ' .
            'FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id',
        );
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
        if ($row === false || !is_string($row['finished_at']) || !is_string($row['started_at'])) {
            return null;
        }

        $started = strtotime($row['started_at']);
        $finished = strtotime($row['finished_at']);
        $files = (int) $row['file_count'];
        if ($started === false || $finished === false || $files < 1) {
            return null;
        }

        return max(0, ($finished - $started) * 1000) / $files;
    }
}
