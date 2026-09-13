<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Discovery\ProjectUnit;
use Knossos\Discovery\UnitInputSet;
use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * WalkDriftOracle in isolation from StalenessProbe: the counts it hands back,
 * the two cases where it declines to answer at all, and that the verdict
 * turns on content, not on mtime.
 *
 * StalenessProbeBoundaryTest already exercises the walk's behaviour end to
 * end through the probe; this covers the oracle's own contract so the two
 * are not conflated.
 *
 * mtime answers "was this file written", content hash answers "is this file
 * different". Only the second one should cost a rescan: a `git checkout` that
 * restores identical bytes moves every mtime in the tree and changes nothing.
 */
final class WalkDriftOracleTest extends KnossosTestCase
{
    /** Content edits, additions and deletions are reported as three separate counts, not one total. */
    #[Group('query')]
    public function testDriftIsSplitIntoChangedAddedAndDeleted(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php', 'src/b.php']);
        try {
            unlink($root . '/src/b.php');
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $drift = self::drift($pdo, $projectId, $root);

            $this->assertNotNull($drift);
            $this->assertSame(1, $drift->changed);
            $this->assertSame(1, $drift->deleted);
            $this->assertSame(0, $drift->added);
            $this->assertSame(2, $drift->total());
        } finally {
            if (is_dir($root)) {
                $this->removeTempTree($root);
            }
        }
    }

    /** A missing project root is undecidable, not zero drift. */
    #[Group('query')]
    public function testDriftIsNullWhenTheRootIsGone(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $statement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $scanId = (string) $statement->fetchColumn();
        $this->removeTempTree($root);

        $oracle = new WalkDriftOracle($pdo);

        $this->assertNull($oracle->drift($projectId, $scanId, $root, null));
    }

    /** A `touch` or a `git checkout` that restores identical bytes must not force a rescan. */
    #[Group('query')]
    public function testATouchWithoutAContentChangeIsNotDrift(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            touch($root . '/src/a.php', time() + 120);

            self::assertSame(0, self::drift($pdo, $projectId, $root)->changed, 'Rewriting identical bytes is not a change worth a rescan.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A mtime moved backwards without touching the bytes must not read as drift either, for the same reason a forward touch does not. */
    #[Group('query')]
    public function testAMtimeMovedBackwardsWithoutAContentChangeIsNotDrift(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            touch($root . '/src/a.php', time() - 3600);

            self::assertSame(0, self::drift($pdo, $projectId, $root)->changed, 'A `git checkout` that restores identical bytes moves mtime backwards for free.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A real edit must still be caught now that mtime is no longer part of the verdict. */
    #[Group('query')]
    public function testAContentChangeIsDrift(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            self::assertSame(1, self::drift($pdo, $projectId, $root)->changed);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A filesystem clock coarse enough to hide an edit inside one tick must not hide it from this probe. */
    #[Group('query')]
    public function testAContentChangeThatLeavesMtimeAloneIsStillDrift(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $mtime = (int) filemtime($root . '/src/a.php');
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");
            touch($root . '/src/a.php', $mtime);

            self::assertSame(1, self::drift($pdo, $projectId, $root)->changed, 'A coarse filesystem clock must not hide a real edit.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A file the hash pass cannot read must be counted once, as a deletion, not doubled as a change too. */
    #[Group('query')]
    public function testAMissingFileCountsAsDeletedNotChanged(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php', 'src/b.php']);
        try {
            unlink($root . '/src/a.php');
            $drift = self::drift($pdo, $projectId, $root);

            self::assertSame(1, $drift->deleted);
            self::assertSame(0, $drift->changed);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The bound has to count what the probe will read and hash, which is the
     * `files` rows and the recorded manifests together. Counted over the rows
     * alone, a project of 20,000 files and 5,000 manifests hashed 25,000
     * inputs on every probe, under a ceiling whose whole purpose is to keep
     * that work off a freshness check.
     *
     * Pinned on the boundary and only through the manifests' own contribution
     * to it: 19,999 rows plus one manifest is exactly the ceiling and is
     * answered, and one more manifest is what carries the same probe to 20,001
     * and over it. A ceiling counted before they join sees 19,999 both times.
     */
    #[Group('query')]
    public function testTheProbeBoundCountsTheManifestsToo(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $scanId = $this->activeScanId($pdo, $projectId);
            $this->addTrackedFileRows($pdo, $projectId, $scanId, 19_998, 'filler');
            $this->recordManifests($pdo, $scanId, ['composer.json']);

            self::assertNotNull(
                (new WalkDriftOracle($pdo))->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId)),
                '19,999 rows and one manifest is exactly the ceiling, which is answered rather than declined one input early.',
            );

            $this->recordManifests($pdo, $scanId, ['composer.json', 'package.json']);

            self::assertNull(
                (new WalkDriftOracle($pdo))->drift($projectId, $scanId, $root, $this->finishedAt($pdo, $scanId)),
                'One more manifest is one input past the ceiling, and the ceiling has to see the total rather than the rows it started from.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Inserts $count additional `files` rows for the active scan, with nothing
     * on disk: what the bound counts is inputs the probe would read, and a row
     * costs the same to count whether or not the file behind it exists.
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

    /**
     * Records the given manifest paths as the scan's unit inputs.
     *
     * @param list<string> $paths
     */
    private function recordManifests(PDO $pdo, string $scanId, array $paths): void
    {
        $units = [];
        foreach ($paths as $path) {
            $units[] = new ProjectUnit('composer', $path, hash('sha256', $path));
        }
        $pdo->prepare('UPDATE scans SET unit_inputs_json = :units WHERE id = :id')
            ->execute(['units' => UnitInputSet::of($units)->encode(), 'id' => $scanId]);
    }

    /** The project's active scan id, looked up by parameter binding rather than string interpolation. */
    private function activeScanId(PDO $pdo, string $projectId): string
    {
        $statement = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);

        return (string) $statement->fetchColumn();
    }

    /** A scan's finish time, which the additions walk takes as its reference point. */
    private function finishedAt(PDO $pdo, string $scanId): string
    {
        $statement = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $statement->execute(['id' => $scanId]);

        return (string) $statement->fetchColumn();
    }

    /** Looks up the active scan and its finish time by parameter binding, not string interpolation, then runs the oracle. */
    private static function drift(PDO $pdo, string $projectId, string $root): DriftCounts
    {
        $project = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :project');
        $project->execute(['project' => $projectId]);
        $scanId = (string) $project->fetchColumn();

        $scan = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :scan');
        $scan->execute(['scan' => $scanId]);
        $finishedAt = (string) $scan->fetchColumn();

        $drift = (new WalkDriftOracle($pdo))->drift($projectId, $scanId, $root, $finishedAt);
        self::assertNotNull($drift);
        return $drift;
    }
}
