<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Whether an edge exists only in the source and is gone from the built code.
 *
 * TypeScript erases `import type { T } from './x'` and `export type { T }`
 * entirely, so a dependency recorded from one describes the type-checker's
 * view rather than the program's. A cycle that runs over such an edge does
 * not exist at runtime and has nothing to break: a React project split
 * `Badge.tsx` from `badgeVariants.ts` deliberately, to keep Vite's hot
 * module replacement intact, and `dependency_cycles` then reported the split
 * as a certain cycle, asking for a considered improvement to be undone.
 *
 * The scanner records one edge per source/target pair, so several import
 * statements between the same two modules collapse into one. When they
 * disagree it leaves `type_only_variants` behind, and a single value import
 * among them is enough to make the dependency real.
 *
 * A class of its own so that code fed rows by {@see SnapshotGraphReader},
 * as well as the query services, applies the one definition.
 */
final readonly class ErasedTypeEdge
{
    /** The edge kinds a type-only statement can produce. */
    public const KINDS = ['imports', 're_exports'];

    /**
     * Whether this edge row is erased at runtime.
     *
     * @param array<string, mixed> $edge An edge row carrying `kind` and `attributes_json`.
     */
    public static function matches(array $edge): bool
    {
        if (!in_array($edge['kind'] ?? null, self::KINDS, true)) {
            return false;
        }
        $attributes = $edge['attributes_json'] ?? null;
        if (!is_string($attributes)) {
            return false;
        }
        $decoded = json_decode($attributes, true);
        if (!is_array($decoded) || ($decoded['type_only'] ?? false) !== true) {
            return false;
        }
        $variants = $decoded['type_only_variants'] ?? null;

        return !is_array($variants) || !in_array(false, $variants, true);
    }
}
