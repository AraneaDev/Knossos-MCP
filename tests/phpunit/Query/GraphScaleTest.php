<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * The whole-graph queries at their own ceilings fit the 128 MB a PHP process gets in CI.
 *
 * max_nodes reaches 50,000 and max_edges 100,000, and both are the defaults of
 * dependency_cycles and architecture_health. A walk that keeps a full row per
 * node, or a named array per edge, needs more than 128 MB long before that
 * size. Each test runs in a process of its own, so the limit it sets measures
 * the query rather than whatever earlier tests left in the heap.
 */
final class GraphScaleTest extends KnossosTestCase
{
    #[Group('query')]
    #[RunInSeparateProcess]
    public function testArchitectureHealthAtItsDefaultNodeCeilingFitsIn128Megabytes(): void
    {
        ini_set('memory_limit', '128M');
        [$pdo, $project] = $this->graphAtTheCeiling();

        // Five seconds rather than the default one is headroom for a loaded CI
        // runner, not a bound the walk needs; any reason beyond the capped hub
        // list (a time, node or edge limit) still fails the test.
        $result = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($project, timeoutMs: 5000);

        assertSame(['result_limit'], $result->data['bounds']['truncation_reasons']);
        assertSame(false, $result->data['bounds']['cycle_scan_truncated']);
        assertSame(50_000, $result->data['bounds']['max_nodes']);
        assertSame(20, count($result->data['hubs']));
        // The back edge lifts its two ends to five; every other interior node
        // ties at four (two in, two out), and a tie goes to the first name.
        assertSame(
            ['App\\Synthetic40000', 'App\\Synthetic40002', 'App\\Synthetic00008'],
            array_slice(array_map(static fn(array $hub): string => $hub['component']['canonical_name'], $result->data['hubs']), 0, 3),
        );
    }

    #[Group('query')]
    #[RunInSeparateProcess]
    public function testDependencyCyclesAtItsNodeAndEdgeCeilingsFitsIn128Megabytes(): void
    {
        ini_set('memory_limit', '128M');
        [$pdo, $project] = $this->graphAtTheCeiling();

        $result = ArchitectureQueryService::forDatabase($pdo)->dependencyCycles($project, timeoutMs: 5000);

        assertSame([], array_values(array_diff($result->data['bounds']['truncation_reasons'], ['edge_limit'])));
        assertSame([3], array_column($result->data['cycles'], 'size'));
    }

    /**
     * 49,998 synthetic nodes beside the fixture's two, about 100,000 edges, and one three-member cycle.
     *
     * @return array{PDO, string}
     */
    private function graphAtTheCeiling(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->beginTransaction();
        SyntheticGraph::seed($pdo, $ids, 49_998, 2);
        SyntheticGraph::edge($pdo, $ids, 'edge:back', 'calls', 40_002, 40_000);
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }
}
