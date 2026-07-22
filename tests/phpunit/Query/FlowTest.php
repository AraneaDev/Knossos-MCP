<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class FlowTest extends KnossosTestCase
{
    #[Group('flow')]
    public function testFlowQueryRanksConfidenceBoundsCyclesAndAmbiguity(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $route = StableId::symbol($ids['project'], 'php', 'route', 'GET /checkout');
        $typescriptCheckout = StableId::symbol($ids['project'], 'ts', 'class', 'frontend/checkout#Checkout');
        $repository->saveNode($route, $ids['project'], 'php', 'route', 'GET /checkout', 'GET /checkout', null, $ids['file'], 1, 1, 'framework_convention', 'certain', [], 'laravel:routes', $ids['scan']);
        $repository->saveNode($typescriptCheckout, $ids['project'], 'ts', 'class', 'frontend/checkout#Checkout', 'Checkout', null, $ids['file'], 40, 45, 'ast', 'certain', [], 'ts:file:checkout.ts', $ids['scan']);
        $repository->saveEdge(
            StableId::edge($ids['project'], 'routes_to', $route, $ids['checkout'], 'route'),
            $ids['project'],
            'routes_to',
            $route,
            $ids['checkout'],
            $ids['file'],
            1,
            1,
            'framework_convention',
            'certain',
            [],
            'laravel:routes',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'depends_on', $route, $ids['invoice'], 'shortcut'),
            $ids['project'],
            'depends_on',
            $route,
            $ids['invoice'],
            $ids['file'],
            2,
            2,
            'derived',
            'probable',
            [],
            'derived:flow',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'cycle'),
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
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $flow = $query->explainFlow($ids['project'], 'GET /checkout', 'InvoiceService', maxPaths: 1);
        assertSame(1, count($flow->data['paths']));
        assertSame(2, count($flow->data['paths'][0]['hops']));
        assertSame(3, $flow->data['paths'][0]['score']['minimum_confidence']);
        assertSame(true, $flow->truncated);
        assertSame('path_limit', $flow->data['bounds']['truncation_reason']);
        assertSame('src/Checkout.php', $flow->evidence[0]['path']);
        assertContains('--routes_to', $flow->data['paths'][0]['hops'][0]['explanation']);
        $nodeIds = array_column($flow->data['paths'][0]['nodes'], 'id');
        assertSame(count($nodeIds), count(array_unique($nodeIds)));

        $shallow = $query->explainFlow($ids['project'], $route, $ids['invoice'], maxDepth: 1);
        assertSame(1, count($shallow->data['paths'][0]['hops']));
        assertSame(2, $shallow->data['paths'][0]['score']['minimum_confidence']);
        $certain = $query->explainFlow($ids['project'], $route, $ids['invoice'], minConfidence: 'certain');
        assertSame(1, count($certain->data['paths']));
        assertSame(2, count($certain->data['paths'][0]['hops']));
        $filtered = $query->explainFlow($ids['project'], $route, $ids['invoice'], edgeKinds: ['calls']);
        assertSame([], $filtered->data['paths']);

        $ambiguous = $query->explainFlow($ids['project'], 'Checkout', 'InvoiceService');
        assertSame([], $ambiguous->data['paths']);
        assertSame(2, count($ambiguous->data['from']['candidates']));
        assertThrows(fn() => $query->explainFlow($ids['project'], $route, $ids['invoice'], maxDepth: 9), InvalidArgumentException::class);
        assertThrows(fn() => $query->explainFlow($ids['project'], $route, $ids['invoice'], timeoutMs: 0), InvalidArgumentException::class);
        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->explainFlow($ids['project'], $route, $ids['invoice'], timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(0, $timed->data['bounds']['visited_states']);
        assertSame('time_limit', $timed->data['bounds']['truncation_reason']);
    }
}
