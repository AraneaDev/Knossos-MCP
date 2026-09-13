<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Git\GitHeadResolver;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanPlanner;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The commit a scan records has to be one the scan's own bytes cannot
 * predate.
 *
 * Reconciliation used to resolve HEAD after discovery had already read every
 * file. A commit landing inside that window left the graph holding pre-commit
 * bytes beside a post-commit sha, and {@see \Knossos\Query\Drift\GitDriftOracle}
 * then diffs from a commit the graph was never built against: with a clean
 * working tree that diff names no candidate, and a graph that really is behind
 * reports `fresh`. A false `fresh` is the one verdict this subsystem exists to
 * prevent, so the capture moved in front of the walk.
 *
 * Ordering is asserted rather than described: the faked runner mutates the
 * tree while answering, so the mutation is visible to discovery only if the
 * capture genuinely came first. Nothing here shells out, because CI has no
 * git binary and no checkout.
 */
final class ScanHeadCaptureTest extends KnossosTestCase
{
    private const HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';

    /**
     * The planner must capture the commit before it walks, which is only
     * observable from the outside as: whatever the tree gained at capture time
     * is in the discovery result.
     */
    #[Group('scan')]
    public function testTheHeadIsCapturedBeforeDiscoveryReadsTheTree(): void
    {
        $root = $this->tempRootWithOneFile();
        try {
            $planner = new ScanPlanner($this->freshTestDatabase(), [$root], $this->resolverCommitting($root));

            $preparation = $planner->prepare($root, null, null, null, 'full', null, null);

            self::assertSame(self::HEAD, $preparation->gitHead, 'The captured commit is what the scan must record.');
            self::assertContains(
                'src/Committed.php',
                array_map(static fn(DiscoveredFile $file): string => $file->relativePath, $preparation->discovery->files),
                'Discovery must run after the capture, so a file that appeared at capture time is in the graph the recorded commit describes.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * End to end: the commit persisted on the scan row is the one captured
     * ahead of the walk, and the graph holds the tree as it stood at or after
     * that commit rather than before it.
     */
    #[Group('scan')]
    public function testAScanRecordsTheCommitItsOwnFilesWereReadAgainst(): void
    {
        $root = $this->tempRootWithOneFile();
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root], $this->resolverCommitting($root));

            $result = $service->scan($root);

            $head = $pdo->prepare('SELECT git_head FROM scans WHERE id = :id');
            $head->execute(['id' => $result->snapshotId]);
            self::assertSame(self::HEAD, $head->fetchColumn(), 'The captured commit must reach the scan row unchanged.');
            self::assertSame(
                1,
                $this->fileRowCount($pdo, $result->projectId, 'src/Committed.php'),
                'The file the commit brought must be in the graph; a graph missing it beside that commit is the desync that reports a stale graph fresh.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A project tree with one source file, the minimum a scan needs to produce a graph. */
    private function tempRootWithOneFile(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/Existing.php', "<?php\n\nfinal class Existing {}\n");

        return $root;
    }

    /** How many rows the graph holds for one path, bound rather than interpolated. */
    private function fileRowCount(PDO $pdo, string $projectId, string $relativePath): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM files WHERE project_id = :project AND relative_path = :path');
        $statement->execute(['project' => $projectId, 'path' => $relativePath]);

        return (int) $statement->fetchColumn();
    }

    /**
     * A resolver whose runner lands a commit while it answers: it writes a new
     * source file into the tree and reports the sha. Discovery sees that file
     * if and only if the capture happened first, which is the ordering under
     * test.
     */
    private function resolverCommitting(string $root): GitHeadResolver
    {
        return new GitHeadResolver(new class ($root) implements GitProcessRunnerInterface {
            public function __construct(private string $root) {}

            /** Simulates a commit landing at capture time, then answers with its sha. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                file_put_contents($this->root . '/src/Committed.php', "<?php\n\nfinal class Committed {}\n");

                return ScanHeadCaptureTest::head() . "\n";
            }
        });
    }

    /** The fixed sha the faked runner answers with, reachable from the anonymous runner class. */
    public static function head(): string
    {
        return self::HEAD;
    }
}
