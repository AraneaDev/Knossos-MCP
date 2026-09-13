<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\StalenessProbe;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The staleness verdict on its boundaries: the age against an injected clock,
 * the 20,000-file probe bound, drift found in any tracked directory, and the
 * guidance each state carries.
 *
 * StalenessProbe scored 83% under mutation testing. Its tests used the real
 * clock and small trees, so the age arithmetic, the clock injection, the file
 * bound and the walk over several directories could change with them green.
 */
final class StalenessProbeBoundaryTest extends KnossosTestCase
{
    /** The age is the injected clock minus the scan's finish, and never negative. */
    #[Group('query')]
    public function testTheAgeIsMeasuredOnTheInjectedClock(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $finished = self::finishedAt($pdo);

            assertSame(90, self::probe($pdo, $finished + 90)->probe($projectId)['age_seconds']);
            assertSame(0, self::probe($pdo, $finished)->probe($projectId)['age_seconds']);
            assertSame(0, self::probe($pdo, $finished - 30)->probe($projectId)['age_seconds'], 'A clock behind the scan reads as no age, not a negative one.');
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Only the unverified state carries the unverified guidance; a fresh graph carries none. */
    #[Group('query')]
    public function testEachStateCarriesOnlyItsOwnGuidance(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $fresh = (new StalenessProbe($pdo))->probe($projectId);
            assertSame('fresh', $fresh['state']);
            assertSame(false, array_key_exists('guidance', $fresh));

            $this->removeTempTree($root);
            $unverified = (new StalenessProbe($pdo))->probe($projectId);
            assertSame('unverified', $unverified['state']);
            assertSame(true, str_starts_with($unverified['guidance'], 'Change detection was skipped'));
        } finally {
            if (is_dir($root)) {
                $this->removeTempTree($root);
            }
        }
    }

    /** Twenty thousand tracked files are probed; twenty thousand and one are reported unverified. */
    #[Group('query')]
    public function testTheProbeBoundIsTwentyThousandFiles(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            self::trackMissingFiles($pdo, $projectId, 19_999);
            $atBound = (new StalenessProbe($pdo))->probe($projectId);
            assertSame('stale', $atBound['state'], 'Twenty thousand files are walked, and the 19,999 missing ones are drift.');
            assertSame(19_999, $atBound['deleted_files_since']);

            self::trackMissingFiles($pdo, $projectId, 1, 'extra');
            assertSame('unverified', (new StalenessProbe($pdo))->probe($projectId)['state']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * An addition in any tracked directory is found, not only in the first one
     * the walk opens, and several additions in one directory are all counted.
     */
    #[Group('query')]
    public function testAdditionsAreCountedAcrossEveryTrackedDirectory(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php', 'lib/b.php']);
        try {
            // src/ is walked first and holds nothing new, so it is backdated
            // below the scan and skipped; the additions are in lib/, after it.
            touch($root . '/src', time() - 3600);
            file_put_contents($root . '/lib/new1.php', "<?php\n");
            file_put_contents($root . '/lib/new2.php', "<?php\n");

            $probe = (new StalenessProbe($pdo))->probe($projectId);

            assertSame(2, $probe['added_files_since']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    private static function probe(PDO $pdo, int $now): StalenessProbe
    {
        return new StalenessProbe($pdo, static fn(): int => $now);
    }

    private static function finishedAt(PDO $pdo): int
    {
        return (int) strtotime((string) $pdo->query('SELECT finished_at FROM scans LIMIT 1')->fetchColumn());
    }

    /** Record files the active scan tracked that are not on disk. */
    private static function trackMissingFiles(PDO $pdo, string $projectId, int $count, string $prefix = 'gone'): void
    {
        $scanId = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
        $insert = $pdo->prepare(
            'INSERT INTO files(id, project_id, relative_path, content_hash, size, mtime, language, scanner_version, last_scan_id) ' .
            "VALUES (:id, :project, :path, 'h', 1, 1, 'php', '1', :scan)",
        );
        $pdo->beginTransaction();
        for ($index = 0; $index < $count; ++$index) {
            $path = sprintf('src/%s-%03d.php', $prefix, $index);
            $insert->execute(['id' => $projectId . ':' . $path, 'project' => $projectId, 'path' => $path, 'scan' => $scanId]);
        }
        $pdo->commit();
    }
}
