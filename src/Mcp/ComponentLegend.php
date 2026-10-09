<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * Compact-verbosity compression: node descriptors repeat verbatim across ranked
 * lists, so hoist each into a one-time components legend keyed by canonical name
 * and leave the name string behind. Mirrors BoundaryLegend. Only maps that are
 * node descriptors (string id + kind + canonical_name/display_name) are rewritten.
 *
 * A canonical name is not unique (a module and a package can share one), so a
 * key belongs to the first component registered under it, in the data's own
 * order. Another component with that name gets `name (kind)`, and if that is
 * held too, `name (kind)#<last 8 characters of its id>`; a disambiguated entry
 * carries its `id`. Keying by name alone merged distinct components into the
 * first one's descriptor.
 */
final class ComponentLegend
{
    use LegendCompression;

    private const IDENTITY_KEYS = ['id', 'kind', 'canonical_name', 'display_name', 'confidence', 'origin', 'roles', 'boundaries', 'attributes', 'scanner_local_id', 'scanner'];

    /**
     * Same compression as compress(), but also returns the id -> legend-key
     * index built while hoisting node descriptors. Callers (ResultEnricher) use
     * this to add, beside each `*_id` reference in evidence, the legend key of
     * the node it names under a de-`_id`'d key.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: array<string, string>}
     */
    public static function compressWithIndex(array $data): array
    {
        $legend = [];
        $idToName = [];
        $owners = [];
        $compressed = self::walk($data, $legend, $idToName, $owners);
        return [$compressed, $legend, $idToName];
    }

    /**
     * Recurse through a result, replacing node objects with legend keys.
     *
     * @param array<string, mixed> $value
     * @param array<string, array<string, mixed>> $legend
     * @param array<string, string>|null $idToName
     * @param array<string, string> $owners legend key => the id that holds it
     * @return array<string, mixed>
     */
    private static function walk(array $value, array &$legend, ?array &$idToName = null, array &$owners = []): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item) && self::isNodeDescriptor($item)) {
                $value[$key] = self::register($item, $legend, $idToName, $owners);
                continue;
            }
            if ($key === 'via' && is_array($item) && self::isEdge($item)) {
                $value[$key] = (string) $item['kind'];
                continue;
            }
            if (is_array($item)) {
                $value[$key] = self::walk($item, $legend, $idToName, $owners);
            }
        }
        return $value;
    }

    /**
     * Whether a value is a node object worth hoisting into the legend.
     *
     * @param array<string, mixed> $item
     */
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
     * Whether a value is an edge object, which is shortened to its kind instead.
     *
     * @param array<string, mixed> $item
     */
    private static function isEdge(array $item): bool
    {
        return isset($item['kind']) && is_string($item['kind'])
            && (isset($item['source_id'], $item['target_id'])
                || (isset($item['edge_id']) && is_string($item['edge_id']))
                || (isset($item['id']) && is_string($item['id']) && str_starts_with($item['id'], 'edge_')));
    }

    /**
     * Record a node descriptor once, so repeated components collapse to a name reference.
     *
     * @param array<string, mixed> $node
     * @param array<string, array<string, mixed>> $legend
     * @param array<string, string>|null $idToName
     * @param array<string, string> $owners legend key => the id that holds it
     */
    private static function register(array $node, array &$legend, ?array &$idToName, array &$owners): string
    {
        $id = (string) $node['id'];
        $name = is_string($node['canonical_name'] ?? null) && $node['canonical_name'] !== ''
            ? $node['canonical_name']
            : ((string) ($node['display_name'] ?? 'unknown')) . '#' . substr($id, -8);
        $qualified = $name . ' (' . $node['kind'] . ')';
        $key = $name;
        foreach ([$name, $qualified, $qualified . '#' . substr($id, -8)] as $candidate) {
            $key = $candidate;
            if (($owners[$candidate] ?? $id) === $id) {
                break;
            }
        }
        // First occurrence defines the descriptor; the same id repeats byte-identical within a response.
        if (!isset($legend[$key])) {
            $owners[$key] = $id;
            $legend[$key] = self::descriptor($node, $key !== $name);
        }
        if ($idToName !== null) {
            $idToName[$id] = $key;
        }
        return $key;
    }

    /**
     * The legend entry for a node; one under a disambiguated key carries the node's id.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private static function descriptor(array $node, bool $withId): array
    {
        $descriptor = ['kind' => $node['kind']];
        if ($withId) {
            $descriptor['id'] = $node['id'];
        }
        if (isset($node['confidence'])) {
            $descriptor['confidence'] = $node['confidence'];
        }
        if (isset($node['origin'])) {
            $descriptor['origin'] = $node['origin'];
        }
        if (($node['boundaries'] ?? []) !== []) {
            $descriptor['boundaries'] = $node['boundaries'];
        }
        $roles = self::roleNames($node['roles'] ?? []);
        if ($roles !== []) {
            $descriptor['roles'] = $roles;
        }
        // Keep attributes (visibility, static, abstract, extends, ...): they
        // are IDENTITY_KEYS-allowlisted and agent-relevant, so hoisting a
        // node must not silently drop them.
        if (($node['attributes'] ?? []) !== []) {
            $descriptor['attributes'] = $node['attributes'];
        }

        return $descriptor;
    }

    /**
     * Role names for a node, flattened for the legend entry.
     *
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
