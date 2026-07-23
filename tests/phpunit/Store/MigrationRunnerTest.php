<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[Group('migration-runner')]
final class MigrationRunnerTest extends TestCase
{
    private string $tempDir;
    private string $tempSqlite;

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->tempDir);
        }
        if (isset($this->tempSqlite)) {
            if (is_file($this->tempSqlite)) {
                @unlink($this->tempSqlite);
            }
            // Sidecars may appear next to the sqlite file when WAL is enabled.
            foreach (glob($this->tempSqlite . '-*') ?: [] as $f) {
                @unlink($f);
            }
        }
    }

    // ----- helpers -----

    private function freshEnvironment(): array
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // Two minimal synthetic migrations that exercise the runner's logic
        // without depending on the project's full schema in /migrations.
        file_put_contents($this->tempDir . '/001_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        file_put_contents($this->tempDir . '/002_create_b.sql', 'CREATE TABLE b (id INTEGER PRIMARY KEY);');

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        return [$pdo, $this->tempDir];
    }

    private function appliedVersions($pdo): array
    {
        $rows = $pdo->query('SELECT version FROM schema_migrations ORDER BY version')->fetchAll(\PDO::FETCH_COLUMN);

        return is_array($rows) ? array_map('strval', $rows) : [];
    }

    // ----- migrate() -----

    public function testMigrateAppliesAllMigrationsInOrderAndReturnsTheirVersions(): void
    {
        [$pdo, $dir] = $this->freshEnvironment();

        $applied = (new MigrationRunner($pdo, $dir))->migrate();

        assertSame(['001_create_a', '002_create_b'], $applied);
        assertSame(['001_create_a', '002_create_b'], $this->appliedVersions($pdo));

        // Tables were created by their migrations (zero rows but queryable).
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM a')->fetchColumn());
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM b')->fetchColumn());
    }

    public function testMigrateIsIdempotentWhenRunASecondTime(): void
    {
        [$pdo, $dir] = $this->freshEnvironment();

        $runner = new MigrationRunner($pdo, $dir);
        $first = $runner->migrate();
        $second = $runner->migrate();

        assertSame(['001_create_a', '002_create_b'], $first);
        assertSame([], $second);
        assertSame(['001_create_a', '002_create_b'], $this->appliedVersions($pdo));
    }

    public function testMigrateThrowsWhenAppliedMigrationChecksumChanges(): void
    {
        [$pdo, $dir] = $this->freshEnvironment();

        $runner = new MigrationRunner($pdo, $dir);
        $runner->migrate();

        // Tamper with the applied migration's stored checksum to simulate drift.
        $pdo->exec("UPDATE schema_migrations SET checksum = 'deadbeef' WHERE version = '001_create_a'");

        $error = captureThrows(
            static fn () => $runner->migrate(),
            RuntimeException::class,
        );

        $this->assertStringContainsString('Applied migration checksum changed: 001_create_a', $error->getMessage());
    }

    public function testMigrateThrowsWhenMigrationDirectoryDoesNotExist(): void
    {
        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        $target = sys_get_temp_dir() . '/knossos-does-not-exist-' . uniqid('', true);

        $error = captureThrows(
            static fn () => (new MigrationRunner($pdo, $target))->migrate(),
            RuntimeException::class,
        );

        $this->assertStringContainsString('Migration directory does not exist', $error->getMessage());
    }

    public function testMigrateRollsBackAndRethrowsWhenSqlFails(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // First migration is valid; second has invalid SQL to force a throw.
        file_put_contents($this->tempDir . '/001_create_a.sql', 'CREATE TABLE a (id INTEGER PRIMARY KEY);');
        file_put_contents($this->tempDir . '/002_bad.sql', 'THIS IS NOT VALID SQL');

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        // The driver-level invalid-SQL error is rethrown intact by the runner
        // (its catch block has signature `Throwable`); we accept any throwable
        // and assert on the message + rollback semantics instead of pinning
        // the exact driver exception class.
        $caught = null;
        try {
            (new MigrationRunner($pdo, $this->tempDir))->migrate();
        } catch (\Throwable $error) {
            $caught = $error;
        }

        $this->assertNotNull($caught, 'invalid SQL must propagate from migrate()');
        $this->assertStringContainsString('syntax error', strtolower($caught->getMessage()));

        // The invalid migration aborted; only the successful first is recorded.
        assertSame(['001_create_a'], $this->appliedVersions($pdo));
        assertSame(false, $pdo->inTransaction(), 'transaction must be released after rollback');
    }

    public function testNoTransactionMarkerRunsMigrationOutsideRunnerTransaction(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // PRAGMA foreign_keys is a silent no-op inside a transaction, so a
        // table rebuild that must disable enforcement declares the marker.
        // The migration manages its own transaction boundaries.
        file_put_contents($this->tempDir . '/001_parent_child.sql', <<<'SQL'
            CREATE TABLE parent (id INTEGER PRIMARY KEY);
            CREATE TABLE child (
                id INTEGER PRIMARY KEY,
                parent_id INTEGER NOT NULL,
                FOREIGN KEY (parent_id) REFERENCES parent(id) ON DELETE CASCADE
            );
            INSERT INTO parent(id) VALUES (1);
            INSERT INTO child(id, parent_id) VALUES (10, 1);
            SQL);
        file_put_contents($this->tempDir . '/002_rebuild_parent.sql', <<<'SQL'
            -- migrate:no-transaction
            PRAGMA foreign_keys = OFF;
            BEGIN;
            CREATE TABLE parent_new (id INTEGER PRIMARY KEY, note TEXT NOT NULL DEFAULT '');
            INSERT INTO parent_new(id) SELECT id FROM parent;
            DROP TABLE parent;
            ALTER TABLE parent_new RENAME TO parent;
            COMMIT;
            PRAGMA foreign_keys = ON;
            SQL);

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        (new MigrationRunner($pdo, $this->tempDir))->migrate();

        assertSame(['001_parent_child', '002_rebuild_parent'], $this->appliedVersions($pdo));
        // With foreign keys genuinely off during the rebuild, the child rows
        // survive the parent DROP instead of being cascade-deleted.
        assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM child')->fetchColumn());
        assertSame(false, $pdo->inTransaction());
    }

    public function testNoTransactionMarkerFailureDoesNotRecordVersion(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        file_put_contents($this->tempDir . '/001_bad.sql', <<<'SQL'
            -- migrate:no-transaction
            BEGIN;
            CREATE TABLE ok (id INTEGER PRIMARY KEY);
            THIS IS NOT VALID SQL;
            COMMIT;
            SQL);

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        $caught = null;
        try {
            (new MigrationRunner($pdo, $this->tempDir))->migrate();
        } catch (\Throwable $error) {
            $caught = $error;
        }

        $this->assertNotNull($caught, 'invalid SQL must propagate from migrate()');
        assertSame([], $this->appliedVersions($pdo));
        assertSame(false, $pdo->inTransaction(), 'the migration-owned transaction must be rolled back');
    }

    public function testNoTransactionMarkerFailureRestoresForeignKeyEnforcement(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // Fails after disabling foreign keys and before re-enabling them; the
        // runner must not leave the connection with enforcement off.
        file_put_contents($this->tempDir . '/001_bad_rebuild.sql', <<<'SQL'
            -- migrate:no-transaction
            PRAGMA foreign_keys = OFF;
            BEGIN;
            THIS IS NOT VALID SQL;
            COMMIT;
            PRAGMA foreign_keys = ON;
            SQL);

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        $caught = null;
        try {
            (new MigrationRunner($pdo, $this->tempDir))->migrate();
        } catch (\Throwable $error) {
            $caught = $error;
        }

        $this->assertNotNull($caught, 'invalid SQL must propagate from migrate()');
        assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        // The migration's SQL-level BEGIN must not linger either; a fresh
        // transaction would fail with "cannot start a transaction within a
        // transaction" if it did.
        $pdo->exec('BEGIN');
        $pdo->exec('COMMIT');
    }

    public function testNoTransactionMigrationRecordsVersionInsideItsOwnOpenTransaction(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // A no-transaction migration may leave its final transaction open for
        // the runner to commit, so the schema change and its bookkeeping row
        // commit atomically — a crash cannot leave a completed rebuild
        // unrecorded and re-run it.
        file_put_contents($this->tempDir . '/001_open_txn.sql', <<<'SQL'
            -- migrate:no-transaction
            PRAGMA foreign_keys = OFF;
            BEGIN;
            CREATE TABLE thing (id INTEGER PRIMARY KEY);
            SQL);

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        (new MigrationRunner($pdo, $this->tempDir))->migrate();

        // The version was recorded and the runner committed the migration's own
        // open transaction, so the created table is durable.
        assertSame(['001_open_txn'], $this->appliedVersions($pdo));
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM thing')->fetchColumn());
        // The connection's foreign-key contract is restored even though the
        // migration left its transaction open (PRAGMA is a no-op inside a txn).
        assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        assertSame(false, $pdo->inTransaction());
        // No lingering SQL-level transaction: a fresh one must start cleanly.
        $pdo->exec('BEGIN');
        $pdo->exec('COMMIT');
    }

    public function testConstructorIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(MigrationRunner::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testMigrateReturnsEmptyForEmptyMigrationDirectory(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        // No .sql files in the directory

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        $applied = (new MigrationRunner($pdo, $this->tempDir))->migrate();

        assertSame([], $applied);
        assertSame([], $this->appliedVersions($pdo));
        // schema_migrations table is created even with no migrations
        assertSame(
            'schema_migrations',
            $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetchColumn(),
        );
    }

    public function testMigrateThrowsWhenMigrationFileIsUnreadable(): void
    {
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test unreadable file when running as root.');
        }

        $this->tempDir = sys_get_temp_dir() . '/knossos-migrations-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);

        // Create a migration file with mode 0000 to make it unreadable
        $file = $this->tempDir . '/001_create_x.sql';
        file_put_contents($file, 'CREATE TABLE x (id INTEGER PRIMARY KEY);');
        chmod($file, 0000);

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        $error = captureThrows(
            fn () => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            RuntimeException::class,
        );

        $this->assertStringContainsString('Unable to read migration', $error->getMessage());
    }

    public function testMigrateThrowsWhenDirectoryUnreadable(): void
    {
        // An unreadable migration directory yields an empty glob (not false), so
        // the runner guards readability explicitly and fails to enumerate rather
        // than silently applying zero migrations.
        if (posix_getuid() === 0) {
            $this->markTestSkipped('Cannot test an unreadable directory when running as root.');
        }

        $this->tempDir = sys_get_temp_dir() . '/knossos-glob-fail-' . uniqid('', true);
        mkdir($this->tempDir, 0777, true);
        file_put_contents($this->tempDir . '/001_test.sql', 'CREATE TABLE test (id INTEGER PRIMARY KEY);');

        // Remove read permission so glob fails
        chmod($this->tempDir, 0000);

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true) . '.sqlite';
        $pdo = SqliteConnection::open($this->tempSqlite);

        $error = captureThrows(
            fn () => (new MigrationRunner($pdo, $this->tempDir))->migrate(),
            RuntimeException::class,
        );

        $this->assertStringContainsString('Unable to enumerate migration files', $error->getMessage());
    }
}