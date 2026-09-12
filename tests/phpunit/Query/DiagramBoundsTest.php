<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The diagram export on its bounds: the node and edge ranges it accepts, where
 * truncation starts, what the slice leaves in the order it renders, and how
 * much evidence it cites.
 *
 * DiagramExportService scored 73% under mutation testing. Its tests exercise
 * small graphs well inside every bound, so each limit could move by one, the
 * candidate pool could stop being wider than the slice (which silently ends
 * truncation reporting), the evidence cap could change, and the PlantUML
 * arrows could vanish, all with them green.
 */
final class DiagramBoundsTest extends KnossosTestCase
{
    /** Every advertised bound is accepted at both ends and refused just outside. */
    #[Group('diagram')]
    public function testTheAdvertisedRangesAreAcceptedAndOneStepOutsideIsRefused(): void
    {
        [$pdo, $ids] = $this->graph(2);
        $query = new ArchitectureQueryService($pdo);

        assertSame(1, $query->exportDiagram($ids['project'], maxNodes: 1)->data['bounds']['max_nodes']);
        assertSame(400, $query->exportDiagram($ids['project'], maxNodes: 400)->data['bounds']['max_nodes']);
        assertSame(1, $query->exportDiagram($ids['project'], maxEdges: 1)->data['bounds']['max_edges']);
        assertSame(1_000, $query->exportDiagram($ids['project'], maxEdges: 1_000)->data['bounds']['max_edges']);

        foreach ([['maxNodes' => 0], ['maxNodes' => 401], ['maxEdges' => 0], ['maxEdges' => 1_001]] as $outside) {
            assertThrows(fn() => $query->exportDiagram($ids['project'], ...$outside), InvalidArgumentException::class);
        }
        // One unsupported kind is enough, however short the list.
        assertThrows(fn() => $query->exportDiagram($ids['project'], edgeKinds: ['calls', 'teleports_to']), InvalidArgumentException::class);
    }

    /**
     * A graph that fits is rendered whole, in the pool's own degree-then-name
     * order, and reports no truncation. One node more and the export says so.
     */
    #[Group('diagram')]
    public function testAGraphThatFitsIsRenderedWholeAndUntruncated(): void
    {
        [$pdo, $ids] = $this->graph(3);
        $query = new ArchitectureQueryService($pdo);

        $whole = $query->exportDiagram($ids['project'], maxNodes: 5);

        assertSame(false, $whole->truncated);
        assertSame([], $whole->data['bounds']['truncation_reasons']);
        // Most connected first, then by name: Link1 sits between Link0 and
        // Link2 and so has two relationships; the rest have one each.
        $lines = explode("\n", trim($whole->data['diagram']));
        assertSame(
            ['flowchart LR', '  n1["Link1 (class)"]', '  n2["Checkout (class)"]', '  n3["InvoiceService (class)"]', '  n4["Link0 (class)"]', '  n5["Link2 (class)"]'],
            array_slice($lines, 0, 6),
        );
        // The arrows are ordered by the ids SQLite sorts on, which are hashes,
        // so this states which arrows exist rather than their order.
        $arrows = array_slice($lines, 6);
        sort($arrows, SORT_STRING);
        assertSame(['  n1 -->|calls| n5', '  n2 -->|calls| n3', '  n4 -->|calls| n1'], $arrows);

        $cut = $query->exportDiagram($ids['project'], maxNodes: 4);
        assertSame(true, $cut->truncated);
        assertSame(['node_limit'], $cut->data['bounds']['truncation_reasons']);
        assertSame(4, $cut->data['bounds']['nodes_exported']);
    }

    /** Exactly as many arrows as the edge budget fit; one more is cut and reported. */
    #[Group('diagram')]
    public function testTheEdgeBudgetIsInclusive(): void
    {
        [$pdo, $ids] = $this->graph(3);
        $query = new ArchitectureQueryService($pdo);

        $atBound = $query->exportDiagram($ids['project'], maxEdges: 3);
        assertSame(3, $atBound->data['bounds']['edges_exported']);
        assertSame([], $atBound->data['bounds']['truncation_reasons']);

        $over = $query->exportDiagram($ids['project'], maxEdges: 2);
        assertSame(2, $over->data['bounds']['edges_exported']);
        assertSame(['edge_limit'], $over->data['bounds']['truncation_reasons']);
        assertSame(true, $over->truncated);
    }

    /** Both bounds together are reported once each, in the order they were hit. */
    #[Group('diagram')]
    public function testBothLimitsAreReportedTogether(): void
    {
        [$pdo, $ids] = $this->graph(3);

        $bounds = (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], maxNodes: 4, maxEdges: 1)->data['bounds'];

        assertSame(['node_limit', 'edge_limit'], $bounds['truncation_reasons']);
    }

    /** PlantUML names every component and draws every arrow. */
    #[Group('diagram')]
    public function testPlantUmlRendersComponentsAndArrows(): void
    {
        [$pdo, $ids] = $this->graph(1);

        $diagram = (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], format: 'plantuml')->data['diagram'];

        assertSame(true, str_contains($diagram, 'component "Checkout (class)" as n1'));
        assertSame(true, str_contains($diagram, 'n1 --> n2 : calls'));
        assertSame('plantuml', (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], format: 'plantuml')->data['format']);
    }

    /**
     * Evidence cites the components the diagram shows, up to a hundred of them,
     * and skips any that has no file to cite.
     */
    #[Group('diagram')]
    public function testEvidenceIsCappedAtAHundredAndSkipsComponentsWithoutAFile(): void
    {
        [$pdo, $ids] = $this->graph(0);
        $repository = new SqliteGraphRepository($pdo);
        for ($index = 0; $index < 120; ++$index) {
            $name = sprintf('App\\Many%03d', $index);
            $id = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', $name, $name, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $unfiled = StableId::symbol($ids['project'], 'php', 'class', 'App\\Unfiled');
        $repository->saveNode($unfiled, $ids['project'], 'php', 'class', 'App\\Unfiled', 'Unfiled', null, null, null, null, 'ast', 'certain', [], 'test:unfiled', $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], maxNodes: 400);

        assertSame(100, count($result->evidence));
        assertSame(
            [],
            array_values(array_filter($result->evidence, static fn(array $entry): bool => $entry['path'] === null)),
            'A component with no file is not cited.',
        );
        assertSame(true, array_key_exists('component_id', $result->evidence[0]));
        // The citations start at the first component rendered, which is the
        // most connected one, not the one after it.
        assertSame($ids['checkout'], $result->evidence[0]['component_id']);
    }

    /**
     * The slice takes the candidate most strongly attached to what is already
     * chosen, not simply the last one that touches it at all.
     *
     * Aaa is the busiest node and is seeded first. Bbb and Xxx both attach to
     * it, and once Bbb is taken Xxx attaches to two chosen nodes while Yyy
     * still attaches to one, so Xxx is third and Yyy is left out.
     */
    #[Group('diagram')]
    public function testTheSliceTakesTheMostAttachedCandidateRatherThanTheLast(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $nodes = [];
        foreach (['Aaa', 'Bbb', 'Xxx', 'Yyy'] as $name) {
            $id = StableId::symbol($ids['project'], 'php', 'class', 'App\\' . $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', 'App\\' . $name, $name, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $nodes[$name] = $id;
        }
        foreach ([['Aaa', 'Bbb'], ['Aaa', 'Xxx'], ['Aaa', 'Yyy'], ['Bbb', 'Xxx']] as [$from, $to]) {
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $nodes[$from], $nodes[$to], $from . $to),
                $ids['project'], 'calls', $nodes[$from], $nodes[$to], $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $diagram = (new ArchitectureQueryService($pdo))->exportDiagram($ids['project'], maxNodes: 3)->data['diagram'];

        assertSame(
            ['flowchart LR', '  n1["Aaa (class)"]', '  n2["Bbb (class)"]', '  n3["Xxx (class)"]'],
            array_slice(explode("\n", trim($diagram)), 0, 4),
        );
        assertSame(false, str_contains($diagram, 'Yyy'));
    }

    /**
     * A store with the shop fixture plus a chain of $links connected nodes, so
     * the graph has a known node count, edge count and order.
     *
     * @return array{0: PDO, 1: array<string, string>}
     */
    private function graph(int $links): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $previous = null;
        for ($index = 0; $index < $links; ++$index) {
            $name = sprintf('App\\Link%d', $index);
            $id = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode($id, $ids['project'], 'php', 'class', $name, sprintf('Link%d', $index), null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            if ($previous !== null) {
                $repository->saveEdge(
                    StableId::edge($ids['project'], 'calls', $previous, $id, 'chain-' . $index),
                    $ids['project'], 'calls', $previous, $id, $ids['file'], 10 + $index, 10 + $index, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan'],
                );
            }
            $previous = $id;
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids];
    }
}
