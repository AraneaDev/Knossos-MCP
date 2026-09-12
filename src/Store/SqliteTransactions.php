<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Write transactions for one SQLite connection.
 *
 * Writes go through BEGIN IMMEDIATE rather than PDO's deferred transaction,
 * because a read-then-write upgrade under WAL can hit a non-retryable
 * SQLITE_BUSY. Nesting uses savepoints, so a caught inner failure rolls back
 * only the inner work. One instance per connection: the open flag is what
 * tells a nested call to use a savepoint, so two instances would each believe
 * they were outermost.
 *
 * Operations take no arguments. `SqliteGraphRepository` binds itself when the
 * `GraphRepository` contract wants the repository passed in.
 */
final class SqliteTransactions
{
    /**
     * Whether this instance has a BEGIN IMMEDIATE open.
     *
     * A flag rather than a depth: nothing but "is one open" was ever asked of
     * the count, and a savepoint inside it never changes the answer.
     */
    private bool $open = false;

    /**
     * The one name every savepoint takes.
     *
     * SQLite resolves ROLLBACK TO and RELEASE to the most recent savepoint of
     * the name, and these nest strictly with the call stack, so the innermost
     * open savepoint is always the one meant. Numbering them bought nothing.
     */
    private const SAVEPOINT = 'knossos_sp';

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Execute an operation atomically and return its result.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed
    {
        if ($this->open || $this->pdo->inTransaction()) {
            return $this->savepoint($operation);
        }

        // BEGIN IMMEDIATE acquires the write lock up front so a read-then-write
        // upgrade under WAL cannot hit a non-retryable SQLITE_BUSY. PDO's
        // beginTransaction() issues a deferred BEGIN, and PDO::inTransaction()
        // only tracks API-level transactions, so the boundary is tracked
        // manually here.
        $this->pdo->exec('BEGIN IMMEDIATE');
        $this->open = true;
        try {
            $result = $operation();
            $this->pdo->exec('COMMIT');
            $this->open = false;

            return $result;
        } catch (Throwable $error) {
            $this->open = false;
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
            throw $error;
        }
    }

    /**
     * Run a whole-graph rewrite, verifying referential integrity once at the end.
     *
     * Per-statement foreign-key enforcement, not the row count, is what a rescan
     * spends its time on: SQLite runs the referencing-table sub-programs for
     * every row deleted, and clearing a large project's graph measured 5.6s
     * that way against 0.6s with enforcement off and a single
     * `PRAGMA foreign_key_check` at the end. The check runs inside the
     * transaction, so a rewrite that would leave a dangling reference is rolled
     * back and never observable — the same guarantee, verified once instead of
     * a few hundred thousand times.
     *
     * `PRAGMA foreign_keys` is a no-op inside a transaction, so it is toggled
     * around the BEGIN and restored in a finally. A nested call cannot do that
     * and runs as an ordinary transaction instead.
     *
     * @template T
     * @param callable(): T $operation
     * @param list<string> $checkedTables the tables whose foreign keys are verified before commit
     *
     * @return T
     */
    public function runBulk(callable $operation, array $checkedTables): mixed
    {
        if ($this->open || $this->pdo->inTransaction()) {
            return $this->run($operation);
        }
        $previous = (string) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn();
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            return $this->run(function () use ($operation, $checkedTables): mixed {
                $result = $operation();
                // Checked per table rather than database-wide: an unrelated
                // table's pre-existing damage is not this rewrite's to fail on,
                // and naming the table keeps the blame where it belongs.
                foreach ($checkedTables as $table) {
                    $violations = $this->pdo->query(sprintf('PRAGMA foreign_key_check(%s)', $table))->fetchAll();
                    if ($violations !== []) {
                        throw new RuntimeException(sprintf(
                            'Refusing to commit a graph with %d dangling reference(s) in table %s.',
                            count($violations),
                            $table,
                        ));
                    }
                }

                return $result;
            });
        } finally {
            // Restored rather than forced on: a caller that had enforcement off
            // did not ask this method to change that.
            $this->pdo->exec('PRAGMA foreign_keys = ' . ($previous === '1' ? 'ON' : 'OFF'));
        }
    }

    /**
     * Run a nested transaction as a SAVEPOINT so a caught inner failure rolls
     * back only the inner work. The previous no-op nesting silently committed
     * partial inner writes with the enclosing transaction.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function savepoint(callable $operation): mixed
    {
        $this->pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        try {
            $result = $operation();
            $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);

            return $result;
        } catch (Throwable $error) {
            try {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
                $this->pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
            } catch (Throwable) {
            }
            throw $error;
        }
    }
}
