<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveredFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('discovered-file')]
final class DiscoveredFileTest extends TestCase
{
    public function testAllFieldsAreStoredAndExposedAsReadonly(): void
    {
        $file = new DiscoveredFile(
            relativePath: 'src/Foo.php',
            absolutePath: '/tmp/proj/src/Foo.php',
            language: 'php',
            size: 1_234,
            mtime: 1_700_000_000,
            contentHash: 'abc123',
            lineCount: 42,
        );

        assertSame('src/Foo.php', $file->relativePath);
        assertSame('/tmp/proj/src/Foo.php', $file->absolutePath);
        assertSame('php', $file->language);
        assertSame(1_234, $file->size);
        assertSame(1_700_000_000, $file->mtime);
        assertSame('abc123', $file->contentHash);
        assertSame(42, $file->lineCount);
    }

    public function testLineCountDefaultsToZeroWhenOmitted(): void
    {
        $file = new DiscoveredFile(
            relativePath: 'src/Bar.php',
            absolutePath: '/tmp/proj/src/Bar.php',
            language: 'php',
            size: 100,
            mtime: 1_700_000_001,
            contentHash: 'def456',
        );

        assertSame(0, $file->lineCount);
    }

    public function testLineCountCanBeExplicitlyZero(): void
    {
        $file = new DiscoveredFile(
            relativePath: 'src/Baz.php',
            absolutePath: '/tmp/proj/src/Baz.php',
            language: 'php',
            size: 0,
            mtime: 0,
            contentHash: '',
            lineCount: 0,
        );

        assertSame(0, $file->size);
        assertSame(0, $file->mtime);
        assertSame('', $file->contentHash);
        assertSame(0, $file->lineCount);
    }

    public function testLargeIntegerFieldsAreStoredAsGiven(): void
    {
        $file = new DiscoveredFile(
            relativePath: 'src/Big.php',
            absolutePath: '/tmp/proj/src/Big.php',
            language: 'php',
            size: \PHP_INT_MAX,
            mtime: \PHP_INT_MAX,
            contentHash: str_repeat('a', 64),
            lineCount: 1_000_000,
        );

        assertSame(\PHP_INT_MAX, $file->size);
        assertSame(\PHP_INT_MAX, $file->mtime);
        assertSame(64, strlen($file->contentHash));
        assertSame(1_000_000, $file->lineCount);
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(DiscoveredFile::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }
}