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
        for ($i = 0; $i < 10; $i++) {
            $name = sprintf('Boundary%02d-%s', $i, str_repeat('x', 40));
            $boundary = StableId::boundary($ids['project'], $name, 'explicit');
            $repository->saveBoundary($boundary, $ids['project'], $name, ['path_prefix' => 'src'], 'explicit', $ids['scan']);
            $repository->saveBoundaryMembership($boundary, $ids['project'], $ids['checkout'], $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);
        $result = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'], 1000);
        assertSame(true, strlen($result->data['markdown']) <= 1000);
        assertSame(true, str_contains($result->data['markdown'], 'scan_project')); // closing line always kept
        assertSame(true, array_key_exists('omitted_sections', $result->data));
        assertSame(false, $result->data['omitted_sections'] === []);
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

    /**
     * The hub section already drops test-role components; the entry-point
     * section did not, so a console-command stub declared inside a test file
     * was offered to a fresh agent as one of twelve places execution enters the
     * system. This repository's own brief listed `FakeCliCommand` from
     * `tests/phpunit/InterfacesStubsTest.php` among its entry points.
     */
    #[Group('query')]
    public function testBriefEntryPointsExcludeTestRoleComponents(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $stub = StableId::symbol($ids['project'], 'php', 'class', 'App\\Tests\\FakeCommand');
        $repository->saveNode($stub, $ids['project'], 'php', 'class', 'App\\Tests\\FakeCommand', 'FakeCommand', null, $ids['file'], 40, 44, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        foreach (['application.command', 'quality.test_module'] as $role) {
            $repository->saveClassification(StableId::classification($ids['project'], $stub, $role, 'rule.' . $role), $ids['project'], $stub, $role, 'derived', 'probable', 'rule.' . $role, $ids['file'], 40, 44, [], $ids['scan']);
        }
        // A production command with the same role stays listed: the section is
        // about where execution enters, and dropping those would empty it.
        $real = StableId::symbol($ids['project'], 'php', 'class', 'App\\ShipCommand');
        $repository->saveNode($real, $ids['project'], 'php', 'class', 'App\\ShipCommand', 'ShipCommand', null, $ids['file'], 50, 54, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(StableId::classification($ids['project'], $real, 'application.command', 'rule.command'), $ids['project'], $real, 'application.command', 'derived', 'probable', 'rule.command', $ids['file'], 50, 54, [], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $markdown = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'])->data['markdown'];

        assertSame(false, str_contains($markdown, 'FakeCommand'));
        assertSame(true, str_contains($markdown, 'ShipCommand'));
    }

    /**
     * Excluding test components made an empty section reachable for the first
     * time: a project whose only command is a stub in a test file now matches
     * no rows at all. The section has to be omitted whole rather than rendered
     * as a bare heading over nothing.
     */
    #[Group('query')]
    public function testBriefOmitsTheEntryPointSectionWhenEveryCandidateIsTestCode(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $stub = StableId::symbol($ids['project'], 'php', 'class', 'App\\Tests\\OnlyFake');
        $repository->saveNode($stub, $ids['project'], 'php', 'class', 'App\\Tests\\OnlyFake', 'OnlyFake', null, $ids['file'], 40, 44, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        foreach (['application.command', 'quality.test_module'] as $role) {
            $repository->saveClassification(StableId::classification($ids['project'], $stub, $role, 'rule.' . $role), $ids['project'], $stub, $role, 'derived', 'probable', 'rule.' . $role, $ids['file'], 40, 44, [], $ids['scan']);
        }
        $repository->completeScan($ids['project'], $ids['scan']);

        $markdown = (new ArchitectureQueryService($pdo))->exportAgentBrief($ids['project'])->data['markdown'];

        assertSame(false, str_contains($markdown, '## Entry points'));
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
