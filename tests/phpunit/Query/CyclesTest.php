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
}
