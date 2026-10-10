<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Query\Diagram\DiagramRenderer;
use Knossos\Query\Diagram\MermaidRenderer;
use Knossos\Query\Diagram\PlantUmlRenderer;

/**
 * Renders a slice of the graph as Mermaid or PlantUML source.
 *
 * Node and edge caps are the point: a diagram of a whole real codebase is
 * unreadable, so the slice is bounded and the omission reported.
 */
final readonly class DiagramExportService extends AbstractArchitectureQueryService
{
    /**
     * How many candidates the slice may be grown from, whatever `max_nodes` is.
     *
     * The pool is loaded with its edges to choose a connected slice, so it is
     * bounded independently of the diagram's own node cap.
     */
    private const int MAX_CANDIDATE_POOL = 2000;

    /**
     * Choose up to `$maxNodes` nodes that are actually connected to each other.
     *
     * Ranking by degree alone is not enough: the highest-degree nodes in a real
     * codebase are hubs whose neighbours are spread across everything else, so
     * the top N of them share almost no edges and the diagram comes out as a
     * page of unconnected boxes. This seeds on the busiest node and then
     * repeatedly takes whichever candidate is most strongly attached to what is
     * already chosen, falling back to the next busiest when nothing connects —
     * which is what starts a second component rather than stalling.
     *
     * @param list<array<string, mixed>> $pool candidates, most connected first
     * @param list<array<string, mixed>> $poolEdges distinct edges within the pool
     * @return list<array<string, mixed>>
     */
    private function selectSlice(array $pool, array $poolEdges, int $maxNodes): array
    {
        if (count($pool) <= $maxNodes) {
            return $pool;
        }
        $neighbours = [];
        foreach ($poolEdges as $edge) {
            $neighbours[$edge['source_id']][] = $edge['target_id'];
            $neighbours[$edge['target_id']][] = $edge['source_id'];
        }
        $byId = array_column($pool, null, 'id');
        $selected = [];
        $attachment = [];
        $remaining = $byId;
        while (count($selected) < $maxNodes && $remaining !== []) {
            $bestId = null;
            $bestAttachment = -1;
            foreach ($remaining as $id => $_) {
                // $remaining preserves the pool's degree-then-name order, so
                // the first candidate at a given attachment wins the tie and
                // the export stays deterministic.
                if (($attachment[$id] ?? 0) > $bestAttachment) {
                    $bestAttachment = $attachment[$id] ?? 0;
                    $bestId = $id;
                }
            }
            if ($bestId === null) {
                break;
            }
            $selected[$bestId] = $byId[$bestId];
            unset($remaining[$bestId]);
            foreach ($neighbours[$bestId] ?? [] as $neighbour) {
                if (isset($remaining[$neighbour])) {
                    $attachment[$neighbour] = ($attachment[$neighbour] ?? 0) + 1;
                }
            }
        }

        return array_values($selected);
    }

    /**
     * Render a bounded slice of the graph as Mermaid or PlantUML.
     *
     * @param list<string> $edgeKinds
     */
    public function exportDiagram(string $projectId, string $format = 'mermaid', ?string $boundary = null, array $edgeKinds = [], string $minConfidence = 'possible', string $direction = 'LR', int $maxNodes = 200, int $maxEdges = 500): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::validateArguments($format, $direction, $maxNodes, $maxEdges, $minConfidence);
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'dependency');
        $boundaryId = $boundary === null ? null : $this->boundaryReferences($projectId)->resolve($boundary);
        $minRank = self::CONFIDENCE_RANK[$minConfidence];

        $pool = $this->candidatePool($projectId, $edgeKinds, $minRank, $boundaryId, $maxNodes);
        $truncated = count($pool) > $maxNodes;
        $reasons = $truncated ? ['node_limit'] : [];
        $poolEdges = $this->poolEdges($projectId, $pool, $edgeKinds, $minRank);
        $rows = $this->selectSlice($pool, $poolEdges, $maxNodes);
        $nodes = [];
        foreach ($rows as $row) {
            $nodes[$row['id']] = $row;
        }
        $edges = array_values(array_filter(
            $poolEdges,
            static fn(array $edge): bool => isset($nodes[$edge['source_id']], $nodes[$edge['target_id']]),
        ));
        if (count($edges) > $maxEdges) {
            $truncated = true;
            $reasons[] = 'edge_limit';
        }
        $edges = array_slice($edges, 0, $maxEdges);
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
            ['format' => $format, 'direction' => $direction, 'boundary_id' => $boundaryId, 'diagram' => self::renderSlice(self::rendererFor($format), $direction, $nodes, $edges), 'bounds' => [
                'max_nodes' => $maxNodes, 'max_edges' => $maxEdges, 'nodes_exported' => count($nodes),
                'edges_exported' => count($edges), 'truncation_reasons' => array_values(array_unique($reasons)),
            ]],
            $evidence,
            ['Diagram source represents the bounded active static graph and may be incomplete when truncated.'],
            $truncated,
        );
    }

    /** Check the export arguments, in the order their errors are reported. */
    private static function validateArguments(string $format, string $direction, int $maxNodes, int $maxEdges, string $minConfidence): void
    {
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
        if (!isset(self::CONFIDENCE_RANK[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
    }

    /** The renderer for a format {@see validateArguments} has accepted. */
    private static function rendererFor(string $format): DiagramRenderer
    {
        return $format === 'mermaid' ? new MermaidRenderer() : new PlantUmlRenderer();
    }

    /**
     * The candidates the slice is grown from, most connected first.
     *
     * Ranked by how connected each node is, because a bounded diagram of a
     * real codebase has to choose, and the alphabetically-first nodes are
     * rarely the ones whose relationships explain anything — truncating on
     * name produced pages of unconnected boxes.
     *
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function candidatePool(string $projectId, array $edgeKinds, int $minRank, ?string $boundaryId, int $maxNodes): array
    {
        $kindPlaceholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $confidenceCase = "CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER)";
        $degreeSql = sprintf(
            'SELECT node_id, COUNT(*) AS degree FROM (' .
            'SELECT source_id AS node_id FROM edges WHERE project_id = ? AND kind IN (%s) AND %s ' .
            'UNION ALL ' .
            'SELECT target_id AS node_id FROM edges WHERE project_id = ? AND kind IN (%s) AND %s' .
            ') GROUP BY node_id',
            $kindPlaceholders,
            $confidenceCase,
            $kindPlaceholders,
            $confidenceCase,
        );
        $sql = 'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.start_line, n.end_line, f.relative_path, ' .
            'COALESCE(d.degree, 0) AS degree ' .
            'FROM nodes n LEFT JOIN files f ON f.id = n.file_id ' .
            'LEFT JOIN (' . $degreeSql . ') d ON d.node_id = n.id ' .
            'WHERE n.project_id = ?';
        $nodeParams = [
            $projectId, ...$edgeKinds, $minRank,
            $projectId, ...$edgeKinds, $minRank,
            $projectId,
        ];
        if ($boundaryId !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM boundary_memberships bm WHERE bm.node_id = n.id AND bm.boundary_id = ?)';
            $nodeParams[] = $boundaryId;
        }
        // Name and id break ties so the export stays deterministic. The pool is
        // wider than the slice so the selection below has neighbours to grow
        // into rather than only the very top of the degree ranking.
        // Eight times the slice, capped: always wider than the slice itself, so
        // the pool is what tells count($pool) > $maxNodes that anything was left
        // out. A max() against $maxNodes + 1 here could never bind.
        $sql .= ' ORDER BY degree DESC, n.canonical_name, n.id LIMIT ?';
        $nodeParams[] = min(self::MAX_CANDIDATE_POOL, $maxNodes * 8);
        $statement = $this->pdo->prepare($sql);
        // bindValue keeps the integers integers; execute([...]) would send them as text.
        foreach ($nodeParams as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * The distinct edges between candidates of the pool, in source, target and kind order.
     *
     * @param list<array<string, mixed>> $pool
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function poolEdges(string $projectId, array $pool, array $edgeKinds, int $minRank): array
    {
        if ($pool === []) {
            return [];
        }
        $poolIds = array_column($pool, 'id');
        $poolPlaceholders = implode(',', array_fill(0, count($poolIds), '?'));
        $kindPlaceholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        // DISTINCT: a relationship written at ten call sites is ten edges
        // but one arrow, and rendering it ten times drew ten overlapping
        // arrows while spending ten of the caller's max_edges budget.
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT kind, source_id, target_id FROM edges WHERE project_id = ? ' .
            sprintf('AND source_id IN (%s) AND target_id IN (%s) AND kind IN (%s) ', $poolPlaceholders, $poolPlaceholders, $kindPlaceholders) .
            "AND CASE confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY source_id, target_id, kind',
        );
        $statement->execute([$projectId, ...$poolIds, ...$poolIds, ...$edgeKinds, $minRank]);

        return $statement->fetchAll();
    }

    /**
     * Hand the slice to the renderer under short aliases (`n1`, `n2`, ...) in
     * slice order, with labels made safe to embed in any diagram source.
     *
     * @param array<string, array<string, mixed>> $nodes id => node row
     * @param list<array<string, mixed>> $edges
     */
    private static function renderSlice(DiagramRenderer $renderer, string $direction, array $nodes, array $edges): string
    {
        $aliases = [];
        $labels = [];
        foreach (array_keys($nodes) as $index => $id) {
            $aliases[$id] = 'n' . ($index + 1);
            $labels[$aliases[$id]] = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $nodes[$id]['display_name'] . ' (' . $nodes[$id]['kind'] . ')') ?? 'component';
        }
        $arrows = [];
        foreach ($edges as $edge) {
            $arrows[] = [
                'source' => $aliases[$edge['source_id']],
                'target' => $aliases[$edge['target_id']],
                'label' => preg_replace('/[^A-Za-z0-9_.-]/', '_', $edge['kind']) ?? 'depends_on',
            ];
        }

        return $renderer->render($direction, $labels, $arrows);
    }
}
