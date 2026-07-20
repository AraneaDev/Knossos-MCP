<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use PHPUnit\Framework\Attributes\CoversNothing;
use RuntimeException;

#[CoversNothing]
final class KnossosTestCaseTest extends KnossosTestCase
{
    public function testAssertThrowsWithMatchesOnThrowableClass(): void
    {
        // The second argument is a throwable class name (preserving the
        // original assertThrows semantics), not a message substring.
        self::assertThrowsWith(static fn() => throw new RuntimeException('boom: bad path'), RuntimeException::class);
    }

    public function testCaptureThrownReturnsTheThrowable(): void
    {
        $error = self::captureThrown(static fn() => throw new RuntimeException('boom'), RuntimeException::class);
        self::assertSame('boom', $error->getMessage());
    }

    public function testCanonicalJsonValueSortsNestedKeysAndPreservesListOrder(): void
    {
        self::assertSame(
            ['a' => ['x' => 1, 'y' => 2], 'b' => 3],
            self::canonicalJsonValue(['b' => 3, 'a' => ['y' => 2, 'x' => 1]]),
        );
        // Lists keep their order.
        self::assertSame([3, 1, 2], self::canonicalJsonValue([3, 1, 2]));
    }

    public function testAssertArrayContainsValueFindsAMatch(): void
    {
        self::assertArrayContainsValue('needle', ['hay', 'needle']);
    }
}
