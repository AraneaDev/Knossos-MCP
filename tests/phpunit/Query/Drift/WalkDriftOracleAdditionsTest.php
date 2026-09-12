<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Query\Drift\TrackedPathPredicate;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * An addition is a file the scanner would have tracked. Counting every new
 * directory entry made an `npm install` look like a thousand-file architectural
 * change, and under the default-on refresh of Task 9 that would buy a full
 * rescan for nothing.
 */
final class WalkDriftOracleAdditionsTest extends KnossosTestCase
{
    /** A dependency directory appearing under a tracked one must not read as an architectural change. */
    #[Group('query')]
    public function testAnIgnoredAdditionIsNotDrift(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            mkdir($root . '/src/node_modules', 0o777, true);
            file_put_contents($root . '/src/node_modules/index.js', "module.exports = {};\n");
            touch($root . '/src', time() + 60);

            self::assertSame(0, self::drift($pdo, $projectId, $root)->added, 'A dependency directory is not a change to this project.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file the scanner would actually track is exactly the case additions counting exists to catch. */
    #[Group('query')]
    public function testATrackableAdditionIsDrift(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            file_put_contents($root . '/src/b.php', "<?php\n");
            touch($root . '/src', time() + 60);

            self::assertSame(1, self::drift($pdo, $projectId, $root)->added);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The oracle must honour a project's own configured ignores, not only IgnoreMatcher's built-in defaults. */
    #[Group('query')]
    public function testAProjectIgnoreIsHonoured(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $pdo->prepare('UPDATE projects SET config_json = :config WHERE id = :id')
                ->execute(['config' => '{"ignores":["src/generated"]}', 'id' => $projectId]);
            mkdir($root . '/src/generated', 0o777, true);
            file_put_contents($root . '/src/generated/c.php', "<?php\n");
            touch($root . '/src', time() + 60);

            self::assertSame(0, self::drift($pdo, $projectId, $root)->added, "The project's own ignores must apply, not only the defaults.");
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A malformed config must degrade to the default matcher, not fail the probe: discovery reports the same fault properly elsewhere. */
    #[Group('query')]
    public function testMalformedConfigJsonDoesNotThrow(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $pdo->prepare('UPDATE projects SET config_json = :config WHERE id = :id')
                ->execute(['config' => 'not valid json', 'id' => $projectId]);
            file_put_contents($root . '/src/b.php', "<?php\n");
            touch($root . '/src', time() + 60);

            self::assertSame(1, self::drift($pdo, $projectId, $root)->added, 'Malformed config must still let the probe answer, falling back to the default matcher.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The per-directory budget bounds the entries the walk looks at, and it is
     * the only thing standing between a freshness probe and a full enumeration
     * of a directory holding a hundred thousand logs, snapshots or swap files.
     *
     * Counted rather than inferred from the drift totals: an entry the scanner
     * would not track changes no count whether it was examined or skipped, so
     * how often the question was asked is the only observable difference
     * between a bounded walk and an unbounded one. It regressed once already,
     * silently, when the filter in front of the counter grew stricter.
     */
    #[Group('query')]
    public function testItStopsExaminingEntriesAtThePerDirectoryBound(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            // Comfortably past the 500-entry budget, and cheap to create.
            for ($index = 0; $index < 700; ++$index) {
                file_put_contents($root . '/src/noise' . $index . '.log', 'x');
            }
            file_put_contents($root . '/src/b.php', "<?php\n");
            touch($root . '/src', time() + 60);
            $predicate = $this->countingPredicate();

            (new WalkDriftOracle($pdo, $predicate))->drift($projectId, $this->activeScanId($pdo, $projectId), $root, $this->finishedAt($pdo, $projectId));

            self::assertSame(500, $predicate->calls, 'The walk must stop at its budget, not enumerate all 701 entries.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A predicate that counts how often the walk asked, and answers no, so nothing else stops the loop. */
    private function countingPredicate()
    {
        return new class implements TrackedPathPredicate {
            public int $calls = 0;

            /** Counts the question and answers that nothing is trackable, so only the budget can end the walk. */
            public function tracks(string $relativePath, string $absolutePath): bool
            {
                ++$this->calls;

                return false;
            }
        };
    }

    /** The active scan id, looked up by parameter binding rather than string interpolation. */
    private function activeScanId(PDO $pdo, string $projectId): string
    {
        $statement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);

        return (string) $statement->fetchColumn();
    }

    /** The active scan's finish time, looked up by parameter binding rather than string interpolation. */
    private function finishedAt(PDO $pdo, string $projectId): string
    {
        $statement = $pdo->prepare('SELECT s.finished_at FROM projects p JOIN scans s ON s.id = p.active_scan_id WHERE p.id = :id');
        $statement->execute(['id' => $projectId]);

        return (string) $statement->fetchColumn();
    }

    private static function drift(PDO $pdo, string $projectId, string $root): \Knossos\Query\Drift\DriftCounts
    {
        $activeScan = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $activeScan->execute(['id' => $projectId]);
        $scanId = (string) $activeScan->fetchColumn();

        $finished = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $finished->execute(['id' => $scanId]);
        $finishedAt = (string) $finished->fetchColumn();

        $drift = (new WalkDriftOracle($pdo))->drift($projectId, $scanId, $root, $finishedAt);
        self::assertNotNull($drift);
        return $drift;
    }
}
