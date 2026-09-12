<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Mcp\ToolCatalog;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The safety annotations every MCP client acts on, stated as policy.
 *
 * A client reads `readOnlyHint` and `destructiveHint` to decide whether to run
 * a tool without asking. Until now the only thing guarding those values was the
 * generated-reference check, which compares the catalogue against the last
 * committed `docs/reference/mcp-tools.md`. That catches drift, but it blesses
 * whatever gets regenerated: marking `remove_project` non-destructive and
 * regenerating the reference would pass it. It also runs in a subprocess, which
 * is why mutation testing scored this file at 7% while the check existed.
 *
 * This test states the policy independently, so a flipped annotation fails
 * against intent rather than against a snapshot of itself.
 */
final class ToolCatalogPolicyTest extends KnossosTestCase
{
    /** Tools that change state: everything else must be read-only. */
    private const WRITE_TOOLS = ['annotate_component', 'cleanup_stale_scans', 'maintain_database', 'remove_project', 'scan_project'];

    /** Tools that delete data, which a client must confirm before running. */
    private const DESTRUCTIVE_TOOLS = ['cleanup_stale_scans', 'remove_project'];

    /** Tools that give a different result, or fail, when repeated. */
    private const NON_IDEMPOTENT_TOOLS = ['maintain_database', 'remove_project'];

    /** Tools that need a live server environment, and are omitted without one. */
    private const ENVIRONMENT_TOOLS = ['diagnose_runtime', 'server_info'];

    #[Group('mcp')]
    public function testEveryToolNotDeclaredAWriterIsReadOnly(): void
    {
        foreach (self::definitions() as $name => $definition) {
            assertSame(
                !in_array($name, self::WRITE_TOOLS, true),
                $definition['annotations']['readOnlyHint'],
                sprintf('%s: readOnlyHint must be %s.', $name, in_array($name, self::WRITE_TOOLS, true) ? 'false' : 'true'),
            );
        }
    }

    #[Group('mcp')]
    public function testOnlyTheToolsThatDeleteDataAreMarkedDestructive(): void
    {
        foreach (self::definitions() as $name => $definition) {
            assertSame(
                in_array($name, self::DESTRUCTIVE_TOOLS, true),
                $definition['annotations']['destructiveHint'],
                sprintf('%s: destructiveHint is what makes a client ask first.', $name),
            );
        }
    }

    #[Group('mcp')]
    public function testIdempotencyMatchesWhetherRepeatingAToolChangesTheOutcome(): void
    {
        foreach (self::definitions() as $name => $definition) {
            assertSame(
                !in_array($name, self::NON_IDEMPOTENT_TOOLS, true),
                $definition['annotations']['idempotentHint'],
                sprintf('%s: idempotentHint is wrong.', $name),
            );
        }
    }

    /** Knossos is local-first: no tool reaches beyond the machine it runs on. */
    #[Group('mcp')]
    public function testNoToolReachesTheOpenWorld(): void
    {
        foreach (self::definitions() as $name => $definition) {
            assertSame(false, $definition['annotations']['openWorldHint'], sprintf('%s claims open-world access.', $name));
        }
    }

    /**
     * Every input schema rejects arguments it does not declare, at every depth.
     *
     * An unknown argument is almost always a typo, and accepting one silently
     * means a misspelled `execute` or `max_depth` is ignored instead of refused.
     */
    #[Group('mcp')]
    public function testEveryInputSchemaIsAStrictObjectAtEveryDepth(): void
    {
        foreach (self::definitions() as $name => $definition) {
            assertSame('object', $definition['inputSchema']['type'] ?? null, sprintf('%s: input must be an object.', $name));
            self::assertStrict($definition['inputSchema'], $name);
        }
    }

    /** The two environment-bound tools appear exactly when an environment is available. */
    #[Group('mcp')]
    public function testEnvironmentToolsAreIncludedByDefaultAndOmittedWithoutAnEnvironment(): void
    {
        $withEnvironment = array_column(ToolCatalog::definitions(), 'name');
        $withoutEnvironment = array_column(ToolCatalog::definitions(false), 'name');

        foreach (self::ENVIRONMENT_TOOLS as $tool) {
            assertSame(true, in_array($tool, $withEnvironment, true), sprintf('%s must be offered by default.', $tool));
            assertSame(false, in_array($tool, $withoutEnvironment, true), sprintf('%s must be withheld without an environment.', $tool));
        }
        assertSame(count($withEnvironment) - count(self::ENVIRONMENT_TOOLS), count($withoutEnvironment));
    }

    /** Every name the policy lists must be a real tool, so a rename cannot orphan a rule. */
    #[Group('mcp')]
    public function testThePolicyNamesOnlyRealTools(): void
    {
        $names = array_keys(self::definitions());
        foreach ([...self::WRITE_TOOLS, ...self::DESTRUCTIVE_TOOLS, ...self::NON_IDEMPOTENT_TOOLS, ...self::ENVIRONMENT_TOOLS] as $tool) {
            assertSame(true, in_array($tool, $names, true), sprintf('Policy names %s, which is not a tool.', $tool));
        }
        foreach (self::DESTRUCTIVE_TOOLS as $tool) {
            assertSame(true, in_array($tool, self::WRITE_TOOLS, true), sprintf('%s destroys data, so it cannot be read-only.', $tool));
        }
    }

    /**
     * Recursively require `additionalProperties: false` on every object schema.
     *
     * @param array<string, mixed> $schema
     */
    private static function assertStrict(array $schema, string $path): void
    {
        if (($schema['type'] ?? null) === 'object') {
            assertSame(false, $schema['additionalProperties'] ?? null, sprintf('%s: additionalProperties must be false.', $path));
        }
        foreach ((array) ($schema['properties'] ?? []) as $property => $child) {
            if (is_array($child)) {
                self::assertStrict($child, $path . '.' . $property);
            }
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            self::assertStrict($schema['items'], $path . '[]');
        }
    }

    /** @return array<string, array<string, mixed>> every tool definition keyed by name */
    private static function definitions(): array
    {
        $definitions = [];
        foreach (ToolCatalog::definitions() as $definition) {
            $definitions[$definition['name']] = $definition;
        }

        return $definitions;
    }
}
