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

    /**
     * Truncating alphabetically produced diagrams of whatever sorted first,
     * which for a real boundary meant a screen of unconnected boxes: exporting
     * this repository's own `core` boundary at 20 nodes yielded 13 edges and
     * left `Application` sitting there with none. A bounded diagram should show
     * the most connected slice, and render one arrow per relationship rather
     * than one per call site.
     */
    #[Group('diagram')]
    public function testABoundedDiagramKeepsTheMostConnectedNodesAndOneArrowPerRelationship(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $node = function (string $name) use ($repository, $ids): string {
            $id = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', $name, $name, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            return $id;
        };
        // Sorts first alphabetically, connected to nothing.
        foreach (['App\\Aaa1', 'App\\Aaa2', 'App\\Aaa3'] as $isolated) {
            $node($isolated);
        }
        $hub = $node('App\\Zeta\\Hub');
        $leaf = $node('App\\Zeta\\Leaf');
        // One relationship, written at two call sites.
        foreach ([41, 42] as $line) {
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $hub, $leaf, 'src/Zeta.php:' . $line),
                $ids['project'],
                'calls',
                $hub,
                $leaf,
                $ids['file'],
                $line,
                $line,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $diagram = (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], maxNodes: 2);

        assertSame(2, $diagram->data['bounds']['nodes_exported']);
        assertContains('Hub', $diagram->data['diagram']);
        assertContains('Leaf', $diagram->data['diagram']);
        assertSame(false, str_contains($diagram->data['diagram'], 'Aaa'));
        // Two call sites, one arrow.
        assertSame(1, $diagram->data['bounds']['edges_exported']);
        assertSame(1, substr_count($diagram->data['diagram'], '-->|calls|'));
    }

    /**
     * Ranking by degree alone still picks badly, because the highest-degree
     * nodes in a real codebase are hubs whose neighbours are everywhere else:
     * take the top 20 and you get 20 unrelated hubs with almost nothing
     * between them. A diagram is about relationships, so the slice has to grow
     * along them rather than skim the top of a list.
     */
    #[Group('diagram')]
    public function testTheSliceGrowsAlongRelationshipsRatherThanTakingTheTopDegrees(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $node = function (string $name) use ($repository, $ids): string {
            $id = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', $name, $name, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            return $id;
        };
        $line = 0;
        $call = function (string $from, string $to) use ($repository, $ids, &$line): void {
            ++$line;
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $from, $to, 'src/G.php:' . $line),
                $ids['project'],
                'calls',
                $from,
                $to,
                $ids['file'],
                $line,
                $line,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
        };
        // Five hubs, each with eight leaves: every hub has degree 8, and no two
        // hubs touch. Taking the top four by degree yields four hubs and zero
        // edges between them.
        for ($hubIndex = 0; $hubIndex < 5; ++$hubIndex) {
            $hub = $node(sprintf('App\\Hub%d', $hubIndex));
            for ($leaf = 0; $leaf < 8; ++$leaf) {
                $call($hub, $node(sprintf('App\\Hub%dLeaf%d', $hubIndex, $leaf)));
            }
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $diagram = (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], maxNodes: 4);

        assertSame(4, $diagram->data['bounds']['nodes_exported']);
        // Four nodes drawn from one hub's neighbourhood: three arrows, not none.
        assertSame(3, $diagram->data['bounds']['edges_exported']);
    }
}
