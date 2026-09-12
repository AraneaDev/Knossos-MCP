<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Driven through a faked runner, because CI has no git binary and no checkout.
 * What is under test is how the oracle reasons about what git says, not git
 * itself: git names candidates, and the graph's own stored hashes decide them.
 */
final class GitDriftOracleTest extends KnossosTestCase
{
    private const HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';

    /**
     * The defect this oracle was rewritten for. `git diff <recorded head>`
     * compares the commit to the working tree, but the scan built its graph
     * from the working tree, so an uncommitted edit the scan already holds is
     * not drift — and cannot be, because a rescan records that same commit.
     */
    #[Group('git')]
    public function testAnEditTheScanAlreadyAbsorbedIsNotDrift(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php'], [], ['src/a.php']);

            self::assertNotNull($drift);
            self::assertSame(0, $drift->total(), 'The file hashes equal to what the scan stored, so nothing drifted.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A tracked file whose bytes actually moved is the case the probe exists for. */
    #[Group('git')]
    public function testATrackedFileWhoseContentMovedIsAChange(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php'], [], ['src/a.php']);

            self::assertNotNull($drift);
            self::assertSame(1, $drift->changed);
            self::assertSame(0, $drift->added);
            self::assertSame(0, $drift->deleted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A tracked file git reports and the disk no longer holds is a deletion, whatever status git gave it. */
    #[Group('git')]
    public function testATrackedFileGoneFromDiskIsADeletion(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            unlink($root . '/src/a.php');

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php'], [], ['src/a.php']);

            self::assertNotNull($drift);
            self::assertSame(1, $drift->deleted);
            self::assertSame(0, $drift->changed);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Git follows files the scanner never looks at. A README, a lockfile or an
     * image changing is drift the graph cannot have, and a rescan would not
     * clear it, so reporting it strands the caller on a permanent warning.
     */
    #[Group('git')]
    public function testAChangedFileTheGraphDoesNotTrackIsNotDrift(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/README.md', "# read me\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['README.md'], [], ['src/a.php', 'README.md']);

            self::assertNotNull($drift);
            self::assertSame(0, $drift->total(), 'A file discovery would never have tracked is not a change to the graph.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** An untracked source file is exactly what a new component looks like before its first scan. */
    #[Group('git')]
    public function testAnUntrackedSourceFileIsAnAddition(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/b.php', "<?php\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], ['src/b.php'], ['src/a.php']);

            self::assertNotNull($drift);
            self::assertSame(1, $drift->added);
            self::assertSame(0, $drift->changed);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `--no-renames` makes git emit a rename as an unrelated delete plus add,
     * so the oracle must not need renames at all: the old path has a `files`
     * row and no file on disk (a deletion), the new path has no row but is
     * trackable (an addition), and the two must not cancel into a false zero.
     */
    #[Group('git')]
    public function testARenameIsADeletionAndAnAdditionNotAWash(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            unlink($root . '/src/a.php');
            file_put_contents($root . '/src/renamed.php', "<?php\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php', 'src/renamed.php'], [], ['src/renamed.php']);

            self::assertNotNull($drift);
            self::assertSame(1, $drift->added, 'The new path has no files row but is trackable.');
            self::assertSame(1, $drift->deleted, 'The old path has a files row and no file on disk.');
            self::assertSame(0, $drift->changed, 'Neither half of a rename is a content change.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A file `.gitignore` excludes and the scanner tracks anyway is invisible
     * to `git diff`, and a zero from this oracle ends the chain before the walk
     * can look. Cross-checking the index against the graph is what keeps this
     * oracle as sensitive as the walk it answers ahead of.
     */
    #[Group('git')]
    public function testAFileGitDoesNotFollowIsStillDecided(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], [], []);

            self::assertNotNull($drift);
            self::assertSame(1, $drift->changed, 'The index does not hold this file, so only the graph can decide it.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A scan taken before Task 1 recorded no commit at all; the oracle must hand over rather than guess. */
    #[Group('git')]
    public function testItReturnsNullWhenTheScanRecordedNoHead(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(null);
        try {
            $drift = (new GitDriftOracle($pdo, $this->fakeRunner([], [], [])))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Git emits a SHA-1 or a SHA-256 and nothing between; a length it never emits is a value no probe should act on. */
    #[Group('git')]
    public function testItReturnsNullForAHeadOfALengthGitNeverEmits(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(str_repeat('a', 50));
        try {
            $drift = (new GitDriftOracle($pdo, $this->fakeRunner([], [], [])))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A rebased or garbage-collected commit must hand over to the walk, not report zero drift. */
    #[Group('git')]
    public function testItReturnsNullWhenTheRecordedHeadNoLongerResolves(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $drift = (new GitDriftOracle($pdo, $this->failingRunner()))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift, 'A rebased or garbage-collected commit must hand over to the walk, not report zero drift.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The index cross-check is the call most likely to hit a large repository's
     * output ceiling, and it must fail on its own: losing it should cost only
     * the gitignored-but-scanned coverage it adds, not the whole oracle's
     * answer, which diff and untracked candidates can still decide.
     */
    #[Group('git')]
    public function testTheIndexCrossCheckFailsSoftInsteadOfSilencingTheOracle(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = (new GitDriftOracle($pdo, $this->runnerFailingOnlyOn('--cached', ['src/a.php'], [])))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNotNull($drift, 'diff and untracked candidates can still decide the oracle even when the cross-check cannot run.');
            self::assertSame(1, $drift->changed);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The argv is the contract with git, and it is where a scoping defect
     * hides: `git diff` is repository-wide however it is invoked, so a monorepo
     * package reported every sibling's churn as its own until the pathspec and
     * --relative were added. Nothing but an assertion on the command itself
     * would have caught that.
     */
    #[Group('git')]
    public function testItPinsEveryGitCommandItIssues(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $runner = $this->capturingRunner();
            (new GitDriftOracle($pdo, $runner))->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));
            $prefix = ['git', '--no-optional-locks', '--no-pager', '-C', $root];

            self::assertSame([
                [...$prefix, 'rev-parse', '--verify', self::HEAD . '^{commit}'],
                [...$prefix, 'diff', '--name-only', '-z', '--no-ext-diff', '--no-renames', '--relative', self::HEAD, '--', $root],
                [...$prefix, 'ls-files', '--others', '--exclude-standard', '-z', '--'],
                [...$prefix, 'ls-files', '--cached', '-z', '--'],
            ], $runner->commands);
            self::assertSame([2000, 2000, 2000, 2000], $runner->timeouts);
            self::assertSame(['drift query', 'drift query', 'drift query', 'drift query'], $runner->operations);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Runs the oracle against a runner answering with the given listings.
     *
     * @param list<string> $changed what `git diff` reports against the recorded commit
     * @param list<string> $untracked what `git ls-files --others` reports
     * @param list<string> $indexed what `git ls-files --cached` reports
     */
    private function drift(PDO $pdo, string $projectId, string $scanId, string $root, array $changed, array $untracked, array $indexed): ?DriftCounts
    {
        return (new GitDriftOracle($pdo, $this->fakeRunner($changed, $untracked, $indexed)))
            ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));
    }

    /**
     * Seeds a project with one file and stamps its scan's `git_head` to the
     * given value, so each test controls what the oracle finds without
     * touching the fixture's own scan-creation path.
     *
     * @return array{0: PDO, 1: string, 2: string, 3: string}
     */
    private function seedWithHead(?string $head): array
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $project = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :project');
        $project->execute(['project' => $projectId]);
        $scanId = (string) $project->fetchColumn();
        $pdo->prepare('UPDATE scans SET git_head = :head WHERE id = :id')->execute(['head' => $head, 'id' => $scanId]);

        return [$pdo, $projectId, $root, $scanId];
    }

    /** Looks up a scan's finish time by parameter binding, not string interpolation. */
    private function finishedAt(PDO $pdo, string $scanId): string
    {
        $statement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);

        return (string) $statement->fetchColumn();
    }

    /**
     * A runner that answers each subcommand by name rather than by call order,
     * so a test stays readable when the oracle's call sequence changes.
     *
     * @param list<string> $changed
     * @param list<string> $untracked
     * @param list<string> $indexed
     */
    private function fakeRunner(array $changed, array $untracked, array $indexed): GitProcessRunnerInterface
    {
        return new class (self::HEAD, $changed, $untracked, $indexed) implements GitProcessRunnerInterface {
            /**
             * @param list<string> $changed
             * @param list<string> $untracked
             * @param list<string> $indexed
             */
            public function __construct(
                private readonly string $head,
                private readonly array $changed,
                private readonly array $untracked,
                private readonly array $indexed,
            ) {}

            /** Canned stdout for whichever subcommand $command names. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return match (true) {
                    in_array('rev-parse', $command, true) => $this->head . "\n",
                    in_array('diff', $command, true) => self::framed($this->changed),
                    in_array('--others', $command, true) => self::framed($this->untracked),
                    default => self::framed($this->indexed),
                };
            }

            /**
             * Git's own `-z` framing: every entry terminated by a NUL.
             *
             * @param list<string> $paths
             */
            private static function framed(array $paths): string
            {
                return $paths === [] ? '' : implode("\0", $paths) . "\0";
            }
        };
    }

    /** A runner that records what it was asked to run and answers nothing, for pinning the argv. */
    private function capturingRunner(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            /** @var list<list<string>> */
            public array $commands = [];
            /** @var list<int> */
            public array $timeouts = [];
            /** @var list<string> */
            public array $operations = [];

            /** Records the call and answers with an empty listing. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $this->commands[] = $command;
                $this->timeouts[] = $timeoutMs;
                $this->operations[] = $operation;

                return '';
            }
        };
    }

    /** A runner that fails every call, standing in for a commit that no longer resolves. */
    private function failingRunner(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            /** Always throws, the way a bad revision or an absent binary does. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                throw new RuntimeException('bad revision');
            }
        };
    }

    /**
     * A runner that answers every subcommand normally except the one named by
     * $failingFlag, which it throws for — standing in for an index listing
     * that overruns GitProcessRunner's output ceiling while diff and
     * untracked candidates still answer fine.
     *
     * @param list<string> $changed
     * @param list<string> $untracked
     */
    private function runnerFailingOnlyOn(string $failingFlag, array $changed, array $untracked): GitProcessRunnerInterface
    {
        return new class ($failingFlag, self::HEAD, $changed, $untracked) implements GitProcessRunnerInterface {
            /**
             * @param list<string> $changed
             * @param list<string> $untracked
             */
            public function __construct(
                private readonly string $failingFlag,
                private readonly string $head,
                private readonly array $changed,
                private readonly array $untracked,
            ) {}

            /** Throws for $failingFlag, otherwise answers as fakeRunner() does. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                if (in_array($this->failingFlag, $command, true)) {
                    throw new RuntimeException('output too large');
                }

                return match (true) {
                    in_array('rev-parse', $command, true) => $this->head . "\n",
                    in_array('diff', $command, true) => self::framed($this->changed),
                    in_array('--others', $command, true) => self::framed($this->untracked),
                    default => '',
                };
            }

            /**
             * Git's own `-z` framing: every entry terminated by a NUL.
             *
             * @param list<string> $paths
             */
            private static function framed(array $paths): string
            {
                return $paths === [] ? '' : implode("\0", $paths) . "\0";
            }
        };
    }
}
