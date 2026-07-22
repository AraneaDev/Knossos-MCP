<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use InvalidArgumentException;
use Knossos\Scanner\Worker\WorkerLimits;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class WorkerLimitsTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(WorkerLimits::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorAppliesDefaults(): void
    {
        $l = new WorkerLimits();

        assertSame(30_000, $l->requestTimeoutMs);
        assertSame(1_000_000, $l->maxLineBytes);
        assertSame(20_000_000, $l->maxOutputBytes);
        assertSame(100_000, $l->maxStderrBytes);
    }

    public function testConstructorStoresExplicitValues(): void
    {
        $l = new WorkerLimits(60_000, 256, 10_000, 500);

        assertSame(60_000, $l->requestTimeoutMs);
        assertSame(256, $l->maxLineBytes);
        assertSame(10_000, $l->maxOutputBytes);
        assertSame(500, $l->maxStderrBytes);
    }

    public function testRejectsRequestTimeoutBelowOne(): void
    {
        assertThrows(
            static fn() => new WorkerLimits(requestTimeoutMs: 0),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsMaxLineBytesBelow128(): void
    {
        assertThrows(
            static fn() => new WorkerLimits(maxLineBytes: 127),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsMaxOutputBytesBelowMaxLineBytes(): void
    {
        assertThrows(
            static fn() => new WorkerLimits(maxLineBytes: 500, maxOutputBytes: 400),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsMaxOutputBytesEqualMaxLineBytes(): void
    {
        $l = new WorkerLimits(maxLineBytes: 500, maxOutputBytes: 500);
        assertSame(500, $l->maxLineBytes);
        assertSame(500, $l->maxOutputBytes);
    }

    public function testRejectsMaxStderrBytesBelowZero(): void
    {
        assertThrows(
            static fn() => new WorkerLimits(maxStderrBytes: -1),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsZeroMaxStderrBytes(): void
    {
        $l = new WorkerLimits(maxStderrBytes: 0);
        assertSame(0, $l->maxStderrBytes);
    }
}
