<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

/**
 * Reports how far a project's graph has drifted from its source.
 *
 * Attached to query results so an answer from an out-of-date graph is visibly
 * qualified rather than silently wrong, and so tools can offer to rescan before
 * answering.
 */
final readonly class StalenessProbe
{
    private Closure $wallClock;

    public function __construct(private PDO $pdo, ?Closure $wallClock = null)
    {
        $this->wallClock = $wallClock ?? static fn(): int => time();
    }

    /**
     * How far a project's graph has drifted from its source, or null when it has none.
     *
     * @return array<string, mixed>|null
     */
    public function probe(string $projectId): ?array
    {
        // 'catalog' and 'server' are scopes, not projects: the tools using them
        // describe the server itself. Probing them found no project row and
        // reported state 'missing' with advice to run scan_project — a project
        // that does not exist, and a scan that would not change the answer.
        if ($projectId === '' || $projectId === 'catalog' || $projectId === 'server') {
            return null;
        }
        $project = $this->fetchProject($projectId);
        if ($project === null) {
            return $this->missing();
        }
        $activeScanId = $project['active_scan_id'];
        if (!is_string($activeScanId) || $activeScanId === '') {
            return $this->missing();
        }

        $finishedAt = $this->activeFinishedAt($activeScanId);
        $ageSeconds = $this->age($finishedAt);
        $newerAttempt = $this->hasNewerAttempt($projectId, $activeScanId);
        $changed = $this->changedFilesSince($projectId, $activeScanId, (string) $project['root_realpath']);

        // 'unverified' when no newer scan attempt exists but content-change
        // detection was skipped (root missing, or too many files to fingerprint):
        // the graph cannot be confirmed fresh, and must not be reported as such.
        $state = match (true) {
            $newerAttempt || ($changed !== null && $changed > 0) => 'stale',
            $changed === null => 'unverified',
            default => 'fresh',
        };
        $result = [
            'state' => $state,
            'scanned_at' => $finishedAt,
            'age_seconds' => $ageSeconds,
        ];
        if ($changed !== null) {
            $result['changed_files_since'] = $changed;
        }
        if ($state === 'stale') {
            $result['guidance'] = 'Graph may be stale; rescan with scan_project for current results.';
        } elseif ($state === 'unverified') {
            $result['guidance'] = 'Change detection was skipped (project root unavailable or too many files); freshness is unconfirmed. Rescan with scan_project to be certain.';
        }
        return $result;
    }

    /**
     * The shape returned when a project has no graph at all, which requires a scan rather than a refresh.
     *
     * @return array<string, mixed>
     */
    private function missing(): array
    {
        return [
            'state' => 'missing',
            'scanned_at' => null,
            'age_seconds' => null,
            'guidance' => 'No active graph for this project; call scan_project first.',
        ];
    }

    /**
     * The project row backing the probe.
     *
     * @return array<string, mixed>|null
     */
    private function fetchProject(string $projectId): ?array
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id, root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }
    /** When the active scan finished, the reference point for every age calculation. */

    private function activeFinishedAt(string $scanId): ?string
    {
        $statement = $this->pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);
        $row = $statement->fetch();
        if ($row === false || !is_string($row['finished_at'])) {
            return null;
        }
        return $row['finished_at'];
    }
    /** Seconds since the graph was built, which is what makes staleness legible. */

    private function age(?string $finishedAt): ?int
    {
        if ($finishedAt === null) {
            return null;
        }
        $then = strtotime($finishedAt);
        if ($then === false) {
            return null;
        }
        return max(0, ($this->wallClock)() - $then);
    }
    /** Whether a later scan attempt exists, so a failed rescan is distinguishable from never trying. */

    private function hasNewerAttempt(string $projectId, string $activeScanId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT id, status FROM scans WHERE project_id = :project ORDER BY started_at DESC, id DESC LIMIT 1',
        );
        $statement->execute(['project' => $projectId]);
        $latest = $statement->fetch();
        if ($latest === false) {
            return false;
        }
        return $latest['id'] !== $activeScanId
            && in_array($latest['status'], ['running', 'failed', 'cancelled'], true);
    }
    /** How many files changed since the scan, the strongest signal that an answer is out of date. */

    private function changedFilesSince(string $projectId, string $activeScanId, string $root): ?int
    {
        if (!is_dir($root)) {
            return null;
        }
        $count = $this->pdo->prepare('SELECT COUNT(*) FROM files WHERE project_id = :project AND last_scan_id = :scan');
        $count->execute(['project' => $projectId, 'scan' => $activeScanId]);
        if ((int) $count->fetchColumn() > 500) {
            return null; // bound exceeded; omit best-effort field
        }
        $statement = $this->pdo->prepare(
            'SELECT relative_path, mtime FROM files WHERE project_id = :project AND last_scan_id = :scan LIMIT 500',
        );
        $statement->execute(['project' => $projectId, 'scan' => $activeScanId]);
        $changed = 0;
        foreach ($statement->fetchAll() as $file) {
            $current = @filemtime($root . '/' . $file['relative_path']);
            if ($current !== false && $current > (int) $file['mtime']) {
                ++$changed;
            }
        }
        return $changed;
    }
}
