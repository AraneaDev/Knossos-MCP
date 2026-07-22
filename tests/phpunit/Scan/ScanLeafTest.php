<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CancellationToken;
use Knossos\Scan\ScanAnalysis;
use Knossos\Scan\ScanBusyException;
use Knossos\Scan\ScanCancelledException;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Direct tests for the 4 Scan module leaf-tier files:
 *
 *   - src/Scan/ScanCancelledException.php  (structural-infimum, extends
 *                                          RuntimeException; the
 *                                          cancellation marker thrown by
 *                                          CancellationToken::throwIfCancelled
 *                                          and propagated by Scan services).
 *   - src/Scan/ScanBusyException.php      (structural-infimum, extends
 *                                          RuntimeException; the busy-state
 *                                          marker thrown when a scan is
 *                                          re-entered while another scan is
 *                                          already in progress on the same
 *                                          project).
 *   - src/Scan/CancellationToken.php      (mut-active; tracks cancellation
 *                                          state via internal $cancelled
 *                                          flag + optional poll closure;
 *                                          4 mut-active public methods).
 *   - src/Scan/ScanAnalysis.php           (readonly DTO with 2 promoted
 *                                          props classifications +
 *                                          boundaries; mut-active via the
 *                                          readonly promoted-property
 *                                          constructor).
 *
 * Per the close-out doc § 8 plan from batch 11d (ProjectDiscoverer anchor):
 * batches 5 (Scan planner), 6 (Contribution cache), 8 (ScanAnalysisPipeline),
 * 9 (Scan-related tier) have established that the Scan module's per-batch
 * scope is ~3–5 files at a time. This is the first batch to write
 * PHPUnit tests for the Scan module.
 *
 * Conventions match batches 1–11d: bare global helpers from
 * `tests/phpunit/Support/Assertions.php`; class-level
 * `#[Group('scan-leaves')]`. NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('scan-leaves')]
final class ScanLeafTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    // ===== ScanCancelledException =========================================

    public function testScanCancelledExceptionIsRuntimeExceptionSubclass(): void
    {
        $error = new ScanCancelledException('cancelled');
        assertSame(true, $error instanceof RuntimeException);
    }

    public function testScanCancelledExceptionCanBeThrownAndCaught(): void
    {
        assertThrows(
            static fn() => throw new ScanCancelledException('cancelled'),
            ScanCancelledException::class,
        );
    }

    public function testScanCancelledExceptionPreservesMessage(): void
    {
        $error = new ScanCancelledException('specific message');
        assertSame('specific message', $error->getMessage());
    }

    // ===== ScanBusyException =============================================

    public function testScanBusyExceptionIsRuntimeExceptionSubclass(): void
    {
        $error = new ScanBusyException('busy');
        assertSame(true, $error instanceof RuntimeException);
    }

    public function testScanBusyExceptionCanBeThrownAndCaught(): void
    {
        assertThrows(
            static fn() => throw new ScanBusyException('busy'),
            ScanBusyException::class,
        );
    }

    public function testScanBusyExceptionPreservesMessage(): void
    {
        $error = new ScanBusyException('project scan already in progress');
        assertSame('project scan already in progress', $error->getMessage());
    }

    // ===== CancellationToken =============================================

    public function testCancellationTokenStartsAsNotCancelled(): void
    {
        $token = new CancellationToken();
        assertSame(false, $token->isCancelled());
    }

    public function testCancellationTokenStartsAsNotCancelledWithPoll(): void
    {
        $token = new CancellationToken(static fn() => false);
        assertSame(false, $token->isCancelled());
    }

    public function testCancellationTokenCancelMarksCancelled(): void
    {
        $token = new CancellationToken();
        $token->cancel();
        assertSame(true, $token->isCancelled());
    }

    public function testCancellationTokenPollClosureMarksCancelled(): void
    {
        // Poll closure returning true on the second invocation flips
        // the token to cancelled. The isCancelled() call invokes poll()
        // and memoises the result.
        $invocations = 0;
        $token = new CancellationToken(static function () use (&$invocations): bool {
            ++$invocations;
            return $invocations >= 2;
        });

        assertSame(false, $token->isCancelled());   // 1st poll -> false
        assertSame(true, $token->isCancelled());    // 2nd poll -> true
        assertSame(2, $invocations);
    }

    public function testCancellationTokenPollNotInvokedAfterCancel(): void
    {
        // Once cancelled, isCancelled() must not invoke poll again.
        // This avoids spurious work in the long-running scan loops.
        $invocations = 0;
        $token = new CancellationToken(static function () use (&$invocations): bool {
            ++$invocations;
            return true;
        });

        $token->cancel();
        // Multiple isCancelled() calls after cancel — none should
        // invoke the poll closure.
        $token->isCancelled();
        $token->isCancelled();
        $token->isCancelled();
        assertSame(0, $invocations);
    }

    public function testCancellationTokenPollInvokedOnlyWhilePending(): void
    {
        // Poll invoked on each isCancelled() while pending; once cancelled
        // (either via cancel() or via poll returning true) further
        // isCancelled() calls don't invoke poll.
        $invocations = 0;
        $token = new CancellationToken(static function () use (&$invocations): bool {
            ++$invocations;
            return false;
        });

        assertSame(false, $token->isCancelled());
        assertSame(false, $token->isCancelled());
        assertSame(2, $invocations);

        $token->cancel();
        assertSame(true, $token->isCancelled());
        assertSame(true, $token->isCancelled());
        // No additional poll invocations after cancel.
        assertSame(2, $invocations);
    }

    public function testCancellationTokenThrowIfCancelledNoOpWhenNotCancelled(): void
    {
        $token = new CancellationToken();
        $token->throwIfCancelled();
        // No throw — control reaches here.
        assertSame(false, $token->isCancelled());
    }

    public function testCancellationTokenThrowIfCancelledThrowsAfterCancel(): void
    {
        $token = new CancellationToken();
        $token->cancel();
        assertThrows(
            static function () use ($token): void {
                $token->throwIfCancelled();
            },
            ScanCancelledException::class,
        );
    }

    public function testCancellationTokenThrowIfCancelledThrowsAfterPollReturnsTrue(): void
    {
        $token = new CancellationToken(static fn(): bool => true);
        // No cancel() — the poll closure flips the state on first
        // isCancelled() / throwIfCancelled() call.
        assertThrows(
            static function () use ($token): void {
                $token->throwIfCancelled();
            },
            ScanCancelledException::class,
        );
    }

    public function testCancellationTokenExceptionMessageIsStable(): void
    {
        $token = new CancellationToken();
        $token->cancel();
        $error = captureThrows(
            static function () use ($token): void {
                $token->throwIfCancelled();
            },
            ScanCancelledException::class,
        );
        assertSame('Scan was cancelled.', $error->getMessage());
    }

    // ===== ScanAnalysis (readonly DTO) ==================================

    public function testScanAnalysisConstructorStoresBothPromotedProperties(): void
    {
        $classifications = [(object) ['kind' => 'php']];
        $boundaries = [(object) ['name' => 'core']];
        $analysis = new ScanAnalysis($classifications, $boundaries);

        assertSame($classifications, $analysis->classifications);
        assertSame($boundaries, $analysis->boundaries);
    }

    public function testScanAnalysisAcceptsEmptyLists(): void
    {
        $analysis = new ScanAnalysis([], []);
        assertSame([], $analysis->classifications);
        assertSame([], $analysis->boundaries);
    }

    public function testScanAnalysisAllowsListOfArbitraryObjects(): void
    {
        // The promoted-property type is `list<object>`; mixed anonymous
        // objects pass through.
        $a = new \stdClass();
        $a->key = 'a';
        $b = new \stdClass();
        $b->key = 'b';

        $analysis = new ScanAnalysis([$a], [$b]);
        assertSame($a, $analysis->classifications[0]);
        assertSame($b, $analysis->boundaries[0]);
    }
}