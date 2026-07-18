<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

final readonly class ComponentQueryService extends AbstractArchitectureQueryService
{
    public function findComponent(string $projectId, string $name, int $limit = 20): ResultEnvelope
    {
        if (trim($name) === '') {
            throw new InvalidArgumentException('Component name must not be empty.');
        }
        self::assertLimit($limit);
        $project = $this->project($projectId);
        $statement = $this->pdo->prepare(
            'SELECT n.*, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id ' .
            'WHERE n.project_id = :project AND (n.canonical_name = :name OR n.display_name = :name ' .
            "OR n.canonical_name LIKE :prefix ESCAPE '!' OR n.display_name LIKE :prefix ESCAPE '!' " .
            "OR n.canonical_name LIKE :contains ESCAPE '!' OR n.display_name LIKE :contains ESCAPE '!') " .
            'ORDER BY CASE WHEN n.canonical_name = :name THEN 0 WHEN n.display_name = :name THEN 1 ' .
            "WHEN n.canonical_name LIKE :prefix ESCAPE '!' THEN 2 WHEN n.display_name LIKE :prefix ESCAPE '!' THEN 3 ELSE 4 END, " .
            'n.canonical_name LIMIT :fetch_limit',
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':name', $name);
        $statement->bindValue(':prefix', self::like($name) . '%');
        $statement->bindValue(':contains', '%' . self::like($name) . '%');
        $statement->bindValue(':fetch_limit', $limit + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $rolesByNode = $this->roles(array_column($rows, 'id'));
        $components = [];
        $evidence = [];
        foreach ($rows as $row) {
            $components[] = [
                'id' => $row['id'], 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                'display_name' => $row['display_name'], 'origin' => $row['origin'],
                'confidence' => $row['confidence'], 'roles' => $rolesByNode[$row['id']] ?? [],
                'attributes' => self::decode($row['attributes_json']),
            ];
            if ($row['relative_path'] !== null) {
                $evidence[] = [
                    'component_id' => $row['id'], 'path' => $row['relative_path'],
                    'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }
        $count = count($components);

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $count === 0 ? sprintf('No component matched "%s".', $name) : sprintf('Found %d component candidate%s.', $count, $count === 1 ? '' : 's'),
            ['query' => $name, 'components' => $components, 'ambiguous' => $count > 1],
            $evidence,
            [],
            $truncated,
        );
    }

    public function inspectComponent(string $projectId, string $component, int $maxRelationships = 25, int $maxChildren = 25, string $minConfidence = 'possible'): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($maxRelationships);
        self::assertLimit($maxChildren);
        $confidenceRank = ['possible' => 1, 'probable' => 2, 'certain' => 3];
        if (!isset($confidenceRank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        $matches = $this->resolve($projectId, $component);
        if (count($matches) !== 1) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                $matches === [] ? sprintf('No component matched "%s".', $component) : sprintf('Component "%s" is ambiguous.', $component),
                ['query' => $component, 'component' => null, 'ambiguous' => count($matches) > 1, 'candidates' => $matches],
            );
        }
        $nodeId = $matches[0]['id'];
        $statement = $this->pdo->prepare(
            'SELECT n.*, f.relative_path, p.id AS parent_component_id, p.kind AS parent_kind, ' .
            'p.canonical_name AS parent_canonical_name FROM nodes n LEFT JOIN files f ON f.id = n.file_id ' .
            'LEFT JOIN nodes p ON p.id = n.parent_id WHERE n.project_id = :project AND n.id = :node',
        );
        $statement->execute(['project' => $projectId, 'node' => $nodeId]);
        $node = $statement->fetch();
        if ($node === false) {
            throw new InvalidArgumentException('Resolved component no longer exists.');
        }

        $childrenStatement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence, f.relative_path, n.start_line, n.end_line ' .
            'FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = :project AND n.parent_id = :node ' .
            'ORDER BY n.canonical_name, n.id LIMIT :limit',
        );
        $childrenStatement->bindValue(':project', $projectId);
        $childrenStatement->bindValue(':node', $nodeId);
        $childrenStatement->bindValue(':limit', $maxChildren + 1, PDO::PARAM_INT);
        $childrenStatement->execute();
        $children = $childrenStatement->fetchAll();
        $childrenTruncated = count($children) > $maxChildren;
        $children = array_slice($children, 0, $maxChildren);

        $allowedConfidence = array_keys(array_filter(
            $confidenceRank,
            static fn(int $rank): bool => $rank >= $confidenceRank[$minConfidence],
        ));
        $relationships = function (string $direction) use ($projectId, $nodeId, $allowedConfidence, $maxRelationships): array {
            $nodeColumn = $direction === 'outgoing' ? 'e.source_id' : 'e.target_id';
            $otherColumn = $direction === 'outgoing' ? 'e.target_id' : 'e.source_id';
            $placeholders = implode(',', array_fill(0, count($allowedConfidence), '?'));
            $statement = $this->pdo->prepare(
                'SELECT e.id, e.kind, e.confidence, e.origin, e.attributes_json, e.start_line, e.end_line, ' .
                'other.id AS component_id, other.kind AS component_kind, other.canonical_name, other.display_name, ' .
                'f.relative_path FROM edges e JOIN nodes other ON other.id = ' . $otherColumn . ' ' .
                'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND ' . $nodeColumn . ' = ? ' .
                'AND e.confidence IN (' . $placeholders . ') ORDER BY e.kind, other.canonical_name, e.id LIMIT ?',
            );
            $statement->execute([$projectId, $nodeId, ...$allowedConfidence, $maxRelationships + 1]);
            $rows = $statement->fetchAll();
            $truncated = count($rows) > $maxRelationships;
            $rows = array_slice($rows, 0, $maxRelationships);
            return [array_map(static fn(array $row): array => [
                'id' => $row['id'], 'kind' => $row['kind'], 'confidence' => $row['confidence'], 'origin' => $row['origin'],
                'attributes' => self::decode($row['attributes_json']),
                'component' => ['id' => $row['component_id'], 'kind' => $row['component_kind'], 'canonical_name' => $row['canonical_name'], 'display_name' => $row['display_name']],
                'evidence' => $row['relative_path'] === null ? null : ['path' => $row['relative_path'], 'start_line' => $row['start_line'], 'end_line' => $row['end_line']],
            ], $rows), $truncated];
        };
        [$outgoing, $outgoingTruncated] = $relationships('outgoing');
        [$incoming, $incomingTruncated] = $relationships('incoming');
        $evidence = $node['relative_path'] === null ? [] : [[
            'component_id' => $nodeId, 'path' => $node['relative_path'],
            'start_line' => $node['start_line'], 'end_line' => $node['end_line'],
        ]];
        $truncationReasons = [];
        if ($childrenTruncated) {
            $truncationReasons[] = 'child_limit';
        }
        if ($outgoingTruncated) {
            $truncationReasons[] = 'outgoing_relationship_limit';
        }
        if ($incomingTruncated) {
            $truncationReasons[] = 'incoming_relationship_limit';
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Inspected %s.', $node['canonical_name']),
            ['query' => $component, 'ambiguous' => false, 'component' => [
                'id' => $nodeId, 'kind' => $node['kind'], 'canonical_name' => $node['canonical_name'],
                'display_name' => $node['display_name'], 'confidence' => $node['confidence'], 'origin' => $node['origin'],
                'attributes' => self::decode($node['attributes_json']), 'roles' => $this->roles([$nodeId])[$nodeId] ?? [],
                'boundaries' => $this->boundaryNames([$nodeId])[$nodeId] ?? [],
                'parent' => $node['parent_component_id'] === null ? null : ['id' => $node['parent_component_id'], 'kind' => $node['parent_kind'], 'canonical_name' => $node['parent_canonical_name']],
                'children' => $children, 'outgoing' => $outgoing, 'incoming' => $incoming,
            ], 'limits' => ['max_relationships_per_direction' => $maxRelationships, 'max_children' => $maxChildren, 'min_confidence' => $minConfidence, 'truncation_reasons' => $truncationReasons]],
            $evidence,
            [],
            $truncationReasons !== [],
        );
    }

    /** @param list<string> $kinds @param list<string> $roles @param list<string> $boundaryIds @param list<string> $confidences */
    public function searchArchitecture(string $projectId, string $query, array $kinds = [], array $roles = [], array $boundaryIds = [], array $confidences = [], int $limit = 20, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        if (trim($query) === '') {
            throw new InvalidArgumentException('query must not be empty.');
        }
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        foreach ([$kinds, $roles, $boundaryIds, $confidences] as $filter) {
            if (!array_is_list($filter) || count($filter) > 20) {
                throw new InvalidArgumentException('Search filters must be lists of at most 20 values.');
            }
            foreach ($filter as $value) {
                if (!is_string($value) || $value === '') {
                    throw new InvalidArgumentException('Search filters must contain non-empty strings.');
                }
            }
        }
        if (array_diff($confidences, ['certain', 'probable', 'possible']) !== []) {
            throw new InvalidArgumentException('confidence filter is invalid.');
        }
        $params = ['project' => $projectId, 'exact' => $query, 'prefix' => self::like($query) . '%', 'contains' => '%' . self::like($query) . '%'];
        $sql = "SELECT DISTINCT n.*, f.relative_path, CASE WHEN n.canonical_name = :exact THEN 0 WHEN n.display_name = :exact THEN 1 WHEN n.canonical_name LIKE :prefix ESCAPE '!' THEN 2 ELSE 3 END AS rank " .
            'FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = :project ' .
            "AND (n.canonical_name LIKE :contains ESCAPE '!' OR n.display_name LIKE :contains ESCAPE '!' OR n.attributes_json LIKE :contains ESCAPE '!' " .
            "OR EXISTS (SELECT 1 FROM classifications cq WHERE cq.node_id = n.id AND cq.role LIKE :contains ESCAPE '!'))";
        $sql .= $this->filterClause('n.kind', 'kind', $kinds, $params);
        $sql .= $this->filterClause('n.confidence', 'confidence', $confidences, $params);
        if ($roles !== []) {
            $clause = $this->filterClause('cr.role', 'role', $roles, $params);
            $sql .= ' AND EXISTS (SELECT 1 FROM classifications cr WHERE cr.node_id = n.id' . $clause . ')';
        }
        if ($boundaryIds !== []) {
            $clause = $this->filterClause('bm.boundary_id', 'boundary', $boundaryIds, $params);
            $sql .= ' AND EXISTS (SELECT 1 FROM boundary_memberships bm WHERE bm.node_id = n.id' . $clause . ')';
        }
        $sql .= ' ORDER BY rank, n.canonical_name LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $rolesByNode = $this->roles(array_column($rows, 'id'));
        $boundariesByNode = $this->boundaryNames(array_column($rows, 'id'));
        $results = [];
        $evidence = [];
        foreach ($rows as $row) {
            $results[] = [
                'id' => $row['id'], 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                'roles' => $rolesByNode[$row['id']] ?? [], 'boundaries' => $boundariesByNode[$row['id']] ?? [],
                'attributes' => self::decode($row['attributes_json']),
            ];
            if ($row['relative_path'] !== null) {
                $evidence[] = [
                    'component_id' => $row['id'], 'path' => $row['relative_path'],
                    'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d architecture search result%s.', count($results), count($results) === 1 ? '' : 's'),
            ['query' => $query, 'results' => $results, 'pagination' => ['offset' => $offset, 'next_offset' => $truncated ? $offset + $limit : null, 'truncation_reason' => $truncated ? 'result_limit' : null]],
            $evidence,
            [],
            $truncated,
        );
    }

    /** @param list<string> $values @param array<string, string> $params */
    private function filterClause(string $column, string $prefix, array $values, array &$params): string
    {
        if ($values === []) {
            return '';
        }
        $placeholders = [];
        foreach ($values as $index => $value) {
            $key = $prefix . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        return sprintf(' AND %s IN (%s)', $column, implode(',', $placeholders));
    }
}
