<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;
use PDO;

/**
 * Projects and scans: creating them, promoting a finished scan to the active
 * graph, recording a failure, and pruning snapshot history on completion.
 *
 * Completion is transactional and asserts that exactly one running scan
 * changed state: two writers racing to finish would otherwise leave a project
 * pointing at a graph that only half exists.
 */
final class SqliteScanLifecycle
{
    public function __construct(
        private readonly SqliteStatementCache $statements,
        private readonly SqliteTransactions $transactions,
    ) {}

    /** Upsert by id: a rescan of the same root updates the name/config rather than creating a second project. */
    public function saveProject(string $id, string $name, string $rootRealpath, array $config = []): void
    {
        $now = SqliteValues::now();
        $statement = $this->statements->pdo()->prepare(
            'INSERT INTO projects(id, name, root_realpath, config_json, created_at, updated_at) ' .
            'VALUES (:id, :name, :root, :config, :created, :updated) ' .
            'ON CONFLICT(id) DO UPDATE SET name = excluded.name, root_realpath = excluded.root_realpath, ' .
            'config_json = excluded.config_json, updated_at = excluded.updated_at',
        );
        $statement->execute([
            'id' => $id,
            'name' => $name,
            'root' => $rootRealpath,
            'config' => SqliteValues::json($config),
            'created' => $now,
            'updated' => $now,
        ]);
    }

    /**
     * The project row, or null when the id is unknown.
     *
     * @return array<string, mixed>|null the raw row, or null when the id is unknown
     */
    public function findProject(string $id): ?array
    {
        $statement = $this->statements->pdo()->prepare('SELECT * FROM projects WHERE id = :id');
        $statement->execute(['id' => $id]);
        $project = $statement->fetch();

        return $project === false ? null : $project;
    }

    /**
     * Open a scan in `running` state.
     *
     * @param string $scannerSetHash identifies the analyzer set; a change invalidates
     *        incremental reuse, because facts from a different analyzer are not comparable
     * @throws InvalidArgumentException when $mode is neither full nor incremental
     */
    public function createScan(string $id, string $projectId, string $mode, string $scannerSetHash): void
    {
        if (!in_array($mode, ['full', 'incremental'], true)) {
            throw new InvalidArgumentException('Scan mode must be full or incremental.');
        }

        $statement = $this->statements->pdo()->prepare(
            'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at) ' .
            'VALUES (:id, :project, :mode, :status, :hash, :started)',
        );
        $statement->execute([
            'id' => $id,
            'project' => $projectId,
            'mode' => $mode,
            'status' => 'running',
            'hash' => $scannerSetHash,
            'started' => SqliteValues::now(),
        ]);
    }

    /**
     * Promote a running scan to the project's active snapshot and prune history.
     *
     * Transactional, and asserts exactly one running scan was updated: two writers
     * racing to finish would otherwise leave the project pointing at a graph that
     * only half-exists.
     *
     * @throws InvalidArgumentException when no running scan matches the project
     */
    public function completeScan(string $projectId, string $scanId): void
    {
        $this->transactions->run(function () use ($projectId, $scanId): void {
            $updateScan = $this->statements->pdo()->prepare(
                'UPDATE scans SET status = :status, finished_at = :finished ' .
                'WHERE id = :id AND project_id = :project AND status = :running',
            );
            $updateScan->execute([
                'status' => 'complete',
                'finished' => SqliteValues::now(),
                'id' => $scanId,
                'project' => $projectId,
                'running' => 'running',
            ]);
            if ($updateScan->rowCount() !== 1) {
                throw new InvalidArgumentException('Running scan not found for project.');
            }

            $updateProject = $this->statements->pdo()->prepare(
                'UPDATE projects SET active_scan_id = :scan, updated_at = :updated WHERE id = :project',
            );
            $updateProject->execute([
                'scan' => $scanId,
                'updated' => SqliteValues::now(),
                'project' => $projectId,
            ]);
            $project = $this->findProject($projectId);
            $config = is_array($project) ? json_decode((string) $project['config_json'], true, 32, JSON_THROW_ON_ERROR) : [];
            $this->pruneSnapshotHistory($projectId, is_int($config['snapshot_retention'] ?? null) ? $config['snapshot_retention'] : 5);
        });
    }

    /**
     * Restamp a completed scan's finished_at, recording that its graph was
     * re-verified against the source without being rebuilt.
     *
     * finished_at is read as "when this graph last agreed with the tree", and it
     * is what StalenessProbe compares directory mtimes against to notice added
     * files. A scan that discovered no change has re-established that agreement,
     * so leaving the timestamp where it was reports drift that no later scan
     * could ever clear. Restricted to a complete scan: a running or terminal one
     * has no completion to restate, and its timestamp means something else.
     */
    public function refreshScanCompletion(string $projectId, string $scanId): void
    {
        $this->statements->prepare(
            "UPDATE scans SET finished_at = :finished WHERE id = :id AND project_id = :project AND status = 'complete'",
        )->execute(['finished' => SqliteValues::now(), 'id' => $scanId, 'project' => $projectId]);
    }

    /**
     * Record a terminal failed/cancelled scan for diagnostics.
     *
     * Silently skips a project that was never persisted: the failure may have been
     * the persist itself, and there is nothing for the foreign key to reference.
     *
     * @throws InvalidArgumentException on an unknown mode or a non-terminal status
     */
    public function recordFailedScan(string $id, string $projectId, string $mode, string $status): void
    {
        if (!in_array($mode, ['full', 'incremental'], true)) {
            throw new InvalidArgumentException('Scan mode must be full or incremental.');
        }
        if (!in_array($status, ['failed', 'cancelled'], true)) {
            throw new InvalidArgumentException('Terminal scan status must be failed or cancelled.');
        }
        $this->transactions->run(function () use ($id, $projectId, $mode, $status): void {
            // A terminal record for a project that was never persisted has nothing
            // to reference and nothing to clean up, so skip it rather than trip the
            // scans.project_id foreign key.
            $exists = $this->statements->pdo()->prepare('SELECT 1 FROM projects WHERE id = :project');
            $exists->execute(['project' => $projectId]);
            if ($exists->fetchColumn() === false) {
                return;
            }
            $now = SqliteValues::now();
            $statement = $this->statements->pdo()->prepare(
                'INSERT INTO scans(id, project_id, mode, status, scanner_set_hash, started_at, finished_at) ' .
                'VALUES (:id, :project, :mode, :status, :hash, :started, :finished)',
            );
            $statement->execute([
                'id' => $id,
                'project' => $projectId,
                'mode' => $mode,
                'status' => $status,
                'hash' => '',
                'started' => $now,
                'finished' => $now,
            ]);
        });
    }

    /** Drop the oldest snapshots beyond the project's retention setting. */
    private function pruneSnapshotHistory(string $projectId, int $retention): void
    {
        $statement = $this->statements->pdo()->prepare('SELECT scan_id FROM scan_snapshots WHERE project_id = :project ORDER BY captured_at DESC, rowid DESC');
        $statement->execute(['project' => $projectId]);
        $snapshotIds = $statement->fetchAll(PDO::FETCH_COLUMN);
        $deleteSnapshot = $this->statements->pdo()->prepare('DELETE FROM scan_snapshots WHERE scan_id = :scan AND project_id = :project');
        foreach (array_slice($snapshotIds, $retention) as $snapshotId) {
            $deleteSnapshot->execute(['scan' => $snapshotId, 'project' => $projectId]);
        }
        $deleteScans = $this->statements->pdo()->prepare(
            "DELETE FROM scans WHERE project_id = :project AND status = 'complete' " .
            'AND id <> COALESCE((SELECT active_scan_id FROM projects WHERE id = :project), \'\') ' .
            'AND NOT EXISTS (SELECT 1 FROM scan_snapshots ss WHERE ss.scan_id = scans.id)',
        );
        $deleteScans->execute(['project' => $projectId]);
    }
}
