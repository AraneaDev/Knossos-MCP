<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\AbstractArchitectureQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/** Concrete probe exposing the protected Kosaraju routine for direct testing. */
final readonly class SccProbe extends AbstractArchitectureQueryService
{
    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, list<string>> $reverse
     * @return array{components: list<list<string>>, timed_out: bool}
     */
    public function scc(array $adjacency, array $reverse, ?int $deadline): array
    {
        return $this->stronglyConnectedComponents($adjacency, $reverse, $deadline);
    }
}

final class AuditBatch2Test extends KnossosTestCase
{
    #[Group('query')]
    public function testCheckArchitectureCountsViolationsPastCollectionLimit(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $worker = StableId::symbol($ids['project'], 'php', 'class', 'App\\Worker');
        $repository->saveNode($worker, $ids['project'], 'php', 'class', 'App\\Worker', 'Worker', null, $ids['file'], 40, 50, 'ast', 'certain', [], 'php:file:src/Worker.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['checkout'], $worker, 'w'), $ids['project'], 'calls', $ids['checkout'], $worker, $ids['file'], 15, 15, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        // Checkout (Backend) calls InvoiceService (Billing) and Worker (@unassigned).
        $policies = [
            ['id' => 'deny-billing', 'from_boundary' => $backend, 'deny_targets' => [$billing], 'edge_kinds' => ['calls']],
            ['id' => 'deny-unassigned', 'from_boundary' => $backend, 'deny_targets' => ['@unassigned'], 'edge_kinds' => ['calls']],
        ];
        $full = $query->checkArchitecture($ids['project'], $policies);
        assertSame(2, count($full->data['violations']));
        assertSame(2, $full->data['bounds']['violation_count']);

        $limited = $query->checkArchitecture($ids['project'], $policies, limit: 1);
        assertSame(1, count($limited->data['violations']));
        assertSame(2, $limited->data['bounds']['violation_count']);
        assertSame(true, $limited->truncated);
        assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
    }

    #[Group('query')]
    public function testQualityGateBoundaryViolationsCatchesCountAboveCollectionLimit(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'gate-next');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-next'));

        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/back'], 'explicit', $next);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/bill'], 'explicit', $next);
        $target = StableId::symbol($ids['project'], 'php', 'class', 'App\\BillingCore');
        $repository->saveNode($target, $ids['project'], 'php', 'class', 'App\\BillingCore', 'BillingCore', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $next);
        $repository->saveBoundaryMembership($billing, $ids['project'], $target, $next);
        for ($i = 0; $i < 101; $i++) {
            $src = StableId::symbol($ids['project'], 'php', 'class', 'App\\Caller' . $i);
            $repository->saveNode($src, $ids['project'], 'php', 'class', 'App\\Caller' . $i, 'Caller' . $i, null, $ids['file'], 3, 4, 'ast', 'certain', [], 'php:file:src/Checkout.php', $next);
            $repository->saveBoundaryMembership($backend, $ids['project'], $src, $next);
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $src, $target, 'e' . $i), $ids['project'], 'calls', $src, $target, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $next);
        }
        $repository->completeScan($ids['project'], $next);

        $query = new ArchitectureQueryService($pdo);
        $policies = [['id' => 'no-billing', 'from_boundary' => $backend, 'deny_targets' => [$billing], 'edge_kinds' => ['calls']]];
        $gate = $query->qualityGate($ids['project'], $ids['scan'], ['boundary_violations' => 100], $policies);
        assertSame(101, $gate->data['metrics']['boundary_violations']);
        assertSame(false, $gate->data['passed']);
    }

    #[Group('query')]
    public function testStronglyConnectedComponentsDiscardOutputWhenPassOneTimesOut(): void
    {
        $pdo = $this->freshTestDatabase();
        $adjacency = $reverse = [];
        // Many independent 2-cycles: pass one finishes dozens of nodes before it
        // trips the deadline, leaving a non-empty partial finish order that the
        // buggy code would still feed into pass two.
        for ($i = 0; $i < 200; $i++) {
            $a = 'a' . $i;
            $b = 'b' . $i;
            $adjacency[$a] = [$b];
            $adjacency[$b] = [$a];
            $reverse[$a] = [$b];
            $reverse[$b] = [$a];
        }
        // Clock always past the deadline: the first %256 check trips inside pass one.
        $probe = new SccProbe($pdo, static fn(): int => 1);
        $result = $probe->scc($adjacency, $reverse, 0);
        assertSame(true, $result['timed_out']);
        assertSame([], $result['components']);
    }

    #[Group('query')]
    public function testImpactAnalysisSignalsPerNodeEdgeLimitWhenHubExceeds500Edges(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $hub = StableId::symbol($ids['project'], 'php', 'class', 'App\\Hub');
        $caller = StableId::symbol($ids['project'], 'php', 'class', 'App\\SingleCaller');
        $repository->saveNode($hub, $ids['project'], 'php', 'class', 'App\\Hub', 'Hub', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveNode($caller, $ids['project'], 'php', 'class', 'App\\SingleCaller', 'SingleCaller', null, $ids['file'], 3, 4, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        for ($i = 0; $i < 501; $i++) {
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $caller, $hub, 'e' . $i), $ids['project'], 'calls', $caller, $hub, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $impact = $query->impactAnalysis($ids['project'], $hub, maxDepth: 1, limit: 100);
        assertSame(1, count($impact->data['direct_dependants']));
        assertSame(true, $impact->truncated);
        assertSame('per_node_edge_limit', $impact->data['bounds']['truncation_reason']);
    }

    #[Group('query')]
    public function testImpactAnalysisPathConfidenceTracksMaxPerDistance(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // A->T certain, B->T probable; X->A possible, X->B certain.
        // The reverse BFS processes A first (its T-edge outranks B's), discovering
        // X at path-confidence possible; the equal-distance path through B carries
        // probable, which the max-per-distance rule must prefer.
        $t = StableId::symbol($ids['project'], 'php', 'class', 'App\\Target');
        $a = StableId::symbol($ids['project'], 'php', 'class', 'App\\ViaA');
        $b = StableId::symbol($ids['project'], 'php', 'class', 'App\\ViaB');
        $x = StableId::symbol($ids['project'], 'php', 'class', 'App\\Origin');
        foreach ([[$t, 'App\\Target', 'Target'], [$a, 'App\\ViaA', 'ViaA'], [$b, 'App\\ViaB', 'ViaB'], [$x, 'App\\Origin', 'Origin']] as [$id, $c, $d]) {
            $repository->saveNode($id, $ids['project'], 'php', 'class', $c, $d, null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $a, $t, 'at'), $ids['project'], 'calls', $a, $t, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $b, $t, 'bt'), $ids['project'], 'calls', $b, $t, $ids['file'], 5, 5, 'ast', 'probable', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $x, $a, 'xa'), $ids['project'], 'calls', $x, $a, $ids['file'], 5, 5, 'ast', 'possible', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $x, $b, 'xb'), $ids['project'], 'calls', $x, $b, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $impact = $query->impactAnalysis($ids['project'], $t, maxDepth: 3);
        $origin = null;
        foreach ($impact->data['by_distance'] as $group) {
            foreach ($group['dependants'] as $record) {
                if ($record['node']['canonical_name'] === 'App\\Origin') {
                    $origin = $record;
                }
            }
        }
        assertSame('probable', $origin['path_confidence']);
    }

    #[Group('query')]
    public function testExplainFlowFindsSelfFlow(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $node = StableId::symbol($ids['project'], 'php', 'class', 'App\\SelfCaller');
        $repository->saveNode($node, $ids['project'], 'php', 'class', 'App\\SelfCaller', 'SelfCaller', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $node, $node, 'self'), $ids['project'], 'calls', $node, $node, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $flow = $query->explainFlow($ids['project'], 'App\\SelfCaller', 'App\\SelfCaller');
        assertSame(true, count($flow->data['paths']) >= 1);
        assertSame(1, count($flow->data['paths'][0]['hops']));
    }

    #[Group('query')]
    public function testDeadCodeExcludesMethodDeclaredByTransitiveAncestor(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $file = $ids['file'];
        $owner = 'php:file:src/Checkout.php';
        // C extends B extends A; A::run declares, C::run overrides, B has no run.
        $a = StableId::symbol($ids['project'], 'php', 'class', 'App\\A');
        $aRun = StableId::symbol($ids['project'], 'php', 'method', 'App\\A::run');
        $b = StableId::symbol($ids['project'], 'php', 'class', 'App\\B');
        $c = StableId::symbol($ids['project'], 'php', 'class', 'App\\C');
        $cRun = StableId::symbol($ids['project'], 'php', 'method', 'App\\C::run');
        foreach ([
            [$a, 'class', 'App\\A', 'A'],
            [$aRun, 'method', 'App\\A::run', 'run'],
            [$b, 'class', 'App\\B', 'B'],
            [$c, 'class', 'App\\C', 'C'],
            [$cRun, 'method', 'App\\C::run', 'run'],
        ] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $file, 1, 2, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $a, $aRun, 'ca'), $ids['project'], 'contains', $a, $aRun, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'contains', $c, $cRun, 'cc'), $ids['project'], 'contains', $c, $cRun, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'extends', $c, $b, 'cb'), $ids['project'], 'extends', $c, $b, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'extends', $b, $a, 'ba'), $ids['project'], 'extends', $b, $a, $file, 1, 1, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'])->data;
        $candidateNames = array_map(static fn(array $c): string => $c['component']['canonical_name'], $data['dead_code_candidates']);
        assertSame(false, in_array('App\\C::run', $candidateNames, true));
    }

    #[Group('query')]
    public function testSearchArchitecturePaginationIsStableAcrossTiedRows(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // Two distinct nodes sharing a canonical_name tie on (rank, canonical_name).
        $dupClass = StableId::symbol($ids['project'], 'php', 'class', 'App\\Dup');
        $dupIface = StableId::symbol($ids['project'], 'php', 'interface', 'App\\Dup');
        $repository->saveNode($dupClass, $ids['project'], 'php', 'class', 'App\\Dup', 'Dup', null, $ids['file'], 1, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveNode($dupIface, $ids['project'], 'php', 'interface', 'App\\Dup', 'Dup', null, $ids['file'], 3, 4, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $first = $query->searchArchitecture($ids['project'], 'App\\Dup', limit: 1, offset: 0);
        $second = $query->searchArchitecture($ids['project'], 'App\\Dup', limit: 1, offset: 1);
        $firstId = $first->data['results'][0]['id'];
        $secondId = $second->data['results'][0]['id'];
        assertSame(true, $firstId !== $secondId);
        $paged = [$firstId, $secondId];
        sort($paged, SORT_STRING);
        $expected = [$dupClass, $dupIface];
        sort($expected, SORT_STRING);
        assertSame($expected, $paged);
    }

    #[Group('query')]
    public function testStalenessProbeReportsUnverifiedWhenChangeDetectionSkipped(): void
    {
        // storeFixture root '/workspace/fixture-shop' does not exist, so change
        // detection is skipped and the probe cannot confirm freshness.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $state = (new StalenessProbe($pdo))->probe($ids['project']);
        assertSame('unverified', $state['state']);
    }

    #[Group('query')]
    public function testArchitectureContextTruncationIsTrackedStructurally(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // A scanned attribute that literally serializes to "status":"truncated"
        // must not flip the envelope truncation flag when nothing was trimmed.
        $poison = StableId::symbol($ids['project'], 'php', 'class', 'App\\Poison');
        $repository->saveNode($poison, $ids['project'], 'php', 'class', 'App\\Poison', 'Poison', null, $ids['file'], 1, 2, 'ast', 'certain', ['status' => 'truncated'], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $result = $query->architectureContext($ids['project'], files: ['src/Checkout.php']);
        assertSame(false, $result->truncated);
    }

    #[Group('query')]
    public function testChangedFilesImpactLoneBaseRefYieldsSpecificMessage(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $query = new ArchitectureQueryService($pdo);

        $message = null;
        try {
            $query->changedFilesImpact($ids['project'], baseRef: 'main');
        } catch (InvalidArgumentException $error) {
            $message = $error->getMessage();
        }
        assertSame('base_ref requires working_tree.', $message);
    }
}
