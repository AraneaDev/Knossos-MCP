<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * Facts about the whole project that dead-code classification reads: which
 * node carries a canonical name, whether any name starts with a prefix, a
 * node's degrees, and the member names called on untyped receivers.
 *
 * Answered from the graph itself, not from the nodes a bounded walk read, so a
 * candidate's verdict does not depend on where its name sorts. Each answer is
 * kept for the life of the instance, which is one health query.
 */
final class CandidateGraphFacts
{
    private const CHUNK = 500;

    /** @var array<string, ?string> */
    private array $ids = [];

    /** @var array<string, bool> */
    private array $prefixes = [];

    /**
     * Degrees memoized per id, one flat map each rather than an array per id:
     * a project's every candidate stays here for the whole query, and a
     * three-key array per id was a third of what a large one needed.
     *
     * @var array<string, int>
     */
    private array $inDegrees = [];

    /** @var array<string, int> */
    private array $inheritanceInDegrees = [];

    /** @var array<string, int> */
    private array $outDegrees = [];

    /** @var array<string, true>|null */
    private ?array $untyped = null;

    /** @var list<string>|null */
    private ?array $suppressions = null;

    /** @var array<string, array{kind: string, value: string}>|null */
    private ?array $annotations = null;

    /** @param list<string> $edgeKinds */
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $projectId,
        private readonly array $edgeKinds,
        private readonly int $minConfidenceRank,
    ) {}

    /** The node carrying `$canonicalName`, the lowest id when several do, or null. */
    public function idOf(string $canonicalName): ?string
    {
        if (!array_key_exists($canonicalName, $this->ids)) {
            $statement = $this->pdo->prepare('SELECT MIN(id) FROM nodes WHERE project_id = ? AND canonical_name = ?');
            $statement->execute([$this->projectId, $canonicalName]);
            $id = $statement->fetchColumn();
            $this->ids[$canonicalName] = is_string($id) ? $id : null;
        }

        return $this->ids[$canonicalName];
    }

    /**
     * Whether any node's canonical name starts with `$prefix`.
     *
     * Asked as a range on the canonical-name index: every name at or after the
     * prefix and before the prefix with its last byte raised by one.
     */
    public function hasCanonicalPrefix(string $prefix): bool
    {
        if ($prefix === '') {
            return true;
        }
        // A byte of 0xFF never occurs in UTF-8, which every canonical name
        // is, so raising the last byte always gives a valid bound.
        if (!array_key_exists($prefix, $this->prefixes)) {
            $statement = $this->pdo->prepare('SELECT 1 FROM nodes WHERE project_id = ? AND canonical_name >= ? AND canonical_name < ? LIMIT 1');
            $statement->execute([$this->projectId, $prefix, substr($prefix, 0, -1) . chr(ord($prefix[strlen($prefix) - 1]) + 1)]);
            $this->prefixes[$prefix] = $statement->fetchColumn() !== false;
        }

        return $this->prefixes[$prefix];
    }

    /**
     * In-degree, inheritance in-degree and out-degree over the selected edge
     * kinds at or above the confidence floor, for each of `$ids`.
     *
     * @param list<string> $ids
     * @return array<string, array{in_degree: int, inheritance_in_degree: int, out_degree: int}>
     */
    public function degrees(array $ids): array
    {
        $missing = array_values(array_filter(array_unique($ids), fn(string $id): bool => !isset($this->inDegrees[$id])));
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            foreach ($chunk as $id) {
                $this->inDegrees[$id] = 0;
                $this->inheritanceInDegrees[$id] = 0;
                $this->outDegrees[$id] = 0;
            }
            $this->countInbound($chunk);
            $this->countOutbound($chunk);
        }

        $degrees = [];
        foreach ($ids as $id) {
            $degrees[$id] = ['in_degree' => $this->inDegrees[$id], 'inheritance_in_degree' => $this->inheritanceInDegrees[$id], 'out_degree' => $this->outDegrees[$id]];
        }

        return $degrees;
    }

    /** Inbound edges of the selected kinds at or above the floor. */
    public function inDegree(string $id): int
    {
        $this->load($id);

        return $this->inDegrees[$id];
    }

    /** Inbound `extends` and `implements` edges, a subset of the in-degree. */
    public function inheritanceInDegree(string $id): int
    {
        $this->load($id);

        return $this->inheritanceInDegrees[$id];
    }

    /** Outbound edges of the selected kinds at or above the floor. */
    public function outDegree(string $id): int
    {
        $this->load($id);

        return $this->outDegrees[$id];
    }

    /**
     * Loads one node's degrees unless a batch already did.
     *
     * The memo is checked directly rather than through degrees(), whose result
     * is built per call: asked once per candidate, that made a large project
     * quadratic.
     */
    private function load(string $id): void
    {
        if (!isset($this->inDegrees[$id])) {
            $this->degrees([$id]);
        }
    }

    /**
     * Member names the scanners saw called on a receiver they could not type,
     * from every node that lists them (a module, or a calling declaration).
     *
     * @return array<string, true>
     */
    public function untypedMemberNames(): array
    {
        if ($this->untyped === null) {
            $this->untyped = [];
            $statement = $this->pdo->prepare("SELECT attributes_json FROM nodes WHERE project_id = ? AND attributes_json LIKE '%unresolved_member_calls%'");
            $statement->execute([$this->projectId]);
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $json) {
                $calls = json_decode((string) $json, true)['unresolved_member_calls'] ?? null;
                foreach (is_array($calls) ? $calls : [] as $name) {
                    if (is_string($name)) {
                        $this->untyped[$name] = true;
                    }
                }
            }
            ksort($this->untyped, SORT_STRING);
        }

        return $this->untyped;
    }

    /**
     * Canonical names the project's own configuration suppresses, exactly or by prefix.
     *
     * @return list<string>
     */
    public function deadCodeSuppressions(): array
    {
        if ($this->suppressions === null) {
            $statement = $this->pdo->prepare('SELECT config_json FROM projects WHERE id = :id');
            $statement->execute(['id' => $this->projectId]);
            $raw = $statement->fetchColumn();
            $config = is_string($raw) ? json_decode($raw, true) : null;
            $list = is_array($config) ? ($config['dead_code_suppressions'] ?? []) : [];
            $this->suppressions = is_array($list) && array_is_list($list) ? array_values(array_filter($list, 'is_string')) : [];
        }

        return $this->suppressions;
    }

    /**
     * Durable agent judgements recorded against a component.
     *
     * @return array<string, array{kind: string, value: string}> keyed by canonical name; false_positive wins over confirmed_dead
     */
    public function componentAnnotations(): array
    {
        if ($this->annotations === null) {
            $statement = $this->pdo->prepare(
                "SELECT canonical_name, kind, value FROM annotations WHERE project_id = :project AND kind IN ('false_positive', 'confirmed_dead') " .
                'ORDER BY canonical_name, kind DESC', // 'false_positive' > 'confirmed_dead' alphabetically DESC
            );
            $statement->execute(['project' => $this->projectId]);
            $this->annotations = [];
            foreach ($statement->fetchAll() as $row) {
                $this->annotations[$row['canonical_name']] ??= ['kind' => $row['kind'], 'value' => $row['value']];
            }
        }

        return $this->annotations;
    }

    /**
     * Adds the inbound edge counts of `$ids` to their degrees.
     *
     * @param list<string> $ids
     */
    private function countInbound(array $ids): void
    {
        $statement = $this->pdo->prepare(
            'SELECT target_id, kind, COUNT(*) AS edge_count FROM edges WHERE project_id = ? ' . $this->edgeFilter() .
            sprintf(' AND target_id IN (%s) GROUP BY target_id, kind', self::placeholders($ids)),
        );
        $statement->execute([$this->projectId, ...$this->edgeKinds, $this->minConfidenceRank, ...$ids]);
        foreach ($statement->fetchAll() as $row) {
            $id = (string) $row['target_id'];
            $this->inDegrees[$id] += (int) $row['edge_count'];
            if (in_array((string) $row['kind'], ['implements', 'extends'], true)) {
                $this->inheritanceInDegrees[$id] += (int) $row['edge_count'];
            }
        }
    }

    /**
     * Records the outbound edge counts of `$ids` in their degrees.
     *
     * @param list<string> $ids
     */
    private function countOutbound(array $ids): void
    {
        $statement = $this->pdo->prepare(
            'SELECT source_id, COUNT(*) AS edge_count FROM edges WHERE project_id = ? ' . $this->edgeFilter() .
            sprintf(' AND source_id IN (%s) GROUP BY source_id', self::placeholders($ids)),
        );
        $statement->execute([$this->projectId, ...$this->edgeKinds, $this->minConfidenceRank, ...$ids]);
        foreach ($statement->fetchAll() as $row) {
            $this->outDegrees[(string) $row['source_id']] = (int) $row['edge_count'];
        }
    }

    /** The kind and confidence conditions every degree counts under, as SQL. */
    private function edgeFilter(): string
    {
        return sprintf('AND kind IN (%s) ', self::placeholders($this->edgeKinds))
            . "AND CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER)";
    }

    /**
     * One `?` per value, comma-separated, for an `IN (…)` list.
     *
     * @param list<string> $values
     */
    private static function placeholders(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }
}
