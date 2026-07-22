<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;
use RuntimeException;
use Throwable;

final readonly class MigrationRunner
{
    public function __construct(
        private PDO $pdo,
        private string $migrationDirectory,
    ) {}

    /** @return list<string> versions applied by this invocation */
    public function migrate(): array
    {
        if (!is_dir($this->migrationDirectory)) {
            throw new RuntimeException(sprintf('Migration directory does not exist: %s', $this->migrationDirectory));
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (' .
            'version TEXT PRIMARY KEY, checksum TEXT NOT NULL, applied_at TEXT NOT NULL' .
            ')',
        );

        $files = glob(rtrim($this->migrationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false) {
            throw new RuntimeException('Unable to enumerate migration files.');
        }
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Unable to read migration: %s', $file));
            }
            $checksum = hash('sha256', $sql);

            $statement = $this->pdo->prepare('SELECT checksum FROM schema_migrations WHERE version = :version');
            $statement->execute(['version' => $version]);
            $existing = $statement->fetchColumn();

            if ($existing !== false) {
                if (!hash_equals((string) $existing, $checksum)) {
                    throw new RuntimeException(sprintf('Applied migration checksum changed: %s', $version));
                }
                continue;
            }

            // Migrations marked no-transaction manage their own transaction
            // boundaries. This exists for table rebuilds: PRAGMA foreign_keys
            // is a silent no-op inside a transaction, so a rebuild that must
            // disable enforcement (dropping a parent table would otherwise
            // cascade into its children) cannot run under the runner's own
            // transaction.
            $ownTransaction = !str_starts_with($sql, '-- migrate:no-transaction');
            if ($ownTransaction) {
                $this->pdo->beginTransaction();
            }
            try {
                $this->pdo->exec($sql);
                $insert = $this->pdo->prepare(
                    'INSERT INTO schema_migrations(version, checksum, applied_at) VALUES (:version, :checksum, :applied_at)',
                );
                $insert->execute([
                    'version' => $version,
                    'checksum' => $checksum,
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                if ($ownTransaction) {
                    $this->pdo->commit();
                }
            } catch (Throwable $error) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if (!$ownTransaction) {
                    // The migration's own SQL-level transaction may still be
                    // open (PDO::inTransaction() only tracks API-level ones),
                    // and PRAGMA foreign_keys is a no-op inside it.
                    try {
                        $this->pdo->exec('ROLLBACK');
                    } catch (Throwable) {
                        // No SQL-level transaction was active.
                    }
                    // A failed rebuild may abort between PRAGMA foreign_keys
                    // OFF and ON; the connection contract is enforcement on.
                    $this->pdo->exec('PRAGMA foreign_keys = ON');
                }
                throw $error;
            }

            $applied[] = $version;
        }

        return $applied;
    }
}
