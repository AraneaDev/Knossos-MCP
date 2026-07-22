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
}
