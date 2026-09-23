<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Dead-code candidates are decided over the whole project. The hub ranking
 * reads a bounded window of nodes in canonical-name order; a candidate past
 * that window was never reported, and one inside it was classified from facts
 * the window happened to hold.
 */
#[Group('query')]
final class WholeProjectCandidatesTest extends KnossosTestCase
{
    public function testAnUntypedCallPastTheWindowStillDemotes(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $method = StableId::symbol($project, 'ts', 'method', 'src/a.ts#Mode::label');
        $repository->saveNode($method, $project, 'ts', 'method', 'src/a.ts#Mode::label', 'label', null, $ids['file'], 3, 4, 'ast', 'certain', [], 'ts:file:src/a.ts', $ids['scan']);
        // Sorts after everything else, so a small window never reads it.
        $loop = StableId::symbol($project, 'ts', 'module', 'zzz/loop.js');
        $repository->saveNode($loop, $project, 'ts', 'module', 'zzz/loop.js', 'loop.js', null, $ids['file'], 1, 9, 'ast', 'certain', ['executable' => true, 'unresolved_member_calls' => ['label']], 'ts:file:zzz/loop.js', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($project, limit: 100, maxNodes: 3)->data;

        $confidence = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $confidence[$candidate['component']['canonical_name']] = $candidate['confidence'];
        }
        self::assertSame('possible', $confidence['src/a.ts#Mode::label'] ?? null);
    }

    public function testADeadFunctionPastTheWindowIsReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $dead = StableId::symbol($project, 'ts', 'function', 'zzz/late.ts#unused');
        $repository->saveNode($dead, $project, 'ts', 'function', 'zzz/late.ts#unused', 'unused', null, $ids['file'], 7, 9, 'ast', 'certain', [], 'ts:file:zzz/late.ts', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->architectureHealth($project, limit: 100, maxNodes: 1);

        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $result->data['dead_code_candidates']);
        self::assertContains('zzz/late.ts#unused', $names);
        // Its evidence is reported though the window never read the node.
        self::assertContains(['component_id' => $dead, 'path' => 'src/Checkout.php', 'start_line' => 7, 'end_line' => 9], $result->evidence);
    }

    public function testConventionAndTestOnlyNodesPastTheWindowAreCounted(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $controller = StableId::symbol($project, 'php', 'class', 'Zzz\\HomeController');
        $repository->saveNode($controller, $project, 'php', 'class', 'Zzz\\HomeController', 'HomeController', null, $ids['file'], 1, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($project, $controller, 'laravel.controller', 'laravel.path.v1'), $project, $controller, 'laravel.controller', 'framework_convention', 'probable', 'laravel.path.v1', $ids['file'], 1, 5, [], $ids['scan']);
        $helper = StableId::symbol($project, 'php', 'function', 'Zzz\\onlyTested');
        $test = StableId::symbol($project, 'php', 'class', 'Zzz\\HelperTest');
        $repository->saveNode($helper, $project, 'php', 'function', 'Zzz\\onlyTested', 'onlyTested', null, $ids['file'], 10, 12, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveNode($test, $project, 'php', 'class', 'Zzz\\HelperTest', 'HelperTest', null, $ids['file'], 20, 30, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($project, $test, 'quality.test_module', 'core.test.modules.v1'), $project, $test, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $ids['file'], 20, 30, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($project, 'calls', $test, $helper, 't:1'), $project, 'calls', $test, $helper, $ids['file'], 25, 25, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        $queries = new ArchitectureQueryService($pdo);
        $data = $queries->architectureHealth($project, limit: 100, maxNodes: 1)->data;

        $reachability = [];
        foreach ($data['dead_code_candidates'] as $candidate) {
            $reachability[$candidate['component']['canonical_name']] = $candidate['reachability'];
        }
        self::assertSame('test_only', $reachability['Zzz\\onlyTested'] ?? null);
        // The controller, and the test class, whose test role is itself a
        // convention: nothing references either, and neither is reported.
        self::assertSame(2, $data['bounds']['excluded_convention_discovered']);
        self::assertFalse($data['bounds']['candidates_truncated']);

        // With tests counted as architecture, the test caller makes it live.
        $included = $queries->architectureHealth($project, limit: 100, maxNodes: 1, includeTests: true)->data;
        $names = array_map(static fn(array $c): string => $c['component']['canonical_name'], $included['dead_code_candidates']);
        self::assertNotContains('Zzz\\onlyTested', $names);
    }

    public function testAProjectWithNoCandidatesReportsNone(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        // The fixture's checkout calls the invoice service; a call back makes
        // every node referenced.
        $repository->saveEdge(StableId::edge($project, 'calls', $ids['invoice'], $ids['checkout'], 'back:1'), $project, 'calls', $ids['invoice'], $ids['checkout'], $ids['file'], 20, 20, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($project, $ids['scan']);

        $data = (new ArchitectureQueryService($pdo))->architectureHealth($project, limit: 100)->data;

        self::assertSame([], $data['dead_code_candidates']);
        self::assertFalse($data['bounds']['candidates_truncated']);
    }

    public function testTheCandidateBudgetIsCheckedAndReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $time = 0;
        // Every reading of the clock moves it 2 ms on, so a 1 ms budget runs
        // out before the first stage after the unreferenced query.
        $queries = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;

            return $time;
        });

        $data = $queries->architectureHealth($ids['project'], limit: 100, candidateTimeoutMs: 1)->data;

        self::assertTrue($data['bounds']['candidates_truncated']);
        self::assertSame(['time_limit'], $data['bounds']['candidate_truncation_reasons']);
        self::assertSame(1, $data['bounds']['candidate_timeout_ms']);
        self::assertSame(count($data['dead_code_candidates']), $data['bounds']['candidates_total']);
    }

    public function testTheCandidateBudgetRejectsValuesOutsideItsRange(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        foreach ([0, 60_001] as $timeout) {
            $error = captureThrows(fn() => $queries->architectureHealth($ids['project'], candidateTimeoutMs: $timeout), \InvalidArgumentException::class);
            self::assertSame('candidate_timeout_ms must be between 1 and 60000.', $error->getMessage());
        }
    }

    /** A large graph's candidates are found inside the default budget. */
    public function testFiftyThousandNodesFitTheDefaultBudget(): void
    {
        [$pdo, $projectId] = $this->seedGraphWithEdges(50_000);

        $started = hrtime(true);
        $data = (new ArchitectureQueryService($pdo))->architectureHealth($projectId, limit: 10)->data;
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        self::assertFalse($data['bounds']['candidates_truncated']);
        self::assertLessThan(5_000, $elapsedMs);
    }
}
