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
            assertSame(['count' => 0, 'truncated' => false, 'truncation_reasons' => [], 'largest' => []], $d['cycles']);
            assertSame(false, $d['dead_code_truncated']);
            assertSame(false, $d['fan_in_truncated']);
            assertSame(false, $d['hubs_truncated']);
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

    /**
     * The fixture declares Core and Edge; its package inference adds wider
     * boundaries over the same files. The declared one is the label.
     */
    #[Group('query')]
    public function testEachHubIsLabelledWithItsDeclaredBoundary(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new DashboardService($pdo))->dashboard($root, 1);
            $labels = array_column(array_merge($d['hubs'], $d['hotspots']), 'boundary', 'canonical_name');
            assertSame('Core', $labels['App\\Greeter'] ?? null);
            assertSame('Core', $labels['App\\Greeter::greet'] ?? null);
            assertSame('Edge', $labels['App\\Caller::run'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** With no declared boundary the narrowest inferred one wins over the repository-wide package. */
    #[Group('query')]
    public function testWithoutDeclaredBoundariesTheNarrowestInferredOneIsTheLabel(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/knossos.json', json_encode(['version' => 1], JSON_THROW_ON_ERROR));
            $this->rescan($pdo, $root);
            $d = (new DashboardService($pdo))->dashboard($root, 1);
            $labels = array_filter(array_column(array_merge($d['hubs'], $d['hotspots']), 'boundary'), is_string(...));
            assertNotSame([], $labels);
            $wide = (string) $pdo->query("SELECT name FROM boundaries WHERE source = 'inferred' AND matcher_json LIKE '%\"value\":\"\"%'")->fetchColumn();
            foreach ($labels as $label) {
                assertNotSame($wide, $label);
            }
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
                ['name', 'canonical_name', 'kind', 'boundary', 'in_degree', 'out_degree', 'cross_boundary_degree'],
                array_keys($d['hubs'][0]),
            );
            // The pane shows the display name and looks the component up by the canonical one.
            assertSame($health['hubs'][0]['component']['canonical_name'], $d['hubs'][0]['canonical_name']);
            assertSame($health['static_hotspots'][0]['component']['canonical_name'], $d['hotspots'][0]['canonical_name']);
            assertSame($health['hubs'][0]['metrics']['in_degree'], $d['hubs'][0]['in_degree']);
            assertSame($health['hubs'][0]['metrics']['out_degree'], $d['hubs'][0]['out_degree']);
            assertSame($health['hubs'][0]['metrics']['cross_boundary_degree'], $d['hubs'][0]['cross_boundary_degree']);
            assertSame(
                ['name', 'canonical_name', 'kind', 'boundary', 'in_degree', 'out_degree', 'cross_boundary_degree', 'score'],
                array_keys($d['hotspots'][0]),
            );
            // A hotspot carries the same degrees the health walk measured for it.
            assertSame($health['static_hotspots'][0]['factors']['in_degree'], $d['hotspots'][0]['in_degree']);
            assertSame($health['static_hotspots'][0]['factors']['cross_boundary_degree'], $d['hotspots'][0]['cross_boundary_degree']);
            // Ten of many is the page, not a cut.
            assertSame(false, $d['hubs_truncated']);
            assertSame([], $d['hubs_truncation_reasons']);
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

    #[Group('query')]
    public function testAProjectWithoutAnActiveScanIsUnscanned(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $pdo->prepare('UPDATE projects SET active_scan_id = NULL WHERE id = :id')->execute(['id' => $projectId]);
            $d = (new DashboardService($pdo))->dashboard($root);
            assertSame('unscanned', $d['status']);
            assertSame(null, $d['snapshot_id']);
            assertSame(null, $d['project_id']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testCleanSectionsAreNotReportedTruncated(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 2);
            $this->rescan($pdo, $root);
            $d = (new DashboardService($pdo))->dashboard($root, 1);
            assertSame(1, $d['cycles']['count']);
            assertSame(false, $d['cycles']['truncated']);
            assertSame([], $d['cycles']['truncation_reasons']);
            assertSame(false, $d['dead_code_truncated']);
            assertSame(false, $d['fan_in_truncated']);
            assertSame(false, $d['hubs_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testMoreCyclesThanTheSearchLimitAreReportedTruncated(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 2);
            $this->writeCycle($root, 'Bb', 2);
            $this->writeCycle($root, 'Cc', 2);
            $this->rescan($pdo, $root);
            $d = (new DashboardService($pdo, cycleLimit: 2))->dashboard($root);
            assertSame(2, $d['cycles']['count']);
            assertSame(true, $d['cycles']['truncated']);
            assertSame(['result_limit'], $d['cycles']['truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAFanInMapPastTheCapIsTrimmedAndReportedTruncated(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 3);
            $this->rescan($pdo, $root);
            $all = (new DashboardService($pdo))->dashboard($root, 1);
            assertGreaterThan(1, count($all['fan_in']));
            assertSame(false, $all['fan_in_truncated']);
            $capped = (new DashboardService($pdo, fanInCap: 1))->dashboard($root, 1);
            assertCount(1, $capped['fan_in']);
            assertSame($all['fan_in'][0], $capped['fan_in'][0]);
            assertSame(true, $capped['fan_in_truncated']);
            $exact = (new DashboardService($pdo, fanInCap: count($all['fan_in'])))->dashboard($root, 1);
            assertSame(false, $exact['fan_in_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAnExhaustedTimeBudgetIsReportedOnCyclesAndDeadCode(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 2);
            $this->rescan($pdo, $root);
            $ticks = 0;
            $clock = static function () use (&$ticks): int {
                return $ticks += 10_000_000_000;
            };
            $d = (new DashboardService($pdo, clock: $clock))->dashboard($root);
            assertSame(true, $d['cycles']['truncated']);
            assertSame(true, in_array('time_limit', $d['cycles']['truncation_reasons'], true));
            assertSame(true, $d['dead_code_truncated']);
            assertSame(true, $d['hubs_truncated']);
            assertSame(true, in_array('time_limit', $d['hubs_truncation_reasons'], true));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The degree walk gets five seconds unless told otherwise; the health query alone would allow one. */
    #[Group('query')]
    public function testTheHubWalkHasAFiveSecondBudget(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // The first reading sets the walk's deadline; every later one is three seconds on.
            $clock = static function (): int {
                static $calls = 0;
                return $calls++ === 0 ? 0 : 3_000_000_000;
            };
            $default = (new DashboardService($pdo, clock: $clock))->dashboard($root);
            assertSame(false, $default['hubs_truncated']);
            assertSame([], $default['hubs_truncation_reasons']);

            $short = static function (): int {
                static $calls = 0;
                return $calls++ === 0 ? 0 : 3_000_000_000;
            };
            $tight = (new DashboardService($pdo, clock: $short, healthTimeoutMs: 1000))->dashboard($root);
            assertSame(true, $tight['hubs_truncated']);
            assertSame(['time_limit'], $tight['hubs_truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
