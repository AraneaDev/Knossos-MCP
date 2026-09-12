<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * What a tool call refuses before it reaches a handler, and what it says.
 *
 * ToolService scored 73% under mutation testing. Beyond the argument defaults
 * pinned elsewhere, what survived was the common request handling every tool
 * shares: the verbosity check, the missing- and unknown-argument reports, and
 * the arm that catches a tool nobody offers. Each is a message an agent reads to
 * correct its own call, so the message is the contract.
 */
final class ToolServiceInputGuardsTest extends KnossosTestCase
{
    /** Verbosity is accepted as exactly the two values advertised, whitespace aside. */
    #[Group('mcp')]
    public function testVerbosityIsAcceptedOnlyAsCompactOrFull(): void
    {
        [$tools, $project] = $this->tools();

        foreach (['compact', 'full', '  full  '] as $accepted) {
            $result = $tools->call('architecture_summary', ['project_id' => $project, 'verbosity' => $accepted]);
            assertSame($project, $result->projectId, $accepted);
        }

        foreach (['brief', 'COMPACT', 'Full', ''] as $refused) {
            $error = captureThrows(
                fn() => $tools->call('architecture_summary', ['project_id' => $project, 'verbosity' => $refused]),
                Throwable::class,
            );
            assertSame('verbosity must be "compact" or "full".', $error->getMessage(), var_export($refused, true));
        }
    }

    /** A missing required argument is named, so the caller knows which one to add. */
    #[Group('mcp')]
    public function testAMissingRequiredArgumentIsNamed(): void
    {
        [$tools, $project] = $this->tools();

        $error = captureThrows(
            fn() => $tools->call('find_component', ['project_id' => $project]),
            Throwable::class,
        );

        assertSame('Missing required argument: name', $error->getMessage());
    }

    /** An argument no tool declares is named rather than ignored. */
    #[Group('mcp')]
    public function testAnUndeclaredArgumentIsNamed(): void
    {
        [$tools, $project] = $this->tools();

        $error = captureThrows(
            fn() => $tools->call('architecture_summary', ['project_id' => $project, 'limitt' => 5]),
            Throwable::class,
        );

        assertSame('Unknown argument: limitt', $error->getMessage(), 'A typo is reported as the typo, not as a missing value.');
    }

    /** A tool nobody offers is refused by name. */
    #[Group('mcp')]
    public function testAToolNobodyOffersIsRefusedByName(): void
    {
        [$tools, $project] = $this->tools();

        $error = captureThrows(
            fn() => $tools->call('summon_architecture', ['project_id' => $project]),
            Throwable::class,
        );

        assertSame(true, str_contains($error->getMessage(), 'summon_architecture'), $error->getMessage());
    }

    /** A malformed request is refused before any rescan it asked for can run. */
    #[Group('mcp')]
    public function testRefreshIfStaleMustBeABoolean(): void
    {
        [$tools, $project] = $this->tools();

        $error = captureThrows(
            fn() => $tools->call('architecture_summary', ['project_id' => $project, 'refresh_if_stale' => 'yes']),
            Throwable::class,
        );

        assertSame('refresh_if_stale must be a boolean.', $error->getMessage());
    }

    /** @return array{0: ToolService, 1: string} */
    private function tools(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);

        return [
            new ToolService(
                new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
                new ArchitectureQueryService($pdo),
                new DatabaseMaintenanceService($pdo, ':memory:'),
                new ResultEnricher(new StalenessProbe($pdo), new NextStepPlanner()),
            ),
            $ids['project'],
        ];
    }
}
