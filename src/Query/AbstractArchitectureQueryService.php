<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

abstract readonly class AbstractArchitectureQueryService
{
    protected const FLOW_EDGE_KINDS = [
        'routes_to', 'calls', 'dispatches', 'handles', 'listens_to', 'constructs',
        'injects', 'binds', 'observes', 'depends_on', 'imports', 'uses_middleware',
    ];
    protected const IMPACT_EDGE_KINDS = [
        'routes_to', 'calls', 'dispatches', 'handles', 'listens_to', 'constructs', 'injects',
        'binds', 'observes', 'depends_on', 'imports', 'uses_middleware', 'references',
        'extends', 'implements', 'returns', 'exports', 're_exports', 'uses_trait',
    ];

    public function __construct(
        protected PDO $pdo,
        protected ?Closure $clock = null,
    ) {}

    /** @return array<string, mixed> */
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

    /** @return list<array<string, mixed>> */
    protected function resolve(string $projectId, string $query): array
    {
        if (trim($query) === '') {
            throw new InvalidArgumentException('Flow endpoint must not be empty.');
        }
        if (preg_match('/^(symbol|route)_[a-f0-9]{64}$/', $query)) {
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
        $rows = $statement->fetchAll();
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

    protected static function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** @return array<string, mixed>|null */
    protected function node(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    /** @param list<array<string, mixed>> $boundaries */
    protected function resolvePolicyBoundary(string $reference, array $boundaries): string
    {
        $idMatches = array_values(array_filter($boundaries, static fn(array $boundary): bool => $boundary['id'] === $reference));
        if (count($idMatches) === 1) {
            return $idMatches[0]['id'];
        }
        $nameMatches = array_values(array_filter($boundaries, static fn(array $boundary): bool => $boundary['name'] === $reference));
        if ($nameMatches === []) {
            throw new InvalidArgumentException('Unknown policy boundary: ' . $reference);
        }
        if (count($nameMatches) > 1) {
            throw new InvalidArgumentException('Ambiguous policy boundary name; use its stable ID: ' . $reference);
        }
        return $nameMatches[0]['id'];
    }

    protected function now(): int
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }

    /** @return array<string, int> */
    protected function confidenceQueryBounds(int $maxEdges, int $timeoutMs, string $minConfidence): array
    {
        if ($maxEdges < 1 || $maxEdges > 100_000) {
            throw new InvalidArgumentException('max_edges must be between 1 and 100000.');
        }
        return $this->confidenceThreshold($timeoutMs, $minConfidence);
    }

    /** @return array<string, int> */
    protected function confidenceThreshold(int $timeoutMs, string $minConfidence): array
    {
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 5000.');
        }
        $confidenceRank = ['possible' => 1, 'probable' => 2, 'certain' => 3];
        if (!isset($confidenceRank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        return $confidenceRank;
    }

    /**
     * @param array<string, list<string>> $adjacency
     * @param array<string, list<string>> $reverse
     * @return array{components: list<list<string>>, timed_out: bool}
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
            $stack = [[$start, 0]];
            while ($stack !== []) {
                if ($deadline !== null && (++$operations % 256) === 0 && $this->now() > $deadline) {
                    $timedOut = true;
                    break 2;
                }
                $top = array_key_last($stack);
                [$nodeId, $index] = $stack[$top];
                if ($index < count($adjacency[$nodeId])) {
                    $next = $adjacency[$nodeId][$index];
                    ++$stack[$top][1];
                    if (!isset($seen[$next])) {
                        $seen[$next] = true;
                        $stack[] = [$next, 0];
                    }
                    continue;
                }
                $finish[] = $nodeId;
                array_pop($stack);
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

    /** @param list<string> $nodeIds @return array<string, list<array<string, mixed>>> */
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

    /** @param list<string> $nodeIds @return array<string, list<array{id: string, name: string, source: string}>> */
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

    /** @return array<string, mixed> */
    protected static function decode(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    protected static function assertLimit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Limit must be between 1 and 100.');
        }
    }

}
