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

        $health = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($ids['project']);

        assertSame(1, $health->data['hubs'][0]['metrics']['cross_boundary_degree']);
    }

    /** The in-degree histogram counts every component the ranking could hold, one nothing depends on included. */
    #[Group('health')]
    public function testTheInDegreeHistogramCountsEveryRankableComponentOnce(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        $health = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($ids['project'])->data;
        $buckets = $health['in_degree_histogram'];

        assertSame([0, 1, 6, 21, 101], array_column($buckets, 'from'));
        assertSame([0, 5, 20, 100, null], array_column($buckets, 'to'));
        $ranked = array_column(array_column($health['hubs'], 'metrics'), 'in_degree');
        $reportable = (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind NOT LIKE 'external\\_%' ESCAPE '\\'")->fetchColumn();
        assertSame($reportable, array_sum(array_column($buckets, 'components')));
        assertSame(count(array_filter($ranked, static fn(int $in): bool => $in >= 1 && $in <= 5)), $buckets[1]['components']);
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

        $query = ArchitectureQueryService::forDatabase($pdo);
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
        $timedQuery = ArchitectureQueryService::forDatabase($pdo, function () use (&$time): int {
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
     * An expired deadline still yields the first `limit` (20) nodes: the
     * degree counts finish before the first row, so those rows, which are the
     * hubs, cost nothing more to read. This test once pinned
     * `nodes_examined: 0` and an empty hub list, which left the agent brief
     * with no hub section whenever the counts were slow. Past those 20 rows
     * the deadline stops the fetch, so the whole 5,001-node result is still
     * never read.
     */
    #[Group('health')]
    public function testHealthFetchesStopAtTheirDeadlineDuringTheFetch(): void
    {
        [$pdo, $projectId] = $this->seedGraphWithEdges(5_000);
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [RowCountingStatement::class, []]);
        $time = 0;
        $expired = ArchitectureQueryService::forDatabase($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });

        RowCountingStatement::reset();
        // Both budgets spent: the hub walk's and the candidate search's.
        $envelope = $expired->architectureHealth($projectId, timeoutMs: 1, candidateTimeoutMs: 1);

        assertSame(true, $envelope->truncated);
        assertSame(true, in_array('time_limit', $envelope->data['bounds']['truncation_reasons'], true));
        assertSame(true, RowCountingStatement::$rows < 100);
        assertSame(20, $envelope->data['bounds']['nodes_examined']);
        assertSame(0, $envelope->data['bounds']['edges_examined']);
        assertSame(20, count($envelope->data['hubs']));
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
     * that way; this pins the two to the same contract. Since the node slice
     * keeps its first `limit` rows past an expired deadline, the bounded
     * report still ranks the two hubs; the summary names the bound either way.
     */
    #[Group('health')]
    public function testABoundedHealthReportNamesItsBoundInTheSummary(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $time = 0;
        $expired = ArchitectureQueryService::forDatabase($pdo, function () use (&$time): int {
            $time += 2_000_000;
            return $time;
        });

        $bounded = $expired->architectureHealth($ids['project'], timeoutMs: 1, candidateTimeoutMs: 1);
        $whole = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($ids['project']);

        assertSame(2, count($bounded->data['hubs']));
        assertSame(true, str_starts_with($bounded->summary, 'Ranked 2 hubs, 2 static hotspots, and 0 unreferenced-code candidates, 0 of them reached only by tests.'), $bounded->summary);
        // The expired deadline also skips the cycle check, named since a cut
        // cycle scan marks the ranking truncated.
        assertSame(true, str_contains($bounded->summary, 'The ranking was truncated (time_limit, cycle_scan)'));
        assertSame(false, $whole->truncated);
        assertSame('Ranked 2 hubs, 2 static hotspots, and 1 unreferenced-code candidates, 0 of them reached only by tests.', $whole->summary);
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
     * With five components and max_nodes 4 the node window is cut. It once
     * dropped `App\Zed`, the instantiating class, because the window ran in
     * name order; it now runs in degree order and drops the constructor, the
     * one component without an edge. Either way the ranking is truncated while
     * the dead-code decision, made over the whole graph, still excuses the
     * constructor, which is what this pins.
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

        $health = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($ids['project'], maxNodes: 4);

        assertSame(true, in_array('node_limit', $health->data['bounds']['truncation_reasons'], true));
        assertSame(1, $health->data['bounds']['excluded_constructors']);
        assertSame(false, in_array('App\\Mailer::__construct', array_column(array_column($health->data['dead_code_candidates'], 'component'), 'canonical_name'), true));
    }

    /**
     * Nodes were windowed by name, so a hub named late vanished and its edges
     * from outside the window were dropped. The window now keeps the
     * highest-degree nodes, and degree is counted over every selected edge.
     */
    #[Group('health')]
    public function testAHubThatSortsLastByNameIsRankedWhenTheNodeWindowIsCut(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $class = static function (string $name) use ($repository, $ids, $project): string {
            $id = StableId::symbol($project, 'php', 'class', $name);
            $repository->saveNode($id, $project, 'php', 'class', $name, substr($name, 4), null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Hub.php', $ids['scan']);
            return $id;
        };
        $zeta = $class('App\\Zeta');
        foreach (range(1, 6) as $index) {
            $caller = $class(sprintf('App\\Aaa%02d', $index));
            $repository->saveEdge(StableId::edge($project, 'calls', $caller, $zeta, 'hub'), $project, 'calls', $caller, $zeta, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Hub.php', $ids['scan']);
        }
        foreach (range(1, 3) as $index) {
            $class(sprintf('App\\Aab%d', $index));
        }
        $repository->completeScan($project, $ids['scan']);

        $health = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($project, maxNodes: 3);

        assertSame('App\\Zeta', $health->data['hubs'][0]['component']['canonical_name']);
        assertSame(6, $health->data['hubs'][0]['metrics']['in_degree'], 'Callers outside the window still count.');
        assertSame(true, in_array('node_limit', $health->data['bounds']['truncation_reasons'], true));
        assertSame(3, $health->data['bounds']['nodes_examined']);
    }

    /**
     * A cycle scan cut short left hotspots without their cycle signal, but
     * said so only in `bounds.cycle_scan_truncated`: the result read as
     * complete. 101 two-node cycles are one more than the 100 the health
     * check asks dependency_cycles for.
     */
    #[Group('health')]
    public function testATruncatedCycleScanIsATruncatedRanking(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $nodes = [];
        $edges = [];
        for ($i = 0; $i < 101; $i++) {
            $pair = [];
            foreach (['A', 'B'] as $end) {
                $name = sprintf('App\\C%03d%s', $i, $end);
                $pair[] = $id = StableId::symbol($project, 'php', 'class', $name);
                $nodes[] = [
                    'id' => $id, 'language' => 'php', 'kind' => 'class', 'canonical_name' => $name, 'display_name' => substr($name, 4),
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast', 'confidence' => 'certain',
                    'attributes' => [], 'owner_key' => 'php:file:src/Cycles.php',
                ];
            }
            foreach ([[$pair[0], $pair[1]], [$pair[1], $pair[0]]] as [$source, $target]) {
                $edges[] = [
                    'id' => StableId::edge($project, 'calls', $source, $target, 'cycle'), 'kind' => 'calls', 'source_id' => $source, 'target_id' => $target,
                    'file_id' => $ids['file'], 'start_line' => 1, 'end_line' => 1, 'origin' => 'ast', 'confidence' => 'certain',
                    'attributes' => [], 'owner_key' => 'php:file:src/Cycles.php',
                ];
            }
        }
        $repository->bulkTransaction(static function ($repository) use ($nodes, $edges, $project, $ids): void {
            $repository->saveNodes($nodes, $project, $ids['scan']);
            $repository->saveEdges($edges, $project, $ids['scan']);
        });
        $repository->completeScan($project, $ids['scan']);

        $health = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($project);

        assertSame(true, $health->data['bounds']['cycle_scan_truncated']);
        assertSame(true, in_array('cycle_scan', $health->data['bounds']['truncation_reasons'], true));
        assertSame(true, $health->truncated);
        assertSame(true, str_contains($health->summary, 'cycle_scan'), $health->summary);
    }

    /**
     * The degree aggregate finishes before its first row arrives, so a slow
     * one put the first deadline check already past the deadline: the slice
     * came back empty and so did every hub. The rows are computed by then, so
     * the first `limit` of them, which are the hubs, are kept regardless.
     */
    #[Group('health')]
    public function testAnAggregateThatOutrunsTheDeadlineStillYieldsTheTopHubs(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->hubGraph($repository, $ids);
        $reads = 0;
        $clock = static function () use (&$reads): int {
            return ++$reads === 1 ? 0 : PHP_INT_MAX >> 1;
        };

        $health = ArchitectureQueryService::forDatabase($pdo, $clock)->architectureHealth($ids['project'], limit: 3);

        assertSame(['App\\Zeta', 'App\\Aaa01', 'App\\Aaa02'], array_column(array_column($health->data['hubs'], 'component'), 'canonical_name'));
        assertSame(6, $health->data['hubs'][0]['metrics']['in_degree']);
        assertSame(true, in_array('time_limit', $health->data['bounds']['truncation_reasons'], true));
        assertSame(3, $health->data['bounds']['nodes_examined'], 'The rows after the first `limit` honour the deadline.');
    }

    /**
     * Degree is counted per end over the selected relationships, filtered by
     * confidence only when a minimum above `possible` is asked for. Pinned
     * against a plain count over the edge table for both cases.
     */
    #[Group('health')]
    public function testDegreesMatchACountOverTheSelectedEdgesAtEachMinimumConfidence(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $names = ['App\\N1', 'App\\N2', 'App\\N3', 'App\\N4'];
        $node = [];
        foreach ($names as $name) {
            $node[$name] = $id = StableId::symbol($project, 'php', 'class', $name);
            $repository->saveNode($id, $project, 'php', 'class', $name, substr($name, 4), null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/N.php', $ids['scan']);
        }
        $edges = [
            ['calls', 'App\\N1', 'App\\N2', 'certain'], ['calls', 'App\\N1', 'App\\N3', 'possible'], ['references', 'App\\N2', 'App\\N3', 'probable'],
            ['imports', 'App\\N4', 'App\\N3', 'possible'], ['contains', 'App\\N4', 'App\\N1', 'certain'], ['calls', 'App\\N3', 'App\\N3', 'certain'],
        ];
        foreach ($edges as $index => [$kind, $source, $target, $confidence]) {
            $repository->saveEdge(StableId::edge($project, $kind, $node[$source], $node[$target], 'mix' . $index), $project, $kind, $node[$source], $node[$target], $ids['file'], 1, 1, 'ast', $confidence, [], 'php:file:src/N.php', $ids['scan']);
        }
        $repository->completeScan($project, $ids['scan']);
        $queries = ArchitectureQueryService::forDatabase($pdo);

        foreach (['possible' => 1, 'probable' => 2] as $minimum => $rank) {
            $expected = [];
            foreach (['in_degree' => 'target_id', 'out_degree' => 'source_id'] as $metric => $column) {
                $count = $pdo->prepare(sprintf(
                    "SELECT n.canonical_name, COUNT(*) FROM edges e JOIN nodes n ON n.id = e.%s WHERE e.project_id = ? AND e.kind <> 'contains' " .
                    "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) GROUP BY n.canonical_name",
                    $column,
                ));
                $count->execute([$project, $rank]);
                foreach ($count->fetchAll(PDO::FETCH_KEY_PAIR) as $name => $degree) {
                    $expected[$name][$metric] = (int) $degree;
                }
            }
            $actual = [];
            foreach ($queries->architectureHealth($project, minConfidence: $minimum, limit: 100)->data['hubs'] as $hub) {
                $metrics = array_filter($hub['metrics'], static fn(int $value, string $key): bool => $value > 0 && in_array($key, ['in_degree', 'out_degree'], true), ARRAY_FILTER_USE_BOTH);
                $actual[$hub['component']['canonical_name']] = $metrics;
            }
            ksort($expected);
            ksort($actual);
            assertSame($expected, $actual, 'min_confidence ' . $minimum);
        }
    }

    /**
     * The node window counted external and test components that the ranking
     * drops afterwards, so a few busy vendor symbols could fill a small window
     * and leave no hub at all. Rankable components now come first.
     */
    #[Group('health')]
    public function testHighDegreeExternalsDoNotPushAnInternalHubOutOfTheWindow(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $save = static function (string $name, string $kind, string $origin) use ($repository, $ids, $project): string {
            $id = StableId::symbol($project, 'php', $kind, $name);
            $repository->saveNode($id, $project, 'php', $kind, $name, $name, null, $ids['file'], 1, 1, $origin, 'certain', [], 'php:file:src/X.php', $ids['scan']);
            return $id;
        };
        $hub = $save('App\\Hub', 'class', 'ast');
        $externals = [$save('Vendor\\Busy', 'external_class', 'external'), $save('Vendor\\Lost', 'class', 'unresolved')];
        // Each external is called six times, the hub four times; no caller has a degree above two.
        foreach (range(1, 10) as $index) {
            $caller = $save(sprintf('App\\Caller%02d', $index), 'class', 'ast');
            foreach ($index <= 4 ? [$hub] : $externals as $target) {
                $repository->saveEdge(StableId::edge($project, 'calls', $caller, $target, 'x'), $project, 'calls', $caller, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/X.php', $ids['scan']);
            }
        }
        $repository->completeScan($project, $ids['scan']);

        $health = ArchitectureQueryService::forDatabase($pdo)->architectureHealth($project, maxNodes: 2);

        assertSame('App\\Hub', $health->data['hubs'][0]['component']['canonical_name'] ?? null);
        assertSame(4, $health->data['hubs'][0]['metrics']['in_degree']);
        assertSame(0, $health->data['bounds']['excluded_external_components'], 'Externals sort after every rankable component, so a full window holds none.');
    }

    /**
     * App\Zeta called by App\Aaa01..06, and App\Aab1..3 with no edges.
     *
     * @param array<string, string> $ids
     */
    private function hubGraph(\Knossos\Store\GraphRepository $repository, array $ids): void
    {
        $project = $ids['project'];
        $class = static function (string $name) use ($repository, $ids, $project): string {
            $id = StableId::symbol($project, 'php', 'class', $name);
            $repository->saveNode($id, $project, 'php', 'class', $name, substr($name, 4), null, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Hub.php', $ids['scan']);
            return $id;
        };
        $zeta = $class('App\\Zeta');
        foreach (range(1, 6) as $index) {
            $caller = $class(sprintf('App\\Aaa%02d', $index));
            $repository->saveEdge(StableId::edge($project, 'calls', $caller, $zeta, 'hub'), $project, 'calls', $caller, $zeta, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Hub.php', $ids['scan']);
        }
        foreach (range(1, 3) as $index) {
            $class(sprintf('App\\Aab%d', $index));
        }
        $repository->completeScan($project, $ids['scan']);
    }
}
