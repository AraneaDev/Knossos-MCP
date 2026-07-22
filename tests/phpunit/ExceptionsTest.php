<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use Knossos\Discovery\DiscoveryException;
use Knossos\Reconciliation\ReconciliationException;
use Knossos\Scan\ScanBusyException;
use Knossos\Scan\ScanCancelledException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;

/**
 * Direct tests for the four nano marker-class exception declarations in
 * src/:
 *   - Knossos\Discovery\DiscoveryException        (9 LoC, final)
 *   - Knossos\Reconciliation\ReconciliationException (9 LoC, final)
 *   - Knossos\Scan\ScanBusyException              (9 LoC, final)
 *   - Knossos\Scan\ScanCancelledException         (9 LoC, final)
 *
 * Each source file is just `final class X extends RuntimeException {}` —
 * no body, no constructor override. PHPUnit green + 100% line coverage is
 * the determinable ground truth because the engine MSI is structurally 0
 * mutants for empty class declarations (Infection 0.31.9 cannot produce
 * mutations against a body that has no executable lines).
 *
 * Conventions match the rest of tests/phpunit/: bare global assertSame /
 * assertContains from tests/phpunit/Support/Assertions.php. The
 * `#[CoversClass]` attribute is deliberately NOT applied here because the
 * marker-class exception declarations have no fixtures, no temp paths,
 * and no integration surface — the per-method assertions count as
 * PHPUnit's risk check under `failOnRisky=\"true\"`. KnossosTestCase::
 * repositoryRoot() not needed (no project-root pathlib here).
 */
#[Group('exceptions')]
final class ExceptionsTest extends KnossosTestCase
{
    public function testDiscoveryExceptionIsRuntimeException(): void
    {
        $error = new DiscoveryException('boom');
        assertSame('Knossos\\Discovery\\DiscoveryException', $error::class);
        assertSame(RuntimeException::class, get_parent_class($error));
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertInstanceOf(Throwable::class, $error);
        assertSame('boom', $error->getMessage());
        assertSame(0, $error->getCode());
    }

    public function testReconciliationExceptionIsRuntimeException(): void
    {
        $error = new ReconciliationException('reconcile failed');
        assertSame('Knossos\\Reconciliation\\ReconciliationException', $error::class);
        assertSame(RuntimeException::class, get_parent_class($error));
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertInstanceOf(Throwable::class, $error);
        assertSame('reconcile failed', $error->getMessage());
        assertSame(0, $error->getCode());
    }

    public function testScanBusyExceptionIsRuntimeException(): void
    {
        $error = new ScanBusyException('a scan is already running');
        assertSame('Knossos\\Scan\\ScanBusyException', $error::class);
        assertSame(RuntimeException::class, get_parent_class($error));
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertInstanceOf(Throwable::class, $error);
        assertSame('a scan is already running', $error->getMessage());
        assertSame(0, $error->getCode());
    }

    public function testScanCancelledExceptionIsRuntimeException(): void
    {
        $error = new ScanCancelledException('scan cancelled');
        assertSame('Knossos\\Scan\\ScanCancelledException', $error::class);
        assertSame(RuntimeException::class, get_parent_class($error));
        self::assertInstanceOf(RuntimeException::class, $error);
        self::assertInstanceOf(Throwable::class, $error);
        assertSame('scan cancelled', $error->getMessage());
        assertSame(0, $error->getCode());
    }

    /**
     * Throw + catch path — verifies the exception can actually be raised by
     * user code and surfaces as a Throwable across the wire.
     */
    public function testExceptionsAreThrowableAndCatchable(): void
    {
        $caught = [];
        try {
            throw new DiscoveryException('d');
        } catch (Throwable $error) {
            $caught[] = $error;
        }
        try {
            throw new ReconciliationException('r');
        } catch (Throwable $error) {
            $caught[] = $error;
        }
        try {
            throw new ScanBusyException('b');
        } catch (Throwable $error) {
            $caught[] = $error;
        }
        try {
            throw new ScanCancelledException('c');
        } catch (Throwable $error) {
            $caught[] = $error;
        }
        assertSame(4, count($caught));
        assertSame(DiscoveryException::class, $caught[0]::class);
        assertSame(ReconciliationException::class, $caught[1]::class);
        assertSame(ScanBusyException::class, $caught[2]::class);
        assertSame(ScanCancelledException::class, $caught[3]::class);
    }

    /**
     * Non-trivial message: each abnormal-shape message flows through the
     * constructor unchanged (sanity check that the body-less class doesn't
     * drop or mutate messages on its way through RuntimeException::construct).
     */
    public function testExceptionsPreserveLongAndUnicodeMessages(): void
    {
        $long = str_repeat('error segment ', 24);
        $unicode = 'failure: σ ≠ Σ ∈ ∅';
        foreach (
            [
                [DiscoveryException::class, $long],
                [ReconciliationException::class, $unicode],
                [ScanBusyException::class, ''],
                [ScanCancelledException::class, '0'],
            ] as [$class, $message]
        ) {
            $error = new $class($message);
            assertSame($message, $error->getMessage());
        }
    }
}
