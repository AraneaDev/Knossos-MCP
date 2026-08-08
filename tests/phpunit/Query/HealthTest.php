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

final class HealthTest extends KnossosTestCase
{
    #[Group('health')]
    public function testTheRepositoryWideBoundaryDoesNotHideEveryCrossBoundaryEdge(): void
    {
        // Package inference gives a single-package repository a boundary whose
        // path prefix is '', so every in-repo node belongs to it. Treating any
        // shared boundary as "same side" then made cross_boundary_degree
        // structurally zero for the whole project, and the term it contributes
        // to the hotspot score dead.
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'inferred');
        $billing = StableId::boundary($ids['project'], 'Billing', 'inferred');
        $wholeRepository = StableId::boundary($ids['project'], 'composer:acme/shop', 'inferred');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['type' => 'path_prefix', 'value' => 'src/Checkout'], 'inferred', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['type' => 'path_prefix', 'value' => 'src/Invoice'], 'inferred', $ids['scan']);
        $repository->saveBoundary($wholeRepository, $ids['project'], 'composer:acme/shop', ['type' => 'path_prefix', 'value' => ''], 'inferred', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->saveBoundaryMembership($wholeRepository, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($wholeRepository, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $health = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project']);

        assertSame(1, $health->data['hubs'][0]['metrics']['cross_boundary_degree']);
    }

    #[Group('health')]
    public function testArchitectureHealthRanksStructuralSignalsAndLabelsDeadCodeUncertainty(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $orphan = StableId::symbol($ids['project'], 'php', 'class', 'App\\OrphanService');
        $model = StableId::symbol($ids['project'], 'php', 'class', 'App\\Order');
        foreach ([[$orphan, 'App\\OrphanService', 'OrphanService'], [$model, 'App\\Order', 'Order']] as [$id, $canonical, $display]) {
            $repository->saveNode(
                $id,
                $ids['project'],
                'php',
                'class',
                $canonical,
                $display,
                null,
                $ids['file'],
                40,
                50,
                'ast',
                'certain',
                [],
                'php:file:src/' . $display . '.php',
                $ids['scan'],
            );
        }
        $repository->saveClassification(
            StableId::classification($ids['project'], $model, 'laravel.model', 'laravel.roles.v1'),
            $ids['project'],
            $model,
            'laravel.model',
            'framework_convention',
            'certain',
            'laravel.roles.v1',
            $ids['file'],
            40,
            50,
            [],
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
            'certain',
            [],
            'php:file:src/InvoiceService.php',
            $ids['scan'],
        );
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $query = new ArchitectureQueryService($pdo);
        $health = $query->architectureHealth($ids['project']);
        assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column(array_column($health->data['hubs'], 'component'), 'canonical_name'));
        assertSame(2, $health->data['hubs'][0]['score']);
        assertSame(2, $health->data['hubs'][0]['metrics']['cross_boundary_degree']);
        assertSame(9, $health->data['static_hotspots'][0]['score']);
        assertSame(true, $health->data['static_hotspots'][0]['factors']['cycle_participant']);
        assertSame(['App\\Order', 'App\\OrphanService'], array_column(array_column($health->data['dead_code_candidates'], 'component'), 'canonical_name'));
        assertSame('possible', $health->data['dead_code_candidates'][0]['confidence']);
        assertSame('probable', $health->data['dead_code_candidates'][1]['confidence']);
        assertContains('candidates only', $health->warnings[1]);
        assertSame(4, count($health->evidence));

        $filtered = $query->architectureHealth($ids['project'], edgeKinds: ['imports']);
        assertSame([], $filtered->data['hubs']);
        $limited = $query->architectureHealth($ids['project'], limit: 1);
        assertSame(true, $limited->truncated);
        assertSame(true, in_array('result_limit', $limited->data['bounds']['truncation_reasons'], true));
        $nodeLimited = $query->architectureHealth($ids['project'], maxNodes: 1);
        assertSame(true, $nodeLimited->truncated);
        assertSame(true, in_array('node_limit', $nodeLimited->data['bounds']['truncation_reasons'], true));
        $edgeLimited = $query->architectureHealth($ids['project'], maxEdges: 1);
        assertSame(true, $edgeLimited->truncated);
        assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
        assertThrows(fn() => $query->architectureHealth($ids['project'], edgeKinds: ['contains']), InvalidArgumentException::class);

        $time = 0;
        $timedQuery = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });
        $timed = $timedQuery->architectureHealth($ids['project'], timeoutMs: 1);
        assertSame(true, $timed->truncated);
        assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
    }

    /**
     * Both of architecture_health's fetches are bounded by the deadline, not just the walk after them.
     *
     * The node fetch and the edge fetch each used to be a fetchAll() that ran to
     * completion before the clock was ever consulted, so timeout_ms bounded
     * neither. `time_limit` alone cannot pin that: it appears on this envelope
     * either way, because the cycle scan below the fetches reports it too. The
     * row count is the only thing that separates a streamed fetch from a
     * materialised one, so that is what is asserted — removing the bound from
     * either fetch reads the whole 5,001-node or 5,000-edge result and fails it.
     *
     * The empty report is the intended shape: a deadline that has already
     * expired means nothing was read within budget, and `truncated` plus
     * `nodes_examined: 0` say exactly that. The alternative — a full node list
     * gathered past the deadline — is the bug being fixed.
     */
    #[Group('health')]
    public function testHealthFetchesStopAtTheirDeadlineDuringTheFetch(): void
    {
        [$pdo, $projectId] = $this->seedGraphWithEdges(5_000);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RowCountingStatement::class, []]);
        $time = 0;
        $expired = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });

        RowCountingStatement::reset();
        $envelope = $expired->architectureHealth($projectId, timeoutMs: 1);

        assertSame(true, $envelope->truncated);
        assertSame(true, in_array('time_limit', $envelope->data['bounds']['truncation_reasons'], true));
        assertSame(true, RowCountingStatement::$rows < 100);
        assertSame(0, $envelope->data['bounds']['nodes_examined']);
        assertSame(0, $envelope->data['bounds']['edges_examined']);
        assertSame([], $envelope->data['hubs']);
        assertSame([], $envelope->data['dead_code_candidates']);
    }

    /**
     * An empty architecture_health report says whether it is empty because the
     * project is clean or because the walk hit a bound.
     *
     * The summary sentence used to be built unconditionally, so "Ranked 0 hubs,
     * 0 static hotspots, and 0 unreferenced-code candidates." was byte-identical
     * for a genuinely clean project and for a walk that read nothing at all
     * before its deadline. dependency_cycles already qualifies its own summary
     * that way; this pins the two to the same contract.
     */
    #[Group('health')]
    public function testABoundedHealthReportNamesItsBoundInTheSummary(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $time = 0;
        $expired = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });

        $bounded = $expired->architectureHealth($ids['project'], timeoutMs: 1);
        $whole = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project']);

        assertSame([], $bounded->data['hubs']);
        assertSame(true, str_starts_with($bounded->summary, 'Ranked 0 hubs, 0 static hotspots, and 0 unreferenced-code candidates.'));
        assertSame(true, str_contains($bounded->summary, 'The ranking was truncated (time_limit)'));
        assertSame(false, $whole->truncated);
        assertSame('Ranked 2 hubs, 2 static hotspots, and 1 unreferenced-code candidates.', $whole->summary);
    }

    /**
     * A constructor is not called dead code because the bounded walk never read
     * the edge that instantiates its class.
     *
     * The classifier excuses an engine-invoked member only when its DECLARING
     * type is referenced, and it read that type's in-degree from the same
     * truncated slice that made the member look unreferenced in the first place.
     * The re-check against the whole edge table covered the candidates but not
     * the types those exclusions gate on, so scanning this very repository under
     * the CLI's default edge cap reported
     * `LaravelContainerFactCollector::__construct` — instantiated one file away
     * — as a `probable` unreferenced-code candidate.
     *
     * `App\Zed` sorts last by canonical name, which is the order the node walk
     * reads in, so max_nodes deterministically drops exactly the class that does
     * the instantiating and nothing else.
     */
    #[Group('health')]
    public function testAConstructorSurvivesWhenItsClassIsInstantiatedBeyondTheNodeBound(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $mailer = StableId::symbol($ids['project'], 'php', 'class', 'App\\Mailer');
        $constructor = StableId::symbol($ids['project'], 'php', 'method', 'App\\Mailer::__construct');
        $caller = StableId::symbol($ids['project'], 'php', 'class', 'App\\Zed');
        foreach ([[$mailer, 'class', 'App\\Mailer', 'Mailer'], [$constructor, 'method', 'App\\Mailer::__construct', '__construct'], [$caller, 'class', 'App\\Zed', 'Zed']] as [$id, $kind, $canonical, $display]) {
            $repository->saveNode($id, $ids['project'], 'php', $kind, $canonical, $display, null, $ids['file'], 60, 70, 'ast', 'certain', [], 'php:file:src/Mailer.php', $ids['scan']);
        }
        $repository->saveEdge(
            StableId::edge($ids['project'], 'constructs', $caller, $mailer, 'src/Zed.php:9'),
            $ids['project'],
            'constructs',
            $caller,
            $mailer,
            $ids['file'],
            9,
            9,
            'ast',
            'certain',
            [],
            'php:file:src/Zed.php',
            $ids['scan'],
        );
        $repository->completeScan($ids['project'], $ids['scan']);

        $health = (new ArchitectureQueryService($pdo))->architectureHealth($ids['project'], maxNodes: 4);

        assertSame(true, in_array('node_limit', $health->data['bounds']['truncation_reasons'], true));
        assertSame(1, $health->data['bounds']['excluded_constructors']);
        assertSame(false, in_array('App\\Mailer::__construct', array_column(array_column($health->data['dead_code_candidates'], 'component'), 'canonical_name'), true));
    }
}
