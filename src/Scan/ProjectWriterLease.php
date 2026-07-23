<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Closure;
use PDO;

final class ProjectWriterLease
{
    private bool $released = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectId,
        private readonly string $token,
        private readonly ?Closure $clock = null,
    ) {}

    public function __destruct()
    {
        $this->release();
    }

    /**
     * Refresh acquired_at so a legitimately long scan is not expired-deleted
     * by another scanner mid-run. Returns false when zero rows matched, which
     * means the lease was stolen (expired and re-acquired by another process)
     * and this scan must not proceed to reconcile.
     */
    public function renew(): bool
    {
        if ($this->released) {
            return false;
        }
        $now = $this->clock === null ? time() : ($this->clock)();
        $statement = $this->pdo->prepare('UPDATE scan_locks SET acquired_at = :acquired WHERE project_id = :project AND owner_token = :token');
        $statement->execute(['acquired' => $now, 'project' => $this->projectId, 'token' => $this->token]);

        return $statement->rowCount() > 0;
    }

    /** @return int the number of lock rows deleted (0 means the lease was already gone). */
    public function release(): int
    {
        if ($this->released) {
            return 0;
        }
        $statement = $this->pdo->prepare('DELETE FROM scan_locks WHERE project_id = :project AND owner_token = :token');
        $statement->execute(['project' => $this->projectId, 'token' => $this->token]);
        $this->released = true;

        return $statement->rowCount();
    }
}
