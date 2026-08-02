<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The gate refuses to pass a boundary budget it could not fully evaluate, which
 * is the right call — a truncated scan only ever reports a lower bound. But it
 * asked for the scan with the default 20,000-edge ceiling, so on any graph
 * larger than that the budget became unpassable no matter what the code did,
 * and `quality_gate` exposes no way to raise the bound.
 */
final class QualityGateBoundaryScanTest extends KnossosTestCase
{
    #[Group('query')]
    public function testABoundaryBudgetIsStillDeterminateOnAGraphAboveTheDefaultEdgeCeiling(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
        // One edge past the 20,000 checkArchitecture defaults to.
        $edges = [];
        for ($i = 0; $i < 20_001; $i++) {
            $edges[] = [
                'id' => StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'bulk:' . $i),
                'kind' => 'calls', 'source_id' => $ids['checkout'], 'target_id' => $ids['invoice'],
                'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast',
                'confidence' => 'certain', 'attributes' => [], 'owner_key' => 'php:file:src/Checkout.php',
            ];
        }
        $repository->bulkTransaction(static function ($repository) use ($edges, $ids, $next): void {
            $repository->saveEdges($edges, $ids['project'], $next);
        });
        $repository->completeScan($ids['project'], $next);
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['type' => 'path_prefix', 'value' => 'src/Checkout'], 'explicit', $next);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $next);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['invoice'], $next);

        unset($edges);
        gc_collect_cycles();
        $queries = new ArchitectureQueryService($pdo);
        $policies = [['id' => 'backend-allows-billing', 'from_boundary' => $backend, 'allow_targets' => [$backend]]];

        // The checker must not hold the edge set in memory. Materialising it
        // exhausted a 128 MB limit at the bound the gate asks for, so raising
        // that bound would have traded an unpassable budget for a crash.
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $queries->checkArchitecture($ids['project'], $policies, maxEdges: 100_000);
        $used = memory_get_peak_usage() - $before;
        $this->assertLessThan(8 * 1024 * 1024, $used, sprintf('Policy check retained %d bytes for 20001 edges.', $used));

        $check = $queries->qualityGate($ids['project'], $ids['scan'], ['boundary_violations' => 0], $policies)->data['checks'][0];
        assertSame('boundary_violations', $check['metric']);
        assertSame(false, isset($check['indeterminate']));
        assertSame(true, $check['passed']);
    }
}
