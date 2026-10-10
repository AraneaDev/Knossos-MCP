<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Closure;
use Knossos\Store\ScanBusyException;
use Knossos\Store\SqliteBusy;
use PDO;
use PDOException;
use Throwable;

/**
 * Grants one writer per project at a time.
 *
 * Two concurrent scans of the same project would interleave their reconciliation
 * and leave a graph that matches neither. Leases carry an expiry so a crashed
 * scanner's lock is reclaimable rather than permanent.
 */
final readonly class ProjectWriterLock
{
    public function __construct(private PDO $pdo, private int $leaseSeconds = 3600, private ?Closure $clock = null) {}
    /** Take the project's write lease, or report that another scan holds it. */

    public function acquire(string $projectId): ProjectWriterLease
    {
        $now = $this->clock === null ? time() : ($this->clock)();
        $token = bin2hex(random_bytes(16));
        try {
            // BEGIN IMMEDIATE is inside the try: on a contended WAL database this is
            // exactly where SQLITE_BUSY surfaces, and it must be translated too.
            $this->pdo->exec('BEGIN IMMEDIATE');
            $delete = $this->pdo->prepare('DELETE FROM scan_locks WHERE project_id = :project AND acquired_at < :expired');
            $delete->execute(['project' => $projectId, 'expired' => $now - $this->leaseSeconds]);
            $insert = $this->pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
            $insert->execute(['project' => $projectId, 'token' => $token, 'acquired' => $now]);
            $this->pdo->exec('COMMIT');
        } catch (PDOException $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            if ((string) $error->getCode() === '23000' || str_contains($error->getMessage(), 'UNIQUE constraint')) {
                throw new ScanBusyException(sprintf('A scan is already running for project %s.', $projectId), previous: $error);
            }
            if (SqliteBusy::is($error)) {
                throw new ScanBusyException(sprintf('The graph database is busy; another writer holds the lock (project %s).', $projectId), previous: $error);
            }
            throw $error;
        } catch (Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $error;
        }
        return new ProjectWriterLease($this->pdo, $projectId, $token, $this->clock);
    }
}
