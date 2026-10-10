<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Result\ResultEnvelope;
use PDO;

/**
 * The graph at a glance: what it holds, and how the project is partitioned.
 *
 * Counts and listings only, none of them a traversal, so nothing here needs
 * the node, edge and time bounds the walks carry. The walks themselves live
 * apart, one question each: {@see FlowQuery}, {@see ImpactAnalysisQuery},
 * {@see DependencyCycleQuery} and {@see ArchitectureHealthQuery}.
 */
final readonly class GraphSummaryQuery extends AbstractArchitectureQueryService
{
    /** Node, relationship, role, and language counts: the orientation query for an unfamiliar codebase. */
    public function architectureSummary(string $projectId, int $limit = 50): ResultEnvelope
    {
        self::assertLimit($limit);
        $project = $this->project($projectId);
        $nodes = $this->counts('nodes', $projectId, $limit);
        $edges = $this->counts('edges', $projectId, $limit);
        $files = $this->counts('files', $projectId, $limit, 'language');
        $roles = $this->counts('classifications', $projectId, $limit, 'role');
        $diagnostics = $this->scalar('SELECT COUNT(*) FROM diagnostics WHERE project_id = :project', $projectId);
        $totalNodes = $this->scalar('SELECT COUNT(*) FROM nodes WHERE project_id = :project', $projectId);
        $totalEdges = $this->scalar('SELECT COUNT(*) FROM edges WHERE project_id = :project', $projectId);

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('%s contains %d nodes and %d relationships.', $project['name'], $totalNodes, $totalEdges),
            [
                'project' => ['name' => $project['name']],
                'node_kinds' => $nodes,
                'edge_kinds' => $edges,
                'languages' => $files,
                'roles' => $roles,
                'diagnostics' => $diagnostics,
            ],
            [],
            [],
            $this->distinctCount('nodes', $projectId) > $limit
                || $this->distinctCount('edges', $projectId) > $limit
                || $this->distinctCount('files', $projectId, 'language') > $limit
                || $this->distinctCount('classifications', $projectId, 'role') > $limit,
        );
    }

    /** How the project is partitioned, and whether each boundary was declared or inferred. */

    public function listBoundaries(string $projectId, ?string $source = null, int $limit = 50, int $offset = 0): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($offset < 0 || $offset > 100_000) {
            throw new InvalidArgumentException('offset must be between 0 and 100000.');
        }
        if ($source !== null && !in_array($source, ['explicit', 'inferred'], true)) {
            throw new InvalidArgumentException('source must be explicit or inferred.');
        }
        $sql = 'SELECT b.*, COUNT(bm.node_id) AS member_count FROM boundaries b LEFT JOIN boundary_memberships bm ON bm.boundary_id = b.id WHERE b.project_id = :project';
        if ($source !== null) {
            $sql .= ' AND b.source = :source';
        }
        $sql .= ' GROUP BY b.id ORDER BY b.source, b.name LIMIT :limit OFFSET :offset';
        $statement = $this->pdo->prepare($sql);
        $statement->bindValue(':project', $projectId);
        if ($source !== null) {
            $statement->bindValue(':source', $source);
        }
        $statement->bindValue(':limit', $limit + 1, PDO::PARAM_INT);
        $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $boundaries = [];
        $evidence = [];
        foreach ($rows as $row) {
            $members = $this->boundaryMemberSample($row['id'], 5);
            [$matcher, $aliases] = self::matcherAndAliases($row['matcher_json']);
            $boundaries[] = [
                'id' => $row['id'], 'name' => $row['name'], 'source' => $row['source'],
                'matcher' => $matcher, 'aliases' => $aliases, 'member_count' => (int) $row['member_count'],
                'sample_members' => array_map(static fn(array $member): array => [
                    'id' => $member['id'], 'kind' => $member['kind'], 'canonical_name' => $member['canonical_name'],
                ], $members),
            ];
            foreach ($members as $member) {
                if ($member['relative_path'] !== null) {
                    $evidence[] = [
                        'boundary_id' => $row['id'], 'component_id' => $member['id'], 'path' => $member['relative_path'],
                        'start_line' => $member['start_line'], 'end_line' => $member['end_line'],
                    ];
                }
            }
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Listed %d architecture boundar%s.', count($boundaries), count($boundaries) === 1 ? 'y' : 'ies'),
            ['boundaries' => $boundaries, 'pagination' => ['offset' => $offset, 'next_offset' => $truncated ? $offset + $limit : null, 'truncation_reason' => $truncated ? 'result_limit' : null]],
            $evidence,
            [],
            $truncated,
        );
    }
    /**
     * Rows per kind (or per `$column`) of one table, largest first, at most `$limit` of them.
     *
     * @return list<array{kind: string, count: int}>
     */
    private function counts(string $table, string $projectId, int $limit, string $column = 'kind'): array
    {
        $statement = $this->pdo->prepare(sprintf(
            'SELECT %1$s AS kind, COUNT(*) AS count FROM %2$s WHERE project_id = :project GROUP BY %1$s ORDER BY count DESC, %1$s LIMIT :limit',
            $column,
            $table,
        ));
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn(array $row): array => ['kind' => $row['kind'], 'count' => (int) $row['count']], $statement->fetchAll());
    }
    /** One scalar column from a prepared query. */
    private function scalar(string $sql, string $projectId): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['project' => $projectId]);
        return (int) $statement->fetchColumn();
    }
    /** Count of distinct values, used for the graph-size figures. */
    private function distinctCount(string $table, string $projectId, string $column = 'kind'): int
    {
        return $this->scalar(sprintf('SELECT COUNT(DISTINCT %s) FROM %s WHERE project_id = :project', $column, $table), $projectId);
    }
    /**
     * A bounded sample of a boundary's members, since listing every one is unhelpful.
     *
     * @return list<array<string, mixed>>
     */
    private function boundaryMemberSample(string $boundaryId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.start_line, n.end_line, f.relative_path ' .
            'FROM boundary_memberships bm JOIN nodes n ON n.id = bm.node_id LEFT JOIN files f ON f.id = n.file_id ' .
            'WHERE bm.boundary_id = :boundary ORDER BY n.canonical_name LIMIT :limit',
        );
        $statement->bindValue(':boundary', $boundaryId);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }
}
