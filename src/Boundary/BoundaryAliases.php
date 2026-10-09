<?php

declare(strict_types=1);

namespace Knossos\Boundary;

/**
 * The names a boundary was known by, stored inside its matcher.
 *
 * A boundary's name changes when a manifest is added beside it (a merge
 * suffix) or a second manifest declares the same name (a directory suffix).
 * Policies refer to boundaries by name, so the former names are kept, under
 * `aliases` in `matcher_json`: there is no column for them, and membership
 * reads only the matcher's `type` and `value`. Every output that shows a
 * matcher takes them out first and shows them beside it.
 */
final class BoundaryAliases
{
    private function __construct() {}

    /**
     * A matcher with its aliases removed, and the aliases: what a stored
     * matcher holds, each name once, read leniently, since an imported bundle
     * writes it too.
     *
     * @param array<string, mixed> $matcher
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public static function split(array $matcher): array
    {
        $stored = $matcher['aliases'] ?? [];
        unset($matcher['aliases']);
        $aliases = [];
        foreach (is_array($stored) ? $stored : [] as $alias) {
            if (is_string($alias) && $alias !== '' && !in_array($alias, $aliases, true)) {
                $aliases[] = $alias;
            }
        }

        return [$matcher, $aliases];
    }

    /**
     * The matcher to store: the aliases inside it when there are any, so a
     * boundary that was never renamed is stored exactly as before.
     *
     * @param array<string, mixed> $matcher
     * @param list<string> $aliases
     * @return array<string, mixed>
     */
    public static function merge(array $matcher, array $aliases): array
    {
        return $aliases === [] ? $matcher : $matcher + ['aliases' => $aliases];
    }
}
