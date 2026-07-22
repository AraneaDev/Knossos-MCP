<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Exception;
use Knossos\Reconciliation\ReconciliationException;
use LogicException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Throwable;

#[Group('reconciliation-exception')]
final class ReconciliationExceptionTest extends TestCase
{
    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(ReconciliationException::class))->isFinal());
    }

    public function testDirectParentIsRuntimeException(): void
    {
        // Kills any `extends` mutation to a different exception class.
        assertSame(RuntimeException::class, get_parent_class(ReconciliationException::class));
    }

    public function testAncestorChainIncludesException(): void
    {
        // Walks the inheritance chain through PHP's class_parents; ensures
        // catching via the bare Exception interface / Throwable interface works.
        $parents = class_parents(ReconciliationException::class);
        $this->assertContains(Exception::class, $parents);
    }

    public function testInstanceIsThrowable(): void
    {
        $this->assertInstanceOf(Throwable::class, new ReconciliationException('boom'));
    }

    public function testInstanceIsRuntimeException(): void
    {
        // Verifies the polymorphic identity — must satisfy `instanceof RuntimeException`.
        $this->assertInstanceOf(RuntimeException::class, new ReconciliationException('boom'));
    }

    public function testThrownExceptionCarriesMessage(): void
    {
        $error = captureThrows(
            static fn () => throw new ReconciliationException('kapow'),
            ReconciliationException::class,
        );
        assertSame('kapow', $error->getMessage());
    }

    public function testThrownExceptionCarriesMessageCodeAndPrevious(): void
    {
        $previous = new LogicException('original');
        $error = captureThrows(
            static fn () => throw new ReconciliationException('kapow', 42, $previous),
            ReconciliationException::class,
        );
        assertSame('kapow', $error->getMessage());
        assertSame(42, $error->getCode());
        assertSame($previous, $error->getPrevious());
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        // Kills a mutation that makes the parent a non-RuntimeException class
        // by demonstrating we can catch via the RuntimeException type.
        $caught = captureThrows(
            static fn () => throw new ReconciliationException('kapow'),
            RuntimeException::class,
        );
        $this->assertInstanceOf(ReconciliationException::class, $caught);
        assertSame('kapow', $caught->getMessage());
    }
}
