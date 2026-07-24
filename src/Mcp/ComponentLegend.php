<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * Compact-verbosity compression: node descriptors repeat verbatim across ranked
 * lists, so hoist each into a one-time components legend keyed by canonical name
 * and leave the name string behind. Mirrors BoundaryLegend. Only maps that are
 * node descriptors (string id + kind + canonical_name/display_name) are rewritten.
 */
final class ComponentLegend
{
    private const IDENTITY_KEYS = ['id', 'kind', 'canonical_name', 'display_name', 'confidence', 'origin', 'roles', 'boundaries', 'attributes', 'scanner_local_id', 'scanner'];

    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, array<string, mixed>>}
     */
    public static function compress(array $data): array
    {
        $legend = [];
        $compressed = self::walk($data, $legend);
        return [$compressed, $legend];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array<string, mixed>> $legend
     * @return array<string, mixed>
     */
    private static function walk(array $value, array &$legend): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) && self::isNodeDescriptor($item)) {
                $value[$key] = self::register($item, $legend);
                continue;
            }
            if (is_array($item)) {
                $value[$key] = self::walk($item, $legend);
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $item */
    private static function isNodeDescriptor(array $item): bool
    {
        if (!isset($item['id'], $item['kind']) || !is_string($item['id']) || !is_string($item['kind'])
            || !(isset($item['canonical_name']) || isset($item['display_name']))) {
            return false;
        }
        foreach (array_keys($item) as $key) {
            if (!in_array($key, self::IDENTITY_KEYS, true)) {
                return false; // carries payload/relationship keys -> not a bare descriptor, recurse instead
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $legend
     */
    private static function register(array $node, array &$legend): string
    {
        $name = is_string($node['canonical_name'] ?? null) && $node['canonical_name'] !== ''
            ? $node['canonical_name']
            : ((string) ($node['display_name'] ?? 'unknown')) . '#' . substr((string) $node['id'], -8);
        // First occurrence defines the descriptor; duplicates are byte-identical within a response.
        if (!isset($legend[$name])) {
            $descriptor = ['kind' => $node['kind']];
            if (isset($node['confidence'])) {
                $descriptor['confidence'] = $node['confidence'];
            }
            if (($node['boundaries'] ?? []) !== []) {
                $descriptor['boundaries'] = $node['boundaries'];
            }
            $roles = self::roleNames($node['roles'] ?? []);
            if ($roles !== []) {
                $descriptor['roles'] = $roles;
            }
            $legend[$name] = $descriptor;
        }
        return $name;
    }

    /**
     * @param mixed $roles
     * @return list<string>
     */
    private static function roleNames(mixed $roles): array
    {
        if (!is_array($roles)) {
            return [];
        }
        $names = [];
        foreach ($roles as $role) {
            if (is_array($role) && isset($role['role']) && is_string($role['role'])) {
                $names[] = $role['role'];
            } elseif (is_string($role)) {
                $names[] = $role;
            }
        }
        return $names;
    }
}
