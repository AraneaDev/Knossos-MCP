<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Pairs a component that left a graph with the one that took its place, by a
 * signature both share and nothing else on that side does.
 *
 * The snapshot diff and the quality gate both ask this, and both ask it here
 * so the matching cannot drift between them. They differ in the signature and
 * in how strict the pairing is:
 *
 * - The diff lists rename candidates by kind and display name, unique among
 *   the added components only. It reports them as "possible" for a reader to
 *   judge, and its output is user-visible, so its rule stays as it was.
 * - The gate decides on its own whether a cycle is new, so it follows a move
 *   only when no guess is involved: the {@see self::moveSignature()} keeps the
 *   language, the kind and the owning type, and the pair must be the only
 *   removed and the only added component with it. A display name alone
 *   (`walk`, `__init__`) is shared by many classes.
 */
final class RenameMatching
{
    /**
     * Each removed component paired with the added one that shares its
     * signature, when exactly one added component has it, and, with
     * `$uniqueOnBothSides`, exactly one removed component too.
     *
     * @param array<string, string> $removed signatures by removed id
     * @param array<string, string> $added signatures by added id
     * @return array<string, string> added ids by removed id, in the order of `$removed`
     */
    public static function uniquePairs(array $removed, array $added, bool $uniqueOnBothSides): array
    {
        $addedBySignature = [];
        foreach ($added as $id => $signature) {
            $addedBySignature[$signature][] = (string) $id;
        }
        $removedCount = $uniqueOnBothSides ? array_count_values($removed) : [];
        $pairs = [];
        foreach ($removed as $id => $signature) {
            $matches = $addedBySignature[$signature] ?? [];
            if (count($matches) === 1 && (!$uniqueOnBothSides || $removedCount[$signature] === 1)) {
                $pairs[(string) $id] = $matches[0];
            }
        }
        return $pairs;
    }

    /**
     * What survives a move in an identity key (`language\0kind\0canonical_name`):
     * the language, the kind and the canonical name's trailing qualified part,
     * the member with its owning type (`Walk::walk_item`) or, for a name with no
     * `::` member, its last segment (`load`).
     *
     * Namespace separators differ per language (`\`, `.`, `/`, `#`, and `::`
     * for Rust), so the owner is the segment before the last `::`. In Rust a
     * module path also uses `::`, so a moved Rust free function keeps its
     * module's short name and is followed only when that name did not change.
     */
    public static function moveSignature(string $identityKey): string
    {
        $parts = explode("\0", $identityKey, 3);
        $name = $parts[2] ?? '';
        $member = strrpos($name, '::');
        if ($member === false) {
            $tail = self::lastSegment($name);
        } else {
            $tail = self::lastSegment(substr($name, 0, $member)) . substr($name, $member);
        }

        return $parts[0] . "\0" . ($parts[1] ?? '') . "\0" . $tail;
    }

    /** A qualified name's last segment, after any namespace separator. */
    private static function lastSegment(string $name): string
    {
        $segments = preg_split('/::|[\\\\.\/#]/', $name) ?: [$name];
        return (string) end($segments);
    }
}
