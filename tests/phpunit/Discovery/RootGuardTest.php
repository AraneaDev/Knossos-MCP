<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\RootGuard;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('root-guard')]
final class RootGuardTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        $this->tempDir = null;
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(RootGuard::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testResolveReturnsCanonicalPathForExistingRootInsideAllowedRoots(): void
    {
        $this->tempDir = $this->makeTempDir();
        $guard = new RootGuard(allowedRoots: [$this->tempDir]);

        $resolved = $guard->resolve($this->tempDir);

        assertSame(realpath($this->tempDir), $resolved);
    }

    public function testResolveReturnsCanonicalPathForSubdirectoryOfAllowedRoot(): void
    {
        $this->tempDir = $this->makeTempDir();
        $child = $this->tempDir . '/sub';
        mkdir($child, 0o755, true);
        $guard = new RootGuard(allowedRoots: [$this->tempDir]);

        $resolved = $guard->resolve($child);

        assertSame(realpath($child), $resolved);
        rmdir($child);
    }

    public function testResolveThrowsForNonExistentRequestedRoot(): void
    {
        $guard = new RootGuard(allowedRoots: [sys_get_temp_dir()]);

        $error = captureThrows(
            static fn () => $guard->resolve(sys_get_temp_dir() . '/knossos-nonexistent-' . uniqid()),
            DiscoveryException::class,
        );

        $this->assertStringStartsWith('Project root does not exist or is not a directory:', $error->getMessage());
    }

    public function testResolveThrowsWhenRequestedPathIsAFileNotADirectory(): void
    {
        $this->tempDir = $this->makeTempDir();
        $file = $this->tempDir . '/file.txt';
        file_put_contents($file, 'x');
        $guard = new RootGuard(allowedRoots: [$this->tempDir]);

        $error = captureThrows(
            static fn () => $guard->resolve($file),
            DiscoveryException::class,
        );

        $this->assertStringStartsWith('Project root does not exist or is not a directory:', $error->getMessage());
        unlink($file);
    }

    public function testResolveThrowsWhenRequestedRootIsOutsideAllowedRoots(): void
    {
        $allowed = $this->makeTempDir();
        $outside = $this->makeTempDir();
        $guard = new RootGuard(allowedRoots: [$allowed]);

        $error = captureThrows(
            static fn () => $guard->resolve($outside),
            DiscoveryException::class,
        );

        assertSame('Project root is outside the configured allowed roots.', $error->getMessage());
    }

    public function testResolveThrowsWhenConfiguredAllowedRootDoesNotExist(): void
    {
        $this->tempDir = $this->makeTempDir();
        $invalidAllowed = sys_get_temp_dir() . '/knossos-missing-' . uniqid('', true);

        $guard = new RootGuard(allowedRoots: [$invalidAllowed]);

        $error = captureThrows(
            static fn () => $guard->resolve(sys_get_temp_dir()),
            DiscoveryException::class,
        );

        $this->assertStringStartsWith('Configured allowed root does not exist:', $error->getMessage());
    }

    public function testContainsReturnsTrueForExactMatch(): void
    {
        assertSame(true, RootGuard::contains('/projects', '/projects'));
    }

    public function testContainsReturnsTrueForDirectChildPath(): void
    {
        assertSame(true, RootGuard::contains('/projects', '/projects/sub'));
    }

    public function testContainsReturnsTrueForDeepDescendant(): void
    {
        assertSame(true, RootGuard::contains('/projects', '/projects/a/b/c'));
    }

    public function testContainsReturnsFalseForUnrelatedPath(): void
    {
        assertSame(false, RootGuard::contains('/projects', '/other/foo'));
    }

    public function testContainsReturnsFalseForParentOfRoot(): void
    {
        // '/projects' is the parent; '/projects/a' is inside /projects.
        // But '/projects-root' (longer prefix) should NOT be inside '/projects-root/a' or vice versa.
        assertSame(false, RootGuard::contains('/projects/sub', '/projects'));
    }

    public function testContainsReturnsFalseForSiblingDirectoryWithSharedPrefix(): void
    {
        // '/projects-foo' is a sibling, NOT inside '/projects'.
        assertSame(false, RootGuard::contains('/projects', '/projects-foo'));
    }

    public function testContainsNormalizesBackslashSeparators(): void
    {
        // Windows-style backslash separator should still match.
        assertSame(true, RootGuard::contains('C:\\projects', 'C:\\projects\\sub'));
        assertSame(true, RootGuard::contains('C:/projects', 'C:\\projects\\sub'));
    }

    public function testContainsStripsTrailingSlashFromRoot(): void
    {
        assertSame(true, RootGuard::contains('/projects/', '/projects/sub'));
        assertSame(true, RootGuard::contains('/projects/', '/projects'));
    }



    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/knossos-rootguard-' . uniqid('', true);
        mkdir($dir, 0o755, true);
        return $dir;
    }
}
