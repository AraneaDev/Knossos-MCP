<?php

declare(strict_types=1);

namespace Knossos\Scan;

use PDO;

final class ProjectWriterLease
{
    private bool $released = false;

    public function __construct(private readonly PDO $pdo, private readonly string $projectId, private readonly string $token) {}

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $statement = $this->pdo->prepare('DELETE FROM scan_locks WHERE project_id = :project AND owner_token = :token');
        $statement->execute(['project' => $this->projectId, 'token' => $this->token]);
        $this->released = true;
    }
}
