<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class HealthFiltersTest extends KnossosTestCase
{
    #[Group('query')]
    public function testHubsExcludeExternalAndTestNodesByDefault(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        // An external node referenced heavily (like the PHP builtin `count`).
        $external = StableId::symbol($ids['project'], 'php', 'external_function', 'count');
        $repository->saveNode($external, $ids['project'], 'php', 'external_function', 'count', 'count', null, $ids['file'], 1, 1, 'derived', 'possible', ['unresolved' => true], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['checkout'], $external, 'x:1'), $ids['project'], 'calls', $ids['checkout'], $external, $ids['file'], 5, 5, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        // A test-role helper called by production code (like assertSame).
        $helper = StableId::symbol($ids['project'], 'php', 'function', 'assertWidgets');
        $repository->saveNode($helper, $ids['project'], 'php', 'function', 'assertWidgets', 'assertWidgets', null, $ids['file'], 40, 44, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $helper, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $helper, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $ids['file'], 40, 44, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['invoice'], $helper, 'y:1'), $ids['project'], 'calls', $ids['invoice'], $helper, $ids['file'], 25, 25, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $default = $queries->architectureHealth($ids['project'])->data;
        $names = array_map(static fn(array $hub): string => $hub['component']['canonical_name'], $default['hubs']);
        assertSame(false, in_array('count', $names, true));
        assertSame(false, in_array('assertWidgets', $names, true));
        assertSame(1, $default['bounds']['excluded_external_components']);
        assertSame(1, $default['bounds']['excluded_test_components']);
        $hotspotNames = array_map(static fn(array $spot): string => $spot['component']['canonical_name'], $default['static_hotspots']);
        assertSame(false, in_array('count', $hotspotNames, true));

        $included = $queries->architectureHealth($ids['project'], includeExternal: true, includeTests: true)->data;
        $allNames = array_map(static fn(array $hub): string => $hub['component']['canonical_name'], $included['hubs']);
        assertSame(true, in_array('count', $allNames, true));
        assertSame(true, in_array('assertWidgets', $allNames, true));
        assertSame(0, $included['bounds']['excluded_external_components']);
    }

    #[Group('query')]
    public function testHealthFlagsPassThroughToolDispatch(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('architecture_health', ['project_id' => $ids['project'], 'include_external' => true, 'include_tests' => true]);
        assertSame(0, $result->data['bounds']['excluded_external_components']);
        assertThrows(fn() => $tools->call('architecture_health', ['project_id' => $ids['project'], 'include_tests' => 'yes']), \InvalidArgumentException::class);
    }
}
