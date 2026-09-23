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
        // The node bound limits the hub ranking, not the candidates, and the
        // summary says which.
        self::assertStringContainsString('so hubs and hotspots beyond that bound are not reported', $result->summary);
        self::assertStringNotContainsString('candidate search ran out of time', $result->summary);

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

    public function testPagesFollowReportOrderWhenAContainerTurnsTestOnly(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $repository->saveEdge(StableId::edge($project, 'calls', $ids['invoice'], $ids['checkout'], 'back:1'), $project, 'calls', $ids['invoice'], $ids['checkout'], $ids['file'], 20, 20, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $node = function (string $kind, string $name, int $line) use ($repository, $project, $ids): string {
            $id = StableId::symbol($project, 'php', $kind, $name);
            $repository->saveNode($id, $project, 'php', $kind, $name, $name, null, $ids['file'], $line, $line, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);

            return $id;
        };
        $test = $node('class', 'Tests\\BoxTest', 1);
        $repository->saveClassification(StableId::classification($project, $test, 'quality.test_module', 'core.test.modules.v1'), $project, $test, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $ids['file'], 1, 1, [], $ids['scan']);
        $calledByTest = function (string $target) use ($repository, $project, $ids, $test): void {
            $repository->saveEdge(StableId::edge($project, 'calls', $test, $target, $target), $project, 'calls', $test, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        };
        // Nothing references the class itself, so it arrives with the
        // unreferenced nodes, ahead of the functions only tests call though
        // it sorts after them, and becomes test-only because only a test
        // calls its member.
        $box = $node('class', 'App\\Box', 3);
        $run = $node('method', 'App\\Box::run', 4);
        $repository->saveEdge(StableId::edge($project, 'contains', $box, $run, 'box'), $project, 'contains', $box, $run, $ids['file'], 4, 4, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $calledByTest($run);
        foreach (['App\\A0', 'App\\A1', 'App\\A2', 'App\\A3'] as $line => $name) {
            $calledByTest($node('function', $name, 10 + $line));
        }
        $repository->completeScan($project, $ids['scan']);

        $queries = new ArchitectureQueryService($pdo);
        $all = $queries->architectureHealth($project, limit: 100)->data;
        $entries = array_map(static fn(array $c): array => [$c['reachability'], $c['component']['canonical_name']], $all['dead_code_candidates']);
        self::assertSame([
            ['test_only', 'App\\A0'], ['test_only', 'App\\A1'], ['test_only', 'App\\A2'], ['test_only', 'App\\A3'],
            ['test_only', 'App\\Box'], ['test_only', 'App\\Box::run'],
        ], $entries);
        self::assertSame(6, $all['bounds']['candidates_total']);
        self::assertSame(['kind' => 'class', 'display_name' => 'App\\Box'], array_intersect_key($all['dead_code_candidates'][4]['component'], ['kind' => 1, 'display_name' => 1]));

        self::assertSame([], $all['bounds']['candidate_truncation_reasons']);
        // One at a time, each page holds what the full list holds there, and
        // carries the whole component.
        foreach ($all['dead_code_candidates'] as $offset => $expected) {
            $page = $queries->architectureHealth($project, limit: 1, candidateOffset: $offset)->data;
            self::assertSame([$expected], $page['dead_code_candidates'], (string) $offset);
            self::assertSame(6, $page['bounds']['candidates_total']);
        }
    }

    public function testAPageShortOfTheCandidatesIsNotAShortRanking(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $repository->saveEdge(StableId::edge($project, 'calls', $ids['invoice'], $ids['checkout'], 'back:1'), $project, 'calls', $ids['invoice'], $ids['checkout'], $ids['file'], 20, 20, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        foreach (['App\\deadA', 'App\\deadB', 'App\\deadC', 'App\\deadD'] as $line => $name) {
            $repository->saveNode(StableId::symbol($project, 'php', 'function', $name), $project, 'php', 'function', $name, $name, null, $ids['file'], 30 + $line, 30 + $line, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $repository->completeScan($project, $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->architectureHealth($project, limit: 2);

        // The candidates report their own page: truncation_reasons describe
        // the hub ranking, which fits the limit.
        self::assertSame(['result_limit'], $result->data['bounds']['candidate_truncation_reasons']);
        self::assertTrue($result->data['bounds']['candidates_truncated']);
        self::assertSame([], $result->data['bounds']['truncation_reasons']);
        self::assertFalse($result->truncated);
        self::assertStringContainsString('More candidates follow this page', $result->summary);
        self::assertStringNotContainsString('The ranking was truncated', $result->summary);
    }

    public function testTheCandidateBudgetIsCheckedAndReported(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $time = 0;
        // Every reading of the clock moves it 2 ms on, so a 1 ms budget runs
        // out before the first query.
        $queries = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;

            return $time;
        });

        $result = $queries->architectureHealth($ids['project'], limit: 100, candidateTimeoutMs: 1);
        $data = $result->data;

        // The summary an agent reads first says the list is partial.
        self::assertStringContainsString('The candidate search ran out of time, so the candidate list is partial.', $result->summary);

        self::assertTrue($data['bounds']['candidates_truncated']);
        self::assertSame(['time_limit'], $data['bounds']['candidate_truncation_reasons']);
        self::assertSame(1, $data['bounds']['candidate_timeout_ms']);
        self::assertSame(count($data['dead_code_candidates']), $data['bounds']['candidates_total']);
    }

    /**
     * A budget that runs out between chunks keeps the chunks already
     * classified: the reader gets a partial list that says it is partial.
     */
    public function testABudgetSpentMidwayKeepsWhatWasClassified(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $pdo->beginTransaction();
        for ($index = 0; $index < 700; ++$index) {
            $name = sprintf('src/f.ts#unused%03d', $index);
            $repository->saveNode(StableId::symbol($project, 'ts', 'function', $name), $project, 'ts', 'function', $name, sprintf('unused%03d', $index), null, $ids['file'], 1, 2, 'ast', 'certain', [], 'ts:file:src/f.ts', $ids['scan']);
        }
        $pdo->commit();
        $repository->completeScan($project, $ids['scan']);
        $time = 0;
        // 2 ms per reading of the clock against a 7 ms budget: the checks
        // before the two queries and before the first chunk pass, and the one
        // before the second chunk of 500 fails.
        $queries = new ArchitectureQueryService($pdo, function () use (&$time): int {
            $time += 2_000_000;

            return $time;
        });

        $data = $queries->architectureHealth($project, limit: 100, candidateTimeoutMs: 7)->data;

        self::assertTrue($data['bounds']['candidates_truncated']);
        self::assertGreaterThanOrEqual(400, $data['bounds']['candidates_total']);
        self::assertLessThan(700, $data['bounds']['candidates_total']);
        self::assertCount(100, $data['dead_code_candidates']);
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

    /**
     * A large graph whose nodes are nearly all candidates, found inside the
     * default budget.
     *
     * The worst case is many unreferenced containers with members: each one
     * asks whether a member is reached from outside it. The store has no
     * planner statistics, as a freshly scanned one does not, so the queries
     * must choose their indexes without them.
     */
    public function testTwentyFiveThousandContainersWithMembersFitTheDefaultBudget(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $project = $ids['project'];
        $pdo->beginTransaction();
        for ($index = 0; $index < 25_000; ++$index) {
            $class = sprintf('App\\Unused%05d', $index);
            $classId = StableId::symbol($project, 'php', 'class', $class);
            $methodId = StableId::symbol($project, 'php', 'method', $class . '::run');
            $repository->saveNode($classId, $project, 'php', 'class', $class, sprintf('Unused%05d', $index), null, $ids['file'], 1, 9, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveNode($methodId, $project, 'php', 'method', $class . '::run', 'run', null, $ids['file'], 2, 8, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
            $repository->saveEdge(StableId::edge($project, 'contains', $classId, $methodId, (string) $index), $project, 'contains', $classId, $methodId, $ids['file'], 2, 2, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        }
        $pdo->commit();
        $repository->completeScan($project, $ids['scan']);

        // Inside PHP's default 128 MB, as the server runs in its image: holding
        // every candidate at once exhausted it, so only the page is kept.
        $limit = ini_set('memory_limit', '128M');
        try {
            $started = hrtime(true);
            $data = (new ArchitectureQueryService($pdo))->architectureHealth($project, limit: 10)->data;
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;
        } finally {
            ini_set('memory_limit', (string) $limit);
        }

        self::assertSame(['result_limit'], $data['bounds']['candidate_truncation_reasons']);
        self::assertGreaterThan(50_000, $data['bounds']['candidates_total']);
        self::assertLessThan(5_000, $elapsedMs);
    }
}
