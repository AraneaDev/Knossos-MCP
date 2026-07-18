<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use PDO;
use RuntimeException;
use Throwable;

final readonly class HttpSessionStore
{
    public const INITIALIZED = 'initialized';
    public const ALREADY_INITIALIZED = 'already_initialized';
    public const UNKNOWN_OR_EXPIRED = 'unknown_or_expired';
    public const CAPACITY_ERROR = 1;

    public function __construct(private PDO $pdo, private int $ttlSeconds = 1800, private int $maxSessions = 1000) {}

    public function create(): string
    {
        $now = time();
        $ownsTransaction = !$this->pdo->inTransaction();
        $transactionActive = false;
        if ($ownsTransaction) {
            // A deferred transaction would allow two creators to observe the same
            // count. Acquire SQLite's single writer slot before cleanup/counting.
            $this->pdo->exec('BEGIN IMMEDIATE');
            $transactionActive = true;
        }

        try {
            $this->pdo->prepare('DELETE FROM http_sessions WHERE expires_at <= :now')->execute(['now' => $now]);
            $count = (int) $this->pdo->query('SELECT COUNT(*) FROM http_sessions')->fetchColumn();
            if ($count >= $this->maxSessions) {
                throw new RuntimeException('HTTP session capacity is exhausted.', self::CAPACITY_ERROR);
            }
            $id = bin2hex(random_bytes(32));
            $statement = $this->pdo->prepare('INSERT INTO http_sessions (id, initialized, created_at, expires_at) VALUES (:id, 0, :now, :expires)');
            $statement->execute(['id' => hash('sha256', $id), 'now' => $now, 'expires' => $now + $this->ttlSeconds]);
            if ($ownsTransaction) {
                $this->pdo->exec('COMMIT');
                $transactionActive = false;
            }
            return $id;
        } catch (Throwable $error) {
            if ($transactionActive) {
                $this->pdo->exec('ROLLBACK');
            }
            throw $error;
        }
    }

    public function exists(string $id): bool
    {
        return $this->row($id) !== null;
    }

    public function initialized(string $id): bool
    {
        return ($this->row($id)['initialized'] ?? 0) === 1;
    }

    /** @return self::INITIALIZED|self::ALREADY_INITIALIZED|self::UNKNOWN_OR_EXPIRED */
    public function markInitialized(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return self::UNKNOWN_OR_EXPIRED;
        }
        $now = time();
        $hashedId = hash('sha256', $id);
        $statement = $this->pdo->prepare('UPDATE http_sessions SET initialized = 1, expires_at = :expires WHERE id = :id AND initialized = 0 AND expires_at > :now');
        $statement->execute(['id' => $hashedId, 'now' => $now, 'expires' => $now + $this->ttlSeconds]);
        if ($statement->rowCount() === 1) {
            return self::INITIALIZED;
        }

        $statement = $this->pdo->prepare('SELECT initialized FROM http_sessions WHERE id = :id AND expires_at > :now');
        $statement->execute(['id' => $hashedId, 'now' => $now]);
        return $statement->fetchColumn() === false ? self::UNKNOWN_OR_EXPIRED : self::ALREADY_INITIALIZED;
    }

    public function delete(string $id): void
    {
        $this->pdo->prepare('DELETE FROM http_sessions WHERE id = :id')->execute(['id' => hash('sha256', $id)]);
    }

    /** @return array{initialized: int}|null */
    private function row(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
            return null;
        }
        $statement = $this->pdo->prepare('SELECT initialized FROM http_sessions WHERE id = :id AND expires_at > :now');
        $statement->execute(['id' => hash('sha256', $id), 'now' => time()]);
        $row = $statement->fetch();
        return $row === false ? null : ['initialized' => (int) $row['initialized']];
    }
}
