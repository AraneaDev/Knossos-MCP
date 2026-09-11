<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\NextStepPlanner;
use Knossos\Mcp\ResultEnricher;
use Knossos\Mcp\ToolCatalog;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\StalenessProbe;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * Every integer bound a tool advertises is the bound it enforces.
 *
 * The same limits are written twice: once in `ToolCatalog`, which is what a
 * client and an agent read, and once as literals in each `ToolService`
 * handler, which is what actually refuses a request. Nothing compared the two,
 * so either copy could move without the other. A schema advertising
 * `limit <= 100` over a handler that refuses 100 is a contract the server
 * itself breaks.
 *
 * The probe values are taken from the schema, never restated here, so this
 * test states the rule ("advertised equals enforced") rather than today's
 * numbers, and a mutation of either copy breaks the agreement.
 */
final class ToolBoundsAgreementTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testEveryAdvertisedIntegerBoundIsTheOneEnforced(): void
    {
        [$tools, $project] = $this->tools();
        $checked = 0;

        foreach (ToolCatalog::definitions(false) as $definition) {
            $name = $definition['name'];
            foreach ((array) ($definition['inputSchema']['properties'] ?? []) as $key => $spec) {
                if (!is_array($spec) || ($spec['type'] ?? null) !== 'integer' || !isset($spec['minimum'], $spec['maximum'])) {
                    continue;
                }
                [$minimum, $maximum] = [(int) $spec['minimum'], (int) $spec['maximum']];
                $expected = sprintf('%s must be an integer between %d and %d.', $key, $minimum, $maximum);
                $base = self::requiredArguments($definition, $project);

                foreach ([$maximum + 1, $minimum - 1] as $outside) {
                    assertSame(
                        $expected,
                        self::errorFrom($tools, $name, [...$base, $key => $outside]),
                        sprintf('%s.%s = %d must be refused with the advertised bounds.', $name, $key, $outside),
                    );
                }
                foreach ([$maximum, $minimum] as $inside) {
                    assertNotSame(
                        $expected,
                        self::errorFrom($tools, $name, [...$base, $key => $inside]),
                        sprintf('%s.%s = %d is advertised as legal and must not be refused as out of range.', $name, $key, $inside),
                    );
                }
                ++$checked;
            }
        }

        // A guard against the test silently checking nothing if the schema's shape changes.
        assertSame(true, $checked >= 25, sprintf('Expected to check at least 25 bounded integers, checked %d.', $checked));
    }

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
