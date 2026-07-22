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
