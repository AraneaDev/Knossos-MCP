<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

final readonly class DiagramExportService extends AbstractArchitectureQueryService
{
    /** @param list<string> $edgeKinds */
    public function exportDiagram(string $projectId, string $format = 'mermaid', ?string $boundary = null, array $edgeKinds = [], string $minConfidence = 'possible', string $direction = 'LR', int $maxNodes = 200, int $maxEdges = 500): ResultEnvelope
    {
        $project = $this->project($projectId);
        if (!in_array($format, ['mermaid', 'plantuml'], true)) {
            throw new InvalidArgumentException('format must be mermaid or plantuml.');
        }
        if (!in_array($direction, ['LR', 'TB'], true)) {
            throw new InvalidArgumentException('direction must be LR or TB.');
        }
        if ($maxNodes < 1 || $maxNodes > 400) {
            throw new InvalidArgumentException('max_nodes must be between 1 and 400.');
        }
        if ($maxEdges < 1 || $maxEdges > 1000) {
            throw new InvalidArgumentException('max_edges must be between 1 and 1000.');
        }
        $rank = ['possible' => 1, 'probable' => 2, 'certain' => 3];
        if (!isset($rank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        $edgeKinds = $edgeKinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::IMPACT_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported dependency relationship.');
        }

        $params = ['project' => $projectId];
        $boundaryId = null;
        if ($boundary !== null) {
            $statement = $this->pdo->prepare('SELECT id, name, source FROM boundaries WHERE project_id = :project ORDER BY source, name, id');
            $statement->execute(['project' => $projectId]);
            $boundaryId = $this->resolvePolicyBoundary($boundary, $statement->fetchAll());
        }
        $sql = 'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.start_line, n.end_line, f.relative_path ' .
            'FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.project_id = :project';
        if ($boundaryId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM boundary_memberships bm WHERE bm.node_id = n.id AND bm.boundary_id = :boundary)';
            $params['boundary'] = $boundaryId;
        }
        $sql .= ' ORDER BY n.canonical_name, n.id LIMIT :limit';
        $statement = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $maxNodes + 1, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $maxNodes;
        $reasons = $truncated ? ['node_limit'] : [];
        $rows = array_slice($rows, 0, $maxNodes);
        $nodes = [];
        foreach ($rows as $row) {
            $nodes[$row['id']] = $row;
        }
        $edges = [];
        if ($nodes !== []) {
            $nodeIds = array_keys($nodes);
            $nodePlaceholders = implode(',', array_fill(0, count($nodeIds), '?'));
            $kindPlaceholders = implode(',', array_fill(0, count($edgeKinds), '?'));
            $statement = $this->pdo->prepare(
                'SELECT id, kind, source_id, target_id, confidence FROM edges WHERE project_id = ? ' .
                sprintf('AND source_id IN (%s) AND target_id IN (%s) AND kind IN (%s) ', $nodePlaceholders, $nodePlaceholders, $kindPlaceholders) .
                "AND CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
                'ORDER BY source_id, target_id, kind, id LIMIT ?',
            );
            $statement->execute([$projectId, ...$nodeIds, ...$nodeIds, ...$edgeKinds, $rank[$minConfidence], $maxEdges + 1]);
            $edges = $statement->fetchAll();
            if (count($edges) > $maxEdges) {
                $truncated = true;
                $reasons[] = 'edge_limit';
            }
            $edges = array_slice($edges, 0, $maxEdges);
        }
        $aliases = [];
        foreach (array_keys($nodes) as $index => $id) {
            $aliases[$id] = 'n' . ($index + 1);
        }
        $lines = [];
        if ($format === 'mermaid') {
            $lines[] = 'flowchart ' . $direction;
            foreach ($nodes as $id => $node) {
                $lines[] = sprintf('  %s["%s"]', $aliases[$id], $this->diagramLabel($node, 'mermaid'));
            }
            foreach ($edges as $edge) {
                $lines[] = sprintf('  %s -->|%s| %s', $aliases[$edge['source_id']], $this->diagramEdgeLabel($edge['kind']), $aliases[$edge['target_id']]);
            }
        } else {
            $lines[] = '@startuml';
            if ($direction === 'LR') {
                $lines[] = 'left to right direction';
            }
            foreach ($nodes as $id => $node) {
                $lines[] = sprintf('component "%s" as %s', $this->diagramLabel($node, 'plantuml'), $aliases[$id]);
            }
            foreach ($edges as $edge) {
                $lines[] = sprintf('%s --> %s : %s', $aliases[$edge['source_id']], $aliases[$edge['target_id']], $this->diagramEdgeLabel($edge['kind']));
            }
            $lines[] = '@enduml';
        }
        $evidence = [];
        foreach (array_slice($rows, 0, 100) as $row) {
            if ($row['relative_path'] !== null) {
                $evidence[] = [
                    'component_id' => $row['id'], 'path' => $row['relative_path'], 'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Exported %d nodes and %d relationships as %s source.', count($nodes), count($edges), $format),
            ['format' => $format, 'direction' => $direction, 'boundary_id' => $boundaryId, 'diagram' => implode("\n", $lines) . "\n", 'bounds' => [
                'max_nodes' => $maxNodes, 'max_edges' => $maxEdges, 'nodes_exported' => count($nodes),
                'edges_exported' => count($edges), 'truncation_reasons' => array_values(array_unique($reasons)),
            ]],
            $evidence,
            ['Diagram source represents the bounded active static graph and may be incomplete when truncated.'],
            $truncated,
        );
    }

    /** @param array<string, mixed> $node */
    private function diagramLabel(array $node, string $format): string
    {
        $label = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $node['display_name'] . ' (' . $node['kind'] . ')') ?? 'component';
        if ($format === 'mermaid') {
            return str_replace(['&', '<', '>', '"'], ['&amp;', '&lt;', '&gt;', '&quot;'], $label);
        }
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $label);
    }
    private function diagramEdgeLabel(string $kind): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $kind) ?? 'depends_on';
    }
}
