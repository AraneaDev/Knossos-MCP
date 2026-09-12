<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteTransactions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Transaction nesting, rollback and the bulk integrity check, exercised on a
 * bare table rather than through a scan.
 *
 * These are the subtlest lines the store owns — an inner failure must roll back
 * only the inner work, and a bulk rewrite must refuse to commit a dangling
 * reference — and before this split they were reachable only through tests that
 * spin up scanner workers.
 */
final class SqliteTransactionsTest extends KnossosTestCase
{
    #[Group('store')]
    public function testRunCommitsAndReturnsTheOperationResult(): void
    {
        $pdo = self::table();

        $result = (new SqliteTransactions($pdo))->run(static function () use ($pdo): string {
            $pdo->exec("INSERT INTO t(v) VALUES ('kept')");

            return 'done';
        });

        assertSame('done', $result);
        assertSame(['kept'], self::values($pdo));
    }

    #[Group('store')]
    public function testRunRollsBackAndRethrowsOnFailure(): void
    {
        $pdo = self::table();

        assertThrows(static fn() => (new SqliteTransactions($pdo))->run(static function () use ($pdo): void {
            $pdo->exec("INSERT INTO t(v) VALUES ('lost')");
            throw new RuntimeException('boom');
        }), RuntimeException::class);

        assertSame([], self::values($pdo));
    }

    #[Group('store')]
    public function testAFailedOuterTransactionLeavesNoneOpen(): void
    {
        $pdo = self::parentChild();
        $transactions = new SqliteTransactions($pdo);

        assertThrows(static fn() => $transactions->run(static function (): void {
            throw new RuntimeException('boom');
        }), RuntimeException::class);

        // A flag left open would route this runBulk down the nested (savepoint)
        // branch, which never turns foreign key enforcement off, so the
        // child-before-parent write below would fail its own FK check
        // immediately instead of succeeding at commit as it does once the
        // flag was actually cleared by the catch block.
        $transactions->runBulk(static function () use ($pdo): void {
            $pdo->exec('INSERT INTO child(id, parent_id) VALUES (1, 7)');
            $pdo->exec('INSERT INTO parent(id) VALUES (7)');
        }, ['child']);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn(), 'A transaction marked open by a failed outer run() must not survive past it.');
    }

    #[Group('store')]
    public function testRunTreatsAConnectionAlreadyInAPdoTransactionAsNested(): void
    {
        $pdo = self::table();
        $pdo->beginTransaction();

        // pdo->inTransaction() is true here (PDO tracks its own
        // beginTransaction()), so run() must take the savepoint branch rather
        // than issuing its own BEGIN IMMEDIATE, which SQLite would refuse
        // while a transaction is already open.
        (new SqliteTransactions($pdo))->run(static function () use ($pdo): void {
            $pdo->exec("INSERT INTO t(v) VALUES ('nested')");
        });

        assertSame(['nested'], self::values($pdo), 'run() must nest via savepoint rather than attempt a second BEGIN.');
        assertSame(true, $pdo->inTransaction(), 'the outer PDO-level transaction must still be open; run() must not have committed it.');

        $pdo->commit();
    }

    #[Group('store')]
    public function testACaughtInnerFailureRollsBackOnlyTheInnerWork(): void
    {
        $pdo = self::table();
        $transactions = new SqliteTransactions($pdo);

        $transactions->run(static function () use ($pdo, $transactions): void {
            $pdo->exec("INSERT INTO t(v) VALUES ('outer')");
            try {
                $transactions->run(static function () use ($pdo): void {
                    $pdo->exec("INSERT INTO t(v) VALUES ('inner')");
                    throw new RuntimeException('inner');
                });
            } catch (RuntimeException) {
            }
        });

        assertSame(['outer'], self::values($pdo), 'The savepoint must discard only the inner row.');
    }

    #[Group('store')]
    public function testSequentialTransactionsEachCommit(): void
    {
        $pdo = self::table();
        $pdo->exec('CREATE TABLE parent(id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child(id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent(id))');
        $transactions = new SqliteTransactions($pdo);

        $transactions->run(static fn() => $pdo->exec("INSERT INTO t(v) VALUES ('one')"));
        $transactions->run(static fn() => $pdo->exec("INSERT INTO t(v) VALUES ('two')"));

        assertSame(['one', 'two'], self::values($pdo), 'Each commit must leave no transaction marked open.');

        // ['one', 'two'] alone cannot tell a cleared flag from a leaked one: SQLite
        // treats a SAVEPOINT opened in autocommit mode as its own transaction, so a
        // leaked flag still commits both rows through the savepoint path. A leaked
        // flag instead shows up here: it routes this runBulk down the nested
        // (savepoint) branch, which never turns foreign key enforcement off, so the
        // child-before-parent write below fails its own FK check immediately instead
        // of succeeding at commit as it does when the flag was cleared.
        $transactions->runBulk(static function () use ($pdo): void {
            $pdo->exec('INSERT INTO child(id, parent_id) VALUES (1, 7)');
            $pdo->exec('INSERT INTO parent(id) VALUES (7)');
        }, ['child']);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn(), 'An open flag must not survive past the transactions that cleared it.');
    }

    #[Group('store')]
    public function testABulkRewriteRefusesToCommitADanglingReference(): void
    {
        $pdo = self::parentChild();

        assertThrows(
            static fn() => (new SqliteTransactions($pdo))->runBulk(
                static fn() => $pdo->exec('INSERT INTO child(id, parent_id) VALUES (1, 999)'),
                ['child'],
            ),
            RuntimeException::class,
        );

        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn());
    }

    #[Group('store')]
    public function testABulkRewriteMayWriteAChildBeforeItsParent(): void
    {
        $pdo = self::parentChild();

        (new SqliteTransactions($pdo))->runBulk(static function () use ($pdo): void {
            $pdo->exec('INSERT INTO child(id, parent_id) VALUES (1, 7)');
            $pdo->exec('INSERT INTO parent(id) VALUES (7)');
        }, ['child']);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn());
    }

    #[Group('store')]
    public function testABulkRewriteRestoresTheForeignKeySettingItFoundOff(): void
    {
        $pdo = self::parentChild();
        // parentChild() leaves enforcement on, so it must be turned off here: a
        // mutant that always restores ON (rather than what it found) would still
        // pass a test that only ever found ON, since found and forced agree.
        $pdo->exec('PRAGMA foreign_keys = OFF');

        (new SqliteTransactions($pdo))->runBulk(static fn() => null, ['child']);

        assertSame('0', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'A restore must return exactly what it found, not force enforcement on.');
    }

    #[Group('store')]
    public function testABulkRewriteRestoresTheForeignKeySettingItFoundEvenWhenTheOperationThrows(): void
    {
        $pdo = self::parentChild();
        $pdo->exec('PRAGMA foreign_keys = ON');

        assertThrows(
            static fn() => (new SqliteTransactions($pdo))->runBulk(static function (): void {
                throw new RuntimeException('boom');
            }, ['child']),
            RuntimeException::class,
        );

        assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'The finally must restore the setting even when the operation throws before the FK check runs.');
    }

    #[Group('store')]
    public function testANestedBulkRewriteRunsAsAnOrdinaryTransaction(): void
    {
        $pdo = self::parentChild();
        $transactions = new SqliteTransactions($pdo);

        $transactions->run(static fn() => $transactions->runBulk(
            static fn() => $pdo->exec('INSERT INTO parent(id) VALUES (1)'),
            ['child'],
        ));

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM parent')->fetchColumn());
    }

    /**
     * run() takes the write lock before the operation reads anything, which
     * is the reason it issues BEGIN IMMEDIATE rather than a deferred BEGIN.
     *
     * Observed from a second connection: while the operation runs, and before
     * it has written a row, that connection cannot open a write transaction of
     * its own. A deferred BEGIN, or a SAVEPOINT opened in autocommit mode,
     * takes no lock until the first write, and the second connection would
     * get in.
     */
    #[Group('store')]
    public function testRunHoldsTheWriteLockBeforeTheOperationWrites(): void
    {
        $path = sys_get_temp_dir() . '/knossos-lock-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $pdo = SqliteConnection::open($path);
            $pdo->exec('CREATE TABLE t(v TEXT NOT NULL)');
            $other = SqliteConnection::open($path);
            $other->exec('PRAGMA busy_timeout = 0');

            $blocked = (new SqliteTransactions($pdo))->run(static function () use ($other): bool {
                try {
                    $other->exec('BEGIN IMMEDIATE');
                    $other->exec('ROLLBACK');

                    return false;
                } catch (\PDOException) {
                    return true;
                }
            });

            assertSame(true, $blocked, 'A second writer got in while run() had written nothing yet.');
            // Released with the transaction.
            assertSame(0, $other->exec('BEGIN IMMEDIATE'));
            $other->exec('ROLLBACK');
        } finally {
            unset($pdo, $other);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Savepoints nest to any depth, and a failure caught at one level undoes
     * only that level's work, however the levels are named.
     */
    #[Group('store')]
    public function testAFailureTwoLevelsDownIsUndoneAtTheLevelThatCaughtIt(): void
    {
        $pdo = self::table();
        $transactions = new SqliteTransactions($pdo);
        $insert = static fn(string $value) => $pdo->exec(sprintf("INSERT INTO t(v) VALUES ('%s')", $value));

        $transactions->run(static function () use ($transactions, $insert): void {
            $insert('outer');
            $transactions->run(static function () use ($transactions, $insert): void {
                $insert('middle');
                try {
                    $transactions->run(static function () use ($transactions, $insert): void {
                        $insert('inner');
                        $transactions->run(static fn() => $insert('innermost'));
                        throw new RuntimeException('inner');
                    });
                } catch (RuntimeException) {
                }
                $insert('middle again');
            });
        });

        assertSame(['outer', 'middle', 'middle again'], self::values($pdo));
    }

    /**
     * Inside a caller's transaction a bulk rewrite cannot switch enforcement
     * off, so it does not check afterwards either: whatever enforcement the
     * caller chose governs its writes. A caller that turned it off gets the
     * write it asked for.
     */
    #[Group('store')]
    public function testANestedBulkRewriteLeavesEnforcementToItsCaller(): void
    {
        $pdo = self::parentChild();
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $transactions = new SqliteTransactions($pdo);

        $transactions->run(static fn() => $transactions->runBulk(
            static fn() => $pdo->exec('INSERT INTO child(id, parent_id) VALUES (1, 999)'),
            ['child'],
        ));

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn());
    }

    /** A connection with one single-column table. */
    private static function table(): PDO
    {
        $pdo = SqliteConnection::open(':memory:');
        $pdo->exec('CREATE TABLE t(v TEXT NOT NULL)');

        return $pdo;
    }

    /** A connection with a foreign key from child to parent, enforcement on. */
    private static function parentChild(): PDO
    {
        $pdo = SqliteConnection::open(':memory:');
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('CREATE TABLE parent(id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE child(id INTEGER PRIMARY KEY, parent_id INTEGER NOT NULL REFERENCES parent(id))');

        return $pdo;
    }

    /** @return list<string> */
    private static function values(PDO $pdo): array
    {
        return $pdo->query('SELECT v FROM t ORDER BY rowid')->fetchAll(PDO::FETCH_COLUMN);
    }
}
