<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\Evidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class EvidenceTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(Evidence::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorStoresProperties(): void
    {
        $e = new Evidence('src/Foo.php', 1, 5);

        assertSame('src/Foo.php', $e->relativePath);
        assertSame(1, $e->startLine);
        assertSame(5, $e->endLine);
    }

    public function testJsonSerialization(): void
    {
        $e = new Evidence('src/Foo.php', 10, 20);
        $json = $e->jsonSerialize();

        assertSame('src/Foo.php', $json['path']);
        assertSame(10, $json['start_line']);
        assertSame(20, $json['end_line']);
    }

    public function testRejectsStartLineBelowOne(): void
    {
        assertThrows(
            static fn() => new Evidence('src/Foo.php', 0, 5),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsEndLineBelowStartLine(): void
    {
        assertThrows(
            static fn() => new Evidence('src/Foo.php', 5, 3),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsEqualStartAndEndLine(): void
    {
        $e = new Evidence('src/Foo.php', 5, 5);
        assertSame(5, $e->startLine);
        assertSame(5, $e->endLine);
    }
}
