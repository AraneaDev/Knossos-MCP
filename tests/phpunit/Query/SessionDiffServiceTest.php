<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\SessionDiffCommand;
use Knossos\Query\SessionDiffService;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringStartsWith;
use function PHPUnit\Framework\assertTrue;

/**
 * One file's change since the commit a session started at: commits made
 * since and the working tree alike, added files whole, deleted files whole,
 * renames as renames, bounded, and a plain status where there is no diff.
 */
final class SessionDiffServiceTest extends KnossosTestCase
{
    /** @var list<string> */
    private array $trees = [];

    protected function tearDown(): void
    {
        foreach ($this->trees as $tree) {
            $this->removeTempTree($tree);
        }
        parent::tearDown();
    }

    /** A temp directory removeTempTree() accepts. */
    private function directory(): string
    {
        $dir = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($dir, 0o700, true);
        $this->trees[] = $dir;
        return (string) realpath($dir);
    }

    /** @param list<string> $args */
    private function git(string $root, array $args): void
    {
        $this->runFixtureCommand(['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
    }

    /**
     * A repository with three committed files; returns its root and the commit.
     *
     * @return array{0: string, 1: string}
     */
    private function repository(): array
    {
        $root = $this->directory();
        $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
        mkdir($root . '/src');
        file_put_contents($root . '/src/Kept.php', "<?php\n\nfinal class Kept\n{\n}\n");
        file_put_contents($root . '/src/Gone.php', "<?php\n\nfinal class Gone {}\n");
        file_put_contents($root . '/src/Moved.php', "<?php\n\nfinal class Moved\n{\n    public function a(): int\n    {\n        return 1;\n    }\n}\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '--quiet', '-m', 'first']);
        return [$root, (string) (new SessionDiffService())->head($root)['rev']];
    }

    #[Group('query')]
    public function testTheSessionStartsAtTheCommitTheProjectIsAt(): void
    {
        [$root, $rev] = $this->repository();
        assertSame(1, preg_match('/^[0-9a-f]{40}$/', $rev));
        assertSame('no-git', (new SessionDiffService())->head($this->directory())['status']);
        // The branch checked out is named beside the commit, by its short name; a detached head names none.
        $this->git($root, ['checkout', '--quiet', '-b', 'feat/pane']);
        assertSame('feat/pane', (new SessionDiffService())->head($root)['branch']);
        $this->git($root, ['checkout', '--quiet', '--detach']);
        assertSame([$rev, null], [(new SessionDiffService())->head($root)['rev'], (new SessionDiffService())->head($root)['branch']]);
        assertNull((new SessionDiffService())->head($this->directory())['branch']);
    }

    #[Group('query')]
    public function testAChangeIsTheWorkingTreeAndTheCommitsSinceAgainstTheSessionsStart(): void
    {
        [$root, $rev] = $this->repository();
        file_put_contents($root . '/src/Kept.php', "<?php\n\nfinal class Kept\n{\n    // committed\n}\n");
        $this->git($root, ['commit', '--quiet', '-am', 'during the session']);
        file_put_contents($root . '/src/Kept.php', "<?php\n\nfinal class Kept\n{\n    // committed\n    // and not yet\n}\n");
        $diff = (new SessionDiffService())->diff($root, $rev, 'src/Kept.php');
        assertSame('ok', $diff['status']);
        assertSame('changed', $diff['kind']);
        assertStringStartsWith('@@ -2,4 +2,6 @@', $diff['diff']);
        assertStringContainsString("+    // committed\n+    // and not yet\n", $diff['diff']);
        assertSame(false, $diff['truncated']);
        // Put back as it was: nothing to show.
        file_put_contents($root . '/src/Kept.php', "<?php\n\nfinal class Kept\n{\n}\n");
        assertSame('unchanged', (new SessionDiffService())->diff($root, $rev, 'src/Kept.php')['kind']);
    }

    #[Group('query')]
    public function testAddedFilesAreShownWholeAsAddedAndDeletedOnesWholeAsRemoved(): void
    {
        [$root, $rev] = $this->repository();
        file_put_contents($root . '/src/Tracked.php', "<?php\nfinal class Tracked {}\n");
        $this->git($root, ['add', 'src/Tracked.php']);
        file_put_contents($root . '/src/Untracked.php', "<?php\nfinal class Untracked {}\n");
        unlink($root . '/src/Gone.php');
        $service = new SessionDiffService();
        $tracked = $service->diff($root, $rev, 'src/Tracked.php');
        assertSame('added', $tracked['kind']);
        assertSame("@@ -0,0 +1,2 @@\n+<?php\n+final class Tracked {}\n", $tracked['diff']);
        $untracked = $service->diff($root, $rev, 'src/Untracked.php');
        assertSame('added', $untracked['kind']);
        assertSame("@@ -0,0 +1,2 @@\n+<?php\n+final class Untracked {}\n", $untracked['diff']);
        $gone = $service->diff($root, $rev, 'src/Gone.php');
        assertSame('deleted', $gone['kind']);
        assertSame("@@ -1,3 +0,0 @@\n-<?php\n-\n-final class Gone {}\n", $gone['diff']);
        assertSame('absent', $service->diff($root, $rev, 'src/Never.php')['kind']);
    }

    #[Group('query')]
    public function testAFileGitMovedIsShownAsTheRenameFromEitherSide(): void
    {
        [$root, $rev] = $this->repository();
        $this->git($root, ['mv', 'src/Moved.php', 'src/Renamed.php']);
        $service = new SessionDiffService();
        foreach (['src/Renamed.php', 'src/Moved.php'] as $side) {
            $diff = $service->diff($root, $rev, $side);
            assertSame('renamed', $diff['kind'], $side);
            assertSame('src/Moved.php', $diff['from']);
            assertSame('src/Renamed.php', $diff['to']);
            assertSame('', $diff['diff']);
        }
        file_put_contents($root . '/src/Renamed.php', str_replace('return 1;', 'return 2;', (string) file_get_contents($root . '/src/Renamed.php')));
        $this->git($root, ['add', 'src/Renamed.php']);
        $diff = $service->diff($root, $rev, 'src/Renamed.php');
        assertSame('renamed', $diff['kind']);
        assertStringContainsString("-        return 1;\n+        return 2;\n", $diff['diff']);
    }

    #[Group('query')]
    public function testARenameIsFoundBesideAChangedFileWhosePathStartsLikeAStatus(): void
    {
        [$root] = $this->repository();
        file_put_contents($root . '/README.md', "# Read me\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '--quiet', '-m', 'readme']);
        $rev = (string) (new SessionDiffService())->head($root)['rev'];
        // `M README.md` then `R100 src/Moved.php src/Renamed.php`: a path starting with R is a path, not a status.
        file_put_contents($root . '/README.md', "# Read me twice\n");
        $this->git($root, ['mv', 'src/Moved.php', 'src/Renamed.php']);
        $diff = (new SessionDiffService())->diff($root, $rev, 'src/Renamed.php');
        assertSame(['renamed', 'src/Moved.php', 'src/Renamed.php'], [$diff['kind'], $diff['from'], $diff['to']]);
    }

    #[Group('query')]
    public function testAnUntrackedFileIsReadByItsLiteralNameAndNeverThroughALinkOutOfTheProject(): void
    {
        [$root, $rev] = $this->repository();
        // A name that is a glob over a tracked file: read as itself, not as the files it would match.
        file_put_contents($root . '/src/*.php', "<?php\n\nliteral();\n");
        file_put_contents($root . '/src/Kept.php', "<?php\n\nfinal class Kept\n{\n    // changed\n}\n");
        $service = new SessionDiffService();
        $glob = $service->diff($root, $rev, 'src/*.php');
        assertSame('added', $glob['kind']);
        assertSame("@@ -0,0 +1,3 @@\n+<?php\n+\n+literal();\n", $glob['diff']);
        // An untracked link to a file outside the project is not shown.
        $outside = $this->directory();
        file_put_contents($outside . '/secret.txt', "not the project's\n");
        symlink($outside . '/secret.txt', $root . '/src/link.txt');
        $link = $service->diff($root, $rev, 'src/link.txt');
        assertSame('', $link['diff']);
        assertSame(true, $link['unreadable']);
    }

    #[Group('query')]
    public function testALargeChangeIsCutAndSaysSoAndABinaryOneSaysWhatItIs(): void
    {
        [$root, $rev] = $this->repository();
        $lines = SessionDiffService::MAX_LINES + 500;
        file_put_contents($root . '/src/Big.txt', implode("\n", array_map(static fn(int $i): string => 'line ' . $i, range(1, $lines))) . "\n");
        $service = new SessionDiffService();
        $big = $service->diff($root, $rev, 'src/Big.txt');
        assertSame(true, $big['truncated']);
        assertSame(SessionDiffService::MAX_LINES, substr_count($big['diff'], "\n"));
        assertSame($lines + 1, $big['lines']);
        file_put_contents($root . '/src/Image.bin', "\x89PNG\0\0\0binary");
        $this->git($root, ['add', 'src/Image.bin']);
        $binary = $service->diff($root, $rev, 'src/Image.bin');
        assertSame(true, $binary['binary']);
        assertSame('', $binary['diff']);
    }

    #[Group('query')]
    public function testNoRepositoryAnUnknownCommitOrAPathOutsideIsAStatusNotADiff(): void
    {
        [$root, $rev] = $this->repository();
        $service = new SessionDiffService();
        assertSame('no-git', $service->diff($this->directory(), $rev, 'src/Kept.php')['status']);
        assertSame('unknown-rev', $service->diff($root, str_repeat('0', 40), 'src/Kept.php')['status']);
        assertSame('unknown-rev', $service->diff($root, 'HEAD', 'src/Kept.php')['status']);
        foreach (['../outside.php', '/etc/passwd', '--output=x', '', 'src/../../x'] as $file) {
            assertSame('error', $service->diff($root, $rev, $file)['status'], $file);
        }
    }

    #[Group('cli')]
    public function testTheCommandsAnswerInJsonAndAlwaysExitZero(): void
    {
        [$root, $rev] = $this->repository();
        file_put_contents($root . '/src/Kept.php', "<?php\n// changed\n");
        [$status, $head] = $this->runJson('session-head', [$root], []);
        assertSame([0, 'ok', $rev], [$status, $head['status'], $head['rev']]);
        [$status, $diff] = $this->runJson('session-diff', [$root], ['rev' => [$rev], 'file' => ['src/Kept.php']]);
        assertSame([0, 'ok', 'changed'], [$status, $diff['status'], $diff['kind']]);
        // A missing option, an unknown one, a stray argument: an error status, still exit 0.
        foreach ([['session-diff', [$root], ['rev' => [$rev]]], ['session-head', [$root], ['db' => ['x']]], ['session-head', [$root, 'more'], []]] as [$command, $positionals, $options]) {
            [$status, $answer] = $this->runJson($command, $positionals, $options);
            assertSame([0, 'error'], [$status, $answer['status']]);
        }
        assertTrue((new SessionDiffCommand())->supports('session-diff') && !(new SessionDiffCommand())->supports('session-changes'));
    }

    /**
     * Runs one command in process and decodes its JSON output.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runJson(string $command, array $positionals, array $options): array
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), ':memory:');
        ob_start();
        $status = (new SessionDiffCommand())->run($command, $positionals, $options + ['json' => ['true']], $context);
        return [$status, json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR)];
    }
}
