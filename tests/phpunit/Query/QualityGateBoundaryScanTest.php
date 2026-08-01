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

        $gate = (new ArchitectureQueryService($pdo))->qualityGate(
            $ids['project'],
            $ids['scan'],
            ['boundary_violations' => 0],
            [['id' => 'backend-allows-billing', 'from_boundary' => $backend, 'allow_targets' => [$backend]]],
        );

        $check = $gate->data['checks'][0];
        assertSame('boundary_violations', $check['metric']);
        assertSame(false, isset($check['indeterminate']));
        assertSame(true, $check['passed']);
    }
}
