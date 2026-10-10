<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Store\ChunkedInQuery;
use PDO;

/**
 * The type hierarchy around a set of classes, read once for the dead-code
 * analysis to ask inheritance questions of.
 *
 * Holds, for the classes given and the types that inherit their members: the
 * transitive `implements`/`extends`/`returns` parents, the metadata of every
 * ancestor so an external one can be told apart, and the member names of the
 * internal ancestors and of the inheriting subtypes.
 *
 * Split out of DeadCodeAnalysis, where the four chunked reads that build this
 * and the closure walk over it made one 190-line method together with the
 * per-method decision that consumes them. Not readonly: the ancestor closure
 * of a class is memoized, since every method of a class asks for it again.
 */
final class InheritanceGraph
{
    /** Bound on the ancestor walk, so a malformed cycle of edges ends. */
    private const MAX_ANCESTOR_DEPTH = 20;

    /** Bound on the walk down to inheriting subtypes. */
    private const MAX_SUBTYPE_DEPTH = 10;

    /** @var array<string, list<string>> class id => memoized ancestor closure */
    private array $closures = [];

    /**
     * @param array<string, list<string>> $parents type id => direct parent ids
     * @param array<string, list<string>> $subtypes type id => ids of the types that inherit its members
     * @param array<string, array<string, mixed>> $meta ancestor id => node row (id, kind, display_name, origin)
     * @param array<string, array<string, true>> $memberNames type id => member display names
     */
    private function __construct(
        private readonly array $parents,
        private readonly array $subtypes,
        private readonly array $meta,
        private readonly array $memberNames,
    ) {}

    /**
     * Read the hierarchy around `$classIds`.
     *
     * The subtypes that inherit each class's members, whose own contracts
     * those members may fulfil, are walked for their ancestors too.
     *
     * @param list<string> $classIds
     */
    public static function load(PDO $pdo, string $projectId, array $classIds): self
    {
        $subtypes = self::inheritingSubtypes($pdo, $projectId, $classIds);
        $subtypeIds = array_merge(...array_values($subtypes) ?: [[]]);
        $parents = self::ancestorEdges($pdo, $projectId, array_values(array_unique([...$classIds, ...$subtypeIds])));
        $ancestorIds = array_values(array_unique(array_merge(...array_values($parents) ?: [[]])));
        $meta = [];
        foreach (ChunkedInQuery::rows($pdo, 'SELECT id, kind, display_name, origin FROM nodes WHERE project_id = ? AND id IN (%s)', $ancestorIds, [$projectId]) as $row) {
            $meta[$row['id']] = $row;
        }
        $internalAncestors = array_values(array_filter($ancestorIds, static fn(string $id): bool => !self::externalMeta($meta[$id] ?? null)));
        $memberNames = [];
        $declaring = array_values(array_unique([...$internalAncestors, ...$subtypeIds]));
        foreach (ChunkedInQuery::rows(
            $pdo,
            'SELECT e.source_id, n.display_name FROM edges e JOIN nodes n ON n.id = e.target_id ' .
            "WHERE e.project_id = ? AND e.kind = 'contains' AND e.source_id IN (%s)",
            $declaring,
            [$projectId],
        ) as $row) {
            $memberNames[$row['source_id']][(string) $row['display_name']] = true;
        }

        return new self($parents, $subtypes, $meta, $memberNames);
    }

    /**
     * The direct parents of a type.
     *
     * @return list<string>
     */
    public function parents(string $id): array
    {
        return $this->parents[$id] ?? [];
    }

    /**
     * The types that inherit `$id`'s members, at any depth.
     *
     * @return list<string>
     */
    public function subtypesOf(string $id): array
    {
        return $this->subtypes[$id] ?? [];
    }

    /**
     * Every ancestor of a class, memoized: the transitive closure of its parents.
     *
     * @return list<string>
     */
    public function closureOf(string $classId): array
    {
        if (isset($this->closures[$classId])) {
            return $this->closures[$classId];
        }
        $seen = [];
        $stack = $this->parents($classId);
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($this->parents($id) as $parentId) {
                if (!isset($seen[$parentId])) {
                    $stack[] = $parentId;
                }
            }
        }
        $this->closures[$classId] = array_keys($seen);

        return $this->closures[$classId];
    }

    /**
     * The node row of an ancestor, or null when the graph holds none.
     *
     * @return array<string, mixed>|null
     */
    public function meta(string $id): ?array
    {
        return $this->meta[$id] ?? null;
    }

    /**
     * The member names a type declares.
     *
     * @return array<string, true>
     */
    public function memberNames(string $id): array
    {
        return $this->memberNames[$id] ?? [];
    }

    /**
     * Whether an ancestor lies outside what static analysis can see: missing
     * from the graph, an external kind, or of external or unresolved origin.
     */
    public function isExternal(string $id): bool
    {
        return self::externalMeta($this->meta($id));
    }

    /**
     * Whether a subtype that does not override `$name` fulfils its own
     * contract with the member `$classId` declares: a trait's method, or a
     * base class's, satisfying an interface the using or extending class
     * declares.
     *
     * @param list<string> $ancestors the closure of `$classId`
     */
    public function isInheritedViaSubtype(string $classId, array $ancestors, string $name): bool
    {
        $chain = array_flip([$classId, ...$ancestors]);
        $descendants = array_flip($this->subtypesOf($classId));
        foreach ($this->subtypesOf($classId) as $subtypeId) {
            if (isset($this->memberNames[$subtypeId][$name])) {
                continue;
            }
            $subtypeAncestors = $this->closureOf($subtypeId);
            // A descendant between this subtype and the method's type
            // that overrides it is what the subtype inherits instead.
            foreach ($subtypeAncestors as $ancestorId) {
                if (isset($descendants[$ancestorId], $this->memberNames[$ancestorId][$name])) {
                    continue 2;
                }
            }
            foreach ($subtypeAncestors as $ancestorId) {
                if (!isset($chain[$ancestorId]) && !isset($descendants[$ancestorId])
                    && !$this->isExternal($ancestorId) && isset($this->memberNames[$ancestorId][$name])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The predicate behind {@see isExternal}, over a node row.
     *
     * @param array<string, mixed>|null $meta
     */
    private static function externalMeta(?array $meta): bool
    {
        return $meta === null
            || str_starts_with((string) $meta['kind'], 'external_')
            || in_array($meta['origin'], ['external', 'unresolved'], true);
    }

    /**
     * Walk the extends/implements closure transitively (bounded depth) so a
     * method overriding a grandparent's member is recognized as inherited,
     * not just one overriding a direct parent's.
     *
     * @param list<string> $frontier
     * @return array<string, list<string>> type id => direct parent ids
     */
    private static function ancestorEdges(PDO $pdo, string $projectId, array $frontier): array
    {
        $parents = [];
        $edgesResolved = [];
        for ($depth = 0; $depth < self::MAX_ANCESTOR_DEPTH && $frontier !== []; $depth++) {
            $pending = array_values(array_filter($frontier, static fn(string $id): bool => !isset($edgesResolved[$id])));
            if ($pending === []) {
                break;
            }
            $discovered = [];
            // `returns` joins the walk because a factory returning an object
            // literal is how a language without classes writes an
            // implementation: the literal's members are contained by the
            // FUNCTION, and a call site typed as the interface resolves to
            // the interface's member, so the literal's member has no inbound
            // edge and reads as dead. The function's declared return type is
            // the contract it satisfies, which is exactly what `extends` and
            // `implements` say for a class. Only a function carries a
            // `returns` edge, so the class case is untouched.
            foreach (ChunkedInQuery::rows(
                $pdo,
                "SELECT source_id, target_id FROM edges WHERE project_id = ? AND kind IN ('implements', 'extends', 'returns') AND source_id IN (%s)",
                $pending,
                [$projectId],
            ) as $row) {
                $parents[$row['source_id']][] = $row['target_id'];
                $discovered[] = $row['target_id'];
            }
            foreach ($pending as $id) {
                $edgesResolved[$id] = true;
            }
            $frontier = array_values(array_unique($discovered));
        }

        return $parents;
    }

    /**
     * The types that inherit each of `$typeIds`' members, at any depth: a class
     * extending it, or a class using it as a trait.
     *
     * @param list<string> $typeIds
     * @return array<string, list<string>> type id => subtype ids
     */
    private static function inheritingSubtypes(PDO $pdo, string $projectId, array $typeIds): array
    {
        $direct = [];
        $frontier = $typeIds;
        for ($depth = 0; $depth < self::MAX_SUBTYPE_DEPTH && $frontier !== []; ++$depth) {
            $found = [];
            foreach (ChunkedInQuery::rows(
                $pdo,
                "SELECT source_id, target_id FROM edges WHERE project_id = ? AND kind IN ('extends', 'uses_trait') AND target_id IN (%s)",
                $frontier,
                [$projectId],
            ) as $row) {
                $direct[(string) $row['target_id']][(string) $row['source_id']] = true;
                $found[] = (string) $row['source_id'];
            }
            $frontier = array_values(array_diff(array_unique($found), array_keys($direct)));
        }
        $result = [];
        foreach ($typeIds as $typeId) {
            $seen = [];
            $stack = array_keys($direct[$typeId] ?? []);
            while ($stack !== []) {
                $id = array_pop($stack);
                if (isset($seen[$id]) || $id === $typeId) {
                    continue;
                }
                $seen[$id] = true;
                array_push($stack, ...array_keys($direct[$id] ?? []));
            }
            if ($seen !== []) {
                $result[$typeId] = array_keys($seen);
            }
        }

        return $result;
    }
}
