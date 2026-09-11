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

        assertSame(['one', 'two'], self::values($pdo), 'Depth must return to zero after each commit.');

        // ['one', 'two'] alone cannot tell a reset depth from a leaked one: SQLite
        // treats a SAVEPOINT opened in autocommit mode as its own transaction, so a
        // leaked depth still commits both rows through the savepoint path. A leaked
        // depth instead shows up here: it routes this runBulk down the nested
        // (savepoint) branch, which never turns foreign key enforcement off, so the
        // child-before-parent write below fails its own FK check immediately instead
        // of succeeding at commit as it does when the depth was reset to zero.
        $transactions->runBulk(static function () use ($pdo): void {
            $pdo->exec('INSERT INTO child(id, parent_id) VALUES (1, 7)');
            $pdo->exec('INSERT INTO parent(id) VALUES (7)');
        }, ['child']);

        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn(), 'A leaked depth must not survive past the transactions that reset it.');
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
