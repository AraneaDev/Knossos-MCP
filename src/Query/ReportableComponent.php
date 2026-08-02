<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Which components architecture reporting is about.
 *
 * `architecture_health` ranks hubs and dead-code candidates over the components
 * a maintainer could act on, and the quality-gate budgets are set from what that
 * report shows. The two therefore have to agree on what is out of scope, or a
 * budget read off the health report fails the moment it is applied: counting
 * test modules alone put the gate's `unreferenced_candidates` two orders of
 * magnitude above the candidate pool health had ranked, and let a shared
 * assertion helper — the highest-degree symbol in most repositories — drive
 * `hub_degree_growth` so that adding tests read as an architecture regression.
 *
 * The predicates here are the exclusions both sides share. `architecture_health`
 * layers further, database-backed ones on top (inherited and contract members,
 * annotations, suppressions), so its candidate pool is a subset of the gate's.
 */
final readonly class ReportableComponent
{
    /** The role classifiers give to test modules and their members. */
    public const TEST_ROLE = 'quality.test_module';

    /**
     * Roles whose members something outside the graph reaches: a router, a
     * console kernel, a test runner globbing files, a build tool loading its
     * own config by name. Having no inbound edge is how these are built, not
     * evidence that they are unused.
     */
    public const CONVENTION_DISCOVERED_ROLES = [
        'application.controller', 'application.command', 'application.entry_point',
        'laravel.controller', 'laravel.command', 'laravel.job', 'laravel.listener',
        self::TEST_ROLE,
        'tooling.config',
    ];

    /** Vendor code and unresolved references: not this project's to delete or restructure. */
    public static function isExternal(string $kind, ?string $origin): bool
    {
        return str_starts_with($kind, 'external_') || in_array($origin, ['external', 'unresolved'], true);
    }

    /**
     * Test code, which is measured by coverage rather than by architecture.
     *
     * @param list<string> $roles
     */
    public static function isTest(array $roles): bool
    {
        return in_array(self::TEST_ROLE, $roles, true);
    }

    /**
     * Whether any of these roles means the component is reached by convention.
     *
     * @param list<string> $roles
     */
    public static function isDiscoveredByConvention(array $roles): bool
    {
        return array_intersect($roles, self::CONVENTION_DISCOVERED_ROLES) !== [];
    }

    /**
     * A module a scanner marked as an executable script.
     *
     * Scanners set this on a module they emitted because the file has a body
     * that runs — a shebang, a `__main__` guard, PHP file-scope code — rather
     * than because it declares things others import. Such a body is entered by
     * something outside the graph: a shell, a web server, a CI step. Nothing in
     * the codebase references it and nothing ever will, so counting it as
     * unreferenced charges a budget nobody can pay down.
     */
    public static function isExecutableScript(string $kind, mixed $attributesJson): bool
    {
        if ($kind !== 'module' || !is_string($attributesJson)) {
            return false;
        }
        $decoded = json_decode($attributesJson, true);
        return is_array($decoded) && ($decoded['executable'] ?? false) === true;
    }

    /**
     * A constructor, which the engine invokes through `new` on the declaring type.
     *
     * No call edge points at it even in code that constructs the type constantly,
     * so an unreferenced constructor is never evidence of dead code on its own.
     */
    public static function isConstructor(string $kind, string $displayName): bool
    {
        return $kind === 'method' && ($displayName === '__construct' || $displayName === 'constructor');
    }
}
