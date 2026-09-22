<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Query\Drift\ScannedPaths;
use Knossos\Query\Drift\WalkDriftOracle;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the probe treats as an input has to be what the scan treated as one.
 *
 * The two answers are reached by different code over different inputs: the walk
 * applies the project's configured ignores, and the probe rebuilt a matcher
 * from whatever the project row happened to carry. The scan persisted no
 * ignores at all, so the probe knew only the built-in defaults and counted
 * every path a project ignored on its own account as drift. That is the worst
 * shape a staleness verdict can take short of a false fresh: a rescan runs,
 * skips those paths exactly as it is configured to, and the next probe reports
 * the same drift again.
 */
final class ScannedPathsTest extends KnossosTestCase
{
    /**
     * The ignores a scan ran with are stored with it and used by the probe, so
     * a path discovery skipped is not counted as drift against the graph it
     * built.
     *
     * Driven through a real scan rather than a seeded project row, because the
     * defect was in what the scan wrote and a fixture that writes the row
     * itself would be asserting against its own arrangement.
     */
    #[Group('query')]
    public function testAScanRecordsTheIgnoresTheProbeThenHonours(): void
    {
        [$pdo, $projectId, $root] = $this->scanRootIgnoring('src/generated');
        try {
            $stored = json_decode((string) $this->configJson($pdo, $projectId), true);
            self::assertSame(['src/generated'], $stored['ignores'] ?? null, 'The scan has to record what it ignored; nothing else knows it afterwards.');

            // Under a directory the graph holds a file in, which is the only
            // place the bounded walk looks at all.
            mkdir($root . '/src/generated', 0o777, true);
            file_put_contents($root . '/src/generated/b.php', "<?php\n");
            touch($root . '/src', time() + 60);

            self::assertSame(
                0,
                $this->drift($pdo, $projectId, $root)->added,
                'A rescan would skip this path, so counting it reports a staleness no rescan can ever clear.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The project's own configuration file is tracked even when the ignores
     * name it, because the walk reads it even when the ignores name it.
     *
     * The exception is discovery's, and a probe that did not share it answered
     * "not an input" for the one file whose content decides what every later
     * scan does.
     */
    #[Group('query')]
    public function testTheConfigurationFileIsTrackedThoughTheIgnoresNameIt(): void
    {
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        try {
            $pdo->prepare('UPDATE projects SET config_json = :config WHERE id = :id')
                ->execute(['config' => '{"ignores":["knossos.json"]}', 'id' => $projectId]);
            file_put_contents($root . '/src/knossos.json', '{"version":1}');
            touch($root . '/src', time() + 60);

            self::assertSame(
                1,
                $this->drift($pdo, $projectId, $root)->added,
                'Discovery would read this file whatever the ignores say, so the graph does not describe the tree until it has.',
            );
            self::assertSame(
                true,
                ScannedPaths::forProject($pdo, $projectId)->tracks('src/knossos.json', $root . '/src/knossos.json'),
                'And the predicate says so directly, so the claim does not rest on one oracle\'s walk.',
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The walk skips what the tree's `.gitignore` files ignore, so the probe
     * has to as well: a framework writing its cache into an ignored directory
     * is not drift, and a rescan would skip every file of it again.
     */
    #[Group('query')]
    public function testAPathTheTreesGitignoreIgnoresIsNotDrift(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-gitignore-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/a.php', "<?php\n\nnamespace Fixture;\n\nfinal class A {}\n");
        file_put_contents($root . '/.gitignore', "/src/cache/\n");
        file_put_contents($root . '/src/.gitignore', "*.gen.php\n");
        $pdo = $this->freshTestDatabase();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
        $this->backdateDirectories($root, 10);
        try {
            mkdir($root . '/src/cache', 0o777, true);
            file_put_contents($root . '/src/cache/Container.php', "<?php\n");
            file_put_contents($root . '/src/b.gen.php', "<?php\n");
            touch($root . '/src', time() + 60);

            self::assertSame(0, $this->drift($pdo, $projectId, $root)->added);
            $paths = ScannedPaths::forProject($pdo, $projectId);
            self::assertFalse($paths->tracks('src/cache/Container.php', $root . '/src/cache/Container.php'));
            self::assertFalse($paths->tracks('src/b.gen.php', $root . '/src/b.gen.php'));
            self::assertTrue($paths->tracks('src/c.php', $root . '/src/c.php'));
            // An edit to a `.gitignore` changes what a rescan walks, so it is an input.
            self::assertTrue($paths->tracks('src/.gitignore', $root . '/src/.gitignore'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A project root holding one source file and a knossos.json that ignores
     * one directory, scanned for real.
     *
     * @return array{0: PDO, 1: string, 2: string}
     */
    private function scanRootIgnoring(string $pattern): array
    {
        $root = sys_get_temp_dir() . '/knossos-stale-ignores-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        file_put_contents($root . '/src/a.php', "<?php\n\nnamespace Fixture;\n\nfinal class A {}\n");
        file_put_contents($root . '/knossos.json', (string) json_encode(['version' => 1, 'ignores' => [$pattern]]));

        $pdo = $this->freshTestDatabase();
        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        // The directories this test just created can read as being at or after
        // the scan's own finished_at, which would make every later mtime
        // comparison meaningless. Same reason scanTempFixture() does it.
        $this->backdateDirectories($root, 10);

        return [$pdo, $result->projectId, $root];
    }

    /** The stored configuration, which is where the persisted ignores have to appear. */
    private function configJson(PDO $pdo, string $projectId): string
    {
        $statement = $pdo->prepare('SELECT config_json FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);

        return (string) $statement->fetchColumn();
    }

    /** What the walk oracle makes of the tree as it stands now, against the active scan. */
    private function drift(PDO $pdo, string $projectId, string $root): \Knossos\Query\Drift\DriftCounts
    {
        $activeScan = $pdo->prepare('SELECT active_scan_id FROM projects WHERE id = :id');
        $activeScan->execute(['id' => $projectId]);
        $scanId = (string) $activeScan->fetchColumn();

        $finished = $pdo->prepare('SELECT finished_at FROM scans WHERE id = :id');
        $finished->execute(['id' => $scanId]);
        $finishedAt = (string) $finished->fetchColumn();

        $drift = (new WalkDriftOracle($pdo))->drift($projectId, $scanId, $root, $finishedAt);
        self::assertNotNull($drift, 'The walk has to answer for this fixture, or nothing below is being tested.');

        return $drift;
    }
}
