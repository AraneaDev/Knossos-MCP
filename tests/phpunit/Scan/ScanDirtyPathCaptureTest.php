<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Git\DirtyPathResolver;
use Knossos\Git\DirtyPathSet;
use Knossos\Git\GitHeadResolver;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Query\Drift\GitDriftOracle;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scan has to record which of its stored hashes may be of uncommitted
 * content.
 *
 * Restore such a file afterwards and git can name it nowhere — `diff` matches
 * HEAD again, the untracked listing skips a tracked file, and the index holds
 * it exactly where it belongs — while the graph still holds a hash of bytes
 * that are gone. The recorded set is the only thing that keeps such a path a
 * candidate, so a scan that fails to persist it silently reopens that hole.
 * Driven through faked runners, because CI has no git binary and no checkout.
 */
final class ScanDirtyPathCaptureTest extends KnossosTestCase
{
    private const HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';

    /**
     * The set reaches the scan row, and the listing that produced it was taken
     * against the commit the same scan recorded rather than against a symbolic
     * HEAD that may have moved.
     */
    #[Group('scan')]
    public function testAScanRecordsThePathsThatDifferedFromItsCommit(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/Existing.php', "<?php\n\nfinal class Existing {}\n");
        try {
            $pdo = $this->freshTestDatabase();
            // A commit whose tree holds the file at a blob id nothing on disk
            // hashes to, so the scan's own read is what marks it dirty.
            $dirty = $this->capturingRunner("100644 blob 1111111111111111111111111111111111111111\tsrc/Existing.php\0");
            $service = new ProjectScanService(
                $pdo,
                self::repositoryRoot(),
                [$root],
                new GitHeadResolver($this->fixedRunner(self::HEAD . "\n")),
                new DirtyPathResolver($dirty),
            );

            $result = $service->scan($root);

            $row = $pdo->prepare('SELECT dirty_paths_json FROM scans WHERE id = :id');
            $row->execute(['id' => $result->snapshotId]);
            $recorded = DirtyPathSet::decode((string) $row->fetchColumn());
            self::assertNotNull($recorded, 'A scan that records nothing leaves the oracle no choice but to decline for the rest of that graph.');
            self::assertSame(['src/Existing.php'], $recorded->paths);
            self::assertContains(self::HEAD, $dirty->commands[0] ?? [], 'The tree must be the one this scan recorded a commit for, not a symbolic HEAD that may have moved since.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A gitless project has no commit to be dirty against, so nothing is asked and nothing is recorded. */
    #[Group('scan')]
    public function testAGitlessProjectRecordsNoSetAndIsNotAsked(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/Existing.php', "<?php\n\nfinal class Existing {}\n");
        try {
            $pdo = $this->freshTestDatabase();
            $dirty = $this->capturingRunner('');
            $service = new ProjectScanService(
                $pdo,
                self::repositoryRoot(),
                [$root],
                new GitHeadResolver($this->fixedRunner("fatal: not a git repository\n")),
                new DirtyPathResolver($dirty),
            );

            $result = $service->scan($root);

            $row = $pdo->prepare('SELECT dirty_paths_json FROM scans WHERE id = :id');
            $row->execute(['id' => $result->snapshotId]);
            self::assertNull($row->fetchColumn(), 'With no commit recorded there is nothing for a dirty set to be relative to.');
            self::assertSame([], $dirty->commands, 'Listing the tree of a commit that does not exist is a subprocess spent on nothing.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The whole loop, on the race the derivation exists for: a file read while
     * it differed from the commit, restored straight afterwards, and then
     * probed.
     *
     * Every question git can be asked after the restore says the tree is
     * clean, so a set built from such a question omits the path and is
     * recorded as complete. The graph then holds a hash of bytes that are not
     * on disk and reports itself fresh for ever. Deriving the set from what
     * discovery read, against a tree that cannot change, is what makes the
     * probe below find the change.
     */
    #[Group('scan')]
    public function testAFileRestoredRightAfterTheScanIsStillDrift(): void
    {
        $committed = "<?php\n\nfinal class Existing {}\n";
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        // What the scan reads: modified relative to the commit it records.
        file_put_contents($root . '/src/Existing.php', "<?php\n\nfinal class Existing { public int \$edited = 1; }\n");
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService(
                $pdo,
                self::repositoryRoot(),
                [$root],
                new GitHeadResolver($this->fixedRunner(self::HEAD . "\n")),
                new DirtyPathResolver($this->fixedRunner(
                    '100644 blob ' . self::blobId($committed) . "\tsrc/Existing.php\0",
                )),
            );

            $result = $service->scan($root);

            // Restored to exactly what the commit holds, so git can name it
            // nowhere: the diff matches, the untracked listing skips a tracked
            // file, and the index holds it where it belongs.
            file_put_contents($root . '/src/Existing.php', $committed);

            $drift = (new GitDriftOracle($pdo, $this->probeRunnerSeeingACleanTree()))
                ->drift($result->projectId, $result->snapshotId, $root, $this->finishedAt($pdo, $result->snapshotId));

            self::assertNotNull($drift, 'The scan recorded a complete set, so the oracle can answer.');
            self::assertSame(1, $drift->changed, 'The graph holds a hash of bytes that are no longer on disk, which no listing git offers can show.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Git's own name for a blob: sha1 over the header and the content, which is what a tree entry carries. */
    private static function blobId(string $contents): string
    {
        return sha1('blob ' . strlen($contents) . "\0" . $contents);
    }

    /** The active scan's finish time, looked up by parameter binding rather than string interpolation. */
    private function finishedAt(PDO $pdo, string $scanId): ?string
    {
        $statement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);
        $finished = $statement->fetchColumn();

        return is_string($finished) ? $finished : null;
    }

    /**
     * A probe-time runner answering the way git does for a repository whose
     * working tree matches its commit exactly: nothing changed, nothing
     * untracked, the file present in the index.
     */
    private function probeRunnerSeeingACleanTree(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            /** Canned stdout for whichever subcommand the oracle names. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return match (true) {
                    in_array('rev-parse', $command, true) => "3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c\n",
                    in_array('--cached', $command, true) => "src/Existing.php\0",
                    default => '',
                };
            }
        };
    }

    /** A runner answering with fixed output, standing in for a git binary CI does not have. */
    private function fixedRunner(string $output): GitProcessRunnerInterface
    {
        return new class ($output) implements GitProcessRunnerInterface {
            public function __construct(private string $output) {}

            /** Answers the fixed output whatever it is asked. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->output;
            }
        };
    }

    /** A runner that records what it was asked, so the revision it diffed against can be asserted. */
    private function capturingRunner(string $output): object
    {
        return new class ($output) implements GitProcessRunnerInterface {
            /** @var list<list<string>> */
            public array $commands = [];

            public function __construct(private string $output) {}

            /** Records the call and answers the fixed listing. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $this->commands[] = $command;

                return $this->output;
            }
        };
    }
}
