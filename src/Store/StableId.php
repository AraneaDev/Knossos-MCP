<?php

declare(strict_types=1);

namespace Knossos\Store;

use InvalidArgumentException;

/**
 * Content-addressed identifiers for graph rows.
 *
 * Every id is `<kind>_<sha256 of its identity parts>`, so the same component
 * scanned twice yields the same id and a rescan can reconcile against the
 * previous graph instead of replacing it. That makes the parts load-bearing:
 * changing what goes into an id, or its order, silently orphans every existing
 * row of that kind. Empty parts are rejected, because an empty string would
 * collapse distinct entities onto one id.
 */
final class StableId
{
    private function __construct() {}

    /** Identity is the project's canonical root path, so one checkout is one project. */
    public static function project(string $identity): string
    {
        return self::make('project', [$identity]);
    }

    /** Scoped to the project, so the same relative path in two projects stays distinct. */
    public static function file(string $projectId, string $relativePath): string
    {
        return self::make('file', [$projectId, $relativePath]);
    }

    /**
     * @param string $nonce distinguishes repeat scans of one project; scans are
     *        events rather than content, so this is deliberately not derived from the graph
     */
    public static function scan(string $projectId, string $nonce): string
    {
        return self::make('scan', [$projectId, $nonce]);
    }

    /**
     * A declared symbol: class, method, function, and so on.
     *
     * Identity is exactly (project, language, kind, canonical name) — the same
     * tuple `nodes` enforces as UNIQUE since migration 010. Adding a
     * discriminator here without widening that index would produce two ids for
     * one row and abort the scan on the constraint.
     */
    public static function symbol(
        string $projectId,
        string $language,
        string $kind,
        string $canonicalName,
    ): string {
        return self::make('symbol', [$projectId, $language, $kind, $canonicalName]);
    }

    /**
     * An HTTP route.
     *
     * Methods are sorted, so GET+POST and POST+GET describe one route rather than two.
     *
     * @param list<string> $methods
     */
    public static function route(string $projectId, array $methods, string $uri, string $action): string
    {
        sort($methods, SORT_STRING);

        return self::make('route', [$projectId, implode(',', $methods), $uri, $action]);
    }

    /**
     * A relationship between two nodes.
     *
     * @param string $evidenceIdentity distinguishes several edges of the same kind
     *        between the same pair — separate call sites are separate facts
     */
    public static function edge(
        string $projectId,
        string $kind,
        string $sourceId,
        string $targetId,
        string $evidenceIdentity,
    ): string {
        return self::make('edge', [$projectId, $kind, $sourceId, $targetId, $evidenceIdentity]);
    }

    /** Includes the rule id, so two rules assigning the same role remain separately attributable. */
    public static function classification(string $projectId, string $nodeId, string $role, string $ruleId): string
    {
        return self::make('classification', [$projectId, $nodeId, $role, $ruleId]);
    }

    /** Includes the source, so an inferred boundary never collides with an explicitly declared one. */
    public static function boundary(string $projectId, string $name, string $source): string
    {
        return self::make('boundary', [$projectId, $name, $source]);
    }

    /**
     * Hash the identity parts into a prefixed id, rejecting an empty part.
     *
     * @param list<string> $parts
     */
    private static function make(string $prefix, array $parts): string
    {
        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidArgumentException(sprintf('%s ID parts must not be empty.', $prefix));
            }
        }

        $payload = json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $prefix . '_' . hash('sha256', $payload);
    }
}
