<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\BoundaryCouplingsService;
use Knossos\Query\BoundaryLabels;
use Knossos\Query\BoundaryMatrix;
use Knossos\Query\DashboardService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertGreaterThan;
use function PHPUnit\Framework\assertGreaterThanOrEqual;
use function PHPUnit\Framework\assertLessThanOrEqual;
use function PHPUnit\Framework\assertSame;

/**
 * One cell of the boundary heat map spelled out: the component pairs behind
 * it, most edges first, counted the way the matrix counts the cell.
 */
final class BoundaryCouplingsServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /** The cell's figure is the matrix's, and each pair runs from a component of one boundary to one of the other. */
    #[Group('query')]
    public function testACellListsItsComponentPairsMostEdgesFirst(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $matrix = (new DashboardService($pdo))->dashboard($root)['boundary_matrix'];
            $at = array_flip($matrix['boundaries']);
            $out = (new BoundaryCouplingsService($pdo))->couplings($root, 'Edge', 'Core');
            assertSame(['ok', $projectId, 'Edge', 'Core'], [$out['status'], $out['project_id'], $out['from'], $out['to']]);
            assertSame($matrix['cells'][$at['Edge']][$at['Core']], $out['edges']);
            assertGreaterThan(0, count($out['couplings']));
            assertLessThanOrEqual(BoundaryCouplingsService::LIMIT, count($out['couplings']));
            $labelled = BoundaryLabels::load($pdo, $projectId)->forProject($projectId);
            $ids = $pdo->prepare('SELECT id FROM nodes WHERE project_id = ? AND canonical_name = ?');
            $edges = $pdo->prepare('SELECT COUNT(*) FROM edges WHERE source_id = ? AND target_id = ? AND kind IN (' . implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?')) . ')');
            $previous = PHP_INT_MAX;
            $sum = 0;
            foreach ($out['couplings'] as $pair) {
                $ids->execute([$projectId, $pair['source']['canonical_name']]);
                $source = (string) $ids->fetchColumn();
                $ids->execute([$projectId, $pair['target']['canonical_name']]);
                $target = (string) $ids->fetchColumn();
                assertSame(['Edge', 'Core'], [$labelled[$source] ?? null, $labelled[$target] ?? null]);
                $edges->execute([$source, $target, ...AbstractArchitectureQueryService::IMPACT_EDGE_KINDS]);
                assertSame((int) $edges->fetchColumn(), $pair['edges']);
                assertLessThanOrEqual($previous, $pair['edges']);
                $previous = $pair['edges'];
                $sum += $pair['edges'];
            }
            assertLessThanOrEqual($out['edges'], $sum);
            assertSame([false, []], [$out['truncated'], $out['truncation_reasons']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A cell joining more components than one SQLite statement may bind still names them all. */
    #[Group('query')]
    public function testACellOfMoreComponentsThanOneStatementBindsIsListed(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // Copies of an Edge component, each depending on a Core one: more ids than SQLite binds in one statement
            // (32,766 by default, 250,000 as some distributions build it).
            $edge = $pdo->query("SELECT e.* FROM edges e JOIN boundary_memberships s ON s.node_id = e.source_id JOIN boundaries bs ON bs.id = s.boundary_id AND bs.name = 'Edge' JOIN boundary_memberships t ON t.node_id = e.target_id JOIN boundaries bt ON bt.id = t.boundary_id AND bt.name = 'Core' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            $copies = 251_000;
            $pdo->beginTransaction();
            $pdo->exec('CREATE TEMP TABLE n(i INTEGER PRIMARY KEY)');
            $pdo->exec("WITH RECURSIVE c(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM c WHERE i < {$copies}) INSERT INTO n SELECT i FROM c");
            $source = $pdo->quote((string) $edge['source_id']);
            $copy = static function (string $table, array $set, string $where) use ($pdo): void {
                $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
                $values = array_map(static fn(string $column): string => $set[$column] ?? $table . '.' . $column, $columns);
                $pdo->exec(sprintf('INSERT INTO %s(%s) SELECT %s FROM %s, n WHERE %s', $table, implode(', ', $columns), implode(', ', $values), $table, $where));
            };
            $copy('nodes', ['id' => "'copy-' || n.i", 'canonical_name' => "nodes.canonical_name || '#' || n.i", 'parent_id' => 'NULL'], "nodes.id = {$source}");
            $copy('boundary_memberships', ['node_id' => "'copy-' || n.i"], "boundary_memberships.node_id = {$source}");
            $copy('edges', ['id' => "'copy-edge-' || n.i", 'source_id' => "'copy-' || n.i"], 'edges.id = ' . $pdo->quote((string) $edge['id']));
            $pdo->commit();
            $out = (new BoundaryMatrix($pdo, maxEdges: 300_000, timeoutMs: 60_000))->couplings($projectId, BoundaryLabels::load($pdo, $projectId), 'Edge', 'Core', 3);
            assertGreaterThan($copies, $out['edges']);
            assertCount(3, $out['couplings']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Fills the Edge to Core cell of a scanned fixture with `$pairs` extra component pairs, each its own two
     * components (so the cell holds that many distinct pairs) and one edge each, named `edge-<i>` and `core-<i>`
     * so the canonical names sort as text, not as numbers.
     */
    private function densify(PDO $pdo, string $projectId, int $pairs): void
    {
        $edge = $pdo->query("SELECT e.* FROM edges e JOIN boundary_memberships s ON s.node_id = e.source_id JOIN boundaries bs ON bs.id = s.boundary_id AND bs.name = 'Edge' JOIN boundary_memberships t ON t.node_id = e.target_id JOIN boundaries bt ON bt.id = t.boundary_id AND bt.name = 'Core' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        $pdo->beginTransaction();
        $pdo->exec('CREATE TEMP TABLE n(i INTEGER PRIMARY KEY)');
        $pdo->exec("WITH RECURSIVE c(i) AS (SELECT 1 UNION ALL SELECT i + 1 FROM c WHERE i < {$pairs}) INSERT INTO n SELECT i FROM c");
        $copy = static function (string $table, array $set, string $where) use ($pdo): void {
            $columns = array_column($pdo->query("PRAGMA table_info({$table})")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $values = array_map(static fn(string $column): string => $set[$column] ?? $table . '.' . $column, $columns);
            $pdo->exec(sprintf('INSERT INTO %s(%s) SELECT %s FROM %s, n WHERE %s', $table, implode(', ', $columns), implode(', ', $values), $table, $where));
        };
        foreach (['source' => 'edge', 'target' => 'core'] as $end => $prefix) {
            $id = $pdo->quote((string) $edge[$end . '_id']);
            $copy('nodes', ['id' => "'{$prefix}-' || n.i", 'canonical_name' => "'{$prefix}-' || n.i", 'display_name' => "'{$prefix}-' || n.i", 'parent_id' => 'NULL'], "nodes.id = {$id}");
            $copy('boundary_memberships', ['node_id' => "'{$prefix}-' || n.i"], "boundary_memberships.node_id = {$id}");
        }
        $copy('edges', ['id' => "'dense-' || n.i", 'source_id' => "'edge-' || n.i", 'target_id' => "'core-' || n.i"], 'edges.id = ' . $pdo->quote((string) $edge['id']));
        $pdo->commit();
    }

    /** A cell of many distinct pairs, as the default edge cap allows, lists its strongest without holding every pair's components. */
    #[Group('query')]
    public function testADenseCellIsListedWithinTheMemoryOfItsResult(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->densify($pdo, $projectId, 49_000);
            $labels = BoundaryLabels::load($pdo, $projectId);
            $matrix = new BoundaryMatrix($pdo);
            gc_collect_cycles();
            $before = memory_get_usage();
            memory_reset_peak_usage();
            $out = $matrix->couplings($projectId, $labels, 'Edge', 'Core');
            $peak = memory_get_peak_usage() - $before;
            assertGreaterThan(49_000, $out['edges']);
            assertCount(5, $out['couplings']);
            // Hydrating every component of every pair takes well over 40 MB here; the five listed take next to nothing.
            assertLessThanOrEqual(40 * 1024 * 1024, $peak);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The strongest pairs come out in the order the plain sort gives: most edges, then the two canonical names as text. */
    #[Group('query')]
    public function testTheStrongestPairsKeepTheirOrderAndTieBreak(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $this->densify($pdo, $projectId, 300);
            $labels = BoundaryLabels::load($pdo, $projectId);
            $labelled = $labels->forProject($projectId);
            $kinds = implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?'));
            $statement = $pdo->prepare("SELECT source_id, target_id, COUNT(*) FROM edges WHERE project_id = ? AND kind IN ({$kinds}) GROUP BY source_id, target_id");
            $statement->execute([$projectId, ...AbstractArchitectureQueryService::IMPACT_EDGE_KINDS]);
            $names = $pdo->query('SELECT id, display_name, canonical_name, kind FROM nodes')->fetchAll(PDO::FETCH_ASSOC | PDO::FETCH_UNIQUE);
            $expected = [];
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$source, $target, $count]) {
                if (($labelled[$source] ?? null) === 'Edge' && ($labelled[$target] ?? null) === 'Core') {
                    $node = static fn(string $id): array => ['name' => (string) ($names[$id]['display_name'] ?? $names[$id]['canonical_name']), 'canonical_name' => (string) $names[$id]['canonical_name'], 'kind' => (string) $names[$id]['kind']];
                    $expected[] = ['source' => $node($source), 'target' => $node($target), 'edges' => (int) $count];
                }
            }
            usort($expected, static fn(array $a, array $b): int => $b['edges'] <=> $a['edges']
                ?: [$a['source']['canonical_name'], $a['target']['canonical_name']] <=> [$b['source']['canonical_name'], $b['target']['canonical_name']]);
            assertGreaterThan(300, count($expected));
            $matrix = new BoundaryMatrix($pdo, maxEdges: 100_000);
            foreach ([1, 3, 7, 50, 301, 1000] as $limit) {
                $out = $matrix->couplings($projectId, $labels, 'Edge', 'Core', $limit);
                assertSame(array_slice($expected, 0, $limit), $out['couplings'], "limit {$limit}");
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** An empty cell, a name no component carries and a path no project holds answer with nothing, never an error. */
    #[Group('query')]
    public function testAnEmptyCellOrAnUnknownBoundaryListsNothing(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $service = new BoundaryCouplingsService($pdo);
            foreach ([['Core', 'Edge'], ['Nowhere', 'Core']] as [$from, $to]) {
                $out = $service->couplings($root, $from, $to);
                assertSame(['ok', 0, []], [$out['status'], $out['edges'], $out['couplings']]);
            }
            $elsewhere = sys_get_temp_dir() . '/knossos-stale-none-' . bin2hex(random_bytes(4));
            $out = $service->couplings($elsewhere, 'Edge', 'Core');
            assertSame(['unscanned', 0, []], [$out['status'], $out['edges'], $out['couplings']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The walk is the matrix's, with its bounds: a cut walk says why, and its figures are floors. */
    #[Group('query')]
    public function testACutWalkSaysWhy(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $labels = BoundaryLabels::load($pdo, $projectId);
            $whole = (new BoundaryMatrix($pdo, maxEdges: 300_000, timeoutMs: 60_000))->couplings($projectId, $labels, 'Edge', 'Core', 1);
            assertCount(1, $whole['couplings']);
            $cut = (new BoundaryCouplingsService($pdo, new BoundaryMatrix($pdo, maxEdges: 1)))->couplings($root, 'Edge', 'Core');
            assertSame([true, ['edge_limit']], [$cut['truncated'], $cut['truncation_reasons']]);
            assertLessThanOrEqual(1, $cut['edges']);
            assertGreaterThanOrEqual($cut['edges'], $whole['edges']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
