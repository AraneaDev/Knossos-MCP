<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Boundary\BoundaryAliases;
use Knossos\Boundary\BoundaryReferences;
use PDO;
use PDOStatement;

/**
 * Shared foundation for the query services.
 *
 * Holds the edge-kind sets that decide what "reachable" and "impacted" mean, plus
 * the confidence filtering and traversal limits every graph walk needs. Those sets
 * live here rather than per service so two queries cannot quietly disagree about
 * which relationships count as a dependency.
 */
abstract readonly class AbstractArchitectureQueryService
{
    protected const FLOW_EDGE_KINDS = [
        'routes_to', 'calls', 'dispatches', 'handles', 'listens_to', 'constructs',
        'injects', 'binds', 'observes', 'depends_on', 'imports', 'uses_middleware',
    ];
    /** The relationships that count as one component depending on another: what a policy check and the boundary matrix walk. */
    public const IMPACT_EDGE_KINDS = [
        'routes_to', 'calls', 'dispatches', 'handles', 'listens_to', 'constructs', 'injects',
        'binds', 'observes', 'depends_on', 'imports', 'uses_middleware', 'references',
        'extends', 'implements', 'returns', 'exports', 're_exports', 'uses_trait',
    ];
    /**
     * The three confidence levels in order, so two can be compared. One
     * definition for every query that filters, ranks or merges by confidence,
     * where there had been seven copies of this literal.
     */
    protected const CONFIDENCE_RANK = ['possible' => 1, 'probable' => 2, 'certain' => 3];

    public function __construct(
        protected PDO $pdo,
        protected ?Closure $clock = null,
    ) {}

    /**
     * Whether an edge exists only in the source and is gone from the built code; see {@see ErasedTypeEdge}.
     *
     * @param array<string, mixed> $edge An edge row carrying `kind` and `attributes_json`.
     */
    protected static function isErasedTypeEdge(array $edge): bool
    {
        return ErasedTypeEdge::matches($edge);
    }

    /**
     * Assert the project exists, so a bad project_id fails clearly rather than returning empty results.
     *
     * @return array<string, mixed>
     */
    protected function project(string $projectId): array
    {
        if ($projectId === '') {
            throw new InvalidArgumentException('Project ID must not be empty.');
        }
        $statement = $this->pdo->prepare('SELECT id, name, root_realpath, active_scan_id FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $project = $statement->fetch();
        if ($project === false) {
            throw new InvalidArgumentException(sprintf('Project not found: %s', $projectId));
        }
        if (!is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            throw new InvalidArgumentException(sprintf('Project has no active snapshot: %s', $projectId));
        }

        return $project;
    }

    /**
     * What to tell a caller whose reference did not resolve to exactly one component.
     *
     * Nothing-matched and too-many-matched are different failures with different
     * recoveries: a caller can pick one of several candidates by stable ID, but a
     * caller with no candidates has no ID to pick and must search for the name
     * first. Advising disambiguation in the empty case sends them after an ID
     * that does not exist, so the two cases are worded apart.
     */
    protected const UNMATCHED_ADVICE = 'No component matched; find the name with find_component or search_architecture, then retry with a returned stable component ID.';

    protected const AMBIGUOUS_ADVICE = 'Use a returned stable component ID to disambiguate the request.';

    /**
     * Resolve a component reference to one node, reporting ambiguity rather than guessing.
     *
     * Exact matches first ({@see self::resolveExact()}); only when there are
     * none, the components whose canonical or display name starts with the
     * query. A read answers with candidates either way; a write must use
     * resolveExact() alone, or a prefix becomes a different component.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolve(string $projectId, string $query): array
    {
        $rows = $this->resolveExact($projectId, $query);
        if ($rows !== []) {
            return $rows;
        }

        $statement = $this->pdo->prepare(
            'SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE project_id = :project ' .
            "AND (canonical_name LIKE :prefix ESCAPE '!' OR display_name LIKE :prefix ESCAPE '!') ORDER BY canonical_name LIMIT 21",
        );
        $statement->execute(['project' => $projectId, 'prefix' => self::like($query) . '%']);
        return $statement->fetchAll();
    }

    /**
     * The components a reference names exactly: a stable id, or a canonical or display name equal to it.
     *
     * @return list<array<string, mixed>>
     */
    protected function resolveExact(string $projectId, string $query): array
    {
        if (trim($query) === '') {
            throw new InvalidArgumentException('Flow endpoint must not be empty.');
        }
        if (preg_match('/^(symbol|route)_[a-f0-9]{64}$/D', $query)) {
            $statement = $this->pdo->prepare('SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE project_id = :project AND id = :id');
            $statement->execute(['project' => $projectId, 'id' => $query]);
            $row = $statement->fetch();
            return $row === false ? [] : [$row];
        }
        $statement = $this->pdo->prepare(
            'SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE project_id = :project ' .
            'AND (canonical_name = :query OR display_name = :query) ' .
            'ORDER BY CASE WHEN canonical_name = :query THEN 0 ELSE 1 END, canonical_name LIMIT 21',
        );
        $statement->execute(['project' => $projectId, 'query' => $query]);
        return $statement->fetchAll();
    }
    /** Escape a value for a LIKE pattern so user input cannot inject wildcards. */

    protected static function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /**
     * Fetch one node row by id.
     *
     * @return array<string, mixed>|null
     */
    protected function node(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /**
     * The project's boundaries as policy references resolve against them: by
     * stable id, exact name, or a name the boundary had before a manifest
     * renamed it. Every reader of a policy's boundary references goes through
     * this one resolver, so they cannot disagree about what a policy names.
     */
    protected function boundaryReferences(string $projectId): BoundaryReferences
    {
        return BoundaryReferences::load($this->pdo, $projectId);
    }

    /**
     * A stored boundary matcher as an output shows it: the matcher without
     * the former names it carries, and those names beside it.
     *
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    protected static function matcherAndAliases(string $json): array
    {
        return BoundaryAliases::split(self::decode($json));
    }
    /** The clock, injectable so time-dependent results are testable. */

    protected function now(): int
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }

    /**
     * SQL bounds for a minimum confidence, so filtering happens in the query rather than in PHP.
     *
     * @return array<string, int>
     */
    protected function confidenceQueryBounds(int $maxEdges, int $timeoutMs, string $minConfidence): array
    {
        if ($maxEdges < 1 || $maxEdges > 100_000) {
            throw new InvalidArgumentException('max_edges must be between 1 and 100000.');
        }
        return $this->confidenceThreshold($timeoutMs, $minConfidence);
    }

    /**
     * Validate a confidence level, rejecting an unknown one rather than defaulting it.
     *
     * @return array<string, int>
     */
    protected function confidenceThreshold(int $timeoutMs, string $minConfidence): array
    {
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 5000.');
        }
        $confidenceRank = self::CONFIDENCE_RANK;
        if (!isset($confidenceRank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        return $confidenceRank;
    }

    /**
     * Stream a bounded result set, stopping at the row limit, the deadline, or the consumer.
     *
     * This replaces fetchAll() in every deadline-carrying traversal. A deadline
     * cannot bound a phase that has already finished, and fetchAll() over a
     * 100,001-row join finished before the first check ever ran — so the
     * documented timeout_ms bounded only the cheap part of the walk while the
     * whole joined result sat in memory, and nothing else on the connection ran
     * meanwhile.
     *
     * The clock is read on the first row and every 64th after it. Reading
     * hrtime() per row is itself measurable at 100,000 rows, and 64 rows is far
     * finer than the millisecond resolution the deadline is expressed in;
     * checking the first row is what lets an already-expired deadline stop the
     * walk before anything is read.
     *
     * A caller whose own cap is not expressible in rows — a node budget, say —
     * stops the walk by returning false from $consume and reports its own
     * reason, so the reasons never double up on one stop.
     *
     * @param callable(array<string, mixed>): bool $consume receives each row; false stops the walk
     * @param string $rowLimitReason truncation reason to report when $maxRows is exceeded
     * @return list<string> truncation reasons, empty when the set was exhausted or $consume stopped it
     */
    protected function streamBounded(PDOStatement $statement, int $maxRows, int $deadline, callable $consume, string $rowLimitReason = 'edge_limit'): array
    {
        $seen = 0;
        try {
            while (($row = $statement->fetch()) !== false) {
                if (++$seen > $maxRows) {
                    return [$rowLimitReason];
                }
                if (($seen % 64) === 1 && $this->now() > $deadline) {
                    return ['time_limit'];
                }
                if (!$consume($row)) {
                    return [];
                }
            }
        } finally {
            // An abandoned result set keeps the statement open otherwise.
            $statement->closeCursor();
        }

        return [];
    }

    /**
     * Kosaraju's algorithm over the edge set, bounded so a large graph cannot run unchecked.
     *
     * Both passes are iterative and linear in nodes plus edges: the depth-first
     * walk keeps the node and its next edge on two parallel stacks rather than a
     * pair per frame, so a chain a hundred thousand nodes deep needs no
     * recursion and no per-node array. Node keys may be ids or the integers a
     * caller numbered them with; every node must have an entry in both maps.
     *
     * @template TNode of array-key
     * @param array<TNode, list<TNode>> $adjacency
     * @param array<TNode, list<TNode>> $reverse
     * @return array{components: list<list<TNode>>, timed_out: bool}
     */
    protected function stronglyConnectedComponents(array $adjacency, array $reverse, ?int $deadline = null): array
    {
        $seen = [];
        $finish = [];
        $operations = 0;
        $timedOut = false;
        foreach (array_keys($adjacency) as $start) {
            if (isset($seen[$start])) {
                continue;
            }
            $seen[$start] = true;
            $nodes = [$start];
            $next = [0];
            $top = 0;
            while ($top >= 0) {
                if ($deadline !== null && (++$operations % 256) === 0 && $this->now() > $deadline) {
                    $timedOut = true;
                    break 2;
                }
                $nodeId = $nodes[$top];
                $index = $next[$top];
                if ($index < count($adjacency[$nodeId])) {
                    $next[$top] = $index + 1;
                    $target = $adjacency[$nodeId][$index];
                    if (!isset($seen[$target])) {
                        $seen[$target] = true;
                        $nodes[] = $target;
                        $next[] = 0;
                        ++$top;
                    }
                    continue;
                }
                $finish[] = $nodeId;
                array_pop($nodes);
                array_pop($next);
                --$top;
            }
        }

        // Kosaraju's correctness depends on a complete decreasing finish order.
        // A pass-one timeout leaves a partial order over which reverse DFS can
        // sweep several distinct SCCs into one false component, so discard it
        // entirely rather than run pass two over a truncated finish order.
        if ($timedOut) {
            return ['components' => [], 'timed_out' => true];
        }

        $components = [];
        $assigned = [];
        while ($finish !== []) {
            if ($deadline !== null && (++$operations % 256) === 0 && $this->now() > $deadline) {
                $timedOut = true;
                break;
            }
            $start = array_pop($finish);
            if (isset($assigned[$start])) {
                continue;
            }
            $assigned[$start] = true;
            $component = [];
            $stack = [$start];
            while ($stack !== []) {
                $current = array_pop($stack);
                $component[] = $current;
                foreach ($reverse[$current] as $next) {
                    if (!isset($assigned[$next])) {
                        $assigned[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
            sort($component, SORT_STRING);
            $components[] = $component;
        }
        return ['components' => $components, 'timed_out' => $timedOut];
    }

    /**
     * Classified roles for a set of nodes, fetched in one query.
     *
     * @param list<string> $nodeIds @return array<string, list<array<string, mixed>>>
     */
    protected function roles(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT node_id, role, origin, confidence, rule_id, attributes_json FROM classifications ' .
                sprintf('WHERE node_id IN (%s) ORDER BY node_id, role, rule_id', $placeholders),
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $result[$row['node_id']][] = [
                    'role' => $row['role'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                    'rule_id' => $row['rule_id'], 'attributes' => self::decode($row['attributes_json']),
                ];
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Boundary names for every node in a project, keyed the same way {@see boundaryNames} keys them.
     *
     * For a walk that streams its rows rather than collecting them: the node ids
     * are not known in advance, and asking per node would issue a query per edge.
     * Membership rows are far smaller and far fewer than the joined edge rows a
     * caller would otherwise have to hold in memory to build the id list first.
     *
     * @return array<string, list<array{id: string, name: string, source: string}>>
     */
    protected function projectBoundaryNames(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT bm.node_id, b.id, b.name, b.source FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id ' .
            'WHERE bm.project_id = :project ORDER BY bm.node_id, b.source, b.name',
        );
        $statement->execute(['project' => $projectId]);
        $result = [];
        while (($row = $statement->fetch()) !== false) {
            $result[$row['node_id']][] = ['id' => $row['id'], 'name' => $row['name'], 'source' => $row['source']];
        }

        return $result;
    }

    /**
     * Boundary names for a set of nodes, for annotating results.
     *
     * @param list<string> $nodeIds @return array<string, list<array{id: string, name: string, source: string}>>
     */
    protected function boundaryNames(array $nodeIds): array
    {
        if ($nodeIds === []) {
            return [];
        }
        $result = [];
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT bm.node_id, b.id, b.name, b.source FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id ' .
                sprintf('WHERE bm.node_id IN (%s) ORDER BY bm.node_id, b.source, b.name', $placeholders),
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $result[$row['node_id']][] = ['id' => $row['id'], 'name' => $row['name'], 'source' => $row['source']];
            }
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /**
     * Boundaries that span the whole repository, keyed by id.
     *
     * Package inference gives a single-package repository a boundary whose path
     * prefix is empty, so every file in the tree belongs to it. Such a boundary
     * says nothing about how the code is partitioned, and counting it as shared
     * ground between two components makes every in-repository edge look
     * intra-boundary — which silently zeroed cross-boundary reporting for the
     * most common project shape there is.
     *
     * @return array<string, true>
     */
    protected function repositoryWideBoundaryIds(string $projectId): array
    {
        $statement = $this->pdo->prepare('SELECT id, matcher_json FROM boundaries WHERE project_id = :project');
        $statement->execute(['project' => $projectId]);
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $matcher = self::decode((string) $row['matcher_json']);
            $prefix = ($matcher['type'] ?? null) === 'path_prefix' ? ($matcher['value'] ?? null) : ($matcher['path_prefix'] ?? null);
            if (is_string($prefix) && trim($prefix, '/') === '') {
                $ids[(string) $row['id']] = true;
            }
        }

        return $ids;
    }

    /**
     * Decode a stored JSON column, tolerating a null.
     *
     * @return array<string, mixed>
     */
    protected static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
    /** Reject a limit outside its bounds rather than clamping it silently. */

    protected static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be between 1 and 100.');
        }
    }

}
