<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\DashboardService;
use Knossos\Scan\ProjectScanService;
use PDO;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertIsInt;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * The project-wide picture: hubs, hotspots, cycles, trend, freshness and the
 * fan-in map, read from the graph without ever scanning.
 */
final class DashboardServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testAScannedProjectFillsEverySection(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new DashboardService($pdo))->dashboard($root, 1);
            assertSame('ok', $d['status']);
            assertSame($projectId, $d['project_id']);
            assertSame(realpath($root), $d['project_root']);
            assertSame('fresh', $d['freshness']['state']);
            assertSame(0, $d['freshness']['drift_files']);
            assertGreaterThanOrEqual(0, $d['freshness']['age_seconds']);
            assertNotSame([], $d['hubs']);
            assertNotSame([], $d['fan_in']);
            assertSame($d['snapshot_id'], $d['trend'][count($d['trend']) - 1]['snapshot_id']);
            assertLessThanOrEqual(3, count($d['cycles']['largest']));
            assertSame(0, $d['cycles']['count']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testASubdirectoryReportsTheProjectRoot(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new DashboardService($pdo))->dashboard($root . '/src');
            assertSame('ok', $d['status']);
            assertSame(realpath($root), $d['project_root']);
            assertSame(realpath($root . '/src'), $d['path']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnUnscannedPathReportsUnscanned(): void
    {
        $pdo = $this->freshTestDatabase();
        $empty = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($empty);
        try {
            $d = (new DashboardService($pdo))->dashboard($empty);
            assertSame('unscanned', $d['status']);
            assertSame(realpath($empty), $d['path']);
            assertSame(null, $d['project_root']);
            assertSame(null, $d['project_id']);
            assertSame(null, $d['snapshot_id']);
            assertSame(['state' => 'unscanned', 'age_seconds' => null, 'drift_files' => 0], $d['freshness']);
            assertSame([], $d['hubs']);
            assertSame([], $d['hotspots']);
            assertSame(0, $d['dead_code_candidates']);
            assertSame(['count' => 0, 'largest' => []], $d['cycles']);
            assertSame([], $d['trend']);
            assertSame([], $d['fan_in']);
        } finally {
            $this->removeTempTree($empty);
        }
    }

    #[Group('query')]
    public function testAPathThatDoesNotExistIsReportedAsGiven(): void
    {
        $pdo = $this->freshTestDatabase();
        $missing = '/nonexistent-knossos-dashboard/project';
        $d = (new DashboardService($pdo))->dashboard($missing);
        assertSame('unscanned', $d['status']);
        assertSame($missing, $d['path']);
    }

    #[Group('query')]
    public function testTheFanInThresholdFilters(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $service = new DashboardService($pdo);
            assertSame([], $service->dashboard($root, 1_000)['fan_in']);
            $low = $service->dashboard($root, 1)['fan_in'];
            assertCount(1, $low);
            assertSame('src/Core/Greeter.php', $low[0]['path']);
            assertSame(2, $low[0]['dependent_files']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheTrendIsOldestFirstAndEndsAtTheActiveSnapshot(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $before = (new DashboardService($pdo))->dashboard($root);
            file_put_contents($root . '/src/Edge/Extra.php', "<?php\nnamespace App;\nfinal class Extra {}\n");
            $this->rescan($pdo, $root);
            $after = (new DashboardService($pdo))->dashboard($root);
            assertCount(2, $after['trend']);
            assertSame($before['snapshot_id'], $after['trend'][0]['snapshot_id']);
            assertSame($after['snapshot_id'], $after['trend'][1]['snapshot_id']);
            assertNotSame($before['snapshot_id'], $after['snapshot_id']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Writes `$length` classes whose methods call each other in a ring, which
     * the scanner reads as one dependency cycle of that many methods.
     */
    private function writeCycle(string $root, string $prefix, int $length): void
    {
        for ($i = 0; $i < $length; ++$i) {
            $next = ($i + 1) % $length;
            $class = $prefix . $i;
            file_put_contents(
                $root . '/src/Edge/' . $class . '.php',
                "<?php\nnamespace App;\nfinal class {$class} { public function {$class}hop(): int { return (new {$prefix}{$next}())->{$prefix}{$next}hop(); } }\n",
            );
        }
    }

    private function rescan(PDO $pdo, string $root): void
    {
        // Retention at its ceiling so every scan stays a trend point.
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, snapshotRetention: 20);
    }

    #[Group('query')]
    public function testCyclesAreCountedAndTheLargestComeFirst(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 2);
            $this->writeCycle($root, 'Bb', 2);
            $this->writeCycle($root, 'Cc', 2);
            $this->writeCycle($root, 'Zz', 3);
            $this->rescan($pdo, $root);
            $d = (new DashboardService($pdo))->dashboard($root);
            assertSame(4, $d['cycles']['count']);
            assertSame([3, 2, 2], array_column($d['cycles']['largest'], 'size'));
            $members = $d['cycles']['largest'][0]['members'];
            sort($members);
            assertSame(['Zz0hop', 'Zz1hop', 'Zz2hop'], $members);
            assertSame(4, $d['trend'][count($d['trend']) - 1]['cycles']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testHubsHotspotsAndDeadCodeAreBoundedAndShaped(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 4);
            $this->writeCycle($root, 'Bb', 4);
            $this->rescan($pdo, $root);
            $d = (new DashboardService($pdo))->dashboard($root);
            assertCount(10, $d['hubs']);
            assertCount(10, $d['hotspots']);
            $health = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 10)->data;
            assertSame($health['hubs'][0]['component']['display_name'], $d['hubs'][0]['name']);
            assertSame($health['hubs'][0]['component']['kind'], $d['hubs'][0]['kind']);
            assertSame(
                ['name', 'kind', 'in_degree', 'out_degree', 'cross_boundary_degree'],
                array_keys($d['hubs'][0]),
            );
            assertSame($health['hubs'][0]['metrics']['in_degree'], $d['hubs'][0]['in_degree']);
            assertSame($health['hubs'][0]['metrics']['out_degree'], $d['hubs'][0]['out_degree']);
            assertSame($health['hubs'][0]['metrics']['cross_boundary_degree'], $d['hubs'][0]['cross_boundary_degree']);
            assertSame(['name', 'kind', 'score'], array_keys($d['hotspots'][0]));
            assertSame($health['static_hotspots'][0]['score'], $d['hotspots'][0]['score']);
            assertSame($health['static_hotspots'][0]['component']['display_name'], $d['hotspots'][0]['name']);
            assertSame($health['static_hotspots'][0]['component']['kind'], $d['hotspots'][0]['kind']);
            assertSame($health['bounds']['candidates_total'], $d['dead_code_candidates']);
            assertGreaterThan(0, $d['dead_code_candidates']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testDriftCountsChangedAddedAndDeletedFiles(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $fresh = (new DashboardService($pdo))->dashboard($root)['freshness'];
            assertSame('fresh', $fresh['state']);
            assertIsInt($fresh['age_seconds']);
            file_put_contents($root . '/src/Edge/Caller.php', file_get_contents($root . '/src/Edge/Caller.php') . "\n// edit\n");
            file_put_contents($root . '/src/Edge/Added.php', "<?php\n");
            unlink($root . '/tests/GreeterTest.php');
            $stale = (new DashboardService($pdo))->dashboard($root)['freshness'];
            assertSame('stale', $stale['state']);
            assertSame(3, $stale['drift_files']);
            assertIsInt($stale['age_seconds']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheTrendKeepsTheTwentyNewestSnapshots(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            for ($i = 0; $i < 21; ++$i) {
                file_put_contents($root . '/src/Edge/Extra.php', "<?php\n// {$i}\n");
                $this->rescan($pdo, $root);
            }
            $d = (new DashboardService($pdo))->dashboard($root);
            assertCount(20, $d['trend']);
            assertSame($d['snapshot_id'], $d['trend'][19]['snapshot_id']);
            assertSame(['snapshot_id', 'cycles', 'max_degree'], array_keys($d['trend'][0]));
            assertIsInt($d['trend'][0]['cycles']);
            assertIsInt($d['trend'][0]['max_degree']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
