<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\BoundaryCouplingsService;
use Knossos\Query\BoundaryLabels;
use Knossos\Query\BoundaryMatrix;
use Knossos\Query\DashboardService;
use Knossos\Tests\Phpunit\KnossosTestCase;
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
            $whole = (new BoundaryMatrix($pdo))->couplings($projectId, $labels, 'Edge', 'Core', 1);
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
