<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\CancellationToken;
use Knossos\Scan\ScanCancelledException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('cancellation-token')]
final class CancellationTokenTest extends TestCase
{
    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(CancellationToken::class))->isFinal());
    }

    public function testClassIsNotReadonly(): void
    {
        // The class itself is NOT readonly — the `$cancelled` field must remain
        // mutable through cancel(). Only $poll is readonly.
        $this->assertFalse((new ReflectionClass(CancellationToken::class))->isReadOnly());
    }

    public function testPollFieldIsReadonly(): void
    {
        $poll = static fn (): bool => false;
        $pollRef = (new ReflectionClass(CancellationToken::class))->getProperty('poll');
        $this->assertTrue($pollRef->isReadOnly());
        $this->assertNull($pollRef->getDefaultValue());
    }

    public function testCancelledFieldIsMutable(): void
    {
        $ref = new ReflectionClass(CancellationToken::class);
        $cancelledProp = $ref->getProperty('cancelled');
        $this->assertFalse($cancelledProp->isReadOnly());
        assertSame(false, $cancelledProp->getDefaultValue());
    }

    public function testFreshTokenIsNotCancelled(): void
    {
        $token = new CancellationToken();
        assertSame(false, $token->isCancelled());
    }

    public function testCancelFlipsStateToTrue(): void
    {
        // Kills `$this->cancelled = false;` mutation in cancel().
        $token = new CancellationToken();
        $token->cancel();
        assertSame(true, $token->isCancelled());
    }

    public function testCancelIsIdempotent(): void
    {
        $token = new CancellationToken();
        $token->cancel();
        $token->cancel();
        assertSame(true, $token->isCancelled());
    }

    public function testPollClosureIsNotInvokedAtConstruction(): void
    {
        $calls = 0;
        $poll = static function () use (&$calls): bool {
            $calls++;
            return false;
        };
        new CancellationToken($poll);
        // The poll closure must be lazy — only called when isCancelled() is
        // invoked, not stored in the constructor.
        assertSame(0, $calls);
    }

    public function testIsCancelledInvokesPollWhenStateIsFresh(): void
    {
        $calls = 0;
        $poll = static function () use (&$calls): bool {
            $calls++;
            return false;
        };
        $token = new CancellationToken($poll);
        $token->isCancelled();
        assertSame(1, $calls);
    }

    public function testIsCancelledSetsStateWhenPollReturnsTrue(): void
    {
        $token = new CancellationToken(static fn (): bool => true);
        assertSame(true, $token->isCancelled());
    }

    public function testIsCancelledLeavesStateFalseWhenPollReturnsFalse(): void
    {
        $token = new CancellationToken(static fn (): bool => false);
        assertSame(false, $token->isCancelled());
    }

    public function testIsCancelledDoesNotPollAfterStateAlreadyTrue(): void
    {
        // Kills the `! $this->cancelled` mutation that would drop the early-return:
        // the poll closure must NOT be re-invoked once the state is already cancelled.
        $calls = 0;
        $poll = static function () use (&$calls): bool {
            $calls++;
            return true;
        };
        $token = new CancellationToken($poll);

        $token->isCancelled();  // first call: poll fires, state becomes true
        $calls = 0;             // reset so the test only counts post-first-call invocations
        $token->isCancelled();  // second call: state already true, must short-circuit
        assertSame(0, $calls);
    }

    public function testIsCancelledDoesNotPollWhenPollClosureIsNull(): void
    {
        // Kills `$this->poll !== null` mutation to `!== false` — null must
        // short-circuit the poll invocation entirely.
        $token = new CancellationToken();
        $token->isCancelled();
        $token->isCancelled();
        // Nothing observable to assert besides "didn't blow up". The state stays
        // false and no poll was attempted.
        assertSame(false, $token->isCancelled());
    }

    public function testThrowIfCancelledThrowsScanCancelledExceptionWhenCancelled(): void
    {
        $token = new CancellationToken();
        $token->cancel();

        $error = captureThrows(
            static fn () => $token->throwIfCancelled(),
            ScanCancelledException::class,
        );
        assertSame('Scan was cancelled.', $error->getMessage());
    }

    public function testThrowIfCancelledThrowsAfterPollReturnsTrue(): void
    {
        $token = new CancellationToken(static fn (): bool => true);

        $error = captureThrows(
            static fn () => $token->throwIfCancelled(),
            ScanCancelledException::class,
        );
        assertSame('Scan was cancelled.', $error->getMessage());
    }

    public function testThrowIfCancelledDoesNotThrowWhenNotCancelled(): void
    {
        $token = new CancellationToken();
        $calls = 0;
        try {
            $token->throwIfCancelled();
        } catch (ScanCancelledException $error) {
            $calls++;
        }
        assertSame(0, $calls);
    }
}
