<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Scan\ProjectWriterLease;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-lease')]
final class ProjectWriterLeaseTest extends TestCase
{
    public function testReleaseIsIdempotentAndOnlyExecutesDeleteOnce(): void
    {
        $prepareCount = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepareCount): PDOStatement {
            $prepareCount++;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'token-abc');

        $lease->release();
        $lease->release();
        $lease->release();

        // Only the first release() should run prepare(); subsequent calls early-return on the `released` flag
        assertSame(1, $prepareCount);
    }

    public function testDestructorCallsReleaseExactlyOnce(): void
    {
        $prepareCount = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepareCount): PDOStatement {
            $prepareCount++;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        (function () use ($pdo): void {
            $lease = new ProjectWriterLease($pdo, 'proj_2', 'token-def');
            unset($lease);
        })();

        // __destruct should fire release() exactly once when the lease goes out of scope
        assertSame(1, $prepareCount);
    }

    public function testDestructorAfterExplicitReleaseDoesNotReExecuteDelete(): void
    {
        $prepareCount = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepareCount): PDOStatement {
            $prepareCount++;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        $lease = new ProjectWriterLease($pdo, 'proj_3', 'token-ghi');
        $lease->release();
        unset($lease);

        // After explicit release(), the `released` flag is true; __destruct should be a no-op
        assertSame(1, $prepareCount);
    }

    public function testReleaseDoesNotExecuteWhenProjectIdIsEmpty(): void
    {
        $prepareCount = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepareCount): PDOStatement {
            $prepareCount++;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        $lease = new ProjectWriterLease($pdo, '', 'token-jkl');
        $lease->release();

        assertSame(1, $prepareCount);
        assertSame(true, $lease instanceof ProjectWriterLease);
    }

    public function testReleaseDoesNotExecuteWhenTokenIsEmpty(): void
    {
        $prepareCount = 0;
        $pdo = $this->createStub(PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$prepareCount): PDOStatement {
            $prepareCount++;
            $stmt = $this->createStub(PDOStatement::class);
            $stmt->method('execute')->willReturn(true);
            return $stmt;
        });

        $lease = new ProjectWriterLease($pdo, 'proj_4', '');
        $lease->release();

        assertSame(1, $prepareCount);
    }

    private function schema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE scan_locks (project_id TEXT PRIMARY KEY, owner_token TEXT NOT NULL, acquired_at INTEGER NOT NULL)');
        return $pdo;
    }

    public function testReleaseReturnsDeletedRowCount(): void
    {
        $pdo = $this->schema();
        $pdo->exec("INSERT INTO scan_locks VALUES ('proj_1', 'token-abc', 100)");

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'token-abc');

        assertSame(1, $lease->release());
        assertSame(0, $lease->release());
    }

    public function testReleaseReturnsZeroWhenLeaseAlreadyGone(): void
    {
        $pdo = $this->schema();
        // No row for this token — a stolen/expired lease deletes nothing.
        $lease = new ProjectWriterLease($pdo, 'proj_1', 'ghost-token');

        assertSame(0, $lease->release());
    }

    public function testRenewRefreshesAcquiredAtForMatchingToken(): void
    {
        $pdo = $this->schema();
        $pdo->exec("INSERT INTO scan_locks VALUES ('proj_1', 'token-abc', 100)");

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'token-abc', static fn(): int => 5_000);

        assertSame(true, $lease->renew());
        $acquired = (int) $pdo->query("SELECT acquired_at FROM scan_locks WHERE project_id = 'proj_1'")->fetchColumn();
        assertSame(5_000, $acquired);
    }

    public function testRenewReturnsFalseWhenLeaseWasStolen(): void
    {
        $pdo = $this->schema();
        // The row now belongs to a different owner — this lease was expired and re-acquired.
        $pdo->exec("INSERT INTO scan_locks VALUES ('proj_1', 'thief-token', 100)");

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'my-token');

        assertSame(false, $lease->renew());
    }

    public function testRenewReturnsFalseAfterRelease(): void
    {
        $pdo = $this->schema();
        $pdo->exec("INSERT INTO scan_locks VALUES ('proj_1', 'token-abc', 100)");

        $lease = new ProjectWriterLease($pdo, 'proj_1', 'token-abc');
        $lease->release();

        assertSame(false, $lease->renew());
    }
}
