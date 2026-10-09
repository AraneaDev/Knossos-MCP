<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class TestImpactTest extends KnossosTestCase
{
    #[Group('query')]
    public function testFindsTestFilesInTheBlastRadius(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        // A test class in tests/CheckoutTest.php that calls App\Checkout.
        $testFile = StableId::file($ids['project'], 'tests/CheckoutTest.php');
        $repository->saveFile($testFile, $ids['project'], 'tests/CheckoutTest.php', hash('sha256', 'test source'), 80, 1, 'php', '0.1.0', $ids['scan']);
        $testClass = StableId::symbol($ids['project'], 'php', 'class', 'Tests\\CheckoutTest');
        $repository->saveNode($testClass, $ids['project'], 'php', 'class', 'Tests\\CheckoutTest', 'CheckoutTest', null, $testFile, 5, 30, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $testClass, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $testClass, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $testFile, 5, 30, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $testClass, $ids['checkout'], 'tests/CheckoutTest.php:12'), $ids['project'], 'calls', $testClass, $ids['checkout'], $testFile, 12, 12, 'ast', 'certain', [], $owner, $ids['scan']);
        // An unrelated production caller (must NOT appear in test_files).
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $result = $queries->testImpact($ids['project'], files: ['src/Checkout.php']);
        assertSame(['src/Checkout.php'], $result->data['changed_files']);
        assertSame(1, count($result->data['test_files']));
        assertSame('tests/CheckoutTest.php', $result->data['test_files'][0]['path']);
        assertSame(1, $result->data['test_files'][0]['distance']);
        assertSame(['CheckoutTest'], $result->data['test_files'][0]['via']);
        assertSame(true, str_contains(implode(' ', $result->warnings), 'lower bound'));
    }

    #[Group('query')]
    public function testChangedTestFileItselfIsDistanceZero(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        $testFile = StableId::file($ids['project'], 'tests/InvoiceTest.php');
        $repository->saveFile($testFile, $ids['project'], 'tests/InvoiceTest.php', hash('sha256', 't2'), 40, 1, 'php', '0.1.0', $ids['scan']);
        $testClass = StableId::symbol($ids['project'], 'php', 'class', 'Tests\\InvoiceTest');
        $repository->saveNode($testClass, $ids['project'], 'php', 'class', 'Tests\\InvoiceTest', 'InvoiceTest', null, $testFile, 3, 20, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $testClass, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $testClass, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $testFile, 3, 20, [], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['tests/InvoiceTest.php']);
        assertSame('tests/InvoiceTest.php', $result->data['test_files'][0]['path']);
        assertSame(0, $result->data['test_files'][0]['distance']);
    }

    /**
     * `limit` caps the answer, never the search.
     *
     * The test search walks every dependant within its own bounds and only
     * then cuts the list of test files to `limit`. Production callers that
     * sort ahead of the test class therefore cannot push it out of the
     * answer, and `bounds.max_visited` shows how wide the search may run
     * whatever `limit` the caller asked for.
     */
    #[Group('query')]
    public function testASmallLimitTruncatesTheAnswerWithoutShrinkingTheSearch(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        // Production callers whose canonical names sort ahead of the test class,
        // so a limit applied to the search would evict the test file entirely.
        foreach (range(1, 5) as $index) {
            $callerFile = StableId::file($ids['project'], sprintf('src/Caller%02d.php', $index));
            $repository->saveFile($callerFile, $ids['project'], sprintf('src/Caller%02d.php', $index), hash('sha256', 'c' . $index), 40, 1, 'php', '0.1.0', $ids['scan']);
            $caller = StableId::symbol($ids['project'], 'php', 'class', sprintf('App\\Caller%02d', $index));
            $repository->saveNode($caller, $ids['project'], 'php', 'class', sprintf('App\\Caller%02d', $index), sprintf('Caller%02d', $index), null, $callerFile, 1, 10, 'ast', 'certain', [], $owner, $ids['scan']);
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $caller, $ids['checkout'], sprintf('src/Caller%02d.php:5', $index)), $ids['project'], 'calls', $caller, $ids['checkout'], $callerFile, 5, 5, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $testFile = StableId::file($ids['project'], 'tests/ZebraTest.php');
        $repository->saveFile($testFile, $ids['project'], 'tests/ZebraTest.php', hash('sha256', 'z'), 80, 1, 'php', '0.1.0', $ids['scan']);
        $testClass = StableId::symbol($ids['project'], 'php', 'class', 'Tests\\ZebraTest');
        $repository->saveNode($testClass, $ids['project'], 'php', 'class', 'Tests\\ZebraTest', 'ZebraTest', null, $testFile, 5, 30, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $testClass, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $testClass, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $testFile, 5, 30, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $testClass, $ids['checkout'], 'tests/ZebraTest.php:12'), $ids['project'], 'calls', $testClass, $ids['checkout'], $testFile, 12, 12, 'ast', 'certain', [], $owner, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $result = $queries->testImpact($ids['project'], files: ['src/Checkout.php'], limit: 2);

        assertSame(['tests/ZebraTest.php'], array_column($result->data['test_files'], 'path'));
        // The search bound is reported separately, so a caller can tell a genuine
        // "nothing found" from a bounded one. It was `impacted_scan_limit` (100
        // dependants per changed component); the test search now has bounds of
        // its own, so the visit bound is what shows the search stayed wide.
        assertSame(2, $result->data['bounds']['limit']);
        assertSame(50_000, $result->data['bounds']['max_visited']);
    }

    #[Group('query')]
    public function testTheLimitStillCapsTheReturnedTestFiles(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $owner = 'php:file:src/Checkout.php';
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $path = sprintf('tests/%sTest.php', $name);
            $file = StableId::file($ids['project'], $path);
            $repository->saveFile($file, $ids['project'], $path, hash('sha256', $name), 60, 1, 'php', '0.1.0', $ids['scan']);
            $class = StableId::symbol($ids['project'], 'php', 'class', 'Tests\\' . $name . 'Test');
            $repository->saveNode($class, $ids['project'], 'php', 'class', 'Tests\\' . $name . 'Test', $name . 'Test', null, $file, 5, 30, 'ast', 'certain', [], $owner, $ids['scan']);
            $repository->saveClassification(StableId::classification($ids['project'], $class, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $class, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $file, 5, 30, [], $ids['scan']);
            $repository->saveEdge(StableId::edge($ids['project'], 'calls', $class, $ids['checkout'], $path . ':12'), $ids['project'], 'calls', $class, $ids['checkout'], $file, 12, 12, 'ast', 'certain', [], $owner, $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php'], limit: 2);

        assertSame(['tests/AlphaTest.php', 'tests/BetaTest.php'], array_column($result->data['test_files'], 'path'));
        assertSame(true, $result->truncated);
    }

    #[Group('query')]
    public function testDispatchThroughToolService(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('test_impact', ['project_id' => $ids['project'], 'files' => ['src/Checkout.php']]);
        assertSame(true, array_key_exists('test_files', $result->data));
    }

    /**
     * A hub's production dependants used to fill the whole search window, so a
     * test two hops away was never met and the answer read "0 test files".
     */
    #[Group('query')]
    public function testATestBehindAHubsProductionDependantsIsFound(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->hubWithATestBehindIt($repository, $ids);

        $result = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php']);

        self::assertSame([['path' => 'tests/HubTest.php', 'distance' => 2, 'via' => ['HubTest']]], $result->data['test_files']);
        self::assertFalse($result->truncated);
        self::assertSame('1 test file statically exercise the change.', $result->summary);
        self::assertSame([], $result->data['bounds']['truncation_reasons']);
        self::assertGreaterThanOrEqual(152, $result->data['bounds']['visited_nodes']);
        self::assertSame(1, $result->data['bounds']['test_files_found']);
        self::assertArrayNotHasKey('impacted_scan_limit', $result->data['bounds']);
        self::assertSame(
            ['max_files', 'max_direct_components', 'limit', 'max_depth', 'max_visited', 'max_edges', 'visited_nodes', 'edges_examined', 'test_files_found', 'truncation_reasons'],
            array_keys($result->data['bounds']),
        );
        self::assertStringContainsString('the test search is bounded; see bounds.truncation_reasons', implode(' ', $result->warnings));
    }

    #[Group('query')]
    public function testASearchCutByItsDeadlineSaysTruncatedInTheSummary(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $this->hubWithATestBehindIt($repository, $ids);
        $ticks = 0;
        $clock = static function () use (&$ticks): int {
            return ++$ticks * 2_000_000;
        };

        $result = (new ArchitectureQueryService($pdo, $clock))->testImpact($ids['project'], files: ['src/Checkout.php'], timeoutMs: 1);

        self::assertTrue($result->truncated);
        self::assertContains('time_limit', $result->data['bounds']['truncation_reasons']);
        self::assertStringContainsString('The search was truncated (time_limit), so test files beyond that bound are not listed.', $result->summary);
    }

    #[Group('query')]
    public function testAResultLimitSaysHowManyWereFound(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $test = $this->component($repository, $ids, sprintf('tests/%sTest.php', $name), sprintf('Tests\\%sTest', $name), test: true);
            $this->calls($repository, $ids, $test, $ids['checkout']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $result = (new ArchitectureQueryService($pdo))->testImpact($ids['project'], files: ['src/Checkout.php'], limit: 2);

        self::assertCount(2, $result->data['test_files']);
        self::assertSame(3, $result->data['bounds']['test_files_found']);
        self::assertSame(['result_limit'], $result->data['bounds']['truncation_reasons']);
        self::assertSame('2 test files statically exercise the change. The list was truncated to the first 2 of 3 test files.', $result->summary);
    }

    /**
     * Arguments are validated by test_impact itself now that it no longer
     * hands them to impact analysis.
     */
    #[Group('query')]
    public function testItRejectsArgumentsOutsideTheirRanges(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);
        $cases = [
            'max_depth must be between 1 and 8.' => static fn() => $queries->testImpact($ids['project'], files: ['src/Checkout.php'], maxDepth: 9),
            'Limit must be between 1 and 100.' => static fn() => $queries->testImpact($ids['project'], files: ['src/Checkout.php'], limit: 0),
            'timeout_ms must be between 1 and 5000.' => static fn() => $queries->testImpact($ids['project'], files: ['src/Checkout.php'], timeoutMs: 0),
            'min_confidence must be possible, probable, or certain.' => static fn() => $queries->testImpact($ids['project'], files: ['src/Checkout.php'], minConfidence: 'sure'),
            'edge_kinds contains an unsupported impact relationship.' => static fn() => $queries->testImpact($ids['project'], files: ['src/Checkout.php'], edgeKinds: ['contains']),
        ];
        foreach ($cases as $message => $call) {
            try {
                $call();
                self::fail('Expected rejection: ' . $message);
            } catch (\InvalidArgumentException $error) {
                self::assertSame($message, $error->getMessage());
            }
        }
    }

    /**
     * Checkout with 150 production callers and one test behind the last.
     *
     * @param array<string, string> $ids
     */
    private function hubWithATestBehindIt(GraphRepository $repository, array $ids): void
    {
        $callers = [];
        foreach (range(1, 150) as $index) {
            $callers[] = $caller = $this->component($repository, $ids, sprintf('src/Caller%03d.php', $index), sprintf('App\\Caller%03d', $index));
            $this->calls($repository, $ids, $caller, $ids['checkout']);
        }
        $test = $this->component($repository, $ids, 'tests/HubTest.php', 'Tests\\HubTest', test: true);
        $this->calls($repository, $ids, $test, $callers[149]);
        $repository->completeScan($ids['project'], $ids['scan']);
    }

    /**
     * A file holding one class, classified as test code when asked.
     *
     * @param array<string, string> $ids
     */
    private function component(GraphRepository $repository, array $ids, string $path, string $class, bool $test = false): string
    {
        $project = $ids['project'];
        $file = StableId::file($project, $path);
        $repository->saveFile($file, $project, $path, hash('sha256', $path), 40, 1, 'php', '0.1.0', $ids['scan']);
        $id = StableId::symbol($project, 'php', 'class', $class);
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $repository->saveNode($id, $project, 'php', 'class', $class, $short, null, $file, 5, 30, 'ast', 'certain', [], 'php:file:' . $path, $ids['scan']);
        if ($test) {
            $repository->saveClassification(StableId::classification($project, $id, 'quality.test_module', 'core.test.modules.v1'), $project, $id, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $file, 5, 30, [], $ids['scan']);
        }
        return $id;
    }

    /** @param array<string, string> $ids */
    private function calls(GraphRepository $repository, array $ids, string $source, string $target): void
    {
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $source, $target, 'impact'), $ids['project'], 'calls', $source, $target, $ids['file'], 1, 1, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
    }
}
