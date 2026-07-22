<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class DiagramTest extends KnossosTestCase
{
    #[Group('diagram')]
    public function testDiagramExportIsDeterministicScopedEscapedAndBounded(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->saveNode(
            $ids['checkout'],
            $ids['project'],
            'php',
            'class',
            'App\\Checkout',
            'Checkout "API" <unsafe>',
            null,
            $ids['file'],
            3,
            18,
            'ast',
            'certain',
            [],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $duplicateBackend = StableId::boundary($ids['project'], 'Backend', 'inferred');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundary($duplicateBackend, $ids['project'], 'Backend', ['namespace_prefix' => 'App'], 'inferred', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $mermaid = $query->exportDiagram($ids['project']);
        assertContains("flowchart LR\n", $mermaid->data['diagram']);
        assertContains('Checkout &quot;API&quot; &lt;unsafe&gt;', $mermaid->data['diagram']);
        assertContains('n1 -->|calls| n2', $mermaid->data['diagram']);
        assertSame($mermaid->data['diagram'], $query->exportDiagram($ids['project'])->data['diagram']);
        assertSame(2, $mermaid->data['bounds']['nodes_exported']);
        assertSame(1, $mermaid->data['bounds']['edges_exported']);

        $plant = $query->exportDiagram($ids['project'], format: 'plantuml', direction: 'TB');
        assertContains("@startuml\n", $plant->data['diagram']);
        assertSame(false, str_contains($plant->data['diagram'], 'left to right direction'));
        assertContains('Checkout \\"API\\" <unsafe>', $plant->data['diagram']);
        assertContains("@enduml\n", $plant->data['diagram']);
        $scoped = $query->exportDiagram($ids['project'], boundary: $backend);
        assertSame(1, $scoped->data['bounds']['nodes_exported']);
        assertSame(0, $scoped->data['bounds']['edges_exported']);
        assertSame($backend, $scoped->data['boundary_id']);
        $filtered = $query->exportDiagram($ids['project'], edgeKinds: ['imports']);
        assertSame(0, $filtered->data['bounds']['edges_exported']);
        $limited = $query->exportDiagram($ids['project'], maxNodes: 1);
        assertSame(true, $limited->truncated);
        assertSame(['node_limit'], $limited->data['bounds']['truncation_reasons']);
        assertThrows(fn() => $query->exportDiagram($ids['project'], boundary: 'Backend'), InvalidArgumentException::class);
        assertThrows(fn() => $query->exportDiagram($ids['project'], format: 'dot'), InvalidArgumentException::class);
        assertThrows(fn() => $query->exportDiagram($ids['project'], direction: 'RL'), InvalidArgumentException::class);
    }
}
