<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * WalkDriftOracle in isolation from StalenessProbe: the counts it hands back,
 * and the two cases where it declines to answer at all.
 *
 * StalenessProbeBoundaryTest already exercises the walk's behaviour end to
 * end through the probe; this covers the oracle's own contract so the two
 * are not conflated.
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
            touch($root . '/src/a.php', time() + 3600);

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
}
