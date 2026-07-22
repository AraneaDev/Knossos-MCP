<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class CyclesTest extends KnossosTestCase
{
    #[Group('cycles')]
    public function testDependencyCyclesComputeDeterministicBoundedStronglyConnectedComponents(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $worker = StableId::symbol($ids['project'], 'php', 'class', 'App\\Worker');
        $repository->saveNode(
            $worker,
            $ids['project'],
            'php',
            'class',
            'App\\Worker',
            'Worker',
            null,
            $ids['file'],
            40,
            50,
            'ast',
            'certain',
            [],
            'php:file:src/Worker.php',
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
            'probable',
            [],
            'php:file:src/InvoiceService.php',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'depends_on', $worker, $worker, 'self'),
            $ids['project'],
            'depends_on',
            $worker,
            $worker,
            $ids['file'],
            45,
            45,
            'ast',
            'certain',
            [],
            'php:file:src/Worker.php',
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $result = $query->dependencyCycles($ids['project']);
        assertSame([2, 1], array_column($result->data['cycles'], 'size'));
        assertSame('probable', $result->data['cycles'][0]['minimum_confidence']);
        assertSame('certain', $result->data['cycles'][1]['minimum_confidence']);
        assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column($result->data['cycles'][0]['members'], 'canonical_name'));
        assertSame(2, count($result->data['cycles'][0]['relationships']));
        assertSame(3, count($result->evidence));
        assertContains('selected static dependency', $result->warnings[0]);

        $certain = $query->dependencyCycles($ids['project'], minConfidence: 'certain');
        assertSame([1], array_column($certain->data['cycles'], 'size'));
        $filtered = $query->dependencyCycles($ids['project'], edgeKinds: ['imports']);
        assertSame([], $filtered->data['cycles']);
        $limited = $query->dependencyCycles($ids['project'], limit: 1);
        assertSame(true, $limited->truncated);
        assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
        $edgeLimited = $query->dependencyCycles($ids['project'], maxEdges: 1);
        assertSame(true, $edgeLimited->truncated);
        assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
        assertThrows(fn() => $query->dependencyCycles($ids['project'], edgeKinds: ['contains']), InvalidArgumentException::class);
        assertThrows(fn() => $query->dependencyCycles($ids['project'], maxNodes: 0), InvalidArgumentException::class);

        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->dependencyCycles($ids['project'], timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
    }
}
