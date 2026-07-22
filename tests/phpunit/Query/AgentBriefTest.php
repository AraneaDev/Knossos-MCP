<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class AgentBriefTest extends KnossosTestCase
{
    #[Group('query')]
    public function testBriefIsDeterministicAndOriented(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $queries = new ArchitectureQueryService($pdo);

        $result = $queries->exportAgentBrief($ids['project']);
        $markdown = $result->data['markdown'];

        assertSame($markdown, $queries->exportAgentBrief($ids['project'])->data['markdown']); // deterministic
        assertSame(true, str_starts_with($markdown, '# Fixture Shop'));
        assertSame(true, str_contains($markdown, 'Backend'));
        assertSame(true, str_contains($markdown, 'Checkout'));
        assertSame(true, str_contains($markdown, 'scan_project')); // closing pointer
        assertSame([], $result->data['omitted_sections']);
        assertSame(true, strlen($markdown) <= 4000);
    }

    #[Group('query')]
    public function testBriefRespectsMaxCharsByOmittingWholeSections(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $result = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'], 1000);
        assertSame(true, strlen($result->data['markdown']) <= 1000);
        assertSame(true, str_contains($result->data['markdown'], 'scan_project')); // closing line always kept
        assertSame(true, array_key_exists('omitted_sections', $result->data));
    }

    #[Group('query')]
    public function testBriefHubsExcludeTestRoleComponents(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $helper = StableId::symbol($ids['project'], 'php', 'function', 'assertWidgets');
        $repository->saveNode($helper, $ids['project'], 'php', 'function', 'assertWidgets', 'assertWidgets', null, $ids['file'], 40, 44, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $helper, 'quality.test_module', 'core.test.modules.v1'), $ids['project'], $helper, 'quality.test_module', 'derived', 'probable', 'core.test.modules.v1', $ids['file'], 40, 44, [], $ids['scan']);
        $repository->saveEdge(StableId::edge($ids['project'], 'calls', $ids['invoice'], $helper, 'h1'), $ids['project'], 'calls', $ids['invoice'], $helper, $ids['file'], 25, 25, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $markdown = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'])->data['markdown'];
        assertSame(false, str_contains($markdown, 'assertWidgets'));
    }

    #[Group('query')]
    public function testBriefDispatchesThroughToolService(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new \Knossos\Mcp\ToolService(
            new \Knossos\Scan\ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new \Knossos\Maintenance\DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $result = $tools->call('export_agent_brief', ['project_id' => $ids['project'], 'max_chars' => 1500]);
        assertSame(true, strlen($result->data['markdown']) <= 1500);
    }
}
