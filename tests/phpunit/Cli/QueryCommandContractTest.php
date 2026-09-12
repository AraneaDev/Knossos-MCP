<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Mcp\ToolCatalog;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * The CLI's argument contract, checked across every query command at once.
 *
 * Two gaps let most of QueryCommand's mutants survive. The positional guards
 * were tested only by exception class, and each command has several guards
 * throwing the same class, so deleting the first one left the second to throw
 * an equally acceptable exception. And the CLI carries its own copy of every
 * integer bound, a third copy after the MCP schema and ToolService, which
 * nothing compared with the other two.
 */
final class QueryCommandContractTest extends KnossosTestCase
{
    /** Every query command, as pinned by CommandsTest's supports() invariant. */
    private const COMMANDS = [
        'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
        'find-component', 'inspect-component', 'list-usages', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
        'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
        'changed-files-impact', 'test-impact', 'review-diff', 'architecture-context', 'export-diagram', 'export-agent-brief', 'list-boundaries',
        'search-architecture', 'annotate-component', 'list-annotations',
    ];

    /**
     * A command missing its first argument explains its own usage.
     *
     * The message, not the exception class, is what tells the guards apart: a
     * command whose first guard was deleted still throws the same class, from
     * the next guard along, with the next guard's message.
     */
    #[Group('cli')]
    public function testEveryCommandMissingItsFirstArgumentPrintsItsOwnUsage(): void
    {
        $checked = 0;
        foreach (self::COMMANDS as $command) {
            $error = self::errorFrom($command, []);
            if ($error === null) {
                continue; // needs no positional argument at all
            }
            assertSame(
                true,
                str_starts_with($error, 'Usage: knossos ' . $command),
                sprintf('%s with no arguments must print its own usage, got: %s', $command, $error),
            );
            ++$checked;
        }
        assertSame(true, $checked >= 20, sprintf('Expected most commands to require an argument, checked %d.', $checked));
    }

    /**
     * Every integer bound the CLI enforces is the bound the MCP schema advertises.
     *
     * The option names are the schema's property names in kebab case, and the
     * probe values come from the schema, so this states the rule rather than
     * today's numbers and catches the CLI's copy moving away from the other two.
     */
    #[Group('cli')]
    public function testEveryCliIntegerBoundMatchesTheAdvertisedSchema(): void
    {
        $schemas = [];
        foreach (ToolCatalog::definitions(false) as $definition) {
            $schemas[$definition['name']] = (array) ($definition['inputSchema']['properties'] ?? []);
        }
        // Some commands refuse a missing file option before they read any
        // integer, so supply the ones a command accepts, with valid content.
        $policies = self::temporaryJson([['id' => 'p', 'from_boundary' => 'core', 'deny_targets' => ['tests']]]);
        $budgets = self::temporaryJson(['new_cycles' => 0]);
        $checked = 0;
        try {
            foreach (self::COMMANDS as $command) {
                $properties = $schemas[str_replace('-', '_', $command)] ?? [];
                $allowed = (new QueryCommand())->allowedOptions($command);
                $files = array_filter(
                    ['policies' => [$policies], 'budgets' => [$budgets]],
                    static fn(string $option): bool => in_array($option, $allowed, true),
                    ARRAY_FILTER_USE_KEY,
                );
                foreach ($allowed as $option) {
                    $spec = $properties[str_replace('-', '_', $option)] ?? null;
                    if (!is_array($spec) || ($spec['type'] ?? null) !== 'integer' || !isset($spec['minimum'], $spec['maximum'])) {
                        continue;
                    }
                    [$minimum, $maximum] = [(int) $spec['minimum'], (int) $spec['maximum']];
                    $expected = sprintf('--%s must be between %d and %d.', $option, $minimum, $maximum);
                    foreach ([$maximum + 1, $minimum - 1] as $outside) {
                        assertSame(
                            $expected,
                            self::errorFrom($command, ['p1', 'p2', 'p3'], [...$files, $option => [(string) $outside]]),
                            sprintf('%s --%s=%d must be refused with the advertised bounds.', $command, $option, $outside),
                        );
                    }
                    foreach ([$maximum, $minimum] as $inside) {
                        assertNotSame(
                            $expected,
                            self::errorFrom($command, ['p1', 'p2', 'p3'], [...$files, $option => [(string) $inside]]),
                            sprintf('%s --%s=%d is advertised as legal and must not be refused as out of range.', $command, $option, $inside),
                        );
                    }
                    ++$checked;
                }
            }
        } finally {
            @unlink($policies);
            @unlink($budgets);
        }
        assertSame(true, $checked >= 25, sprintf('Expected to check at least 25 bounded CLI options, checked %d.', $checked));
    }

    /** Write a JSON value to a temporary file and return its path. */
    private static function temporaryJson(mixed $value): string
    {
        $path = sys_get_temp_dir() . '/knossos-contract-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * The message a command fails with, or null when it completes.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     */
    private static function errorFrom(string $command, array $positionals, array $options = []): ?string
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), ':memory:');
        ob_start();
        try {
            (new QueryCommand())->run($command, $positionals, $options, $context);

            return null;
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        } finally {
            ob_end_clean();
        }
    }
}
