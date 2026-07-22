<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * Compact-verbosity compression: per-component boundary objects repeat
 * verbatim across ranked lists, so hoist them into one legend and leave ids
 * behind. Only exact {id, name, source} string triples are rewritten; richer
 * shapes (e.g. list_boundaries rows) pass through untouched.
 */
final class BoundaryLegend
{
    private function __construct() {}

    /**
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, array{name: string, source: string}>}
     */
    public static function compress(array $data): array
    {
        $legend = [];
        $compressed = self::walk($data, $legend);
        return [$compressed, $legend];
    }

    /**
     * @param array<string, mixed> $value
     * @param array<string, array{name: string, source: string}> $legend
     * @return array<string, mixed>
     */
    private static function walk(array $value, array &$legend): array
    {
        foreach ($value as $key => $item) {
            if ($key === 'boundaries' && self::isBoundaryList($item)) {
                $ids = [];
                foreach ($item as $boundary) {
                    $legend[$boundary['id']] = ['name' => $boundary['name'], 'source' => $boundary['source']];
                    $ids[] = $boundary['id'];
                }
                $value[$key] = $ids;
                continue;
            }
            if (is_array($item)) {
                $value[$key] = self::walk($item, $legend);
            }
        }
        return $value;
    }

    private static function isBoundaryList(mixed $item): bool
    {
        if (!is_array($item) || !array_is_list($item) || $item === []) {
            return false;
        }
        foreach ($item as $entry) {
            if (!is_array($entry) || count($entry) !== 3
                || !is_string($entry['id'] ?? null) || !is_string($entry['name'] ?? null) || !is_string($entry['source'] ?? null)) {
                return false;
            }
        }
        return true;
    }
}
