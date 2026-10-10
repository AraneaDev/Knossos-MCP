<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use Knossos\Store\ChunkedInQuery;

/**
 * Dependency cycles: the strongly connected components of the selected dependency graph.
 *
 * Its own class because three answers lean on it: `dependency_cycles` itself,
 * the cycle signal `architecture_health` folds into its hotspots, and the
 * cycles `review_diff` reports. Each reaches the same search with the same
 * bounds, so none of them can come to count a cycle the others do not.
 */
final readonly class DependencyCycleQuery extends AbstractArchitectureQueryService
{
    /**
     * Strongly connected components, bounded by node, edge, and time limits.
     *
     * The search runs in two phases. The whole selected graph is read as bare
     * endpoint pairs and searched first; names, file paths and lines are loaded
     * afterwards, and only for the members and edges of the components that are
     * reported. Reading every edge with both endpoints and its file attached
     * cost two orders of magnitude more than the search itself, so on a graph of
     * a few tens of thousands of edges the deadline ran out while rows were
     * still being read, and the search reported no cycles at all.
     *
     * max_nodes counts only the nodes that take part in a selected edge, so a
     * symbol nothing depends on and that depends on nothing never uses it up.
     *
     * @param list<string> $edgeKinds
     */
    public function dependencyCycles(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 50_000, int $maxEdges = 100_000, int $timeoutMs = 1000, bool $includeSelfLoops = false): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($maxNodes < 1 || $maxNodes > 50_000) {
            throw new InvalidArgumentException('max_nodes must be between 1 and 50000.');
        }
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'dependency');

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        // One read transaction over both phases, so the details are loaded
        // from the same snapshot the search ran over; a scan that commits in
        // between cannot take away a row the search counted on.
        $own = !$this->pdo->inTransaction() && $this->pdo->beginTransaction();
        try {
            $search = $this->cycleSearch($projectId, $edgeKinds, $confidenceRank, $minConfidence, $limit, $maxNodes, $maxEdges, $deadline, $includeSelfLoops);
        } finally {
            if ($own) {
                $this->pdo->commit();
            }
        }
        $truncationReasons = $search['truncation_reasons'];
        // A bounded search must not read as an exhaustive one: "Found 0
        // dependency cycle components" is the same sentence a genuinely acyclic
        // project gets, and a cycle living beyond the edge cap is invisible in
        // it. Naming the bound in the summary is what lets a caller tell the two
        // apart without reading bounds.truncation_reasons.
        $cycles = $search['cycles'];
        $summary = sprintf('Found %d dependency cycle component%s.', count($cycles), count($cycles) === 1 ? '' : 's');
        if ($truncationReasons !== []) {
            $summary .= sprintf(' The search was truncated (%s), so cycles beyond that bound are not reported.', implode(', ', $truncationReasons));
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $summary,
            [
                'cycles' => $cycles,
                'bounds' => [
                    'limit' => $limit, 'max_nodes' => $maxNodes, 'max_edges' => $maxEdges,
                    'timeout_ms' => $timeoutMs, 'nodes_examined' => $search['nodes_examined'],
                    'edges_examined' => $search['edges_examined'], 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $search['evidence'],
            [
                'Cycles are derived from the selected static dependency relationships and confidence threshold.',
                'Imports and re-exports that carry only types are erased at compile time and are not treated as dependencies, so a loop closed by nothing but `import type` is not reported.',
            ],
            $truncationReasons !== [],
        );
    }

    /**
     * Both phases of the cycle search: the whole selected graph searched as
     * numbered endpoint pairs, then the reported components' details loaded.
     *
     * @param list<string> $edgeKinds
     * @param array<string, int> $confidenceRank
     * @return array{nodes_examined: int, edges_examined: int, truncation_reasons: list<string>, cycles: list<array<string, mixed>>, evidence: list<array<string, mixed>>}
     */
    private function cycleSearch(string $projectId, array $edgeKinds, array $confidenceRank, string $minConfidence, int $limit, int $maxNodes, int $maxEdges, int $deadline, bool $includeSelfLoops): array
    {
        $graph = $this->cycleSearchGraph($projectId, $edgeKinds, $confidenceRank[$minConfidence], $maxNodes, $maxEdges, $deadline);
        $truncation = new Truncation();
        $truncation->add(...$graph['truncation_reasons']);
        $componentScan = $this->stronglyConnectedComponents($graph['adjacency'], $graph['reverse'], $deadline);
        if ($componentScan['timed_out']) {
            $truncation->add('time_limit');
        }
        // A single self-recursive symbol is ordinary recursion, not an
        // architectural tangle, so self-loops are opt-in.
        $components = [];
        foreach ($componentScan['components'] as $component) {
            if (count($component) > 1 || ($includeSelfLoops && isset($graph['self_loops'][$component[0]]))) {
                $components[] = $component;
            }
        }
        $nodeIds = $graph['node_ids'];
        $components = array_map(static function (array $component) use ($nodeIds): array {
            $members = [];
            foreach ($component as $index) {
                $members[] = $nodeIds[$index];
            }
            sort($members, SORT_STRING);

            return $members;
        }, $components);
        usort($components, static fn(array $a, array $b): int => (count($b) <=> count($a)) ?: ($a[0] <=> $b[0]));
        if (count($components) > $limit) {
            $components = array_slice($components, 0, $limit);
            $truncation->add('result_limit');
        }

        $detail = $this->cycleDetails($components, $graph, $confidenceRank);
        $truncation->add(...$detail['truncation_reasons']);

        return [
            'nodes_examined' => count($nodeIds), 'edges_examined' => count($graph['edge_ids']),
            'truncation_reasons' => $truncation->reasons(),
            'cycles' => $detail['cycles'], 'evidence' => $detail['evidence'],
        ];
    }

    /**
     * The selected dependency graph as numbered adjacency lists, read in one narrow pass.
     *
     * Each node is numbered in the order the edge walk first meets it, and the
     * search runs over those numbers rather than over the ids. An edge keeps its
     * id and its two ends, so the details of the few edges inside a reported
     * component can be loaded afterwards in the order the walk read them.
     *
     * The walk stops at the first edge whose new node would exceed max_nodes;
     * that edge's source still counts when it was new, as it always has.
     *
     * @param list<string> $edgeKinds
     * @return array{node_ids: list<string>, node_index: array<string, int>, adjacency: list<list<int>>, reverse: list<list<int>>, self_loops: array<int, true>, edge_ids: list<string>, edge_sources: list<int>, edge_targets: list<int>, truncation_reasons: list<string>}
     */
    private function cycleSearchGraph(string $projectId, array $edgeKinds, int $minimumRank, int $maxNodes, int $maxEdges, int $deadline): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        // Only what the search and the erased-import test read: the attributes
        // of any other kind are never decoded, so they are not read either.
        $statement = $this->pdo->prepare(
            "SELECT e.id, e.kind, e.source_id, e.target_id, CASE WHEN e.kind IN ('imports', 're_exports') THEN e.attributes_json END AS attributes_json " .
            'FROM edges e WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $statement->execute([$projectId, ...$edgeKinds, $minimumRank, $maxEdges + 1]);
        $graph = [
            'node_ids' => [], 'node_index' => [], 'adjacency' => [], 'reverse' => [], 'self_loops' => [],
            'edge_ids' => [], 'edge_sources' => [], 'edge_targets' => [], 'truncation_reasons' => [],
        ];
        $nodeLimitHit = false;
        $graph['truncation_reasons'] = $this->streamBounded(
            $statement,
            $maxEdges,
            $deadline,
            static function (array $row) use (&$graph, &$nodeLimitHit, $maxNodes): bool {
                // Dropped before the node cap sees it, so a pair joined by
                // nothing but erased type imports contributes neither the edge
                // nor the two nodes it would have introduced.
                if (self::isErasedTypeEdge($row)) {
                    return true;
                }
                $ends = [];
                foreach ([$row['source_id'], $row['target_id']] as $id) {
                    if (!isset($graph['node_index'][$id])) {
                        if (count($graph['node_ids']) >= $maxNodes) {
                            $nodeLimitHit = true;

                            return false;
                        }
                        $graph['node_index'][$id] = count($graph['node_ids']);
                        $graph['node_ids'][] = $id;
                        $graph['adjacency'][] = [];
                        $graph['reverse'][] = [];
                    }
                    $ends[] = $graph['node_index'][$id];
                }
                [$source, $target] = $ends;
                $graph['adjacency'][$source][] = $target;
                $graph['reverse'][$target][] = $source;
                if ($source === $target) {
                    $graph['self_loops'][$source] = true;
                }
                $graph['edge_ids'][] = $row['id'];
                $graph['edge_sources'][] = $source;
                $graph['edge_targets'][] = $target;

                return true;
            },
        );
        // The node cap is the one stop condition streamBounded cannot see, so it
        // reports itself here rather than being folded into a row reason.
        if ($nodeLimitHit) {
            $graph['truncation_reasons'][] = 'node_limit';
        }

        return $graph;
    }

    /**
     * Members, relationships and evidence for the reported components, loaded for those alone.
     *
     * Each component lists its first 100 members and the first 200 edges that
     * join two of them, in the order the search read them; trimming either is
     * reported as member_limit or internal_edge_limit, and a member or edge
     * whose row cannot be loaded as missing_detail.
     *
     * @param list<list<string>> $components member ids, each component sorted
     * @param array{node_index: array<string, int>, edge_ids: list<string>, edge_sources: list<int>, edge_targets: list<int>} $graph
     * @param array<string, int> $confidenceRank
     * @return array{cycles: list<array<string, mixed>>, evidence: list<array<string, mixed>>, truncation_reasons: list<string>}
     */
    private function cycleDetails(array $components, array $graph, array $confidenceRank): array
    {
        $componentOf = [];
        foreach ($components as $componentIndex => $component) {
            foreach ($component as $id) {
                $componentOf[$graph['node_index'][$id]] = $componentIndex;
            }
        }
        $internal = array_fill(0, count($components), []);
        if ($componentOf !== []) {
            foreach ($graph['edge_sources'] as $position => $source) {
                $componentIndex = $componentOf[$source] ?? null;
                if ($componentIndex !== null && ($componentOf[$graph['edge_targets'][$position]] ?? null) === $componentIndex) {
                    $internal[$componentIndex][] = $position;
                }
            }
        }
        $sampledIds = $memberIds = [];
        foreach ($components as $componentIndex => $component) {
            foreach (array_slice($internal[$componentIndex], 0, 200) as $position) {
                $sampledIds[] = $graph['edge_ids'][$position];
            }
            array_push($memberIds, ...array_slice($component, 0, 100));
        }
        $edgeRows = $this->rowsByIds(
            'SELECT e.id, e.kind, e.source_id, e.target_id, e.origin, e.confidence, e.start_line, e.end_line, f.relative_path ' .
            'FROM edges e LEFT JOIN files f ON f.id = e.file_id WHERE e.id IN (%s)',
            $sampledIds,
        );
        $nodeRows = $this->rowsByIds('SELECT id, kind, canonical_name, display_name, confidence FROM nodes WHERE id IN (%s)', $memberIds);
        $boundaryMap = $this->boundaryNames($memberIds);

        $cycles = $evidence = $truncationReasons = [];
        foreach ($components as $componentIndex => $component) {
            $edgeTruncated = count($internal[$componentIndex]) > 200;
            $memberTruncated = count($component) > 100;
            // Per-cycle member/edge trimming is real result truncation; surface
            // it on the envelope so dependency_cycles never reports truncated:false
            // over demonstrably truncated cycle detail.
            if ($memberTruncated) {
                $truncationReasons[] = 'member_limit';
            }
            if ($edgeTruncated) {
                $truncationReasons[] = 'internal_edge_limit';
            }
            $cycleEdges = [];
            $minimum = 3;
            // Both phases read one snapshot, so a row is missing only where the
            // graph itself lacks it: an edge left pointing at a node that is
            // gone. The cycle says so rather than shrinking without a word.
            $missing = false;
            foreach (array_slice($internal[$componentIndex], 0, 200) as $position) {
                $edge = $edgeRows[$graph['edge_ids'][$position]] ?? null;
                if ($edge === null) {
                    $missing = true;
                    continue;
                }
                $minimum = min($minimum, $confidenceRank[$edge['confidence']]);
                $cycleEdges[] = [
                    'id' => $edge['id'], 'kind' => $edge['kind'], 'source_id' => $edge['source_id'],
                    'target_id' => $edge['target_id'], 'origin' => $edge['origin'], 'confidence' => $edge['confidence'],
                ];
                if ($edge['relative_path'] !== null && count($evidence) < 500) {
                    $evidence[] = [
                        'component_index' => $componentIndex, 'edge_id' => $edge['id'], 'path' => $edge['relative_path'],
                        'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
                    ];
                }
            }
            $members = [];
            foreach (array_slice($component, 0, 100) as $id) {
                if (isset($nodeRows[$id])) {
                    $members[] = $nodeRows[$id] + ['boundaries' => $boundaryMap[$id] ?? []];
                } else {
                    $missing = true;
                }
            }
            if ($missing) {
                $truncationReasons[] = 'missing_detail';
            }
            $cycles[] = [
                'size' => count($component),
                'minimum_confidence' => array_search($minimum, $confidenceRank, true),
                // Full membership (pre-slice) so callers such as architecture_health
                // can flag every participant, not just the first 100.
                'member_ids' => $component,
                'members' => $members,
                'relationships' => $cycleEdges,
                'truncated' => $edgeTruncated || $memberTruncated || $missing,
                'truncation_reasons' => array_values(array_filter([$memberTruncated ? 'member_limit' : null, $edgeTruncated ? 'internal_edge_limit' : null, $missing ? 'missing_detail' : null])),
            ];
        }

        return ['cycles' => $cycles, 'evidence' => $evidence, 'truncation_reasons' => $truncationReasons];
    }

    /**
     * Rows keyed by their id column, fetched in chunks small enough for SQLite's parameter limit.
     *
     * @param string $sql a query whose `%s` is replaced by the placeholders of one chunk
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    private function rowsByIds(string $sql, array $ids): array
    {
        $rows = [];
        foreach (ChunkedInQuery::rows($this->pdo, $sql, array_values(array_unique($ids))) as $row) {
            $rows[$row['id']] = $row;
        }

        return $rows;
    }
}
