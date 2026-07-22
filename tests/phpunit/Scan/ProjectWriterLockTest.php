<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Scan\ProjectWriterLease;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanBusyException;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('scan-locks')]
final class ProjectWriterLockTest extends TestCase
{
    private function createSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE scan_locks (project_id TEXT PRIMARY KEY, owner_token TEXT NOT NULL, acquired_at INTEGER NOT NULL)');
        return $pdo;
    }

    private function rowCount(PDO $pdo, string $projectId): int
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM scan_locks WHERE project_id = :project');
        $stmt->execute(['project' => $projectId]);
        return (int) $stmt->fetchColumn();
    }

    private function rowExists(PDO $pdo, string $projectId): bool
    {
        return $this->rowCount($pdo, $projectId) === 1;
    }

    private function fetchRow(PDO $pdo, string $projectId): array
    {
        $rows = $pdo->query('SELECT owner_token, acquired_at FROM scan_locks WHERE project_id = ' . $pdo->quote($projectId), PDO::FETCH_ASSOC)->fetchAll();
        assertSame(true, count($rows) === 1);
        return $rows[0];
    }

    public function testAcquireCreatesLeaseAndInsertsRow(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $lease = $lock->acquire('proj_1');

        assertSame(true, $lease instanceof ProjectWriterLease);
        assertSame(true, $this->rowExists($pdo, 'proj_1'));
        $lease->release();
    }

    public function testAcquirePopulatesOwnerTokenAndAcquiredAt(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $lease = $lock->acquire('proj_1');

        $row = $this->fetchRow($pdo, 'proj_1');
        assertSame(32, strlen($row['owner_token']));
        assertSame(true, ctype_xdigit($row['owner_token']));
        assertSame(true, $row['acquired_at'] > 0);
        assertSame(true, abs($row['acquired_at'] - time()) < 5);
        $lease->release();
    }

    public function testReleaseRemovesRowAndIsIdempotent(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $lease = $lock->acquire('proj_1');
        assertSame(true, $this->rowExists($pdo, 'proj_1'));

        $lease->release();
        assertSame(false, $this->rowExists($pdo, 'proj_1'));

        $lease->release();
        assertSame(false, $this->rowExists($pdo, 'proj_1'));
    }

    public function testDestructAutomaticallyReleasesLease(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        (function () use ($lock, $pdo): void {
            $lease = $lock->acquire('proj_2');
            assertSame(true, $this->rowExists($pdo, 'proj_2'));
            unset($lease);
        })();

        assertSame(false, $this->rowExists($pdo, 'proj_2'));
    }

    public function testReleaseOnUnmatchedTokenStillIssuesZeroRowDelete(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);

        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute(['project' => 'proj_1', 'token' => 'real-owner-token', 'acquired' => time()]);

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'fake-token');
        $lease->release();

        assertSame(true, $this->rowExists($pdo, 'proj_1'));
    }

    public function testAcquireThrowsScanBusyExceptionOnUniqueCollision(): void
    {
        $pdo = $this->createSchema();
        $lock = new ProjectWriterLock($pdo);
        $lease = $lock->acquire('proj_3');

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_3'),
            ScanBusyException::class,
        );

        assertSame(true, $error instanceof ScanBusyException);
        assertSame('A scan is already running for project proj_3.', $error->getMessage());
        assertSame(true, $error->getPrevious() instanceof PDOException);
        $lease->release();
    }

    public function testAcquireExpiresStaleLeaseAndInsertsFreshRow(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 2_000_000_000;
        $lock = new ProjectWriterLock($pdo, leaseSeconds: 60, clock: $clock);

        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_4',
            'token' => 'stale-token',
            'acquired' => 1_999_999_900,
        ]);

        $lease = $lock->acquire('proj_4');

        assertSame(true, $lease instanceof ProjectWriterLease);
        $row = $this->fetchRow($pdo, 'proj_4');
        assertSame(true, $row['owner_token'] !== 'stale-token');
        assertSame(32, strlen($row['owner_token']));
        assertSame(true, ctype_xdigit($row['owner_token']));
        assertSame(2_000_000_000, (int) $row['acquired_at']);
        $lease->release();
    }

    public function testAcquireRethrowsNonUniquePdoExceptionAfterRollback(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new PDOException('disk full', 123));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_5'),
            PDOException::class,
        );

        assertSame(true, $error instanceof PDOException);
        assertSame('disk full', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireRollsBackOnGenericThrowable(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new RuntimeException('boom'));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_6'),
            RuntimeException::class,
        );

        assertSame(true, $error instanceof RuntimeException);
        assertSame('boom', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireRollsBackEvenIfRollbackItselfFails(): void
    {
        $execCalls = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            if ($sql === 'ROLLBACK') {
                throw new RuntimeException('rollback failed');
            }
            return 0;
        });
        $pdo->method('prepare')->willThrowException(new PDOException('disk full', 123));

        $lock = new ProjectWriterLock($pdo);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_7'),
            PDOException::class,
        );

        assertSame(true, $error instanceof PDOException);
        assertSame('disk full', $error->getMessage());
        assertSame(['BEGIN IMMEDIATE', 'ROLLBACK'], $execCalls);
    }

    public function testAcquireWithDefaultLeaseSecondsDoesNotExpireRowAtExactBoundary(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 1_000_000;
        // Default leaseSeconds = 3600 (no explicit value)
        $lock = new ProjectWriterLock($pdo, clock: $clock);

        // Manually insert a row at clock - 3600 (exact boundary)
        // With default leaseSeconds=3600: expired = clock - 3600, DELETE clause is `acquired_at < expired`
        // Strict < means the row at exactly expired is NOT deleted → INSERT collides → ScanBusyException
        // With mutated leaseSeconds=3599: expired = clock - 3599, row < expired → DELETE fires → INSERT succeeds
        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_boundary',
            'token' => 'boundary-token',
            'acquired' => 1_000_000 - 3600,
        ]);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_boundary'),
            ScanBusyException::class,
        );

        assertSame(true, $error instanceof ScanBusyException);
    }

    public function testAcquireWithDefaultLeaseSecondsExpiresRowOlderThan3600Seconds(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 1_000_000;
        $lock = new ProjectWriterLock($pdo, clock: $clock);

        // Row at clock - 3601 (just past the default boundary)
        // With default leaseSeconds=3600: row < expired → DELETE fires → INSERT succeeds
        // With mutated leaseSeconds=3601: expired = clock - 3601, row NOT < expired → INSERT collides
        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_3601',
            'token' => 'old-token',
            'acquired' => 1_000_000 - 3601,
        ]);

        $lease = $lock->acquire('proj_3601');

        assertSame(true, $lease instanceof ProjectWriterLease);
        $row = $this->fetchRow($pdo, 'proj_3601');
        assertSame(true, $row['owner_token'] !== 'old-token');
        assertSame(1_000_000, (int) $row['acquired_at']);
        $lease->release();
    }

    public function testAcquireBindingsIncludeAllRequiredKeys(): void
    {
        $execCalls = [];
        $executeBindings = [];
        $pdo = $this->createStub(PDO::class);
        $pdo->method('exec')->willReturnCallback(function (string $sql) use (&$execCalls) {
            $execCalls[] = $sql;
            return 0;
        });
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$executeBindings): PDOStatement {
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturnCallback(function (array $bindings) use (&$executeBindings, $sql): bool {
                $executeBindings[$sql] = $bindings;
                return true;
            });
            return $stmt;
        });

        $lock = new ProjectWriterLock($pdo, leaseSeconds: 3600);

        $lease = $lock->acquire('proj_bindings');

        assertSame(true, $lease instanceof ProjectWriterLease);

        $deleteSql = 'DELETE FROM scan_locks WHERE project_id = :project AND acquired_at < :expired';
        $insertSql = 'INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)';

        assertSame(true, isset($executeBindings[$deleteSql]));
        assertSame(['project', 'expired'], array_keys($executeBindings[$deleteSql]));

        assertSame(true, isset($executeBindings[$insertSql]));
        assertSame(['project', 'token', 'acquired'], array_keys($executeBindings[$insertSql]));

        $lease->release();
    }

    public function testAcquireWithRecentRowDoesNotExpireUnderDefaultLeaseSeconds(): void
    {
        $pdo = $this->createSchema();
        $clock = static fn(): int => 1_000_000;
        $lock = new ProjectWriterLock($pdo, clock: $clock);

        // Row at clock - 1 (just 1 second old): NOT expired by default leaseSeconds=3600
        // Original: expired = clock - 3600, row > expired → DELETE doesn't fire → INSERT collides → ScanBusyException
        // Mutated M#10 ($now + vs -): expired = clock + 3600, row < expired → DELETE fires → INSERT succeeds (no exception)
        $stmt = $pdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, :acquired)');
        $stmt->execute([
            'project' => 'proj_recent',
            'token' => 'recent-token',
            'acquired' => 999_999,
        ]);

        $error = captureThrows(
            static fn(): ProjectWriterLease => $lock->acquire('proj_recent'),
            ScanBusyException::class,
        );

        assertSame(true, $error instanceof ScanBusyException);
        assertSame('A scan is already running for project proj_recent.', $error->getMessage());
    }
}
