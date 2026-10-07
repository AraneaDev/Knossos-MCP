<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\RowCountingStatement;
use PDO;
use PHPUnit\Framework\Attributes\Group;

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
     */
    #[Group('cycles')]
    public function testTheDefaultSearchCoversALargeGraphCompletely(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $nodes = 12_000;
        $node = static fn(int $i): string => 'node:' . sprintf('%064d', $i);
        $pdo->beginTransaction();
        // The bound is spelled into the SQL: a bound parameter arrives as text,
        // and an integer compares below any text, so the recursion never ends.
        $last = sprintf('%d', $nodes - 1);
        $pdo->prepare(
            'INSERT INTO nodes (id, project_id, language, kind, canonical_name, display_name, parent_id, file_id, ' .
            'start_line, end_line, origin, confidence, attributes_json, owner_key, last_scan_id) ' .
            'WITH RECURSIVE seq(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM seq WHERE i < ' . $last . ') ' .
            "SELECT 'node:' || printf('%064d', i), :project, 'php', 'class', 'App\\Synthetic' || printf('%05d', i), " .
            "'Synthetic' || printf('%05d', i), NULL, :file, 1, 1, 'ast', 'certain', '{}', 'php:file:src/Checkout.php', :scan FROM seq",
        )->execute(['project' => $project, 'file' => $ids['file'], 'scan' => $ids['scan']]);
        // Every edge points from a lower index to a higher one, so the bulk of
        // the graph is acyclic and only the back edges below close a loop.
        $pdo->prepare(
            'INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, ' .
            'confidence, attributes_json, owner_key, last_scan_id) ' .
            'WITH RECURSIVE seq(i) AS (SELECT 0 UNION ALL SELECT i + 1 FROM seq WHERE i < ' . $last . '), ' .
            'step(k) AS (VALUES (0), (1), (2), (3), (4)) ' .
            "SELECT 'edge:' || i || ':' || k, :project, CASE k % 2 WHEN 0 THEN 'calls' ELSE 'imports' END, " .
            "'node:' || printf('%064d', i), 'node:' || printf('%064d', i + k * 7 + 1), :file, 1, 1, 'ast', 'certain', " .
            "'{}', 'php:file:src/Checkout.php', :scan FROM seq, step WHERE i + k * 7 + 1 <= " . $last,
        )->execute(['project' => $project, 'file' => $ids['file'], 'scan' => $ids['scan']]);
        $back = $pdo->prepare(
            'INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, ' .
            "confidence, attributes_json, owner_key, last_scan_id) VALUES (?, ?, ?, ?, ?, ?, 7, 7, 'ast', 'certain', ?, 'php:file:src/Checkout.php', ?)",
        );
        // 100 -> 101 -> ... -> 112 -> 100: thirteen members.
        $back->execute(['edge:back:a', $project, 'calls', $node(112), $node(100), $ids['file'], '{}', $ids['scan']]);
        // 5000 -> 5001 -> 5002 -> 5000: three members.
        $back->execute(['edge:back:b', $project, 'calls', $node(5002), $node(5000), $ids['file'], '{}', $ids['scan']]);
        // A loop closed only by an erased type import is not a cycle.
        $back->execute(['edge:back:type', $project, 'imports', $node(9002), $node(9000), $ids['file'], '{"type_only":true}', $ids['scan']]);
        $pdo->commit();
        $repository->completeScan($project, $ids['scan']);

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
}
