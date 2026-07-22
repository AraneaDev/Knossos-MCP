<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ImpactTest extends KnossosTestCase
{
    #[Group('impact')]
    public function testImpactAnalysisGroupsReverseBlastRadiusAndEntryPoints(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $route = StableId::symbol($ids['project'], 'php', 'route', 'GET /checkout');
        $typescriptInvoice = StableId::symbol($ids['project'], 'typescript', 'class', 'frontend#InvoiceService');
        $repository->saveNode($route, $ids['project'], 'php', 'route', 'GET /checkout', 'GET /checkout', null, $ids['file'], 1, 1, 'framework_convention', 'certain', [], 'laravel:routes', $ids['scan']);
        $repository->saveNode($typescriptInvoice, $ids['project'], 'ts', 'class', 'frontend#InvoiceService', 'InvoiceService', null, $ids['file'], 40, 45, 'ast', 'certain', [], 'ts:file:invoice.ts', $ids['scan']);
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
        $repository->saveClassification(
            StableId::classification($ids['project'], $ids['checkout'], 'application.controller', 'test.roles'),
            $ids['project'],
            $ids['checkout'],
            'application.controller',
            'user_rule',
            'certain',
            'test.roles',
            $ids['file'],
            3,
            18,
            [],
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $impact = $query->impactAnalysis($ids['project'], $ids['invoice']);
        assertSame(1, count($impact->data['direct_dependants']));
        assertSame($ids['checkout'], $impact->data['direct_dependants'][0]['node']['id']);
        assertSame([1, 2], array_column($impact->data['by_distance'], 'distance'));
        assertSame(2, count($impact->data['entry_points']));
        assertSame(2, count($impact->data['by_confidence']['certain']));
        assertSame([], $impact->data['by_confidence']['probable']);
        assertSame('src/Checkout.php', $impact->evidence[0]['path']);
        assertContains('conservative static blast radius', $impact->warnings[0]);

        $direct = $query->impactAnalysis($ids['project'], $ids['invoice'], maxDepth: 1);
        assertSame(1, count($direct->data['by_distance']));
        $limited = $query->impactAnalysis($ids['project'], $ids['invoice'], limit: 1);
        assertSame(true, $limited->truncated);
        $filtered = $query->impactAnalysis($ids['project'], $ids['invoice'], edgeKinds: ['imports']);
        assertSame([], $filtered->data['direct_dependants']);
        $ambiguous = $query->impactAnalysis($ids['project'], 'InvoiceService');
        assertSame(2, count($ambiguous->data['candidates']));

        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->impactAnalysis($ids['project'], $ids['invoice'], timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(0, $timed->data['bounds']['visited_states']);
        assertSame('time_limit', $timed->data['bounds']['truncation_reason']);
    }
}
