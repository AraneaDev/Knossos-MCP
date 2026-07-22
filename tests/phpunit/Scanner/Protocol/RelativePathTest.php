<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Protocol;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\RelativePath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-protocol')]
final class RelativePathTest extends TestCase
{
    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(RelativePath::class);
        $this->assertTrue($reflection->getConstructor()->isPrivate());
    }

    public function testAcceptsNormalRelativePath(): void
    {
        RelativePath::assertValid('src/Foo/Bar.php');
        $this->expectNotToPerformAssertions();
    }

    public function testAcceptsSingleSegmentPath(): void
    {
        RelativePath::assertValid('index.php');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsEmptyPath(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid(''),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsNulByte(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid("src/Foo\x00.php"),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsBackslash(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid('src\\Foo.php'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsLeadingSlash(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid('/src/Foo.php'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsWindowsDriveLetter(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid('C:/src/Foo.php'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsDoubleDotParentTraversal(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid('src/../etc/passwd'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsSingleDotSegment(): void
    {
        assertThrows(
            static fn() => RelativePath::assertValid('src/./foo.php'),
            InvalidArgumentException::class,
        );
    }

    public function testRejectsTrailingSlash(): void
    {
        // A trailing slash creates an empty segment, which is rejected.
        assertThrows(
            static fn() => RelativePath::assertValid('src/foo/'),
            InvalidArgumentException::class,
        );
    }
}
