<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * The one boundary the architecture pane labels a component with, and the
 * project's boundaries with how many components each holds.
 *
 * A component usually sits in several boundaries: a declared one, a package,
 * a namespace and the repository-wide package of a single-package project.
 * The label is the most telling of them: declared before inferred, then
 * anything before a boundary spanning the whole repository, then the fewest
 * members, then the name, so the choice is stable between loads. The
 * dashboard and the component detail both label through this class, so a
 * component reads the same in the list and in its detail.
 *
 * Loaded with one query over the project's boundaries, which number in the
 * tens; {@see self::forNodes()} adds one membership query per 500 nodes.
 */
final readonly class BoundaryLabels
{
    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param array<string, array{int, int, int, string}> $ranks each boundary's rank by id: declared, wide, members, name
     * @param array<string, string> $sources each boundary's source (`explicit` or `inferred`) by id
     */
    private function __construct(private PDO $pdo, private array $ranks, private array $sources) {}

    /**
     * Every boundary of the project, ranked for labelling.
     *
     * @param PDO $pdo an existing, migrated graph database
     */
    public static function load(PDO $pdo, string $projectId): self
    {
        $statement = $pdo->prepare(
            'SELECT b.id, b.name, b.source, b.matcher_json, COUNT(bm.node_id) AS members FROM boundaries b '
            . 'LEFT JOIN boundary_memberships bm ON bm.boundary_id = b.id WHERE b.project_id = :project GROUP BY b.id',
        );
        $statement->execute(['project' => $projectId]);
        $ranks = [];
        $sources = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $matcher = json_decode((string) $row['matcher_json'], true);
            $wide = is_array($matcher) && ($matcher['type'] ?? null) === 'path_prefix' && ($matcher['value'] ?? null) === '';
            $ranks[(string) $row['id']] = [$row['source'] === 'explicit' ? 0 : 1, $wide ? 1 : 0, (int) $row['members'], (string) $row['name']];
            $sources[(string) $row['id']] = (string) $row['source'];
        }

        return new self($pdo, $ranks, $sources);
    }

    /**
     * The label among a component's memberships, or null when it has none.
     *
     * @param list<array<string, mixed>> $boundaries the memberships, `id` and `name` each
     */
    public function of(array $boundaries): ?string
    {
        $best = null;
        foreach ($boundaries as $boundary) {
            $rank = $this->ranks[(string) $boundary['id']] ?? [2, 2, PHP_INT_MAX, (string) $boundary['name']];
            if ($best === null || $rank < $best) {
                $best = $rank;
            }
        }

        return $best === null ? null : $best[3];
    }

    /**
     * The label of each node, by node id; a node in no boundary is absent.
     *
     * @param list<string> $nodeIds
     * @return array<string, string>
     */
    public function forNodes(array $nodeIds): array
    {
        $memberships = [];
        // Chunked to stay far below SQLite's bound-variable limit.
        foreach (array_chunk(array_values(array_unique($nodeIds)), 500) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT bm.node_id, b.id, b.name FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id '
                . 'WHERE bm.node_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $memberships[(string) $row['node_id']][] = ['id' => $row['id'], 'name' => $row['name']];
            }
        }
        $labels = [];
        foreach ($memberships as $node => $boundaries) {
            $label = $this->of($boundaries);
            if ($label !== null) {
                $labels[(string) $node] = $label;
            }
        }

        return $labels;
    }

    /**
     * The label of each file, by project-relative path: the label among the
     * boundaries its own components belong to (never its dependents'). A file
     * none of whose components sits in a boundary is absent, and so is one
     * that sits only in a boundary spanning the whole repository: every file
     * is in that one, so it says nothing about where this file sits.
     *
     * @param list<string> $paths project-relative paths
     * @return array<string, string>
     */
    public function forFiles(string $projectId, array $paths): array
    {
        $memberships = [];
        // Chunked to stay far below SQLite's bound-variable limit.
        foreach (array_chunk(array_values(array_unique($paths)), 500) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT DISTINCT f.relative_path, b.id, b.name FROM files f JOIN nodes n ON n.file_id = f.id '
                . 'JOIN boundary_memberships bm ON bm.node_id = n.id JOIN boundaries b ON b.id = bm.boundary_id '
                . 'WHERE f.project_id = ? AND f.relative_path IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')',
            );
            $statement->execute([$projectId, ...$chunk]);
            foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$path, $id, $name]) {
                if (($this->ranks[(string) $id][1] ?? 0) === 0) {
                    $memberships[(string) $path][] = ['id' => $id, 'name' => $name];
                }
            }
        }

        return array_filter(array_map(fn(array $boundaries): ?string => $this->of($boundaries), $memberships), static fn(?string $label): bool => $label !== null);
    }

    /**
     * The label of every node of the project that sits in a boundary, by node
     * id, read in one query over the project's memberships.
     *
     * @return array<string, string>
     */
    public function forProject(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bm.node_id, bm.boundary_id FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id '
            . 'WHERE b.project_id = :project',
        );
        $statement->execute(['project' => $projectId]);
        $best = [];
        // One row at a time: a large project has a membership per component, and all of them at once is the bulk of the memory.
        while (($row = $statement->fetch(PDO::FETCH_NUM)) !== false) {
            [$node, $boundary] = $row;
            $rank = $this->ranks[(string) $boundary] ?? null;
            if ($rank !== null && (!isset($best[(string) $node]) || $rank < $best[(string) $node])) {
                $best[(string) $node] = $rank;
            }
        }

        return array_map(static fn(array $rank): string => $rank[3], $best);
    }

    /**
     * The name a policy's boundary reference stands for: a boundary id, or a
     * name, the way the policy check resolves one. Null when it names none.
     */
    public function nameOf(string $reference): ?string
    {
        if (isset($this->ranks[$reference])) {
            return $this->ranks[$reference][3];
        }
        foreach ($this->ranks as $rank) {
            if ($rank[3] === $reference) {
                return $reference;
            }
        }

        return null;
    }

    /**
     * The id a policy's boundary reference resolves to, the way the policy
     * check resolves it: an id, else the one boundary of that name. Null when
     * it names none, or more than one (the check refuses such a reference).
     */
    public function idOf(string $reference): ?string
    {
        if (isset($this->ranks[$reference])) {
            return $reference;
        }
        $ids = array_keys(array_filter($this->ranks, static fn(array $rank): bool => $rank[3] === $reference));

        return count($ids) === 1 ? (string) $ids[0] : null;
    }

    /** The most declared boundaries named beside the list. */
    public const DECLARED = 200;

    /**
     * The project's boundaries as the pane lists them, with their member
     * counts: declared first, a repository-wide one last, then the largest.
     * `truncated` when more boundaries exist than are listed.
     *
     * Beside them, the name of every declared boundary in name order
     * (`declared`, at most `$declaredLimit`, `declared_truncated` past it):
     * whether a boundary is declared must not hang on the short list's cap.
     *
     * @return array{items: list<array{name: string, source: string, members: int}>, truncated: bool, declared: list<string>, declared_truncated: bool}
     */
    public function listed(int $limit, int $declaredLimit = self::DECLARED): array
    {
        $ranks = $this->ranks;
        uasort($ranks, static fn(array $a, array $b): int => [$a[0], $a[1], -$a[2], $a[3]] <=> [$b[0], $b[1], -$b[2], $b[3]]);
        $items = [];
        foreach (array_slice($ranks, 0, $limit, true) as $id => $rank) {
            $items[] = ['name' => $rank[3], 'source' => $this->sources[$id], 'members' => $rank[2]];
        }

        $declared = [];
        foreach ($this->sources as $id => $source) {
            if ($source === 'explicit') {
                $declared[] = $this->ranks[$id][3];
            }
        }
        $declared = array_values(array_unique($declared));
        sort($declared, SORT_STRING);

        return [
            'items' => $items,
            'truncated' => count($ranks) > $limit,
            'declared' => array_slice($declared, 0, $declaredLimit),
            'declared_truncated' => count($declared) > $declaredLimit,
        ];
    }
}
