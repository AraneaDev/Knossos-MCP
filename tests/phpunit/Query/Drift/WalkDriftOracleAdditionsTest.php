<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

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

    private static function drift(PDO $pdo, string $projectId, string $root): \Knossos\Query\Drift\DriftCounts
    {
        $scanId = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
        $finishedAt = (string) $pdo->query("SELECT finished_at FROM scans WHERE id = '{$scanId}'")->fetchColumn();
        $drift = (new WalkDriftOracle($pdo))->drift($projectId, $scanId, $root, $finishedAt);
        self::assertNotNull($drift);
        return $drift;
    }
}
