<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootNotFoundException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('discovery-exception')]
final class DiscoveryExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $reflection = new \ReflectionClass(DiscoveryException::class);

        $this->assertTrue($reflection->isSubclassOf(RuntimeException::class));
    }

    public function testIsOpenOnlySoFarAsRootNotFoundExceptionNeeds(): void
    {
        // Not final, and the only class in Discovery that is not: RootGuard has
        // two refusals to report and every caller catches this one type, so the
        // narrower one has to be reachable through it. The narrower one is
        // final, so the opening stops here rather than becoming a hierarchy.
        $this->assertFalse((new \ReflectionClass(DiscoveryException::class))->isFinal());
        $this->assertTrue((new \ReflectionClass(RootNotFoundException::class))->isFinal());
        $this->assertTrue(is_subclass_of(RootNotFoundException::class, DiscoveryException::class));
    }

    public function testARootNotFoundExceptionIsCaughtByEveryExistingDiscoveryCatch(): void
    {
        // The one property that makes the split safe to introduce: nothing that
        // already catches DiscoveryException had to change.
        $caught = null;

        try {
            throw new RootNotFoundException('no such directory');
        } catch (DiscoveryException $error) {
            $caught = $error;
        }

        assertSame('no such directory', $caught->getMessage());
        assertSame(RootNotFoundException::class, $caught::class);
    }

    public function testCanBeThrownWithMessageAndConstructedFromPrevious(): void
    {
        $previous = new \LogicException('boom');

        $error = captureThrows(
            static fn () => throw new DiscoveryException('wrap', 7, $previous),
            DiscoveryException::class,
        );

        assertSame('wrap', $error->getMessage());
        assertSame(7, $error->getCode());
        assertSame($previous, $error->getPrevious());
    }

    public function testCanBeCaughtAsRuntimeException(): void
    {
        $caught = null;

        try {
            throw new DiscoveryException('discovery failed');
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        assertSame('discovery failed', $caught->getMessage());
        assertSame('Knossos\\Discovery\\DiscoveryException', $caught::class);
    }
}