<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Mock-based unit tests for Git process providers.
 *
 * Uses anonymous-class mocks of GitProcessRunnerInterface to test
 * argument validation, git-output parsing, and edge cases without
 * requiring a real Git repository on disk.
 */
#[Group('git')]
final class ProcessGitProviderTest extends KnossosTestCase
{
    private string $existingDir;

    protected function setUp(): void
    {
        $this->existingDir = sys_get_temp_dir();
    }

    // ── ProcessGitWorkingTreeProvider ────────────────────────────────

    public function testChangesRejectsInvalidMaxFiles(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->changes($this->existingDir, null, 0, 100), RuntimeException::class);
        assertThrows(fn() => $provider->changes($this->existingDir, null, 1001, 100), RuntimeException::class);
    }

    public function testChangesRejectsInvalidTimeout(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->changes($this->existingDir, null, 1, 0), RuntimeException::class);
        assertThrows(fn() => $provider->changes($this->existingDir, null, 1, 5001), RuntimeException::class);
    }

    public function testChangesRejectsNonExistentRoot(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(
            fn() => $provider->changes('/knossos/does-not-exist-12345', null, 1, 100),
            RuntimeException::class,
        );
    }

    public function testChangesRejectsInvalidBaseRef(): void
    {
        $provider = new ProcessGitWorkingTreeProvider(runner: $this->mockRunner(''));
        assertThrows(
            fn() => $provider->changes($this->existingDir, '--bad-ref', 1, 100),
            RuntimeException::class,
        );
    }

    public function testChangesRejectsABaseRefEndingInALineBreakBeforeGitRuns(): void
    {
        $runner = new class implements GitProcessRunnerInterface {
            public int $calls = 0;

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                ++$this->calls;

                return str_repeat('a', 40);
            }
        };
        $provider = new ProcessGitWorkingTreeProvider(runner: $runner);
        $error = captureThrows(fn() => $provider->changes($this->existingDir, "main\n", 10, 100), RuntimeException::class);
        assertSame('base_ref contains unsupported characters.', $error->getMessage());
        assertSame(0, $runner->calls);
    }

    public function testChangesRejectsBaseRefThatDoesNotResolve(): void
    {
        $mock = $this->mockRunner('not-a-commit-hash');
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        assertThrows(
            fn() => $provider->changes($this->existingDir, 'HEAD~1', 10, 100),
            RuntimeException::class,
        );
    }

    public function testChangesReturnsEmptyForCleanWorkingTree(): void
    {
        $mock = $this->mockRunner('a'.str_repeat('b', 39) . "\n");
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, 'HEAD', 10, 100);

        assertSame([], $result['paths']);
        assertSame([], $result['renames']);
        assertSame(false, $result['truncated']);
    }

    public function testChangesWithMovedAndUntrackedFiles(): void
    {
        // Mock that returns appropriate output based on the command pattern
        $mock = new class implements GitProcessRunnerInterface {
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $subcommand = $command[5] ?? '';
                // rev-parse: returns a 40-char hex hash
                if ($subcommand === 'rev-parse') {
                    return 'abcdef1234567890abcdef1234567890abcdef12';
                }
                // git diff: returns changes
                if ($subcommand === 'diff') {
                    return "M\x0src/InvoiceService.php\x0" .
                        "R100\x0src/OldCheckout.php\x0src/Checkout.php\x0";
                }
                // git ls-files: returns untracked
                return "src/NEW_FILE.php\x0src/another.php\x0";
            }
        };

        // baseRef !== null → rev-parse + diff
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, 'HEAD', 10, 100);

        assertSame(['src/Checkout.php', 'src/InvoiceService.php', 'src/OldCheckout.php'], $result['paths']);
        assertSame([['from' => 'src/OldCheckout.php', 'to' => 'src/Checkout.php']], $result['renames']);
        assertSame(false, $result['truncated']);

        // baseRef = null → diff + ls-files (no rev-parse)
        $result2 = $provider->changes($this->existingDir, null, 10, 100);

        $this->assertContains('src/NEW_FILE.php', $result2['paths']);
        $this->assertContains('src/another.php', $result2['paths']);
    }

    public function testChangesTruncatesWhenExceedingMaxFiles(): void
    {
        $mock = $this->mockRunner(
            "M\x0src/a.php\x0" .
            "M\x0src/b.php\x0" .
            "M\x0src/c.php\x0"
        );
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, null, 2, 100);

        $this->assertCount(2, $result['paths']);
        assertSame(true, $result['truncated']);
    }

    public function testChangesFiltersInvalidPathsFromOutput(): void
    {
        // Mock returns diff output and empty ls-files output to avoid
        // ls-files tokens creating spurious paths from diff status codes.
        $mock = new class implements GitProcessRunnerInterface {
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $subcommand = $command[5] ?? '';
                if ($subcommand === 'ls-files') {
                    return '';  // no untracked files
                }
                // diff output with an empty path after M status
                // Empty string fails RelativePath::assertValid
                return "M\x0src/valid.php\x0M\x0\x0";
            }
        };
        $provider = new ProcessGitWorkingTreeProvider(runner: $mock);
        $result = $provider->changes($this->existingDir, null, 10, 100);

        assertSame(['src/valid.php'], $result['paths']);
    }

    // ── ProcessGitHistoryProvider ────────────────────────────────────

    public function testHistoryRejectsInvalidSinceDays(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->history($this->existingDir, 0, 10, 100), RuntimeException::class);
        assertThrows(fn() => $provider->history($this->existingDir, 3651, 10, 100), RuntimeException::class);
    }

    public function testHistoryRejectsInvalidMaxCommits(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->history($this->existingDir, 30, 0, 100), RuntimeException::class);
        assertThrows(fn() => $provider->history($this->existingDir, 30, 5001, 100), RuntimeException::class);
    }

    public function testHistoryRejectsInvalidTimeout(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(fn() => $provider->history($this->existingDir, 30, 10, 0), RuntimeException::class);
        assertThrows(fn() => $provider->history($this->existingDir, 30, 10, 5001), RuntimeException::class);
    }

    public function testHistoryRejectsNonExistentRoot(): void
    {
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner(''));
        assertThrows(
            fn() => $provider->history('/knossos/does-not-exist-12345', 30, 10, 100),
            RuntimeException::class,
        );
    }

    public function testHistoryReturnsEmptyForEmptyLog(): void
    {
        $mock = $this->mockRunner('');
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame([], $result['files']);
        assertSame(0, $result['commits_examined']);
        assertSame(false, $result['truncated']);
    }

    public function testLastChangedAtOrdersByInstantNotByOffsetString(): void
    {
        // 10:00+02:00 is 08:00 UTC; 09:00+00:00 is 09:00 UTC — so the SECOND
        // commit is later, even though the first sorts higher as a string.
        $log = self::logOutput([
            ["KNOSSOS_COMMIT\x1faaa\x1f2024-01-01T10:00:00+02:00\x1f1704096000\x1fa@example.com", ['src/Foo.php']],
            ["KNOSSOS_COMMIT\x1fbbb\x1f2024-01-01T09:00:00+00:00\x1f1704099600\x1fb@example.com", ['src/Foo.php']],
        ]);

        $result = (new ProcessGitHistoryProvider(runner: $this->mockRunner($log)))->history('/tmp', 30, 100, 1000);

        self::assertSame('2024-01-01T09:00:00+00:00', $result['files']['src/Foo.php']['last_changed_at']);
    }

    public function testHistoryParsesCommitsAndAggregatesFiles(): void
    {
        $gitLog = self::logOutput([
            ["KNOSSOS_COMMIT\x1fabc123\x1f2026-07-20T10:00:00+00:00\x1f1784541600\x1fa@test.dev", ['src/InvoiceService.php', 'src/Checkout.php']],
            ["KNOSSOS_COMMIT\x1fdef456\x1f2026-07-19T09:00:00+00:00\x1f1784451600\x1fb@test.dev", ['src/InvoiceService.php']],
            ["KNOSSOS_COMMIT\x1fghi789\x1f2026-07-18T08:00:00+00:00\x1f1784361600\x1fa@test.dev", ['src/Order.php']],
        ]);
        $mock = $this->mockRunner($gitLog);
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame(3, $result['commits_examined']);
        assertSame(false, $result['truncated']);
        $this->assertArrayHasKey('src/InvoiceService.php', $result['files']);
        assertSame(2, $result['files']['src/InvoiceService.php']['commit_count']);
        assertSame(['a@test.dev', 'b@test.dev'], $result['files']['src/InvoiceService.php']['authors']);
        $this->assertArrayHasKey('src/Checkout.php', $result['files']);
        $this->assertArrayHasKey('src/Order.php', $result['files']);
    }

    public function testHistoryTruncatesWhenExceedingMaxCommits(): void
    {
        $commits = [];
        for ($i = 0; $i < 5; ++$i) {
            $hash = str_pad((string) $i, 40, 'a');
            $commits[] = ["KNOSSOS_COMMIT\x1f{$hash}\x1f2026-07-20T10:00:00+00:00\x1f1784541600\x1fa@test.dev", ["src/file{$i}.php"]];
        }
        $mock = $this->mockRunner(self::logOutput($commits));
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 3, 100);

        assertSame(3, $result['commits_examined']);
        assertSame(true, $result['truncated']);
    }

    public function testHistorySkipsMalformedCommitLines(): void
    {
        // Malformed commit line (3 parts instead of 5) comes FIRST — its path
        // leaks into an undefined $current (null) and is dropped.
        $gitLog = self::logOutput([
            // Short line — only 3 parts instead of 5
            ["KNOSSOS_COMMIT\x1fabc123\x1f2026-07-20T10:00:00+00:00", ['src/skipped_path.php']],
            ["KNOSSOS_COMMIT\x1fdef456\x1f2026-07-19T09:00:00+00:00\x1f1784451600\x1fb@test.dev", ['src/valid.php']],
        ]);
        $mock = $this->mockRunner($gitLog);
        $provider = new ProcessGitHistoryProvider(runner: $mock);
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame(1, $result['commits_examined']);
        $this->assertArrayHasKey('src/valid.php', $result['files']);
        $this->assertArrayNotHasKey('src/skipped_path.php', $result['files']);
    }

    public function testAMalformedHeaderAfterAValidCommitDoesNotAttributeItsPathsToThatCommit(): void
    {
        // Ordering that a first-position malformed header cannot reach: a valid
        // commit, then a malformed header, then a path. If the parser keeps the
        // preceding commit active across the malformed header, the orphaned path
        // is credited to it — silent per-file attribution corruption in
        // change_impact rather than a dropped record.
        $gitLog = self::logOutput([
            ["KNOSSOS_COMMIT\x1fdef456\x1f2026-07-19T09:00:00+00:00\x1f1784451600\x1fb@test.dev", ['src/valid.php']],
            // Short line — only 3 parts instead of 5.
            ["KNOSSOS_COMMIT\x1fabc123\x1f2026-07-20T10:00:00+00:00", ['src/orphaned_path.php']],
        ]);
        $provider = new ProcessGitHistoryProvider(runner: $this->mockRunner($gitLog));
        $result = $provider->history($this->existingDir, 30, 10, 100);

        assertSame(1, $result['commits_examined']);
        $this->assertArrayHasKey('src/valid.php', $result['files']);
        $this->assertArrayNotHasKey('src/orphaned_path.php', $result['files']);
        assertSame(1, $result['files']['src/valid.php']['commit_count']);
    }

    public function testHistoryDisablesGitPathQuotingSoNonAsciiPathsSurvive(): void
    {
        // With git's default core.quotePath=true, paths with bytes >0x7F are
        // octal-escaped and backslash-quoted, which RelativePath rejects and the
        // parser silently drops. The provider must pass -c core.quotePath=false.
        $captured = [];
        $runner = new class($captured) implements GitProcessRunnerInterface {
            /** @param list<string> $captured */
            public function __construct(private array &$captured) {}

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $this->captured = $command;
                return '';
            }
        };
        $provider = new ProcessGitHistoryProvider(runner: $runner);
        $provider->history($this->existingDir, 30, 10, 100);

        // The '-c core.quotePath=false' config pair must appear before 'log'.
        $configIndex = array_search('core.quotePath=false', $captured, true);
        $logIndex = array_search('log', $captured, true);
        assertSame(true, is_int($configIndex) && is_int($logIndex) && $configIndex < $logIndex);
        assertSame('-c', $captured[(int) $configIndex - 1]);
    }

    // ── Scope: a project in a repository subdirectory ────────────────

    /**
     * Without --relative and a pathspec, git printed repository-root paths and
     * included sibling packages, so a project in a subdirectory matched none of
     * its own changed files.
     */
    public function testWorkingTreeDiffIsScopedToTheProjectDirectory(): void
    {
        $runner = $this->recordingRunner(static fn(array $command): string => in_array('rev-parse', $command, true) ? str_repeat('a', 40) . "\n" : '');
        (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, null, 10, 100);

        $diffs = $runner->commandsWith('diff');
        assertSame(1, count($diffs));
        assertSame(true, in_array('--relative', $diffs[0], true));
        assertSame(['--', '.'], array_slice($diffs[0], -2));
        $listings = $runner->commandsWith('ls-files');
        assertSame(1, count($listings));
        assertSame(['--', '.'], array_slice($listings[0], -2));
    }

    /**
     * The history log, like the diff, listed repository-root paths and let a
     * sibling package's commits use up the max_commits budget.
     */
    public function testHistoryIsScopedToTheProjectDirectory(): void
    {
        $runner = $this->recordingRunner(static fn(array $command): string => '');
        (new ProcessGitHistoryProvider(runner: $runner))->history($this->existingDir, 30, 10, 100);

        $logs = $runner->commandsWith('log');
        assertSame(1, count($logs));
        assertSame(true, in_array('--relative', $logs[0], true));
        assertSame(['--', '.'], array_slice($logs[0], -2));
    }

    /**
     * A project one and two levels below the repository root sees only its own
     * changes, relative to its own root; a rename from a sibling package into
     * the project reads as an add. The repository-root project is unchanged.
     */
    public function testANestedProjectSeesOnlyItsOwnChangesRelativeToItsRoot(): void
    {
        $repo = $this->gitRepository();
        try {
            $this->writeFiles($repo, ['pkg/src/x.ts' => "x\n", 'other/y.ts' => "y\n", 'pkg/deep/sub/z.ts' => "z\n"]);
            $this->git($repo, ['add', '.']);
            $this->git($repo, ['commit', '--quiet', '-m', 'first']);
            $this->writeFiles($repo, ['pkg/src/x.ts' => "x2\n", 'other/y.ts' => "y2\n", 'pkg/deep/sub/z.ts' => "z2\n", 'pkg/new.ts' => "n\n"]);
            $this->git($repo, ['mv', 'other/y.ts', 'pkg/moved.ts']);
            $provider = new ProcessGitWorkingTreeProvider();

            $nested = $provider->changes($repo . '/pkg', null, 100, 5000);
            assertSame(['deep/sub/z.ts', 'moved.ts', 'new.ts', 'src/x.ts'], $nested['paths']);
            assertSame([], $nested['renames']);
            assertSame(['sub/z.ts'], $provider->changes($repo . '/pkg/deep', null, 100, 5000)['paths']);
            $whole = $provider->changes($repo, null, 100, 5000)['paths'];
            foreach (['pkg/src/x.ts', 'pkg/moved.ts', 'pkg/new.ts', 'pkg/deep/sub/z.ts', 'other/y.ts'] as $path) {
                $this->assertContains($path, $whole);
            }
        } finally {
            $this->removeTempTree($repo);
        }
    }

    /** A sibling package's commit is neither counted nor allowed to use the commit budget. */
    public function testANestedProjectsHistoryCountsOnlyItsOwnCommits(): void
    {
        $repo = $this->gitRepository();
        try {
            foreach ([['pkg/src/x.ts', "1\n"], ['other/y.ts', "2\n"], ['pkg/src/x.ts', "3\n"]] as [$file, $content]) {
                $this->writeFiles($repo, [$file => $content]);
                $this->git($repo, ['add', $file]);
                $this->git($repo, ['commit', '--quiet', '-m', 'change ' . $file]);
            }

            $history = (new ProcessGitHistoryProvider())->history($repo . '/pkg', 30, 2, 5000);

            assertSame(['src/x.ts'], array_keys($history['files']));
            assertSame(2, $history['files']['src/x.ts']['commit_count']);
            assertSame([2, false], [$history['commits_examined'], $history['truncated']]);
        } finally {
            $this->removeTempTree($repo);
        }
    }

    /** A rename takes three tokens: the entry after it is read from the right place. */
    public function testAnEntryAfterARenameIsReadInStep(): void
    {
        $runner = $this->recordingRunner(static fn(array $command): string => match (true) {
            in_array('rev-parse', $command, true) => str_repeat('a', 40),
            in_array('diff', $command, true) => "R090\0src/old.php\0src/new.php\0M\0src/other.php\0",
            default => '',
        });
        $result = (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, null, 10, 100);

        assertSame(['src/new.php', 'src/old.php', 'src/other.php'], $result['paths']);
        assertSame([['from' => 'src/old.php', 'to' => 'src/new.php']], $result['renames']);
    }

    /** A rename whose source is no valid project path is no rename: the target alone is listed, as an add. */
    public function testARenameFromAnInvalidPathIsListedAsAnAdd(): void
    {
        $runner = $this->recordingRunner(static fn(array $command): string => match (true) {
            in_array('rev-parse', $command, true) => str_repeat('a', 40),
            in_array('diff', $command, true) => "R100\0../outside.php\0src/x.php\0",
            default => '',
        });
        $result = (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, null, 10, 100);

        assertSame([['src/x.php'], []], [$result['paths'], $result['renames']]);
    }

    /** A base ref is resolved to the commit it names (`^{commit}`), so a tag peels to its commit. */
    public function testABaseRefIsPeeledToItsCommit(): void
    {
        $runner = $this->recordingRunner(static fn(array $command): string => in_array('rev-parse', $command, true) ? str_repeat('b', 40) : '');
        (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, 'v1.0', 10, 100);

        assertSame(['rev-parse', '--verify', 'v1.0^{commit}'], array_slice($runner->commandsWith('rev-parse')[0], -3));
        assertSame([str_repeat('b', 40), '--', '.'], array_slice($runner->commandsWith('diff')[0], -3));
    }

    // ── A branch with no commit yet ──────────────────────────────────

    /**
     * Before the first commit `HEAD` names nothing, so `diff HEAD` failed and
     * the working tree read as "git unavailable". The index is compared with
     * nothing instead (every staged file is an add), then the worktree with
     * the index.
     */
    public function testAnUnbornBranchIsDiffedThroughTheIndexInsteadOfHead(): void
    {
        $runner = $this->recordingRunner(static function (array $command): string {
            if (in_array('rev-parse', $command, true)) {
                throw new RuntimeException('fatal: Needed a single revision');
            }
            if (in_array('--cached', $command, true)) {
                return "A\0src/staged.ts\0";
            }

            return in_array('diff', $command, true) ? "M\0src/staged.ts\0D\0src/gone.ts\0" : "src/untracked.ts\0";
        });
        $result = (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, null, 10, 100);

        assertSame(['src/gone.ts', 'src/staged.ts', 'src/untracked.ts'], $result['paths']);
        $diffs = $runner->commandsWith('diff');
        assertSame(2, count($diffs));
        assertSame([true, false], [in_array('--cached', $diffs[0], true), in_array('--cached', $diffs[1], true)]);
        foreach ($diffs as $diff) {
            assertSame(false, in_array('HEAD', $diff, true));
            assertSame(true, in_array('--relative', $diff, true));
            assertSame(['--', '.'], array_slice($diff, -2));
        }
    }

    /** An empty answer to `rev-parse --verify -q HEAD`, a bare line break included, is the same unborn branch as a failed one. */
    public function testAnEmptyHeadAnswerIsTreatedAsAnUnbornBranch(): void
    {
        $runner = $this->recordingRunner(static fn(array $command): string => in_array('rev-parse', $command, true) ? "\n" : '');
        (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, null, 10, 100);

        $diffs = $runner->commandsWith('diff');
        assertSame(2, count($diffs));
        assertSame(true, in_array('--cached', $diffs[0], true));
    }

    /** A born branch keeps the single diff against the commit HEAD resolved to. */
    public function testABornBranchIsDiffedAgainstHead(): void
    {
        $hash = str_repeat('c', 40);
        $runner = $this->recordingRunner(static fn(array $command): string => in_array('rev-parse', $command, true) ? $hash . "\n" : '');
        (new ProcessGitWorkingTreeProvider(runner: $runner))->changes($this->existingDir, null, 10, 100);

        $revParse = $runner->commandsWith('rev-parse');
        assertSame(['git', '--no-optional-locks', '--no-pager', '-C', (string) realpath($this->existingDir), 'rev-parse', '--verify', '-q', 'HEAD'], $revParse[0]);
        $diffs = $runner->commandsWith('diff');
        assertSame(1, count($diffs));
        assertSame(false, in_array('--cached', $diffs[0], true));
        assertSame(true, in_array('HEAD', $diffs[0], true));
    }

    public function testStagedAndUntrackedFilesAreListedBeforeTheFirstCommit(): void
    {
        $repo = $this->gitRepository();
        try {
            $this->writeFiles($repo, ['a.ts' => "a\n"]);
            $this->git($repo, ['add', 'a.ts']);
            $this->writeFiles($repo, ['b.ts' => "b\n"]);

            $result = (new ProcessGitWorkingTreeProvider())->changes($repo, null, 100, 5000);

            assertSame(['a.ts', 'b.ts'], $result['paths']);
            assertSame(false, $result['truncated']);
        } finally {
            $this->removeTempTree($repo);
        }
    }

    public function testANestedProjectBeforeTheFirstCommitSeesOnlyItsOwnFiles(): void
    {
        $repo = $this->gitRepository();
        try {
            $this->writeFiles($repo, ['pkg/a.ts' => "a\n", 'top.ts' => "t\n"]);
            $this->git($repo, ['add', 'pkg/a.ts', 'top.ts']);

            assertSame(['a.ts'], (new ProcessGitWorkingTreeProvider())->changes($repo . '/pkg', null, 100, 5000)['paths']);
        } finally {
            $this->removeTempTree($repo);
        }
    }

    // ── Paths git would quote ────────────────────────────────────────

    /**
     * Without -z git C-quoted a path holding '"', '\', a tab or a newline; the
     * quoted form failed path validation and the file vanished from history.
     * trim() also cut a real leading or trailing space. RelativePath still
     * rejects tab and newline (control characters), and discovery skips such
     * names, so the graph never holds them: those two are skipped whole, and
     * never split into fragments credited to other files.
     */
    public function testHistoryKeepsPathsGitWouldQuote(): void
    {
        $header = "KNOSSOS_COMMIT\x1fabc\x1f2026-07-20T10:00:00+00:00\x1f1784541600\x1fa@test.dev";
        $runner = $this->recordingRunner(static fn(array $command): string => self::logOutput([[$header, ['we"ird.ts', "tab\tname.ts", "new\nline.ts", ' spaced.ts ']]]));

        $history = (new ProcessGitHistoryProvider(runner: $runner))->history($this->existingDir, 30, 10, 100);

        assertSame([' spaced.ts ', 'we"ird.ts'], array_keys($history['files']));
        assertSame([1, 1], array_column($history['files'], 'commit_count'));
        assertSame(true, in_array('-z', $runner->commandsWith('log')[0], true));
    }

    public function testRealGitHistoryKeepsAQuotedPathAndSkipsATabbedOneWhole(): void
    {
        $repo = $this->gitRepository();
        try {
            $this->writeFiles($repo, ['we"ird.ts' => "w\n", "a\tb.ts" => "t\n", ' spaced.ts ' => "s\n"]);
            $this->git($repo, ['add', '.']);
            $this->git($repo, ['commit', '--quiet', '-m', 'odd names']);

            $history = (new ProcessGitHistoryProvider())->history($repo, 30, 10, 5000);

            assertSame([' spaced.ts ', 'we"ird.ts'], array_keys($history['files']));
        } finally {
            $this->removeTempTree($repo);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * What `git log -z --name-only` prints for these commits: each header
     * NUL-terminated, a newline, then each path NUL-terminated.
     *
     * @param list<array{0: string, 1: list<string>}> $commits
     */
    private static function logOutput(array $commits): string
    {
        $output = '';
        foreach ($commits as [$header, $paths]) {
            $output .= $header . "\0" . ($paths === [] ? '' : "\n" . implode("\0", $paths) . "\0");
        }

        return $output;
    }

    /** A fresh, empty repository under the temporary directory; skips the test without git. */
    private function gitRepository(): string
    {
        if (self::locateGit() === null) {
            self::markTestSkipped('git is not available on this host.');
        }
        $repo = sys_get_temp_dir() . '/knossos-stale-git-' . bin2hex(random_bytes(6));
        mkdir($repo, 0o700, true);
        $this->git($repo, ['init', '--quiet']);

        return (string) realpath($repo);
    }

    /** @param list<string> $args */
    private function git(string $repo, array $args): void
    {
        $this->runFixtureCommand(['git', '-C', $repo, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false', ...$args]);
    }

    /** @param array<string, string> $files */
    private function writeFiles(string $root, array $files): void
    {
        foreach ($files as $path => $content) {
            if (!is_dir(dirname($root . '/' . $path))) {
                mkdir(dirname($root . '/' . $path), 0o700, true);
            }
            file_put_contents($root . '/' . $path, $content);
        }
    }

    /**
     * A runner that records every command and answers through `$respond`.
     *
     * @param callable(list<string>): string $respond
     */
    private function recordingRunner(callable $respond): GitProcessRunnerInterface
    {
        return new class($respond) implements GitProcessRunnerInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            /** @var callable(list<string>): string */
            private $respond;

            /** @param callable(list<string>): string $respond */
            public function __construct(callable $respond)
            {
                $this->respond = $respond;
            }

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $this->commands[] = $command;

                return ($this->respond)($command);
            }

            /** @return list<list<string>> the recorded commands whose subcommand is `$subcommand` */
            public function commandsWith(string $subcommand): array
            {
                return array_values(array_filter($this->commands, static fn(array $command): bool => in_array($subcommand, $command, true)));
            }
        };
    }

    private function mockRunner(string $returnValue): GitProcessRunnerInterface
    {
        return new class($returnValue) implements GitProcessRunnerInterface {
            public function __construct(private readonly string $returnValue) {}

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->returnValue;
            }
        };
    }
}
