<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class HealthTest extends KnossosTestCase
{
    #[Group('health')]
    public function testArchitectureHealthRanksStructuralSignalsAndLabelsDeadCodeUncertainty(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphan = StableId::symbol($ids['project'], 'php', 'class', 'App\\OrphanService');
        $model = StableId::symbol($ids['project'], 'php', 'class', 'App\\Order');
        foreach ([[$orphan, 'App\\OrphanService', 'OrphanService'], [$model, 'App\\Order', 'Order']] as [$id, $canonical, $display]) {
            $repository->saveNode(
                $id,
                $ids['project'],
                'class',
                $canonical,
                $display,
                null,
                $ids['file'],
                40,
                50,
                'ast',
                'certain',
                [],
                'php:file:src/' . $display . '.php',
                $ids['scan'],
            );
        }
        $repository->saveClassification(
            StableId::classification($ids['project'], $model, 'laravel.model', 'laravel.roles.v1'),
            $ids['project'],
            $model,
            'laravel.model',
            'framework_convention',
            'certain',
            'laravel.roles.v1',
            $ids['file'],
            40,
            50,
            [],
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'reverse'),
            $ids['project'],
            'calls',
            $ids['invoice'],
            $ids['checkout'],
            $ids['file'],
            30,
            30,
            'ast',
            'certain',
            [],
            'php:file:src/InvoiceService.php',
            $ids['scan'],
        );
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $health = $query->architectureHealth($ids['project']);
        assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column(array_column($health->data['hubs'], 'component'), 'canonical_name'));
        assertSame(2, $health->data['hubs'][0]['score']);
        assertSame(2, $health->data['hubs'][0]['metrics']['cross_boundary_degree']);
        assertSame(9, $health->data['static_hotspots'][0]['score']);
        assertSame(true, $health->data['static_hotspots'][0]['factors']['cycle_participant']);
        assertSame(['App\\Order', 'App\\OrphanService'], array_column(array_column($health->data['dead_code_candidates'], 'component'), 'canonical_name'));
        assertSame('possible', $health->data['dead_code_candidates'][0]['confidence']);
        assertSame('probable', $health->data['dead_code_candidates'][1]['confidence']);
        assertContains('candidates only', $health->warnings[1]);
        assertSame(4, count($health->evidence));

        $filtered = $query->architectureHealth($ids['project'], edgeKinds: ['imports']);
        assertSame([], $filtered->data['hubs']);
        $limited = $query->architectureHealth($ids['project'], limit: 1);
        assertSame(true, $limited->truncated);
        assertSame(true, in_array('result_limit', $limited->data['bounds']['truncation_reasons'], true));
        $nodeLimited = $query->architectureHealth($ids['project'], maxNodes: 1);
        assertSame(true, $nodeLimited->truncated);
        assertSame(true, in_array('node_limit', $nodeLimited->data['bounds']['truncation_reasons'], true));
        $edgeLimited = $query->architectureHealth($ids['project'], maxEdges: 1);
        assertSame(true, $edgeLimited->truncated);
        assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
        assertThrows(fn() => $query->architectureHealth($ids['project'], edgeKinds: ['contains']), InvalidArgumentException::class);

        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->architectureHealth($ids['project'], timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
    }
}
