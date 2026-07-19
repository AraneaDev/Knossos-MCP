<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use PDO;

final readonly class StalenessProbe
{
    private Closure $wallClock;

    public function __construct(private PDO $pdo, ?Closure $wallClock = null)
    {
        $this->wallClock = $wallClock ?? static fn(): int => time();
    }

    /** @return array<string, mixed>|null */
    public function probe(string $projectId): ?array
    {
        if ($projectId === '' || $projectId === 'catalog') {
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

        $isStale = $newerAttempt || ($changed !== null && $changed > 0);
        $result = [
            'state' => $isStale ? 'stale' : 'fresh',
            'scanned_at' => $finishedAt,
            'age_seconds' => $ageSeconds,
        ];
        if ($changed !== null) {
            $result['changed_files_since'] = $changed;
        }
        if ($isStale) {
            $result['guidance'] = 'Graph may be stale; rescan with scan_project for current results.';
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function missing(): array
    {
        return [
            'state' => 'missing',
            'scanned_at' => null,
            'age_seconds' => null,
            'guidance' => 'No active graph for this project; call scan_project first.',
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchProject(string $projectId): ?array
    {
        $statement = $this->pdo->prepare('SELECT active_scan_id, root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

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
