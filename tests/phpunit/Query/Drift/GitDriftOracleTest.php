<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Git\DirtyPathSet;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\Drift\FirstAnsweringDriftOracle;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Driven through a faked runner, because CI has no git binary and no checkout.
 * What is under test is how the oracle reasons about what git says, not git
 * itself: git names candidates, and the graph's own stored hashes decide
 * them — except when the index cross-check finds a tracked row git does not
 * follow at all, where the oracle must decline outright rather than decide.
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
     * and the departing path also leaves the index. The index alone is not
     * the visibility check, though: `diff --name-only` still names the old
     * path as a change versus HEAD, so git can see it and the oracle must
     * answer normally rather than decline. The old path counts as a deletion
     * (its `files` row survives, its bytes do not), the new path as an
     * addition (no `files` row, but present and trackable), and the two must
     * not cancel into a false zero.
     */
    #[Group('git')]
    public function testARenameIsADeletionAndAnAdditionNotAWash(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            unlink($root . '/src/a.php');
            file_put_contents($root . '/src/renamed.php', "<?php\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php', 'src/renamed.php'], [], ['src/renamed.php']);

            self::assertNotNull($drift, 'The old path is named by diff, so git can still see it; the oracle must answer rather than decline.');
            self::assertSame(1, $drift->added, 'The new path has no files row but is trackable.');
            self::assertSame(1, $drift->deleted, 'The old path has a files row and no file on disk.');
            self::assertSame(0, $drift->changed, 'Neither half of a rename is a content change.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A staged delete's path leaves the index just as a rename's old half
     * does, and is visible the same way: `diff --name-only` still names it as
     * a change versus HEAD. The oracle must answer rather than decline, and
     * must count it as a deletion, not silently drop it.
     */
    #[Group('git')]
    public function testAStagedDeleteIsVisibleThroughDiffAndCountsAsADeletion(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            unlink($root . '/src/a.php');

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php'], [], []);

            self::assertNotNull($drift, 'The deleted path is named by diff, so git can still see it; the oracle must answer rather than decline.');
            self::assertSame(1, $drift->deleted);
            self::assertSame(0, $drift->changed);
            self::assertSame(0, $drift->added);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The regression this refinement removes: a source file created and
     * scanned but not yet committed has a files row and is absent from the
     * index, exactly like a genuinely gitignored file — but it is present in
     * `ls-files --others`, so git can see it, and the oracle must decide it
     * rather than decline. This is the normal shape of uncommitted agent
     * work, not the narrow case the decline exists to catch.
     */
    #[Group('git')]
    public function testANewUncommittedFileIsVisibleThroughUntrackedAndDecided(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/new.php', "<?php\necho 'edited';\n");
            $pdo->prepare(
                'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) ' .
                'VALUES (:id, :project, :path, :hash, 1, 1, :language, :version, :scan)',
            )->execute([
                'id' => 'new-file-row',
                'project' => $projectId,
                'path' => 'src/new.php',
                'hash' => hash('sha256', "<?php\n"),
                'language' => 'php',
                'version' => '0.1.0',
                'scan' => $scanId,
            ]);

            // diff: empty, the file was never committed or staged against HEAD.
            // untracked (--others): the new file, since it is not yet added.
            // indexed (--cached): only the original tracked file.
            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], ['src/new.php'], ['src/a.php']);

            self::assertNotNull($drift, 'The new file is named by --others, so git can see it; the oracle must decide it rather than decline.');
            self::assertSame(1, $drift->changed, "The stored hash is for the file's original content; the disk content has since been edited.");
            self::assertSame(0, $drift->added);
            self::assertSame(0, $drift->deleted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The guarantee this whole mechanism exists for: a file `.gitignore`
     * excludes and the scanner tracks anyway is absent from all three
     * listings this oracle gathers — `diff`, `--others` (excluded by
     * `--exclude-standard`), and `--cached` — which is what makes it
     * genuinely invisible to git rather than merely uncommitted. Deciding it
     * from the graph's own stored hash was the original design; it is no
     * longer what this oracle does. A tracked row git cannot name by any
     * means means this oracle's view is incomplete, so it must decline
     * outright — null, not a count, however confidently the graph's hash
     * could answer for this one file — and let the walk, which sees the file
     * directly, answer from a complete view instead.
     */
    #[Group('git')]
    public function testAFileGitDoesNotFollowMakesTheOracleDecline(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], [], []);

            self::assertNull($drift, 'The index does not hold this tracked file at all; the oracle must hand over to the walk, not decide it alone.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The cross-check must not fire on an ordinary project where git's index
     * covers everything the graph tracks. Without this guard, a decline could
     * regress into firing on every project, silently routing every probe to
     * the walk and losing the fast path this oracle exists to provide.
     */
    #[Group('git')]
    public function testCrossCheckWithNothingExtraStillAnswersNormally(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/a.php'], [], ['src/a.php']);

            self::assertNotNull($drift, "Git's index names every tracked file; the cross-check finds nothing extra and must not decline.");
            self::assertSame(1, $drift->changed);
            self::assertSame(0, $drift->added);
            self::assertSame(0, $drift->deleted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The property the whole change exists for, pinned through the real
     * chain rather than only at GitDriftOracle's own boundary: once this
     * oracle declines, FirstAnsweringDriftOracle must fall through to the
     * walk, and the walk — which reads the file directly through
     * ScannedPaths rather than through git — must be the one whose answer
     * surfaces. A unit test of GitDriftOracle's null alone would not catch a
     * chain that swallowed it instead of falling through.
     */
    #[Group('git')]
    public function testWhenGitDeclinesTheWalkAnswersInstead(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");
            // The index does not hold src/a.php at all (as `.gitignore` would
            // produce), so the cross-check must decline the git oracle
            // outright and hand this probe to the walk.
            $oracle = new FirstAnsweringDriftOracle(
                new GitDriftOracle($pdo, $this->fakeRunner([], [], [])),
                new WalkDriftOracle($pdo),
            );

            $drift = $oracle->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNotNull($drift, 'The walk must answer once the git oracle defers, not leave the probe with nothing.');
            self::assertSame(1, $drift->changed, "The walk's answer, not a false zero, must be what surfaces.");
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
     * output ceiling, and its failure must decline the whole oracle rather than
     * let diff and untracked candidates decide alone: without the index
     * listing, a file `.gitignore` excludes that the scanner tracks anyway is
     * absent from both remaining listings, so proceeding here could return a
     * non-null zero for a project that is not actually fresh — a false
     * `fresh`, which this whole mechanism exists to avoid. Previously this
     * fell back to diff and untracked candidates alone; that was reversed
     * because it traded a false `fresh` for an honest `unverified`, the wrong
     * way round.
     */
    #[Group('git')]
    public function testAFailedIndexCrossCheckDeclinesTheOracle(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = (new GitDriftOracle($pdo, $this->runnerFailingOnlyOn('--cached', ['src/a.php'], [])))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift, 'A failed index cross-check must decline the oracle outright, not decide from diff and untracked candidates alone.');
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
     * The defect trackedHashes() can hide with no test to notice: chunking
     * the narrowed hash lookup over `array_chunk($paths, PLACEHOLDERS_PER_QUERY)`
     * and accumulating with `+=`. Mutating that to `=` keeps only the last
     * chunk's rows, but every existing test stayed under 400 candidates, so
     * the loop only ever ran once and the mutation was invisible. This seeds
     * 405 tracked files (400 in one directory, 5 in another, spanning two
     * chunks) and forces the narrowed, chunked lookup path by pushing the
     * tracked file count past MAX_CROSS_CHECKED_FILES (rather than failing
     * the index cross-check, which now declines the oracle outright), then
     * edits one file in each chunk. `+=` finds both edits; `=` would drop the
     * first chunk's hashes entirely and misreport its untouched files as
     * fresh additions instead.
     */
    #[Group('git')]
    public function testTheChunkedHashLookupAccumulatesAcrossChunks(): void
    {
        $paths = [];
        for ($index = 0; $index < 400; ++$index) {
            $paths[] = sprintf('src/chunk1/f%04d.php', $index);
        }
        for ($index = 0; $index < 5; ++$index) {
            $paths[] = sprintf('src/chunk2/g%04d.php', $index);
        }
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles($paths);
        try {
            $project = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :project');
            $project->execute(['project' => $projectId]);
            $scanId = (string) $project->fetchColumn();
            $pdo->prepare('UPDATE scans SET git_head = :head, dirty_paths_json = :dirty WHERE id = :id')
                ->execute(['head' => self::HEAD, 'dirty' => DirtyPathSet::clean()->encode(), 'id' => $scanId]);

            file_put_contents($root . '/src/chunk1/f0005.php', "<?php\nfinal class Chunk1Edit {}\n");
            file_put_contents($root . '/src/chunk2/g0002.php', "<?php\nfinal class Chunk2Edit {}\n");

            // Pushing the tracked file count past MAX_CROSS_CHECKED_FILES
            // disables the cross-check by size, without git failing at all,
            // which is what routes trackedHashes() through the narrowed,
            // chunked call rather than the whole-scan one: with the
            // cross-check answering, $hashes would come from the single
            // unchunked query instead, and the chunking bug would never run.
            $this->addTrackedFileRows($pdo, $projectId, $scanId, 19_600, 'src/filler');

            $drift = $this->drift($pdo, $projectId, $scanId, $root, ['src/chunk1/f0005.php', 'src/chunk2/g0002.php'], [], []);

            self::assertNotNull($drift);
            self::assertSame(2, $drift->changed, 'One edit in each of the two chunks must both be found; += becoming = would drop the first chunk entirely.');
            self::assertSame(0, $drift->added, 'Every path has a files row; none should read as a fresh addition.');
            self::assertSame(0, $drift->deleted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The malformed-head guard (`return null;` right after the sha regex
     * check) has no test proving it actually stops the oracle: the existing
     * malformed-head test also happens to decline via the index cross-check
     * finding the tracked file invisible, so deleting the guard would still
     * leave that test green. Here the tracked file is named by the indexed
     * listing, so the cross-check finds nothing invisible and would let the
     * oracle answer normally (0 candidates, 0 drift) if the guard were gone;
     * only the guard itself can be why this stays null.
     */
    #[Group('git')]
    public function testAMalformedHeadStopsTheOracleEvenWhenNothingElseWouldDecline(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(str_repeat('a', 50));
        try {
            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], [], ['src/a.php']);

            self::assertNull($drift, 'A head of a length git never emits must stop the oracle by itself; with the tracked file visible through the cross-check, nothing else here would decline.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The catch around the index listing must decline the oracle even when
     * nothing else in the change set looks suspicious: a failed cross-check
     * means this oracle no longer has a complete view of what git can name,
     * whether or not diff and untracked happen to report anything. Declining
     * here regardless of an otherwise-quiet change set is what closes the gap
     * a scanner-tracked, gitignored file would otherwise fall through.
     */
    #[Group('git')]
    public function testAFailedIndexCrossCheckDeclinesEvenWhenNothingElseChanged(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $drift = (new GitDriftOracle($pdo, $this->runnerFailingOnlyOn('--cached', [], [])))
                ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));

            self::assertNull($drift, 'A failed index cross-check must decline the oracle even when diff and untracked both report nothing changed.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `trackedFileCount(...) <= MAX_CROSS_CHECKED_FILES` decides whether the
     * cross-check runs at all, and no test pins the boundary itself: at
     * exactly 20,000 tracked files the cross-check must still run (`<=`
     * true), not be skipped a row early (`<` false). Every one of the
     * 20,000 tracked rows is left out of all three git listings, so a
     * cross-check that runs must decline; one that is skipped answers
     * normally instead.
     */
    #[Group('git')]
    public function testCrossCheckStillRunsAtExactlyItsFileCountBound(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $this->addTrackedFileRows($pdo, $projectId, $scanId, 19_999, 'src/bulk');

            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], [], []);

            self::assertNull($drift, 'At exactly 20,000 tracked files the cross-check must still run and decline once it finds every tracked row invisible to git.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `count($candidates) > MAX_CANDIDATES` decides whether the oracle
     * declines for size, and no test pins the boundary itself: exactly
     * 20,000 candidates must still be decided (`>` false), not declined one
     * candidate early (`>=` true). None of the synthetic candidates exist on
     * disk or have a files row, so once past the size check they cost
     * nothing to decide and drift stays at zero either way — only the
     * decline itself (a null result) tells the two branches apart.
     */
    #[Group('git')]
    public function testCandidateCountAtExactlyItsCeilingStillAnswers(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $changed = [];
            for ($index = 0; $index < 20_000; ++$index) {
                $changed[] = sprintf('nonexistent/f%05d.php', $index);
            }

            $drift = $this->drift($pdo, $projectId, $scanId, $root, $changed, [], ['src/a.php']);

            self::assertNotNull($drift, 'Exactly 20,000 candidates is still at the ceiling, not over it; the oracle must decide them rather than decline for size.');
            self::assertSame(0, $drift->total(), 'None of the synthetic paths exist on disk or have a files row, so nothing about them can register as drift.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * `if (!is_file($absolute)) { continue; }` (~line 251) skips one
     * candidate that has no stored hash and is also gone from disk.
     * Mutating `continue` to `break` abandons every remaining candidate the
     * moment this happens, instead of only skipping this one. `ghost.php`
     * is such a candidate — untracked and absent from disk — placed before
     * a second, genuinely new file that must still be decided (and counted
     * as an addition) once the first is skipped.
     */
    #[Group('git')]
    public function testASkippedCandidateDoesNotAbandonTheRestOfTheLoop(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            file_put_contents($root . '/new.php', "<?php\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], ['ghost.php', 'new.php'], ['src/a.php']);

            self::assertNotNull($drift);
            self::assertSame(1, $drift->added, 'The candidate listed after the skipped one must still be decided, not abandoned.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The `error_log()` breadcrumb in the outer catch (~line 162) exists
     * because a permanently silent git fast path is undiagnosable in
     * production; a `FunctionCallRemoval` mutant deletes the call itself and
     * nothing previously noticed. Points PHP's `error_log` ini directive at a
     * temporary file for the duration of the call, triggers the failure
     * path, and asserts the file received a stable marker rather than the
     * full sentence, which is cosmetic and free to reword.
     */
    #[Group('git')]
    public function testAGitFailureEmitsADiagnosticBreadcrumb(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $log = $this->captureErrorLog(function () use ($pdo, $projectId, $scanId, $root): void {
                (new GitDriftOracle($pdo, $this->failingRunner()))
                    ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));
            });

            self::assertStringContainsString('knossos drift query: git could not answer', $log, 'A failed git call must leave a breadcrumb; a silent fast path is undiagnosable in production.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The `error_log()` breadcrumb in the index cross-check's own catch is a
     * distinct requirement from the outer one above, with its own
     * independently surviving `FunctionCallRemoval` mutant.
     */
    #[Group('git')]
    public function testAFailedIndexCrossCheckEmitsItsOwnDiagnosticBreadcrumb(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $log = $this->captureErrorLog(function () use ($pdo, $projectId, $scanId, $root): void {
                (new GitDriftOracle($pdo, $this->runnerFailingOnlyOn('--cached', [], [])))
                    ->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId));
            });

            self::assertStringContainsString('ls-files --cached could not answer', $log, 'A failed index cross-check must leave its own breadcrumb, distinct from the outer git-failure one.');
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
     * The hole the recorded dirty set exists to close. A tracked file scanned
     * while it differed from HEAD stores a hash of the working-tree bytes;
     * restore it and git can no longer name it at all — `diff` matches, the
     * untracked listing skips a tracked file, and the index holds it exactly
     * where it belongs — while the stored hash is of content that is gone.
     * Without the recorded set there is no candidate and the graph reports
     * fresh; with it the path is a candidate whatever the listings say, and
     * the ordinary hash comparison finds the change.
     */
    #[Group('git')]
    public function testAFileScannedDirtyAndSinceRestoredIsStillAChange(): void
    {
        // The scan read `src/a.php` while it was modified, so the graph holds
        // a hash of those bytes rather than of the committed ones.
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD, ['src/a.php']);
        try {
            // Restored to its committed content: git now names it nowhere.
            file_put_contents($root . '/src/a.php', "<?php\nfinal class Committed {}\n");

            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], [], ['src/a.php']);

            self::assertNotNull($drift, 'The scan recorded a complete dirty set, so the oracle can answer.');
            self::assertSame(1, $drift->changed, 'The stored hash is of bytes no longer on disk; that is a change however invisible it is to git.');
            self::assertSame(0, $drift->added);
            self::assertSame(0, $drift->deleted);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A path recorded as dirty that has not moved since must not be counted:
     * every candidate is still decided against the hash the scan stored, so
     * always-a-candidate cannot become always-drifted. Otherwise a project
     * with any uncommitted work would report permanent staleness no rescan
     * could clear.
     */
    #[Group('git')]
    public function testARecordedDirtyPathThatHasNotMovedIsNotDrift(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD, ['src/a.php']);
        try {
            $drift = $this->drift($pdo, $projectId, $scanId, $root, [], [], ['src/a.php']);

            self::assertNotNull($drift);
            self::assertSame(0, $drift->total(), 'The file still hashes to what the scan stored, so nothing drifted.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A scan that recorded no dirty set — a graph built before the column
     * existed, or one whose dirty listing git could not answer — cannot rule
     * out the restore above. Declining hands the question to the walk, which
     * sees the file directly; deciding would report a freshness this oracle
     * never verified.
     */
    #[Group('git')]
    public function testAScanWithNoRecordedDirtySetDeclines(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $pdo->prepare('UPDATE scans SET dirty_paths_json = NULL WHERE id = :id')->execute(['id' => $scanId]);

            self::assertNull(
                $this->drift($pdo, $projectId, $scanId, $root, [], [], ['src/a.php']),
                'Without a recorded dirty set a file scanned dirty and since restored is invisible, so the walk must answer instead.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A set the scan had to truncate is not a set: reading it as complete
     * would put the invisible-restore hole straight back for exactly the
     * repositories most likely to have one. It declines like an absent set.
     */
    #[Group('git')]
    public function testATruncatedDirtySetDeclinesLikeAnAbsentOne(): void
    {
        [$pdo, $projectId, $root, $scanId] = $this->seedWithHead(self::HEAD);
        try {
            $pdo->prepare('UPDATE scans SET dirty_paths_json = :dirty WHERE id = :id')->execute([
                'dirty' => (string) json_encode(['paths' => ['src/a.php'], 'complete' => false]),
                'id' => $scanId,
            ]);

            self::assertNull(
                $this->drift($pdo, $projectId, $scanId, $root, [], [], ['src/a.php']),
                'An incomplete record cannot be decided from, and says so rather than being trimmed into a complete-looking one.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Seeds a project with one file and stamps its scan's `git_head` and
     * recorded dirty set, so each test controls what the oracle finds without
     * touching the fixture's own scan-creation path.
     *
     * The dirty set defaults to a clean working tree, which is a recorded
     * answer and not an absent one: a scan that recorded nothing makes the
     * oracle decline outright, and every test here that is about something
     * else needs it past that gate.
     *
     * @param list<string> $dirty tracked paths the scan read while they
     *        differed from $head
     * @return array{0: PDO, 1: string, 2: string, 3: string}
     */
    private function seedWithHead(?string $head, array $dirty = []): array
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $project = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :project');
        $project->execute(['project' => $projectId]);
        $scanId = (string) $project->fetchColumn();
        $pdo->prepare('UPDATE scans SET git_head = :head, dirty_paths_json = :dirty WHERE id = :id')
            ->execute(['head' => $head, 'dirty' => DirtyPathSet::of($dirty)->encode(), 'id' => $scanId]);

        return [$pdo, $projectId, $root, $scanId];
    }

    /**
     * Inserts $count additional `files` rows for the active scan, with no
     * files created on disk, so a boundary test can reach thousands of
     * tracked rows without paying for a real tree of that size: what
     * MAX_CROSS_CHECKED_FILES counts is the row count, not disk content.
     */
    private function addTrackedFileRows(PDO $pdo, string $projectId, string $scanId, int $count, string $prefix): void
    {
        $insert = $pdo->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) ' .
            'VALUES (:id, :project, :path, :hash, 1, 1, :language, :version, :scan)',
        );
        $pdo->beginTransaction();
        for ($index = 0; $index < $count; ++$index) {
            $insert->execute([
                'id' => $prefix . '-' . $index,
                'project' => $projectId,
                'path' => $prefix . '/f' . $index . '.php',
                'hash' => hash('sha256', (string) $index),
                'language' => 'php',
                'version' => '0.1.0',
                'scan' => $scanId,
            ]);
        }
        $pdo->commit();
    }

    /** Looks up a scan's finish time by parameter binding, not string interpolation. */
    private function finishedAt(PDO $pdo, string $scanId): string
    {
        $statement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);

        return (string) $statement->fetchColumn();
    }

    /**
     * Points PHP's `error_log` ini directive at a temporary file for the
     * duration of $trigger, so `error_log()` calls land somewhere assertable
     * instead of stderr or syslog, then restores the previous setting in a
     * `finally` — the same restore-in-finally discipline the putenv
     * kill-switch tests use, so nothing leaks into a later test. Never
     * touches stdout, which carries MCP protocol frames rather than
     * diagnostics.
     */
    private function captureErrorLog(callable $trigger): string
    {
        $previous = ini_get('error_log');
        $tmpFile = tempnam(sys_get_temp_dir(), 'knossos-errlog-');
        ini_set('error_log', $tmpFile);
        try {
            $trigger();

            return (string) file_get_contents($tmpFile);
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($tmpFile);
        }
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
     * that overruns GitProcessRunner's output ceiling, while diff and
     * untracked still answer fine on their own (the oracle now declines
     * regardless, once the index listing itself fails).
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
