<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * The image is built from the working tree, and docker reads .dockerignore,
 * never .gitignore, so what git keeps out of the repository has to be kept out
 * of the image on its own. Both files are copied into the quality stage only,
 * so this check runs in CI; the runtime image ships neither. A directory left
 * out of .dockerignore puts a developer's local output (frames, caches,
 * generated types) into a locally built image, and the container no longer
 * answers the same question CI does.
 */
final class DockerIgnoreTest extends KnossosTestCase
{
    /** Every directory .gitignore anchors at the root is in .dockerignore too. */
    #[Group('documentation')]
    public function testEveryRootAnchoredGitIgnoredDirectoryIsKeptOutOfTheImage(): void
    {
        $root = self::repositoryRoot();
        $anchored = [];
        foreach (self::patterns($root . '/.gitignore') as $pattern) {
            if (preg_match('#^/(.+)/$#', $pattern, $match) === 1) {
                $anchored[] = $match[1];
            }
        }
        assertNotSame([], $anchored);
        $docker = array_map(static fn(string $pattern): string => trim($pattern, '/'), self::patterns($root . '/.dockerignore'));
        assertSame([], array_values(array_diff($anchored, $docker)), 'git-ignored directories missing from .dockerignore');
    }

    /**
     * The patterns of an ignore file, without comments and blank lines.
     *
     * @return list<string>
     */
    private static function patterns(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn(string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }
}
