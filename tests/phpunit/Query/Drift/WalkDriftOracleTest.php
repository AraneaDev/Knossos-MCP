<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

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
            $scanId = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
            $finishedAt = (string) $pdo->query('SELECT finished_at FROM scans LIMIT 1')->fetchColumn();

            unlink($root . '/src/b.php');
            file_put_contents($root . '/src/a.php', "<?php\nfinal class A {}\n");

            $oracle = new WalkDriftOracle($pdo);
            $drift = $oracle->drift($projectId, $scanId, $root, $finishedAt);

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
        $scanId = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
        $this->removeTempTree($root);

        $oracle = new WalkDriftOracle($pdo);

        $this->assertNull($oracle->drift($projectId, $scanId, $root, null));
    }

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
