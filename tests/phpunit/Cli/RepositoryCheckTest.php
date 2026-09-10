<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class RepositoryCheckTest extends KnossosTestCase
{
    /**
     * The repository gate has no claim on files the repository does not carry.
     *
     * It enforces a 2 MB ceiling, LF line endings and a secret scan, and it used
     * to walk the filesystem behind a hand-maintained list of directory names. CI
     * works from a fresh checkout where git-ignored files do not exist, so CI
     * never saw them; a developer who had run Infection locally failed on
     * `chaos-infection-log.json`, a 3.4 MB generated artifact that .gitignore
     * excludes and that no commit could ever contain.
     *
     * The fixture below deliberately VIOLATES the CR rule: it has to be skipped
     * for being ignored, not for being clean.
     */
    #[Group('documentation')]
    public function testRepositoryCheckSkipsGitIgnoredFiles(): void
    {
        $root = self::repositoryRoot();
        // Its OWN subdirectory: the sibling fixture in DocumentationTest lives
        // under the same ignored parent, and sharing one directory means either
        // test's teardown can remove the other's ground.
        $parent = $root . '/docs/superpowers';
        $directory = $parent . '/repository-check-fixture';
        $fixture = $directory . '/broken.md';
        $createdParent = !is_dir($parent);
        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create ' . $directory);
        }
        // Never write over something a developer already has there.
        self::assertFileDoesNotExist($fixture);

        try {
            file_put_contents($fixture, "a line that ends the wrong way\r\n");
            // Guard: without git the checker fails OPEN and inspects everything,
            // which would make this assert something the fix never promised.
            [$ignoreExit] = $this->runFixtureCommandOutput(['git', '-C', $root, 'check-ignore', '-q', $fixture]);
            if ($ignoreExit !== 0) {
                self::markTestSkipped('git cannot report ignore status here.');
            }

            [$exit, $output] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/repository-check.php']);

            assertSame(0, $exit);
            assertContains('Repository JSON, size, line-ending, and secret checks passed:', $output);
        } finally {
            @unlink($fixture);
            @rmdir($directory);
            if ($createdParent) {
                @rmdir($parent);
            }
        }
    }

    /**
     * The other direction: a file the repository WOULD carry is still inspected,
     * including one that is untracked because it has not been committed yet.
     * That window — added to the working tree, not yet in a commit — is exactly
     * when the secret and line-ending rules earn their keep, so the git-ignore
     * filter must not widen into "skip everything git does not already track".
     */
    #[Group('documentation')]
    /**
     * A skipped directory must not be opened, only discarded.
     *
     * The walk collected every path first and dropped the skipped ones
     * afterwards, so a directory the gate never intended to read was opened
     * anyway. `tools/coverage` writes `coverage/rust/html/coverage` as root
     * with mode 0750, and the suite runs as a non-root user, so the second
     * `tools/quality-container full` on any machine died here with an
     * uncaught UnexpectedValueException and exit 255 rather than the gate's
     * own exit 1.
     */
    #[Group('documentation')]
    public function testRepositoryCheckDoesNotDescendIntoSkippedDirectories(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('Running as root; an unreadable directory is still readable.');
        }
        $root = self::repositoryRoot();
        $unreadable = $root . '/coverage/repository-check-unreadable';
        if (!is_dir($root . '/coverage') && !@mkdir($root . '/coverage', 0o755, true) && !is_dir($root . '/coverage')) {
            self::markTestSkipped('coverage/ cannot be created here.');
        }
        if (!@mkdir($unreadable, 0o700) && !is_dir($unreadable)) {
            self::markTestSkipped('the fixture directory cannot be created here.');
        }

        try {
            // Nothing inside is readable, so a walk that opens it throws.
            @chmod($unreadable, 0o000);
            [$exit] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/repository-check.php']);

            assertSame(0, $exit);
        } finally {
            @chmod($unreadable, 0o700);
            @rmdir($unreadable);
        }
    }

    public function testRepositoryCheckStillInspectsUntrackedFilesThatAreNotIgnored(): void
    {
        $root = self::repositoryRoot();
        $fixture = $root . '/docs/repository-check-fixture.md';
        self::assertFileDoesNotExist($fixture);

        try {
            file_put_contents($fixture, "a line that ends the wrong way\r\n");
            [$ignoreExit] = $this->runFixtureCommandOutput(['git', '-C', $root, 'check-ignore', '-q', $fixture]);
            if ($ignoreExit === 0) {
                self::markTestSkipped('the fixture path is ignored here, so it cannot test the kept behaviour.');
            }

            [$exit, , $errors] = $this->runFixtureCommandOutput([PHP_BINARY, $root . '/tools/repository-check.php']);

            assertSame(1, $exit);
            assertContains('docs/repository-check-fixture.md contains CR line endings', $errors);
        } finally {
            @unlink($fixture);
        }
    }
}
