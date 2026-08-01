<?php

declare(strict_types=1);

namespace Knossos\Maintenance;

use DateTimeImmutable;
use InvalidArgumentException;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectWriterLease;
use Knossos\Scan\ProjectWriterLock;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Housekeeping on the derived graph database.
 *
 * Integrity check, WAL checkpoint, optimize, atomic backup, and the destructive
 * project and stale-scan removals. The destructive operations preview by default
 * and act only when explicitly told to, because the caller is often an agent and
 * a mistaken deletion is not recoverable from inside the session.
 */
final readonly class DatabaseMaintenanceService
{
    public function __construct(private PDO $pdo, private string $databasePath) {}
    /** Delete a project and its graph, previewing the row counts unless executing. */

    public function removeProject(string $projectId, bool $execute = false): ResultEnvelope
    {
        $project = $this->project($projectId);
        $counts = $this->projectCounts($projectId);
        if (!$execute) {
            return new ResultEnvelope($projectId, $project['active_scan_id'] ?? '', 'Dry run: project would be removed.', [
                'executed' => false, 'project' => ['id' => $projectId, 'name' => $project['name']], 'counts' => $counts,
            ], warnings: ['Set execute=true to permanently remove this project and its stored graph.']);
        }

        $lease = (new ProjectWriterLock($this->pdo))->acquire($projectId);
        try {
            $this->pdo->beginTransaction();
            $clear = $this->pdo->prepare('UPDATE projects SET active_scan_id = NULL WHERE id = :project');
            $clear->execute(['project' => $projectId]);
            $delete = $this->pdo->prepare('DELETE FROM projects WHERE id = :project');
            $delete->execute(['project' => $projectId]);
            $this->pdo->commit();
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        } finally {
            $lease->release();
        }

        return new ResultEnvelope($projectId, $project['active_scan_id'] ?? '', 'Project and stored graph removed.', [
            'executed' => true, 'project' => ['id' => $projectId, 'name' => $project['name']], 'removed' => $counts,
        ]);
    }
    /** Drop failed, cancelled, or abandoned scan records older than the cutoff. */

    public function cleanupStaleScans(string $projectId, int $olderThanHours = 24, bool $execute = false): ResultEnvelope
    {
        if ($olderThanHours < 1 || $olderThanHours > 8760) {
            throw new InvalidArgumentException('older_than_hours must be between 1 and 8760.');
        }
        $project = $this->project($projectId);
        $cutoff = (new DateTimeImmutable())->modify(sprintf('-%d hours', $olderThanHours));
        $statement = $this->pdo->prepare(
            "SELECT s.id, s.status, s.started_at FROM scans s WHERE s.project_id = :project AND s.id <> COALESCE(:active, '') " .
            "AND s.status IN ('running', 'failed', 'cancelled') ORDER BY s.started_at, s.id LIMIT 1001",
        );
        $statement->execute(['project' => $projectId, 'active' => $project['active_scan_id']]);
        $candidates = [];
        foreach ($statement->fetchAll() as $scan) {
            try {
                $started = new DateTimeImmutable($scan['started_at']);
            } catch (Throwable) {
                continue;
            }
            if ($started < $cutoff) {
                $candidates[] = $scan;
            }
        }
        $truncated = count($candidates) > 1000;
        $candidates = array_slice($candidates, 0, 1000);
        $protected = [];
        $removable = [];
        foreach ($candidates as $scan) {
            if ($this->scanReferenceCount($scan['id']) > 0) {
                $protected[] = $scan['id'];
            } else {
                $removable[] = $scan['id'];
            }
        }
        if ($execute && $removable !== []) {
            $lease = (new ProjectWriterLock($this->pdo))->acquire($projectId);
            try {
                $this->pdo->beginTransaction();
                $delete = $this->pdo->prepare('DELETE FROM scans WHERE id = :scan AND project_id = :project');
                foreach ($removable as $scanId) {
                    $delete->execute(['scan' => $scanId, 'project' => $projectId]);
                }
                $this->pdo->commit();
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $error;
            } finally {
                $lease->release();
            }
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'] ?? '',
            sprintf('%s %d stale scan%s.', $execute ? 'Removed' : 'Dry run: would remove', count($removable), count($removable) === 1 ? '' : 's'),
            ['executed' => $execute, 'older_than_hours' => $olderThanHours, 'removable_scan_ids' => $removable, 'protected_scan_ids' => $protected],
            warnings: $protected === [] ? ($execute ? [] : ['Set execute=true to remove the listed stale scans.']) : ['Referenced scans were protected from cleanup.'],
            truncated: $truncated,
        );
    }
    /** Run one maintenance action, previewing unless explicitly told to execute. */

    public function maintain(string $action, bool $execute = false, ?string $backupName = null): ResultEnvelope
    {
        if (!in_array($action, ['integrity', 'checkpoint', 'optimize', 'vacuum', 'backup'], true)) {
            throw new InvalidArgumentException('action must be integrity, checkpoint, optimize, vacuum, or backup.');
        }
        if ($action === 'integrity') {
            $rows = $this->pdo->query('PRAGMA integrity_check(100)')->fetchAll(PDO::FETCH_COLUMN);
            $ok = $rows === ['ok'];
            return new ResultEnvelope('database', '', $ok ? 'Database integrity check passed.' : 'Database integrity check reported problems.', [
                'action' => $action, 'executed' => true, 'ok' => $ok, 'results' => $rows,
            ], warnings: $ok ? [] : ['Restore from a verified backup before attempting manual repair.']);
        }

        if (!$execute) {
            $data = ['action' => $action, 'executed' => false];
            if ($action === 'backup') {
                $data['target'] = $this->backupTarget($backupName);
            }
            return new ResultEnvelope('database', '', sprintf('Dry run: database %s would run.', $action), $data, warnings: ['Set execute=true to perform this maintenance action.']);
        }

        $leases = $this->acquireAllProjectLeases();
        try {
            $data = ['action' => $action, 'executed' => true];
            if ($action === 'checkpoint') {
                $data['result'] = $this->pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetch();
            } elseif ($action === 'optimize') {
                $this->pdo->exec('PRAGMA optimize');
            } elseif ($action === 'vacuum') {
                $data += $this->vacuum();
            } else {
                $data += $this->backup($backupName);
            }
        } finally {
            foreach (array_reverse($leases) as $lease) {
                $lease->release();
            }
        }

        return new ResultEnvelope('database', '', sprintf('Database %s completed.', $action), $data);
    }

    /**
     * Rebuild the database file, returning the free pages the rebuild reclaimed.
     *
     * Deleting a project, or ageing a retained snapshot out of history, leaves
     * its pages on the free list rather than returning them to the filesystem.
     * Snapshots are the largest rows this schema holds, so without a rebuild the
     * file keeps the high-water mark of every graph ever archived into it.
     *
     * @return array{reclaimed_pages: int, page_size: int, freed_bytes: int}
     */
    private function vacuum(): array
    {
        $before = (int) $this->pdo->query('PRAGMA freelist_count')->fetchColumn();
        $pageSize = (int) $this->pdo->query('PRAGMA page_size')->fetchColumn();
        // VACUUM cannot run inside a transaction, and acquireAllProjectLeases
        // has already kept writers out for the duration.
        $this->pdo->exec('VACUUM');
        // VACUUM rewrites every page through the write-ahead log, so without
        // this the reclaimed space reappears as a WAL the size of the database.
        $this->pdo->query('PRAGMA wal_checkpoint(TRUNCATE)')->fetchAll();
        $after = (int) $this->pdo->query('PRAGMA freelist_count')->fetchColumn();
        $reclaimed = max(0, $before - $after);

        return ['reclaimed_pages' => $reclaimed, 'page_size' => $pageSize, 'freed_bytes' => $reclaimed * $pageSize];
    }

    /**
     * The project row, so a removal reports what it is about to delete.
     *
     * @return array<string, mixed>
     */
    private function project(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT id, name, active_scan_id FROM projects WHERE id = :project');
        $statement->execute(['project' => $projectId]);
        $project = $statement->fetch();
        if (!is_array($project)) {
            throw new InvalidArgumentException(sprintf('Unknown project: %s', $projectId));
        }
        return $project;
    }

    /**
     * Row counts per table for a project, which is what makes a removal preview meaningful.
     *
     * @return array<string, int>
     */
    private function projectCounts(string $projectId): array
    {
        $counts = [];
        foreach (['scans', 'files', 'nodes', 'edges', 'diagnostics', 'annotations'] as $table) {
            $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE project_id = :project', $table));
            $statement->execute(['project' => $projectId]);
            $counts[$table] = (int) $statement->fetchColumn();
        }
        return $counts;
    }
    /** How many scans still reference a project, so a removal cannot orphan history. */

    private function scanReferenceCount(string $scanId): int
    {
        $count = 0;
        foreach (['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships'] as $table) {
            $statement = $this->pdo->prepare(sprintf('SELECT COUNT(*) FROM %s WHERE last_scan_id = :scan', $table));
            $statement->execute(['scan' => $scanId]);
            $count += (int) $statement->fetchColumn();
        }
        return $count;
    }

    /**
     * Take every writer lease before a destructive change, so no scan is mid-write.
     *
     * @return list<ProjectWriterLease>
     */
    private function acquireAllProjectLeases(): array
    {
        $ids = $this->pdo->query('SELECT id FROM projects ORDER BY id LIMIT 1001')->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 1000) {
            throw new RuntimeException('Database maintenance is limited to 1000 projects per invocation.');
        }
        $leases = [];
        try {
            $lock = new ProjectWriterLock($this->pdo);
            foreach ($ids as $id) {
                $leases[] = $lock->acquire((string) $id);
            }
        } catch (Throwable $error) {
            foreach (array_reverse($leases) as $lease) {
                $lease->release();
            }
            throw $error;
        }
        return $leases;
    }
    /** Resolve and validate the backup path, refusing to overwrite an existing file. */

    private function backupTarget(?string $backupName): string
    {
        if ($this->databasePath === ':memory:') {
            throw new InvalidArgumentException('Backup requires a file-backed database.');
        }
        $backupName ??= 'knossos-' . gmdate('Ymd-His') . '.sqlite';
        if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,119}\.sqlite\z/', $backupName)) {
            throw new InvalidArgumentException('backup_name must be a simple .sqlite filename without directories.');
        }
        return dirname($this->databasePath) . '/backups/' . $backupName;
    }

    /**
     * Copy the database atomically via SQLite's backup API rather than a file copy, which would capture a torn WAL.
     *
     * @return array{target: string, bytes: int}
     */
    private function backup(?string $backupName): array
    {
        $target = $this->backupTarget($backupName);
        $directory = dirname($target);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the database backup directory.');
        }
        if (file_exists($target)) {
            throw new InvalidArgumentException('Backup target already exists.');
        }
        $temporary = $directory . '/.' . basename($target) . '.' . bin2hex(random_bytes(8)) . '.tmp';
        try {
            $this->pdo->exec('VACUUM INTO ' . $this->pdo->quote($temporary));
            $copy = new PDO('sqlite:' . $temporary, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $copy->exec('DELETE FROM scan_locks');
            $copy = null;
            if (!rename($temporary, $target)) {
                throw new RuntimeException('Unable to atomically publish database backup.');
            }
            chmod($target, 0600);
            return ['target' => $target, 'bytes' => filesize($target) ?: 0];
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
