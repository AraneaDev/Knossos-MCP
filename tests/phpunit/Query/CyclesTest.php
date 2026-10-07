<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\RowCountingStatement;
use Knossos\Tests\Phpunit\Support\SyntheticGraph;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

final class CyclesTest extends KnossosTestCase
{
    /**
     * `import type` is erased at compile time, so a loop that runs over one is
     * not a loop in anything that executes.
     *
     * Reported as a `certain` cycle, it asks for a deliberate improvement to be
     * undone: splitting a component's variants into their own module and
     * importing the variant TYPE back is how Vite's hot module replacement is
     * kept intact, and the built bundle has one arrow between the two files,
     * not two.
     */
    #[Group('cycles')]
    public function testACycleClosedOnlyByAnErasedTypeImportIsNotReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->saveEdge(
            StableId::edge($ids['project'], 'imports', $ids['checkout'], $ids['invoice'], 'value'),
            $ids['project'],
            'imports',
            $ids['checkout'],
            $ids['invoice'],
            $ids['file'],
            1,
            1,
            'ast',
            'certain',
            ['type_only' => false],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'imports', $ids['invoice'], $ids['checkout'], 'type'),
            $ids['project'],
            'imports',
            $ids['invoice'],
            $ids['checkout'],
            $ids['file'],
            1,
            1,
            'ast',
            'certain',
            ['type_only' => true],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = (new ArchitectureQueryService($pdo))->dependencyCycles($ids['project'], minConfidence: 'certain')->data['cycles'];
        assertSame([], $cycles);
    }

    /**
     * One value import among several statements between the same two modules is
     * enough to make the dependency real.
     *
     * The scanner collapses them into a single edge and records the
     * disagreement in `type_only_variants`; reading only `type_only` would take
     * whichever statement happened to be parsed first as the whole truth.
     */
    #[Group('cycles')]
    public function testACycleSurvivesWhenOneOfTheMergedImportsIsAValueImport(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->saveEdge(
            StableId::edge($ids['project'], 'imports', $ids['checkout'], $ids['invoice'], 'value'),
            $ids['project'],
            'imports',
            $ids['checkout'],
            $ids['invoice'],
            $ids['file'],
            1,
            1,
            'ast',
            'certain',
            ['type_only' => false],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        $repository->saveEdge(
            StableId::edge($ids['project'], 'imports', $ids['invoice'], $ids['checkout'], 'mixed'),
            $ids['project'],
            'imports',
            $ids['invoice'],
            $ids['checkout'],
            $ids['file'],
            1,
            1,
            'ast',
            'certain',
            ['type_only' => true, 'type_only_variants' => [false, true]],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $cycles = (new ArchitectureQueryService($pdo))->dependencyCycles($ids['project'], minConfidence: 'certain')->data['cycles'];
        assertSame(1, count($cycles));
    }

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
        // Self-recursion is not an architectural cycle, so self-loops are excluded by default.
        $result = $query->dependencyCycles($ids['project']);
        assertSame([2], array_column($result->data['cycles'], 'size'));
        assertSame('probable', $result->data['cycles'][0]['minimum_confidence']);
        assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column($result->data['cycles'][0]['members'], 'canonical_name'));
        assertSame(2, count($result->data['cycles'][0]['relationships']));
        assertSame(2, count($result->evidence));
        assertContains('selected static dependency', $result->warnings[0]);

        $withSelfLoops = $query->dependencyCycles($ids['project'], includeSelfLoops: true);
        assertSame([2, 1], array_column($withSelfLoops->data['cycles'], 'size'));
        assertSame('certain', $withSelfLoops->data['cycles'][1]['minimum_confidence']);

        $certain = $query->dependencyCycles($ids['project'], minConfidence: 'certain');
        assertSame([], $certain->data['cycles']);
        $certainSelf = $query->dependencyCycles($ids['project'], minConfidence: 'certain', includeSelfLoops: true);
        assertSame([1], array_column($certainSelf->data['cycles'], 'size'));
        $filtered = $query->dependencyCycles($ids['project'], edgeKinds: ['imports']);
        assertSame([], $filtered->data['cycles']);
        $limited = $query->dependencyCycles($ids['project'], limit: 1, includeSelfLoops: true);
        assertSame(true, $limited->truncated);
        assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
        $edgeLimited = $query->dependencyCycles($ids['project'], maxEdges: 1);
        assertSame(true, $edgeLimited->truncated);
        assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
        // The node cap is the one stop condition the row stream cannot see, so
        // it reports itself — once, and without a row reason alongside it.
        $nodeLimited = $query->dependencyCycles($ids['project'], maxNodes: 1);
        assertSame(true, $nodeLimited->truncated);
        assertSame(['node_limit'], $nodeLimited->data['bounds']['truncation_reasons']);
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

    /**
     * A bounded search must not read as an exhaustive one.
     *
     * The default edge bound sat below the size of a mid-sized graph, so a real
     * cycle beyond the cap went unreported while the summary said "Found 0
     * dependency cycle components" — the same sentence an genuinely acyclic
     * project gets. architecture_trends, which counts over the whole snapshot,
     * disagreed with dependency_cycles for exactly this reason.
     */
    #[Group('cycles')]
    public function testATruncatedSearchSaysSoInTheSummary(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
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
        $repository->completeScan($ids['project'], $ids['scan']);
        $query = new ArchitectureQueryService($pdo);

        $bounded = $query->dependencyCycles($ids['project'], maxEdges: 1);
        assertSame([], $bounded->data['cycles']);
        assertSame(true, str_contains($bounded->summary, 'search was truncated'));
        assertSame(true, str_contains($bounded->summary, 'edge_limit'));

        // An exhaustive search keeps the plain sentence.
        $complete = $query->dependencyCycles($ids['project']);
        assertSame(1, count($complete->data['cycles']));
        assertSame(false, str_contains($complete->summary, 'truncated'));
    }

    /**
     * The deadline has to bound the fetch, not only what follows it.
     *
     * The row phase used to be a fetchAll() with the first deadline check inside
     * the loop that ran afterwards, so timeout_ms could not bound the phase that
     * dominates the cost and every joined row was materialised first regardless.
     * The envelope looked identical either way, so the row count is what the
     * assertion has to be made against.
     */
    #[Group('cycles')]
    public function testTraversalStopsAtItsDeadlineDuringTheFetch(): void
    {
        // A deadline already past when the query starts must produce a truncated
        // result immediately, not after every row is materialised.
        [$pdo, $projectId] = $this->seedGraphWithEdges(5_000);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RowCountingStatement::class, []]);
        $time = 0;
        $expired = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });

        RowCountingStatement::reset();
        $envelope = $expired->dependencyCycles($projectId, timeoutMs: 1);

        assertSame(true, $envelope->truncated);
        assertSame(true, in_array('time_limit', $envelope->data['bounds']['truncation_reasons'], true));
        // The 5,000-edge join must not have been read past the deadline check.
        assertSame(true, RowCountingStatement::$rows < 100);

        // A live clock crosses the periodic gate rather than tripping on the
        // first row, which is the path a real timeout_ms takes.
        RowCountingStatement::reset();
        $live = (new ArchitectureQueryService($pdo))->dependencyCycles($projectId, timeoutMs: 1);
        assertSame(true, $live->truncated);
        assertSame(true, in_array('time_limit', $live->data['bounds']['truncation_reasons'], true));
    }

    /**
     * The default bounds cover a graph of a mid-sized repository.
     *
     * Twelve thousand symbols and sixty thousand dependency edges is an
     * ordinary project, yet the search used to stop at its 10,000-node cap or
     * at its one-second deadline long before it got there, because every edge
     * was read with both endpoints' names and its file path attached. A search
     * that stops early finds nothing, so the answer was "0 cycles" over a graph
     * that has two.
     *
     * Run in a process of its own, like the other large-graph tests: what the
     * graph leaves behind in the heap would otherwise count against the 128 MB
     * of every test after it.
     */
    #[Group('cycles')]
    #[RunInSeparateProcess]
    public function testTheDefaultSearchCoversALargeGraphCompletely(): void
    {
        [$pdo, $project, $nodes, $node] = $this->largeGraph();

        $result = (new ArchitectureQueryService($pdo))->dependencyCycles($project);

        assertSame(false, $result->truncated);
        assertSame([], $result->data['bounds']['truncation_reasons']);
        assertSame([13, 3], array_column($result->data['cycles'], 'size'));
        assertSame(array_map($node, range(100, 112)), $result->data['cycles'][0]['member_ids']);
        assertSame(array_map($node, range(5000, 5002)), $result->data['cycles'][1]['member_ids']);
        assertSame('App\\Synthetic00100', $result->data['cycles'][0]['members'][0]['canonical_name']);
        assertSame('certain', $result->data['cycles'][1]['minimum_confidence']);
        // 5000 -> 5001, 5001 -> 5002 and the back edge; nothing else joins two members.
        assertSame(['edge:5000:0', 'edge:5001:0', 'edge:back:b'], array_column($result->data['cycles'][1]['relationships'], 'id'));
        assertSame(true, str_starts_with($result->summary, 'Found 2 dependency cycle components.'));
        // The fixture's own two nodes and their edge are part of the graph too.
        assertSame($nodes + 2, $result->data['bounds']['nodes_examined']);
    }

    /**
     * architecture_health flags cycle participants from its own cycle search,
     * which it used to run with its 10,000-node cap: on a graph past that size
     * the search was cut short and no participant was flagged.
     */
    #[Group('cycles')]
    #[RunInSeparateProcess]
    public function testArchitectureHealthFlagsEveryCycleOfALargeGraph(): void
    {
        [$pdo, $project, , $node] = $this->largeGraph();

        $result = (new ArchitectureQueryService($pdo))->architectureHealth($project);

        assertSame(false, $result->data['bounds']['cycle_scan_truncated']);
        assertSame([], array_values(array_diff($result->data['bounds']['truncation_reasons'], ['result_limit'])));
        $flagged = array_column(array_filter($result->data['static_hotspots'], static fn(array $hotspot): bool => $hotspot['factors']['cycle_participant']), 'component');
        $flaggedIds = array_column($flagged, 'id');
        sort($flaggedIds);
        assertSame([...array_map($node, range(100, 112)), ...array_map($node, range(5000, 5002))], $flaggedIds);
    }

    /**
     * A cycle whose detail cannot be loaded says so instead of shrinking.
     *
     * The search reads endpoint pairs and loads names and edge details
     * afterwards. An edge left pointing at a node that no longer exists (a
     * graph written before foreign keys were enforced) closes a loop the
     * search sees, but has no row to describe; the cycle is reported with the
     * members it could load and marked truncated.
     */
    #[Group('cycles')]
    public function testACycleThroughAMissingNodeIsReportedTruncated(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $ghost = 'node:ghost';
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $insert = $pdo->prepare(
            'INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, ' .
            "confidence, attributes_json, owner_key, last_scan_id) VALUES (?, ?, 'calls', ?, ?, ?, 3, 3, 'ast', 'certain', '{}', 'php:file:src/Checkout.php', ?)",
        );
        $insert->execute(['edge:to-ghost', $ids['project'], $ids['invoice'], $ghost, $ids['file'], $ids['scan']]);
        $insert->execute(['edge:from-ghost', $ids['project'], $ghost, $ids['checkout'], $ids['file'], $ids['scan']]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->dependencyCycles($ids['project']);

        assertSame([3], array_column($result->data['cycles'], 'size'));
        $cycle = $result->data['cycles'][0];
        assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column($cycle['members'], 'canonical_name'));
        assertSame(true, $cycle['truncated']);
        assertSame(['missing_detail'], $cycle['truncation_reasons']);
        assertSame(true, $result->truncated);
        assertSame(['missing_detail'], $result->data['bounds']['truncation_reasons']);
    }

    /**
     * Twelve thousand synthetic symbols and about sixty thousand dependency
     * edges, two of which close a loop: 100 -> ... -> 112 -> 100 (thirteen
     * members) and 5000 -> 5001 -> 5002 -> 5000 (three). A third back edge is
     * an erased type import and closes nothing.
     *
     * @return array{PDO, string, int, Closure(int): string} the connection, the project, the node count and the id of node i
     */
    private function largeGraph(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $nodes = 12_000;
        $pdo->beginTransaction();
        SyntheticGraph::seed($pdo, $ids, $nodes, 5);
        // 100 -> 101 -> ... -> 112 -> 100: thirteen members.
        SyntheticGraph::edge($pdo, $ids, 'edge:back:a', 'calls', 112, 100);
        // 5000 -> 5001 -> 5002 -> 5000: three members.
        SyntheticGraph::edge($pdo, $ids, 'edge:back:b', 'calls', 5002, 5000);
        // A loop closed only by an erased type import is not a cycle.
        SyntheticGraph::edge($pdo, $ids, 'edge:back:type', 'imports', 9002, 9000, '{"type_only":true}');
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project'], $nodes, SyntheticGraph::node(...)];
    }
}
