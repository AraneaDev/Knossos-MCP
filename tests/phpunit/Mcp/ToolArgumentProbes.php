<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Throwable;

/**
 * Calls a tool with schema-derived arguments and reports how it answered, for
 * the tests that hold the advertised schema and the enforced limits together.
 */
trait ToolArgumentProbes
{
    /**
     * The message a call fails with, or null when it succeeds.
     *
     * @param array<string, mixed> $arguments
     */
    private static function errorFrom(ToolService $tools, string $name, array $arguments): ?string
    {
        try {
            $tools->call($name, $arguments);

            return null;
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        }
    }

    /**
     * The tool's required arguments, filled with values its handler accepts.
     *
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    private static function requiredArguments(array $definition, string $project): array
    {
        $arguments = [];
        foreach ((array) ($definition['inputSchema']['required'] ?? []) as $key) {
            $arguments[$key] = match ($key) {
                'project_id' => $project,
                'budgets' => ['new_cycles' => 0],
                'policies' => [['id' => 'p', 'from_boundary' => 'core', 'deny_targets' => ['tests']]],
                'files' => ['src/Checkout.php'],
                'path' => '/workspace/fixture-shop',
                'feature_description' => 'checkout refunds',
                'task_description' => 'add refunds',
                'action' => 'integrity',
                default => 'App\\Checkout',
            };
        }

        return $arguments;
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
