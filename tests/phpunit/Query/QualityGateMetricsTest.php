<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ReportableComponent;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The quality-gate budgets are only usable if they count what `architecture_health`
 * ranks. A user reads "25 unreferenced candidates" off the health report, sets a
 * budget near it, and the gate then answers with a number that also counts every
 * unreferenced test method and constructor in the tree — orders of magnitude
 * higher, and impossible to hold to any budget.
 */
final class QualityGateMetricsTest extends KnossosTestCase
{
    #[Group('query')]
    public function testUnreferencedCandidatesExcludeTheSameComponentsHealthExcludes(): void
    {
        [$pdo, $repository, $ids] = $this->baseline();
        // One genuinely unreferenced production class, plus the three shapes
        // health never reports: a test method, a constructor, and an external.
        $this->addNode($repository, $ids, 'class', 'App\\OrphanService', 'OrphanService');
        $testMethod = $this->addNode($repository, $ids, 'method', 'Tests\\CheckoutTest::testItCharges', 'testItCharges');
        $this->classify($repository, $ids, $testMethod, ReportableComponent::TEST_ROLE);
        $this->addNode($repository, $ids, 'method', 'App\\Checkout::__construct', '__construct');
        $this->addNode($repository, $ids, 'external_class', 'Vendor\\Client', 'Client', origin: 'external');
        // A controller is reached by routing, so in-degree 0 is structural —
        // architecture_health does not report it and neither may the gate.
        $controller = $this->addNode($repository, $ids, 'class', 'App\\Http\\OrderController', 'OrderController');
        $this->classify($repository, $ids, $controller, 'laravel.controller');
        $repository->completeScan($ids['project'], $ids['scan']);

        $gate = (new ArchitectureQueryService($pdo))
            ->qualityGate($ids['project'], $ids['baseline'], ['unreferenced_candidates' => 100]);

        // App\Checkout is called by nothing either, so the reportable set is it
        // plus OrphanService — never the test method, constructor, or external.
        assertSame(2, $gate->data['metrics']['unreferenced_candidates']);
    }

    /**
     * A script's body is entered from outside the graph, so its module has no
     * inbound edge however heavily the script is used. `architecture_health`
     * stopped reporting those; the gate went on counting them, so this
     * repository's own budget carried ten entry scripts that no maintainer
     * could ever act on and no amount of cleanup could reclaim.
     */
    #[Group('query')]
    public function testUnreferencedCandidatesExcludeExecutableScriptModules(): void
    {
        [$pdo, $repository, $ids] = $this->baseline();
        $this->addNode($repository, $ids, 'module', 'bin/console', 'console', attributes: ['executable' => true]);
        // An orphaned library module is exactly what the budget is for.
        $this->addNode($repository, $ids, 'module', 'src/orphan.ts', 'orphan.ts');
        $repository->completeScan($ids['project'], $ids['scan']);

        $gate = (new ArchitectureQueryService($pdo))
            ->qualityGate($ids['project'], $ids['baseline'], ['unreferenced_candidates' => 100]);

        // App\Checkout from the baseline plus the orphan module — never the script.
        assertSame(2, $gate->data['metrics']['unreferenced_candidates']);
    }

    /**
     * The exclusion is for a *module* a scanner marked executable, and the kind
     * half of that guard carries the weight: the predicate is public, both call
     * sites hand it every node kind they walk, and a scanner or an imported
     * bundle is free to put the attribute anywhere. Without this case, swapping
     * the guard's `||` for `&&` — or dropping its `return` outright — let a
     * class carrying the attribute pass as a script, and both mutants survived.
     */
    #[Group('query')]
    public function testUnreferencedCandidatesStillCountANonModuleCarryingAnExecutableAttribute(): void
    {
        [$pdo, $repository, $ids] = $this->baseline();
        $this->addNode($repository, $ids, 'class', 'App\\Orphan', 'Orphan', attributes: ['executable' => true]);
        $repository->completeScan($ids['project'], $ids['scan']);

        $gate = (new ArchitectureQueryService($pdo))
            ->qualityGate($ids['project'], $ids['baseline'], ['unreferenced_candidates' => 100]);

        // App\Checkout from the baseline plus the class: only a module can be a script.
        assertSame(2, $gate->data['metrics']['unreferenced_candidates']);
    }

    #[Group('query')]
    public function testHubDegreeIgnoresTestOnlyHubsSoAddingTestsIsNotAnArchitectureRegression(): void
    {
        [$pdo, $repository, $ids] = $this->baseline();
        // A shared assertion helper every test calls is the highest-degree node
        // in most repositories, which made `hub_degree_growth: 0` fail on any
        // commit that added a test.
        $helper = $this->addNode($repository, $ids, 'function', 'Tests\\assertSame', 'assertSame');
        $this->classify($repository, $ids, $helper, ReportableComponent::TEST_ROLE);
        for ($i = 0; $i < 12; $i++) {
            $caller = $this->addNode($repository, $ids, 'method', sprintf('Tests\\CheckoutTest::test%d', $i), sprintf('test%d', $i));
            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $caller, $helper, 'tests/CheckoutTest.php:' . $i),
                $ids['project'],
                'calls',
                $caller,
                $helper,
                $ids['file'],
                $i + 1,
                $i + 1,
                'ast',
                'certain',
                [],
                'php:file:tests/CheckoutTest.php',
                $ids['scan'],
            );
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $gate = (new ArchitectureQueryService($pdo))
            ->qualityGate($ids['project'], $ids['baseline'], ['hub_degree_growth' => 0]);

        // The production graph's busiest node still has degree 1 (Checkout ->
        // Invoice), so nothing about the architecture grew and the budget holds.
        assertSame(0, $gate->data['metrics']['hub_degree_growth']);
        assertSame(true, $gate->data['passed']);
    }

    /**
     * Give one node a role, the way the classifier does during a scan.
     *
     * @param array<string, string> $ids
     */
    private function classify(SqliteGraphRepository $repository, array $ids, string $nodeId, string $role): void
    {
        $repository->saveClassification(
            StableId::classification($ids['project'], $nodeId, $role, 'core.naming.roles.v1'),
            $ids['project'],
            $nodeId,
            $role,
            'derived',
            'certain',
            'core.naming.roles.v1',
            $ids['file'],
            1,
            2,
            [],
            $ids['scan'],
        );
    }

    /**
     * The two-scan setup the gate needs: an archived baseline snapshot, and a
     * second scan left open for the caller to add the components under test to.
     *
     * @return array{0: \PDO, 1: SqliteGraphRepository, 2: array<string, string>}
     */
    private function baseline(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $ids['baseline'] = $ids['scan'];
        $ids['scan'] = StableId::scan($ids['project'], 'scan-2');
        $repository->createScan($ids['scan'], $ids['project'], 'incremental', hash('sha256', 'scanner-set'));

        return [$pdo, $repository, $ids];
    }

    /**
     * Add one node to the fixture graph and return its id.
     *
     * @param array<string, string> $ids
     */
    private function addNode(
        SqliteGraphRepository $repository,
        array $ids,
        string $kind,
        string $canonicalName,
        string $displayName,
        string $origin = 'ast',
        array $attributes = [],
    ): string {
        $id = StableId::symbol($ids['project'], 'php', $kind, $canonicalName);
        $repository->saveNode(
            $id,
            $ids['project'],
            'php',
            $kind,
            $canonicalName,
            $displayName,
            null,
            $ids['file'],
            1,
            2,
            $origin,
            'certain',
            $attributes,
            'php:file:src/Checkout.php',
            $ids['scan'],
        );

        return $id;
    }
}
