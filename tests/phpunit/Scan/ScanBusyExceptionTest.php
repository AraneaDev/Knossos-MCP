<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Exception;
use Knossos\Scan\ScanBusyException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

#[Group('scan-busy-exception')]
final class ScanBusyExceptionTest extends TestCase
{
    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(ScanBusyException::class))->isFinal());
    }

    public function testDirectParentIsRuntimeException(): void
    {
        assertSame(RuntimeException::class, get_parent_class(ScanBusyException::class));
    }

    public function testAncestorChainIncludesException(): void
    {
        $parents = class_parents(ScanBusyException::class);
        $this->assertContains(Exception::class, $parents);
    }

    public function testInstanceIsThrowable(): void
    {
        $this->assertInstanceOf(Throwable::class, new ScanBusyException('busy'));
    }

    public function testInstanceIsRuntimeException(): void
    {
        $this->assertInstanceOf(RuntimeException::class, new ScanBusyException('busy'));
    }

    public function testThrownExceptionCarriesMessage(): void
    {
        $error = captureThrows(
            static fn () => throw new ScanBusyException('scan X is busy'),
            ScanBusyException::class,
        );
        assertSame('scan X is busy', $error->getMessage());
    }

    public function testThrownExceptionCarriesMessageCodeAndPrevious(): void
    {
        $previous = new LogicException('lock contention');
        $error = captureThrows(
            static fn () => throw new ScanBusyException('busy', 7, $previous),
            ScanBusyException::class,
        );
        assertSame('busy', $error->getMessage());
        assertSame(7, $error->getCode());
        assertSame($previous, $error->getPrevious());
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        // Prove the throw site is polymorphic against the supertype — kills any
        // mutation that changes `extends RuntimeException` to something else.
        $caught = captureThrows(
            static fn () => throw new ScanBusyException('busy'),
            RuntimeException::class,
        );
        $this->assertInstanceOf(ScanBusyException::class, $caught);
        assertSame('busy', $caught->getMessage());
    }
}
