<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\BoundaryLabels;
use Knossos\Query\BoundaryMatrix;
use Knossos\Query\DashboardService;
use Knossos\Query\PolicyScope;
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
            assertSame([], $d['freshness']['drifted']);
            assertSame(false, $d['freshness']['drifted_truncated']);
            assertGreaterThanOrEqual(0, $d['freshness']['age_seconds']);
            assertNotSame([], $d['hubs']);
            // Each hub says where it is declared, so the pane can open its file.
            foreach ($d['hubs'] as $hub) {
                assertSame(true, is_file($root . '/' . $hub['path']), (string) $hub['path']);
                assertSame(true, is_int($hub['line']) && $hub['line'] >= 1);
            }
            assertNotSame([], $d['fan_in']);
            assertSame($d['snapshot_id'], $d['trend'][count($d['trend']) - 1]['snapshot_id']);
            assertLessThanOrEqual(10, count($d['cycles']['largest']));
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
            assertSame(['state' => 'unscanned', 'age_seconds' => null, 'drift_files' => 0, 'drifted' => [], 'drifted_truncated' => false], $d['freshness']);
            assertSame([], $d['hubs']);
            assertSame([], $d['hotspots']);
            assertSame(0, $d['dead_code_candidates']);
            assertSame(['count' => 0, 'truncated' => false, 'truncation_reasons' => [], 'largest' => []], $d['cycles']);
            assertSame(false, $d['dead_code_truncated']);
            assertSame(false, $d['fan_in_truncated']);
            assertSame(false, $d['hubs_truncated']);
            assertSame([], $d['trend']);
            assertSame([], $d['fan_in']);
            assertSame([], $d['dead_code']);
            assertSame(0, $d['summary']['components']);
            assertSame(['items' => [], 'truncated' => false, 'declared' => [], 'declared_truncated' => false], $d['boundaries']);
            assertSame(0, $d['diagnostics']['total']);
            assertSame([], $d['largest_files']);
            assertSame('not_evaluated', $d['policy']['status']);
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
            assertSame([3, 2, 2, 2], array_column($d['cycles']['largest'], 'size'));
            $members = $d['cycles']['largest'][0]['members'];
            sort($members);
            assertSame(['Zz0hop', 'Zz1hop', 'Zz2hop'], $members);
            // Each member also comes as a node the pane colours by its boundary.
            $nodes = $d['cycles']['largest'][0]['nodes'];
            assertSame(['name', 'canonical_name', 'kind', 'boundary'], array_keys($nodes[0]));
            $canonical = array_column($nodes, 'canonical_name');
            sort($canonical);
            assertSame(['App\\Zz0::Zz0hop', 'App\\Zz1::Zz1hop', 'App\\Zz2::Zz2hop'], $canonical);
            assertSame(['Edge'], array_values(array_unique(array_column($nodes, 'boundary'))));
            assertSame(false, $d['cycles']['largest'][0]['nodes_truncated']);
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
                ['name', 'canonical_name', 'kind', 'boundary', 'in_degree', 'out_degree', 'cross_boundary_degree', 'path', 'line'],
                array_keys($d['hubs'][0]),
            );
            // The pane shows the display name and looks the component up by the canonical one.
            assertSame($health['hubs'][0]['component']['canonical_name'], $d['hubs'][0]['canonical_name']);
            assertSame($health['static_hotspots'][0]['component']['canonical_name'], $d['hotspots'][0]['canonical_name']);
            assertSame($health['hubs'][0]['metrics']['in_degree'], $d['hubs'][0]['in_degree']);
            assertSame($health['hubs'][0]['metrics']['out_degree'], $d['hubs'][0]['out_degree']);
            assertSame($health['hubs'][0]['metrics']['cross_boundary_degree'], $d['hubs'][0]['cross_boundary_degree']);
            assertSame(
                ['name', 'canonical_name', 'kind', 'boundary', 'in_degree', 'out_degree', 'cross_boundary_degree', 'path', 'line', 'score'],
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
            // Named by path, with how each drifted and the file's own boundary (none for a file the graph lacks).
            assertSame(
                [['src/Edge/Added.php', 'added'], ['src/Edge/Caller.php', 'changed'], ['tests/GreeterTest.php', 'deleted']],
                array_map(static fn(array $f): array => [$f['path'], $f['change']], $stale['drifted']),
            );
            assertSame(null, $stale['drifted'][0]['boundary']);
            assertSame(false, $stale['drifted_truncated']);
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

    /** A cycle longer than the pane lists keeps every name in `members` and says its nodes were cut. */
    #[Group('query')]
    public function testACycleLongerThanFortyListsFortyNodes(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->writeCycle($root, 'Aa', 42);
            $this->rescan($pdo, $root);
            $cycle = (new DashboardService($pdo))->dashboard($root)['cycles']['largest'][0];
            assertSame(42, $cycle['size']);
            assertCount(42, $cycle['members']);
            assertCount(40, $cycle['nodes']);
            assertSame(true, $cycle['nodes_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The summary matches the graph: components by kind without external symbols, files by language. */
    #[Group('query')]
    public function testTheSummaryCountsComponentsByKindAndFilesByLanguage(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $summary = (new DashboardService($pdo))->dashboard($root)['summary'];
            $count = static fn(string $sql): int => (int) $pdo->query(str_replace(':p', $pdo->quote($projectId), $sql))->fetchColumn();
            assertSame($count("SELECT COUNT(*) FROM nodes WHERE project_id = :p AND kind NOT LIKE 'external%'"), $summary['components']);
            assertSame($count('SELECT COUNT(*) FROM files WHERE project_id = :p'), $summary['files']);
            assertSame($summary['components'], array_sum(array_column($summary['kinds'], 'count')));
            assertSame($summary['files'], array_sum(array_column($summary['languages'], 'files')));
            // The most common first, a tie by name: the fixture has as many classes as methods.
            $classes = $count("SELECT COUNT(*) FROM nodes WHERE project_id = :p AND kind = 'class'");
            assertSame(['kind' => 'class', 'count' => $classes], $summary['kinds'][0]);
            assertSame($classes, $count("SELECT COUNT(*) FROM nodes WHERE project_id = :p AND kind = 'method'"));
            $counts = array_column($summary['kinds'], 'count');
            $sorted = $counts;
            rsort($sorted);
            assertSame($sorted, $counts);
            assertSame(false, $summary['kinds_truncated']);
            assertSame(false, $summary['languages_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Nine kinds or languages list eight and say the breakdown no longer adds up. */
    #[Group('query')]
    public function testASummaryWithMoreThanEightCategoriesIsReportedTruncated(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $node = (string) $pdo->query("SELECT id FROM nodes WHERE project_id = '{$projectId}' AND kind = 'class' LIMIT 1")->fetchColumn();
            $file = (string) $pdo->query("SELECT id FROM files WHERE project_id = '{$projectId}' LIMIT 1")->fetchColumn();
            for ($i = 0; $i < 9; ++$i) {
                $this->cloneRow($pdo, 'nodes', $node, ['id' => "node-k{$i}", 'kind' => "kind{$i}", 'canonical_name' => "K{$i}"]);
                $this->cloneRow($pdo, 'files', $file, ['id' => "file-l{$i}", 'language' => "lang{$i}", 'relative_path' => "extra/{$i}.x"]);
            }
            $summary = (new DashboardService($pdo))->dashboard($root)['summary'];
            assertCount(8, $summary['kinds']);
            assertSame(true, $summary['kinds_truncated']);
            assertCount(8, $summary['languages']);
            assertSame(true, $summary['languages_truncated']);
            assertGreaterThan(array_sum(array_column($summary['kinds'], 'count')), $summary['components']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The declared boundaries come first, each with how many components it holds. */
    #[Group('query')]
    public function testBoundariesAreListedWithTheirMemberCounts(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $boundaries = (new DashboardService($pdo))->dashboard($root)['boundaries'];
            assertSame(false, $boundaries['truncated']);
            assertSame(['Core', 'Edge'], array_column(array_slice($boundaries['items'], 0, 2), 'name'));
            assertSame(['explicit', 'explicit'], array_column(array_slice($boundaries['items'], 0, 2), 'source'));
            $members = $pdo->prepare('SELECT COUNT(*) FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id WHERE b.project_id = ? AND b.name = ?');
            foreach (array_slice($boundaries['items'], 0, 2) as $boundary) {
                $members->execute([$projectId, $boundary['name']]);
                assertSame((int) $members->fetchColumn(), $boundary['members']);
                assertGreaterThan(0, $boundary['members']);
            }
            // Larger first among the declared ones.
            assertGreaterThanOrEqual($boundaries['items'][1]['members'], $boundaries['items'][0]['members']);
            $listed = BoundaryLabels::load($pdo, $projectId)->listed(1);
            assertCount(1, $listed['items']);
            assertSame(true, $listed['truncated']);
            // Every declared boundary is named apart from the short list, so one past its cap is still known as declared.
            assertSame(['Core', 'Edge'], $boundaries['declared']);
            assertSame(false, $boundaries['declared_truncated']);
            assertSame(['items' => [$boundaries['items'][0]], 'truncated' => true, 'declared' => ['Core'], 'declared_truncated' => true], BoundaryLabels::load($pdo, $projectId)->listed(1, 1));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Diagnostics are counted by severity; the first errors, then warnings, are listed with file and line. */
    #[Group('query')]
    public function testDiagnosticsAreCountedAndTheFirstErrorsListed(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $clean = (new DashboardService($pdo))->dashboard($root)['diagnostics'];
            assertSame(['total' => 0, 'errors' => 0, 'warnings' => 0, 'infos' => 0, 'items' => []], $clean);
            $scan = (string) $pdo->query("SELECT active_scan_id FROM projects WHERE id = '{$projectId}'")->fetchColumn();
            $file = (string) $pdo->query("SELECT id FROM files WHERE project_id = '{$projectId}' AND relative_path = 'src/Core/Greeter.php'")->fetchColumn();
            $insert = $pdo->prepare('INSERT INTO diagnostics(id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $rows = [['info', 1], ['warning', 9], ['error', 7], ['warning', 2], ['error', 3], ['error', 5], ['info', 4]];
            foreach ($rows as $i => [$severity, $line]) {
                $insert->execute(["diag-{$i}", $projectId, $scan, $file, $severity, "C{$i}", str_repeat('m', 200), $line, $line, 'test']);
            }
            $d = (new DashboardService($pdo))->dashboard($root)['diagnostics'];
            assertSame(7, $d['total']);
            assertSame(3, $d['errors']);
            assertSame(2, $d['warnings']);
            assertSame(2, $d['infos']);
            assertCount(5, $d['items']);
            assertSame(['error', 'error', 'error', 'warning', 'warning'], array_column($d['items'], 'severity'));
            assertSame([3, 5, 7, 2, 9], array_column($d['items'], 'line'));
            assertSame('src/Core/Greeter.php', $d['items'][0]['path']);
            assertSame(['severity', 'code', 'message', 'path', 'line'], array_keys($d['items'][0]));
            // One line of message is all the pane shows.
            assertSame(160, strlen($d['items'][0]['message']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheLargestFilesComeFirst(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/src/Edge/Long.php', "<?php\n" . str_repeat("// line\n", 300));
            $this->rescan($pdo, $root);
            $files = (new DashboardService($pdo))->dashboard($root)['largest_files'];
            assertLessThanOrEqual(5, count($files));
            assertSame(['path' => 'src/Edge/Long.php', 'language' => 'php', 'lines' => 301], $files[0]);
            $lines = array_column($files, 'lines');
            $sorted = $lines;
            rsort($sorted);
            assertSame($sorted, $lines);
            $total = (int) $pdo->query("SELECT COUNT(*) FROM files WHERE project_id = '{$projectId}'")->fetchColumn();
            assertCount(min(5, $total), $files);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The first dead-code candidates are listed with their file, line and boundary. */
    #[Group('query')]
    public function testDeadCodeCandidatesAreListedWithWhereTheyAreDeclared(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            file_put_contents($root . '/src/Edge/Unused.php', "<?php\nnamespace App;\nfinal class Unused { public function never(): int { return 1; } }\n");
            $this->rescan($pdo, $root);
            $d = (new DashboardService($pdo))->dashboard($root);
            assertGreaterThan(0, count($d['dead_code']));
            assertLessThanOrEqual(10, count($d['dead_code']));
            assertLessThanOrEqual($d['dead_code_candidates'], count($d['dead_code']));
            assertSame(
                ['name', 'canonical_name', 'kind', 'boundary', 'reachability', 'confidence', 'path', 'line'],
                array_keys($d['dead_code'][0]),
            );
            $health = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 10)->data;
            assertSame(
                array_map(static fn(array $c): string => $c['component']['canonical_name'], $health['dead_code_candidates']),
                array_column($d['dead_code'], 'canonical_name'),
            );
            $never = array_values(array_filter($d['dead_code'], static fn(array $c): bool => $c['canonical_name'] === 'App\\Unused::never'))[0] ?? null;
            assertNotSame(null, $never);
            assertSame('src/Edge/Unused.php', $never['path']);
            assertSame(3, $never['line']);
            assertSame('Edge', $never['boundary']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A project without declared policies has nothing to evaluate. */
    #[Group('query')]
    public function testAProjectWithoutPoliciesIsNotEvaluated(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $policy = (new DashboardService($pdo))->dashboard($root)['policy'];
            assertSame(['status' => 'not_evaluated', 'total' => 0, 'truncated' => false, 'truncation_reasons' => [], 'items' => [], 'rules' => [], 'boundaries' => [], 'files' => [], 'files_truncated' => false], $policy);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Violations of the project's declared policies are counted and the first listed with their place. */
    #[Group('query')]
    public function testPolicyViolationsAreCountedAndListedWithTheirPlace(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->denyEdgeToCore($root);
            $this->rescan($pdo, $root);
            $policy = (new DashboardService($pdo))->dashboard($root)['policy'];
            assertSame('evaluated', $policy['status']);
            assertGreaterThanOrEqual(1, $policy['total']);
            assertSame(false, $policy['truncated']);
            assertSame([], $policy['truncation_reasons']);
            assertSame(min(5, $policy['total']), count($policy['items']));
            $first = $policy['items'][0];
            assertSame(
                ['policy_id', 'source', 'source_kind', 'source_boundary', 'target', 'target_kind', 'target_boundary', 'path', 'line'],
                array_keys($first),
            );
            assertSame('edge-stays-out-of-core', $first['policy_id']);
            assertSame('Edge', $first['source_boundary']);
            assertSame('Core', $first['target_boundary']);
            assertSame('src/Edge/Caller.php', $first['path']);
            assertIsInt($first['line']);
            // The rule itself, and the boundary it binds: Edge places its files by prefix, so none is listed.
            assertSame([['id' => 'edge-stays-out-of-core', 'from' => 'Edge', 'deny' => ['Core'], 'allow' => [], 'edge_kinds' => []]], $policy['rules']);
            assertSame(['Edge' => ['rules' => ['edge-stays-out-of-core'], 'path_prefixes' => ['src/Edge/'], 'listed' => false]], $policy['boundaries']);
            assertSame([], $policy['files']);
            assertSame(false, $policy['files_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Allow lists, edge kinds and `@unassigned` reach the rules as declared;
     * a boundary named by its id reads as its name; the file cap says when it cut.
     */
    #[Group('query')]
    public function testPolicyRulesNameTheirBoundariesAndCapTheBoundFiles(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $labels = BoundaryLabels::load($pdo, $projectId);
            $coreId = (string) $pdo->query("SELECT id FROM boundaries WHERE name = 'Core'")->fetchColumn();
            $policies = [
                ['id' => 'edge-only-core', 'from_boundary' => 'Edge', 'allow_targets' => [$coreId, '@unassigned'], 'edge_kinds' => ['calls']],
                ['id' => 'core-alone', 'from_boundary' => $coreId, 'deny_targets' => ['Edge']],
            ];
            $scope = (new PolicyScope($pdo))->build($projectId, $policies, $labels);
            assertSame(
                [
                    ['id' => 'edge-only-core', 'from' => 'Edge', 'deny' => [], 'allow' => ['Core', '@unassigned'], 'edge_kinds' => ['calls']],
                    ['id' => 'core-alone', 'from' => 'Core', 'deny' => ['Edge'], 'allow' => [], 'edge_kinds' => []],
                ],
                $scope['rules'],
            );
            assertSame(
                [
                    'Core' => ['rules' => ['core-alone'], 'path_prefixes' => ['src/Core/'], 'listed' => false],
                    'Edge' => ['rules' => ['edge-only-core'], 'path_prefixes' => ['src/Edge/'], 'listed' => false],
                ],
                $scope['boundaries'],
            );
            assertSame([], $scope['files']);
            assertSame(false, $scope['files_truncated']);
            assertSame(2000, PolicyScope::FILE_CAP);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A rule binds the files of the boundary its policy names, by id: an
     * inferred boundary that shares a declared one's name binds nothing.
     */
    #[Group('query')]
    public function testARuleBindsItsOwnBoundaryNotOneSharingItsName(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $edgeId = (string) $pdo->query("SELECT id FROM boundaries WHERE name = 'Edge'")->fetchColumn();
            $coreId = (string) $pdo->query("SELECT id FROM boundaries WHERE name = 'Core'")->fetchColumn();
            // An inferred twin of Edge that holds Core's members.
            $twin = $pdo->prepare(
                "INSERT INTO boundaries (id, project_id, name, matcher_json, source, last_scan_id) "
                . "SELECT 'inferred-edge', project_id, 'Edge', :matcher, 'inferred', last_scan_id FROM boundaries WHERE id = :core",
            );
            $twin->execute(['matcher' => json_encode(['type' => 'namespace_prefix', 'value' => 'App\\']), 'core' => $coreId]);
            $members = $pdo->prepare(
                "INSERT INTO boundary_memberships (boundary_id, project_id, node_id, last_scan_id) "
                . "SELECT 'inferred-edge', project_id, node_id, last_scan_id FROM boundary_memberships WHERE boundary_id = :core",
            );
            $members->execute(['core' => $coreId]);
            $labels = BoundaryLabels::load($pdo, $projectId);
            $policies = [['id' => 'edge-stays-out-of-core', 'from_boundary' => $edgeId, 'deny_targets' => [$coreId]]];
            $scope = (new PolicyScope($pdo))->build($projectId, $policies, $labels);
            assertSame(['Edge' => ['rules' => ['edge-stays-out-of-core'], 'path_prefixes' => ['src/Edge/'], 'listed' => false]], $scope['boundaries']);
            assertSame([], $scope['files']);
            // The twin itself, named by its id, places its members by namespace: its files are listed, up to the cap.
            $twin = [['id' => 'twin-alone', 'from_boundary' => 'inferred-edge', 'deny_targets' => [$edgeId]]];
            $listed = (new PolicyScope($pdo))->build($projectId, $twin, $labels);
            assertSame(['Edge' => ['rules' => ['twin-alone'], 'path_prefixes' => [], 'listed' => true]], $listed['boundaries']);
            assertSame(['src/Core/Greeter.php' => ['Edge']], $listed['files']);
            assertSame(false, $listed['files_truncated']);
            $capped = (new PolicyScope($pdo, 0))->build($projectId, $twin, $labels);
            assertSame([], $capped['files']);
            assertSame(true, $capped['files_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A policy check that runs out of time says its total is a floor. */
    #[Group('query')]
    public function testAPolicyCheckOutOfTimeIsReportedTruncated(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->denyEdgeToCore($root);
            $this->rescan($pdo, $root);
            $ticks = 0;
            $clock = static function () use (&$ticks): int {
                return $ticks += 10_000_000_000;
            };
            $policy = (new DashboardService($pdo, clock: $clock))->dashboard($root)['policy'];
            assertSame('evaluated', $policy['status']);
            assertSame(true, $policy['truncated']);
            assertSame(['time_limit'], $policy['truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Each dependency edge lands in one cell: row the source's boundary, column the target's. */
    #[Group('query')]
    public function testTheBoundaryMatrixCountsDependenciesBetweenBoundaries(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $d = (new DashboardService($pdo))->dashboard($root);
            $matrix = $d['boundary_matrix'];
            // The declared boundaries label every component the fixture has.
            assertSame(['Core', 'Edge'], array_slice($matrix['boundaries'], 0, 2));
            assertSame(false, $matrix['boundaries_truncated']);
            $at = array_flip($matrix['boundaries']);
            $labelled = array_count_values(BoundaryLabels::load($pdo, $projectId)->forProject($projectId));
            assertSame($labelled['Core'], $matrix['members'][$at['Core']]);
            assertSame(array_sum($labelled), array_sum($matrix['members']));
            assertGreaterThan(0, $matrix['cells'][$at['Edge']][$at['Core']]);
            assertSame(0, $matrix['cells'][$at['Core']][$at['Edge']]);
            assertSame(array_sum(array_map('array_sum', $matrix['cells'])), $matrix['edges']);
            assertCount(count($matrix['boundaries']), $matrix['cells']);
            assertSame([], $matrix['forbidden']);
            assertSame(false, $matrix['truncated']);
            assertSame([], $matrix['truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A denied target is one forbidden cell; an allow list forbids every other boundary but its own. */
    #[Group('query')]
    public function testTheBoundaryMatrixMarksTheCellsAPolicyForbids(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->denyEdgeToCore($root);
            $this->rescan($pdo, $root);
            $matrix = (new DashboardService($pdo))->dashboard($root)['boundary_matrix'];
            $at = array_flip($matrix['boundaries']);
            assertSame([[$at['Edge'], $at['Core']]], $matrix['forbidden']);

            $config = json_decode((string) file_get_contents($root . '/knossos.json'), true, flags: JSON_THROW_ON_ERROR);
            $config['policies'] = [['id' => 'core-keeps-to-itself', 'from_boundary' => 'Core', 'allow_targets' => ['Core']]];
            file_put_contents($root . '/knossos.json', json_encode($config, JSON_THROW_ON_ERROR));
            $matrix = (new DashboardService($pdo))->dashboard($root)['boundary_matrix'];
            $at = array_flip($matrix['boundaries']);
            $expected = [];
            foreach ($matrix['boundaries'] as $column => $name) {
                if ($name !== 'Core') {
                    $expected[] = [$at['Core'], $column];
                }
            }
            assertSame($expected, $matrix['forbidden']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The count stops at its edge budget and its time limit, and says which. */
    #[Group('query')]
    public function testTheBoundaryMatrixReportsTheLimitThatStoppedIt(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $labels = BoundaryLabels::load($pdo, $projectId);
            $byEdges = (new BoundaryMatrix($pdo, maxEdges: 1))->build($projectId, $labels, []);
            assertSame(true, $byEdges['truncated']);
            assertSame(['edge_limit'], $byEdges['truncation_reasons']);
            assertLessThanOrEqual(1, $byEdges['edges']);

            $ticks = 0;
            $clock = static function () use (&$ticks): int {
                return $ticks += 10_000_000_000;
            };
            $byTime = (new BoundaryMatrix($pdo, $clock))->build($projectId, $labels, []);
            // One axis only: the rest are counted past it.
            $one = (new BoundaryMatrix($pdo))->build($projectId, $labels, [], 1);
            assertSame(['Core'], $one['boundaries']);
            assertSame(true, $one['boundaries_truncated']);
            assertSame(true, $byTime['truncated']);
            assertSame(['time_limit'], $byTime['truncation_reasons']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Declares a policy the fixture breaks: Edge's Caller calls Core's Greeter. */
    private function denyEdgeToCore(string $root): void
    {
        $config = json_decode((string) file_get_contents($root . '/knossos.json'), true, flags: JSON_THROW_ON_ERROR);
        $config['policies'] = [['id' => 'edge-stays-out-of-core', 'from_boundary' => 'Edge', 'deny_targets' => ['Core']]];
        file_put_contents($root . '/knossos.json', json_encode($config, JSON_THROW_ON_ERROR));
    }

    /**
     * Copies one row of `$table` with some columns changed: a way to give the
     * graph more kinds or languages than a fixture scan produces.
     *
     * @param array<string, string> $overrides
     */
    private function cloneRow(PDO $pdo, string $table, string $id, array $overrides): void
    {
        $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $select = array_map(static fn(string $c): string => array_key_exists($c, $overrides) ? $pdo->quote($overrides[$c]) : $c, $columns);
        $pdo->exec(sprintf('INSERT INTO %s (%s) SELECT %s FROM %s WHERE id = %s', $table, implode(', ', $columns), implode(', ', $select), $table, $pdo->quote($id)));
    }
}
