<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;

#[CoversNothing]
final class SupportTraitsTest extends KnossosTestCase
{
    public function testFreshTestDatabaseIsMigratedAndEmpty(): void
    {
        $pdo = $this->freshTestDatabase();
        self::assertInstanceOf(PDO::class, $pdo);
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    /**
     * A symbolic link to a directory reports isDir(), and rmdir() refuses a
     * link, so a tree holding one used to survive its own cleanup. The link
     * is removed as a link: whatever it points at, inside the tree or out of
     * it, is left alone.
     */
    public function testRemoveTempTreeUnlinksDirectoryLinksWithoutFollowingThem(): void
    {
        $outside = rtrim(sys_get_temp_dir(), '/') . '/knossos-outside-' . bin2hex(random_bytes(6));
        $root = rtrim(sys_get_temp_dir(), '/') . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($outside);
        file_put_contents($outside . '/kept.txt', 'kept');
        mkdir($root . '/pkg/real', 0o777, true);
        file_put_contents($root . '/pkg/real/a.py', 'a');
        symlink('real', $root . '/pkg/inside');
        symlink($outside, $root . '/outside');
        symlink('gone', $root . '/dangling');
        try {
            $this->removeTempTree($root);

            self::assertFalse(file_exists($root) || is_link($root));
            self::assertSame('kept', file_get_contents($outside . '/kept.txt'));
        } finally {
            @unlink($outside . '/kept.txt');
            @rmdir($outside);
        }
    }

    public function testRunFixtureCommandOutputCapturesExitCodeAndStreams(): void
    {
        [$exit, $stdout] = $this->runFixtureCommandOutput([PHP_BINARY, '-r', 'echo "hi";']);
        self::assertSame(0, $exit);
        self::assertStringContainsString('hi', $stdout);
    }
}
