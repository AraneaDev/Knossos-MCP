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
     * Every default a tool advertises is the default its handler applies.
     *
     * The same defaults are written twice, exactly as the bounds above are:
     * once in `ToolCatalog`, which is what a client reads and fills in, and once
     * as a literal in the `ToolService` handler, which is what a call actually
     * uses when the argument is absent. A schema promising `limit` defaults to
     * 50 over a handler that quietly applies 20 misleads every caller that
     * trusted the schema and omitted the key.
     *
     * Stated as a rule rather than a table: omitting an argument must produce
     * what passing its advertised default produces. The values come from the
     * schema and are never restated here, so moving either copy breaks it.
     *
     * Both calls of a pair share one store. A scan stamps itself with the wall
     * clock and with a fresh id, so two fixtures built a second apart differ in
     * ways that have nothing to do with the default under test. Comparing across
     * two fixtures made this test flaky, and that flakiness broke a mutation
     * audit's initial run before it was caught here. Sharing is safe in the
     * direction that matters: a correct `execute => false` default means neither
     * call writes anything, and when that default is wrong, the write the
     * omitted call performs is precisely what makes the two disagree.
     */
    #[Group('mcp')]
    public function testEveryAdvertisedDefaultIsTheOneApplied(): void
    {
        $checked = 0;

        foreach (ToolCatalog::definitions(false) as $definition) {
            $name = $definition['name'];
            foreach ((array) ($definition['inputSchema']['properties'] ?? []) as $key => $spec) {
                if (!is_array($spec) || !array_key_exists('default', $spec)) {
                    continue;
                }
                if (!in_array($spec['type'] ?? null, ['integer', 'boolean'], true)) {
                    continue;
                }
                [$tools, $project] = $this->tools();
                assertSame(
                    self::outcomeOf($tools, $project, $name, $definition, (string) $key, $spec['default']),
                    self::outcomeOf($tools, $project, $name, $definition, (string) $key, null),
                    sprintf(
                        '%s.%s: omitting the argument must do what its advertised default (%s) does.',
                        $name,
                        $key,
                        var_export($spec['default'], true),
                    ),
                );
                ++$checked;
            }
        }

        // A guard against the test silently checking nothing if the schema's shape changes.
        assertSame(true, $checked >= 100, sprintf('Expected to check at least 100 advertised defaults, checked %d.', $checked));
    }

    /**
     * Maintenance that writes previews by default, and only acts when told to.
     *
     * The loop above cannot see this one. It probes `maintain_database` with the
     * action its schema requires, `integrity`, which reads the database and
     * changes nothing whichever way `execute` falls, so both arms agree and the
     * default is invisible. Every other action does write, and there the default
     * is the whole safety margin: an agent calling `maintain_database` to find
     * out what a vacuum would do must not thereby vacuum the database.
     *
     * Both arms are asserted, because a preview is only meaningful if the other
     * arm genuinely does something.
     */
    #[Group('mcp')]
    public function testMaintenanceThatWritesPreviewsUnlessExecuteIsAskedFor(): void
    {
        [$tools] = $this->tools();

        $preview = $tools->call('maintain_database', ['action' => 'vacuum']);

        assertSame('vacuum', $preview->data['action']);
        assertSame(false, $preview->data['executed'], 'Omitting execute must not perform maintenance.');
        assertSame(true, str_starts_with($preview->summary, 'Dry run:'));
        assertSame(false, array_key_exists('freed_bytes', $preview->data), 'A dry run reports no reclaimed bytes, because it reclaimed none.');
        assertSame(['Set execute=true to perform this maintenance action.'], $preview->warnings);

        $performed = $tools->call('maintain_database', ['action' => 'vacuum', 'execute' => true]);

        assertSame(true, $performed->data['executed']);
        assertSame(true, array_key_exists('freed_bytes', $performed->data));
        assertSame([], $performed->warnings);
    }

    /**
     * What one call produces: the data it returns, or the error it fails with.
     * `null` omits the argument entirely.
     *
     * Wall-clock stamps are blanked before comparing. A tool that reports when a
     * scan finished would otherwise differ between two calls made either side of
     * a second boundary, which says nothing about the default under test.
     *
     * @param array<string, mixed> $definition
     */
    private static function outcomeOf(ToolService $tools, string $project, string $name, array $definition, string $key, mixed $value): string
    {
        $arguments = self::requiredArguments($definition, $project);
        if ($value !== null) {
            $arguments[$key] = $value;
        }
        try {
            $encoded = json_encode($tools->call($name, $arguments)->data, JSON_THROW_ON_ERROR);
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        }

        return preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', '<time>', $encoded) ?? $encoded;
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
