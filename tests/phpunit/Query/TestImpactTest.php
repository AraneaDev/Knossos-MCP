<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
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
     * It used to be handed straight to the underlying blast-radius scan, so a
     * caller who narrowed the result set also narrowed the candidate pool the
     * test roles are filtered out of. Production dependants that sort ahead of a
     * test file consumed the whole window and the tool answered "0 test files
     * statically exercise the change" — a false negative, not a truncation, for
     * the one tool whose output decides which tests get run.
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
        // "nothing found" from a bounded one.
        assertSame(2, $result->data['bounds']['limit']);
        assertSame(true, $result->data['bounds']['impacted_scan_limit'] > 2);
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
}
