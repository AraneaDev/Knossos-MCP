<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A refactor that only moved a class holding a recursion to another namespace
 * and file failed the gate: every member got a new name, so the old cycle read
 * as new. The gate and the branch comparison now follow the move, and still
 * count a cycle the change really made.
 */
final class QualityGateMovedCycleTest extends KnossosTestCase
{
    #[Group('query')]
    public function testAMovedRecursionIsNotANewCycleButANewOneIs(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-moved-cycle-' . bin2hex(random_bytes(6));
        mkdir($root . '/src/Old', 0777, true);
        file_put_contents($root . '/composer.json', '{"autoload": {"psr-4": {"App\\\\": "src/"}}}');
        file_put_contents($root . '/src/Old/Walk.php', self::walker('App\\Old'));
        try {
            $pdo = $this->freshTestDatabase();
            $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $baseline = $scanner->scan($root, mode: 'full');
            $project = $baseline->projectId;
            mkdir($root . '/src/New');
            unlink($root . '/src/Old/Walk.php');
            file_put_contents($root . '/src/New/Walk.php', self::walker('App\\New'));
            $scanner->scan($root, mode: 'full');
            $queries = new ArchitectureQueryService($pdo);

            $gate = $queries->qualityGate($project, $baseline->snapshotId, ['new_cycles' => 0]);

            self::assertSame(0, $gate->data['metrics']['new_cycles'], 'Walk::walk and Walk::visit only moved.');
            self::assertSame(0, $queries->branchComparison($project, $baseline->snapshotId, [])['cycles']['count']);

            file_put_contents($root . '/src/New/Ping.php', <<<'PHP'
                <?php

                namespace App\New;

                final class Ping
                {
                    public function ping(int $n): int
                    {
                        return $n > 0 ? $this->pong($n - 1) : 0;
                    }

                    public function pong(int $n): int
                    {
                        return $this->ping($n);
                    }
                }
                PHP);
            $scanner->scan($root, mode: 'full');

            $gate = $queries->qualityGate($project, $baseline->snapshotId, ['new_cycles' => 0]);

            self::assertSame(1, $gate->data['metrics']['new_cycles'], 'Ping::ping and Ping::pong are a cycle the change made.');
            self::assertSame(1, $queries->branchComparison($project, $baseline->snapshotId, [])['cycles']['count']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    private static function walker(string $namespace): string
    {
        return <<<PHP
            <?php

            namespace {$namespace};

            final class Walk
            {
                public function walk(array \$items): int
                {
                    return \$items === [] ? 0 : \$this->visit(\$items);
                }

                public function visit(array \$items): int
                {
                    return 1 + \$this->walk(array_slice(\$items, 1));
                }
            }
            PHP;
    }
}
