<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

final readonly class GraphTopologyQueryService extends AbstractArchitectureQueryService
{
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

    /** @param list<string> $edgeKinds */
    public function dependencyCycles(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 10_000, int $maxEdges = 20_000, int $timeoutMs = 1000): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($maxNodes < 1 || $maxNodes > 50_000) {
            throw new InvalidArgumentException('max_nodes must be between 1 and 50000.');
        }
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        $edgeKinds = $edgeKinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::IMPACT_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported dependency relationship.');
        }

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.kind AS source_kind, source.canonical_name AS source_name, ' .
            'source.display_name AS source_display_name, source.confidence AS source_confidence, ' .
            'target.kind AS target_kind, target.canonical_name AS target_name, ' .
            'target.display_name AS target_display_name, target.confidence AS target_confidence ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $statement->execute([$projectId, ...$edgeKinds, $confidenceRank[$minConfidence], $maxEdges + 1]);
        $rows = $statement->fetchAll();
        $truncated = count($rows) > $maxEdges;
        $truncationReasons = $truncated ? ['edge_limit'] : [];
        $rows = array_slice($rows, 0, $maxEdges);
        $nodes = [];
        $edges = [];
        foreach ($rows as $row) {
            if ($this->now() > $deadline) {
                $truncated = true;
                $truncationReasons[] = 'time_limit';
                break;
            }
            foreach (['source', 'target'] as $side) {
                $id = $row[$side . '_id'];
                if (!isset($nodes[$id]) && count($nodes) >= $maxNodes) {
                    $truncated = true;
                    $truncationReasons[] = 'node_limit';
                    break 2;
                }
                $nodes[$id] ??= [
                    'id' => $id,
                    'kind' => $row[$side . '_kind'],
                    'canonical_name' => $row[$side . '_name'],
                    'display_name' => $row[$side . '_display_name'],
                    'confidence' => $row[$side . '_confidence'],
                ];
            }
            $edges[] = $row;
        }

        $adjacency = $reverse = [];
        foreach (array_keys($nodes) as $id) {
            $adjacency[$id] = $reverse[$id] = [];
        }
        foreach ($edges as $edge) {
            $adjacency[$edge['source_id']][] = $edge['target_id'];
            $reverse[$edge['target_id']][] = $edge['source_id'];
        }
        foreach ($adjacency as &$targets) {
            $targets = array_values(array_unique($targets));
            sort($targets, SORT_STRING);
        }
        unset($targets);
        foreach ($reverse as &$sources) {
            $sources = array_values(array_unique($sources));
            sort($sources, SORT_STRING);
        }
        unset($sources);

        $componentScan = $this->stronglyConnectedComponents($adjacency, $reverse, $deadline);
        if ($componentScan['timed_out']) {
            $truncated = true;
            $truncationReasons[] = 'time_limit';
        }
        $components = array_values(array_filter(
            $componentScan['components'],
            fn(array $component): bool => count($component) > 1 || $this->hasSelfLoop($component[0], $adjacency),
        ));
        usort($components, static fn(array $a, array $b): int => (count($b) <=> count($a)) ?: ($a[0] <=> $b[0]));
        if (count($components) > $limit) {
            $components = array_slice($components, 0, $limit);
            $truncated = true;
            $truncationReasons[] = 'result_limit';
        }

        $cycles = [];
        $evidence = [];
        foreach ($components as $componentIndex => $component) {
            $memberSet = array_fill_keys($component, true);
            $internal = array_values(array_filter($edges, static fn(array $edge): bool => isset($memberSet[$edge['source_id']], $memberSet[$edge['target_id']])));
            $edgeTruncated = count($internal) > 200;
            $memberTruncated = count($component) > 100;
            $sampledEdges = array_slice($internal, 0, 200);
            $cycleEdges = [];
            $minimum = 3;
            foreach ($sampledEdges as $edge) {
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
            $memberIds = array_slice($component, 0, 100);
            $boundaryMap = $this->boundaryNames($memberIds);
            $cycles[] = [
                'size' => count($component),
                'minimum_confidence' => array_search($minimum, $confidenceRank, true),
                'members' => array_map(static fn(string $id): array => $nodes[$id] + ['boundaries' => $boundaryMap[$id] ?? []], $memberIds),
                'relationships' => $cycleEdges,
                'truncated' => $edgeTruncated || $memberTruncated,
                'truncation_reasons' => array_values(array_filter([$memberTruncated ? 'member_limit' : null, $edgeTruncated ? 'internal_edge_limit' : null])),
            ];
        }

        $truncationReasons = array_values(array_unique($truncationReasons));
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d dependency cycle component%s.', count($cycles), count($cycles) === 1 ? '' : 's'),
            [
                'cycles' => $cycles,
                'bounds' => [
                    'limit' => $limit, 'max_nodes' => $maxNodes, 'max_edges' => $maxEdges,
                    'timeout_ms' => $timeoutMs, 'nodes_examined' => count($nodes),
                    'edges_examined' => count($edges), 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            ['Cycles are derived from the selected static dependency relationships and confidence threshold.'],
            $truncated,
        );
    }

    /** @param list<string> $edgeKinds */
    public function architectureHealth(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 10_000, int $maxEdges = 20_000, int $timeoutMs = 1000): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if ($maxNodes < 1 || $maxNodes > 50_000) {
            throw new InvalidArgumentException('max_nodes must be between 1 and 50000.');
        }
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        $edgeKinds = $edgeKinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::IMPACT_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported dependency relationship.');
        }

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $nodeStatement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.origin, n.confidence, n.attributes_json, ' .
            'n.start_line, n.end_line, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id ' .
            'WHERE n.project_id = :project ORDER BY n.canonical_name, n.id LIMIT :limit',
        );
        $nodeStatement->bindValue(':project', $projectId);
        $nodeStatement->bindValue(':limit', $maxNodes + 1, PDO::PARAM_INT);
        $nodeStatement->execute();
        $nodeRows = $nodeStatement->fetchAll();
        $truncated = count($nodeRows) > $maxNodes;
        $truncationReasons = $truncated ? ['node_limit'] : [];
        $nodeRows = array_slice($nodeRows, 0, $maxNodes);
        $nodes = [];
        foreach ($nodeRows as $row) {
            $nodes[$row['id']] = $row;
        }

        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $edgeStatement = $this->pdo->prepare(
            'SELECT e.id, e.kind, e.source_id, e.target_id, e.confidence FROM edges e WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $edgeStatement->execute([$projectId, ...$edgeKinds, $confidenceRank[$minConfidence], $maxEdges + 1]);
        $edgeRows = $edgeStatement->fetchAll();
        if (count($edgeRows) > $maxEdges) {
            $truncated = true;
            $truncationReasons[] = 'edge_limit';
        }
        $edgeRows = array_slice($edgeRows, 0, $maxEdges);

        $nodeIds = array_keys($nodes);
        $roles = $this->roles($nodeIds);
        $boundaries = $this->boundaryNames($nodeIds);
        $metrics = [];
        foreach ($nodeIds as $id) {
            $metrics[$id] = ['in_degree' => 0, 'out_degree' => 0, 'cross_boundary_degree' => 0];
        }
        $edgesExamined = 0;
        foreach ($edgeRows as $edge) {
            if ((++$edgesExamined % 256) === 0 && $this->now() > $deadline) {
                $truncated = true;
                $truncationReasons[] = 'time_limit';
                break;
            }
            if (!isset($nodes[$edge['source_id']], $nodes[$edge['target_id']])) {
                continue;
            }
            ++$metrics[$edge['source_id']]['out_degree'];
            ++$metrics[$edge['target_id']]['in_degree'];
            $sourceBoundaries = array_column($boundaries[$edge['source_id']] ?? [], 'id');
            $targetBoundaries = array_column($boundaries[$edge['target_id']] ?? [], 'id');
            if ($sourceBoundaries !== [] && $targetBoundaries !== [] && array_intersect($sourceBoundaries, $targetBoundaries) === []) {
                ++$metrics[$edge['source_id']]['cross_boundary_degree'];
                ++$metrics[$edge['target_id']]['cross_boundary_degree'];
            }
        }

        $cycleMembers = [];
        $cycleScanTruncated = false;
        if ($this->now() <= $deadline) {
            $remainingMs = max(1, min(5000, (int) (($deadline - $this->now()) / 1_000_000)));
            $cycleResult = $this->dependencyCycles($projectId, $edgeKinds, $minConfidence, 100, $maxNodes, $maxEdges, $remainingMs);
            $cycleScanTruncated = $cycleResult->truncated;
            foreach ($cycleResult->data['cycles'] as $cycle) {
                foreach ($cycle['members'] as $member) {
                    $cycleMembers[$member['id']] = true;
                }
            }
        } else {
            $truncated = true;
            $truncationReasons[] = 'time_limit';
            $cycleScanTruncated = true;
        }

        $hubs = $hotspots = $deadCandidates = [];
        foreach ($nodes as $id => $row) {
            $degree = $metrics[$id]['in_degree'] + $metrics[$id]['out_degree'];
            $component = [
                'id' => $id, 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                'roles' => $roles[$id] ?? [], 'boundaries' => $boundaries[$id] ?? [],
            ];
            if ($degree > 0) {
                $hubs[] = ['component' => $component, 'metrics' => $metrics[$id], 'score' => $degree];
                $hotspots[] = [
                    'component' => $component,
                    'factors' => $metrics[$id] + ['cycle_participant' => isset($cycleMembers[$id])],
                    'score' => $degree + (2 * $metrics[$id]['cross_boundary_degree']) + (isset($cycleMembers[$id]) ? 3 : 0),
                ];
            }
            if ($metrics[$id]['in_degree'] === 0 && $this->isDeadCodeCandidate($row, $roles[$id] ?? [])) {
                $dynamicRisk = $row['origin'] !== 'ast' || $this->hasFrameworkRole($roles[$id] ?? []);
                $deadCandidates[] = [
                    'component' => $component,
                    'confidence' => $dynamicRisk ? 'possible' : 'probable',
                    'reason' => 'No selected inbound static dependency references this component.',
                    'uncertainty' => $dynamicRisk
                        ? 'Framework conventions or dynamic resolution may reference this component.'
                        : 'Reflection, configuration, templates, or runtime dispatch may not be visible statically.',
                    'out_degree' => $metrics[$id]['out_degree'],
                ];
            }
        }
        $rank = static function (array &$items): void {
            usort($items, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
                ?: ($a['component']['canonical_name'] <=> $b['component']['canonical_name']));
        };
        $rank($hubs);
        $rank($hotspots);
        usort($deadCandidates, static fn(array $a, array $b): int => ($a['component']['canonical_name'] <=> $b['component']['canonical_name']));
        foreach ([$hubs, $hotspots, $deadCandidates] as $items) {
            if (count($items) > $limit) {
                $truncated = true;
                $truncationReasons[] = 'result_limit';
            }
        }
        $hubs = array_slice($hubs, 0, $limit);
        $hotspots = array_slice($hotspots, 0, $limit);
        $deadCandidates = array_slice($deadCandidates, 0, $limit);
        $evidence = [];
        $reported = [];
        foreach ([$hubs, $hotspots, $deadCandidates] as $items) {
            foreach ($items as $item) {
                $reported[$item['component']['id']] = true;
            }
        }
        foreach (array_keys($reported) as $id) {
            $row = $nodes[$id];
            if ($row['relative_path'] !== null) {
                $evidence[] = [
                    'component_id' => $id, 'path' => $row['relative_path'],
                    'start_line' => $row['start_line'], 'end_line' => $row['end_line'],
                ];
            }
        }
        $truncationReasons = array_values(array_unique($truncationReasons));
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Ranked %d hubs, %d static hotspots, and %d unreferenced-code candidates.', count($hubs), count($hotspots), count($deadCandidates)),
            [
                'hubs' => $hubs, 'static_hotspots' => $hotspots, 'dead_code_candidates' => $deadCandidates,
                'bounds' => [
                    'limit' => $limit, 'max_nodes' => $maxNodes, 'max_edges' => $maxEdges, 'timeout_ms' => $timeoutMs,
                    'nodes_examined' => count($nodes), 'edges_examined' => $edgesExamined,
                    'cycle_scan_truncated' => $cycleScanTruncated, 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            [
                'Hotspots are static structural signals, not change-frequency or defect predictions.',
                'Dead-code results are candidates only; dynamic language and framework references may be invisible.',
            ],
            $truncated,
        );
    }

    /** @param list<string> $edgeKinds */
    public function explainFlow(string $projectId, string $from, string $to, int $maxDepth = 6, int $maxPaths = 5, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($maxDepth < 1 || $maxDepth > 8) {
            throw new InvalidArgumentException('max_depth must be between 1 and 8.');
        }
        if ($maxPaths < 1 || $maxPaths > 20) {
            throw new InvalidArgumentException('max_paths must be between 1 and 20.');
        }
        $confidenceRank = $this->confidenceThreshold($timeoutMs, $minConfidence);
        $edgeKinds = $edgeKinds === [] ? self::FLOW_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::FLOW_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported flow relationship.');
        }

        $fromCandidates = $this->resolve($projectId, $from);
        $toCandidates = $this->resolve($projectId, $to);
        if (count($fromCandidates) !== 1 || count($toCandidates) !== 1) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                'Flow endpoints require one unambiguous component each.',
                [
                    'from' => ['query' => $from, 'candidates' => $fromCandidates],
                    'to' => ['query' => $to, 'candidates' => $toCandidates],
                    'paths' => [],
                ],
                [],
                ['Use a returned stable component ID to disambiguate the request.'],
            );
        }
        $source = $fromCandidates[0];
        $target = $toCandidates[0];
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $queue = [[[$source], [], [$source['id'] => true]]];
        $paths = [];
        $visited = 0;
        $truncated = false;
        $truncationReason = null;
        $candidateCap = $maxPaths * 20;
        while ($queue !== [] && count($paths) < $candidateCap) {
            if ($this->now() > $deadline || $visited >= 10_000) {
                $truncated = true;
                $truncationReason = $visited >= 10_000 ? 'visit_limit' : 'time_limit';
                break;
            }
            [$nodes, $hops, $seen] = array_shift($queue);
            ++$visited;
            if (count($hops) >= $maxDepth) {
                continue;
            }
            $last = $nodes[array_key_last($nodes)];
            foreach ($this->flowEdges($projectId, $last['id'], $edgeKinds, $confidenceRank[$minConfidence]) as $edge) {
                if (isset($seen[$edge['target_id']])) {
                    continue;
                }
                $next = $this->node($edge['target_id']);
                if ($next === null) {
                    continue;
                }
                $newNodes = [...$nodes, $next];
                $newHops = [...$hops, $edge];
                if ($next['id'] === $target['id']) {
                    $paths[] = $this->path($newNodes, $newHops);
                    if (count($paths) >= $candidateCap) {
                        $truncated = true;
                        $truncationReason = 'candidate_limit';
                        break 2;
                    }
                    continue;
                }
                $newSeen = $seen;
                $newSeen[$next['id']] = true;
                $queue[] = [$newNodes, $newHops, $newSeen];
            }
        }
        usort($paths, static function (array $left, array $right): int {
            return ($right['score']['minimum_confidence'] <=> $left['score']['minimum_confidence'])
                ?: ($left['score']['hops'] <=> $right['score']['hops'])
                ?: ($right['score']['semantic_edges'] <=> $left['score']['semantic_edges'])
                ?: ($left['signature'] <=> $right['signature']);
        });
        if (count($paths) > $maxPaths) {
            $paths = array_slice($paths, 0, $maxPaths);
            $truncated = true;
            $truncationReason = 'path_limit';
        }
        $evidence = [];
        foreach ($paths as $pathIndex => $path) {
            foreach ($path['hops'] as $hopIndex => $hop) {
                if ($hop['evidence'] !== null) {
                    $evidence[] = ['path_index' => $pathIndex, 'hop_index' => $hopIndex] + $hop['evidence'];
                }
            }
        }
        $count = count($paths);
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $count === 0 ? 'No supported static flow was found within the configured bounds.' : sprintf('Found %d plausible static flow%s.', $count, $count === 1 ? '' : 's'),
            [
                'from' => $source, 'to' => $target, 'paths' => $paths,
                'bounds' => ['max_depth' => $maxDepth, 'max_paths' => $maxPaths, 'timeout_ms' => $timeoutMs, 'visited_states' => $visited, 'truncation_reason' => $truncationReason],
            ],
            $evidence,
            ['Flows are plausible statically supported paths, not proof of runtime execution.'],
            $truncated,
        );
    }

    /** @param list<string> $edgeKinds */
    public function impactAnalysis(string $projectId, string $symbol, int $maxDepth = 4, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        $project = $this->project($projectId);
        if ($maxDepth < 1 || $maxDepth > 8) {
            throw new InvalidArgumentException('max_depth must be between 1 and 8.');
        }
        self::assertLimit($limit);
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 5000.');
        }
        $confidenceRank = ['possible' => 1, 'probable' => 2, 'certain' => 3];
        if (!isset($confidenceRank[$minConfidence])) {
            throw new InvalidArgumentException('min_confidence must be possible, probable, or certain.');
        }
        $edgeKinds = $edgeKinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::IMPACT_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported impact relationship.');
        }
        $candidates = $this->resolve($projectId, $symbol);
        if (count($candidates) !== 1) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                'Impact analysis requires one unambiguous component.',
                ['query' => $symbol, 'candidates' => $candidates, 'by_distance' => [], 'entry_points' => [], 'boundaries' => []],
                [],
                ['Use a returned stable component ID to disambiguate the request.'],
            );
        }
        $target = $candidates[0];
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $queue = [[$target, 0, 3]];
        $seen = [$target['id'] => true];
        $dependants = [];
        $truncated = false;
        $truncationReason = null;
        $visited = 0;
        while ($queue !== []) {
            if ($this->now() > $deadline || $visited >= 10_000) {
                $truncated = true;
                $truncationReason = $visited >= 10_000 ? 'visit_limit' : 'time_limit';
                break;
            }
            [$current, $distance, $pathConfidence] = array_shift($queue);
            ++$visited;
            if ($distance >= $maxDepth) {
                continue;
            }
            foreach ($this->impactEdges($projectId, $current['id'], $edgeKinds, $confidenceRank[$minConfidence]) as $edge) {
                if (isset($seen[$edge['source_id']])) {
                    continue;
                }
                $node = $this->node($edge['source_id']);
                if ($node === null) {
                    continue;
                }
                $seen[$node['id']] = true;
                $edgeConfidence = $confidenceRank[$edge['confidence']];
                $record = [
                    'node' => $node,
                    'distance' => $distance + 1,
                    'path_confidence' => array_search(min($pathConfidence, $edgeConfidence), $confidenceRank, true),
                    'via' => $this->impactHop($edge),
                    'roles' => $this->roles([$node['id']])[$node['id']] ?? [],
                ];
                $dependants[] = $record;
                if (count($dependants) >= $limit) {
                    $truncated = true;
                    $truncationReason = 'result_limit';
                    break 2;
                }
                $queue[] = [$node, $distance + 1, min($pathConfidence, $edgeConfidence)];
            }
        }
        $boundaryMap = $this->boundaryNames(array_map(static fn(array $record): string => $record['node']['id'], $dependants));
        $boundaryGroups = [];
        foreach ($dependants as &$record) {
            $record['boundaries'] = $boundaryMap[$record['node']['id']] ?? [];
            foreach ($record['boundaries'] as $boundary) {
                $boundaryGroups[$boundary['id']] ??= ['boundary' => $boundary, 'dependants' => []];
            }
            foreach ($record['boundaries'] as $boundary) {
                $boundaryGroups[$boundary['id']]['dependants'][] = $record['node'];
            }
        }
        unset($record);
        $byDistance = [];
        $byConfidence = ['certain' => [], 'probable' => [], 'possible' => []];
        $entryPoints = [];
        $evidence = [];
        foreach ($dependants as $record) {
            $byDistance[$record['distance']][] = $record;
            $byConfidence[$record['path_confidence']][] = $record['node'];
            if ($this->isEntryPoint($record)) {
                $entryPoints[] = $record;
            }
            if ($record['via']['evidence'] !== null) {
                $evidence[] = ['dependant_id' => $record['node']['id']] + $record['via']['evidence'];
            }
        }
        ksort($byDistance, SORT_NUMERIC);
        $groups = [];
        foreach ($byDistance as $distance => $items) {
            $groups[] = ['distance' => $distance, 'dependants' => $items];
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d potential static dependant%s within depth %d.', count($dependants), count($dependants) === 1 ? '' : 's', $maxDepth),
            [
                'target' => $target,
                'direct_dependants' => $byDistance[1] ?? [],
                'by_distance' => $groups,
                'by_confidence' => $byConfidence,
                'entry_points' => $entryPoints,
                'boundaries' => array_values($boundaryGroups),
                'bounds' => ['max_depth' => $maxDepth, 'limit' => $limit, 'timeout_ms' => $timeoutMs, 'visited_states' => $visited, 'truncation_reason' => $truncationReason],
            ],
            $evidence,
            ['Impact is a conservative static blast radius; it does not guarantee that a dependant will break.'],
            $truncated,
        );
    }

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
            $boundaries[] = [
                'id' => $row['id'], 'name' => $row['name'], 'source' => $row['source'],
                'matcher' => self::decode($row['matcher_json']), 'member_count' => (int) $row['member_count'],
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

    /** @param list<string> $edgeKinds @return list<array<string, mixed>> */
    private function flowEdges(string $projectId, string $sourceId, array $edgeKinds, int $minimumConfidence): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND e.source_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY CASE e.confidence WHEN \'certain\' THEN 3 WHEN \'probable\' THEN 2 ELSE 1 END DESC, e.kind, e.id LIMIT 500',
        );
        $statement->execute([$projectId, $sourceId, ...$edgeKinds, $minimumConfidence]);
        return $statement->fetchAll();
    }
    /** @param list<string> $edgeKinds @return list<array<string, mixed>> */
    private function impactEdges(string $projectId, string $targetId, array $edgeKinds, int $minimumConfidence): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND e.target_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY CASE e.confidence WHEN \'certain\' THEN 3 WHEN \'probable\' THEN 2 ELSE 1 END DESC, e.kind, e.id LIMIT 500',
        );
        $statement->execute([$projectId, $targetId, ...$edgeKinds, $minimumConfidence]);
        return $statement->fetchAll();
    }
    /** @param array<string, mixed> $edge @return array<string, mixed> */
    private function impactHop(array $edge): array
    {
        return [
            'edge_id' => $edge['id'], 'kind' => $edge['kind'], 'source_id' => $edge['source_id'],
            'target_id' => $edge['target_id'], 'origin' => $edge['origin'], 'confidence' => $edge['confidence'],
            'attributes' => self::decode($edge['attributes_json']),
            'explanation' => sprintf('%s depends through --%s (%s, %s)--> %s', $edge['source_name'], $edge['kind'], $edge['confidence'], $edge['origin'], $edge['target_name']),
            'evidence' => $edge['relative_path'] === null ? null : [
                'path' => $edge['relative_path'], 'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
            ],
        ];
    }
    /** @param array<string, mixed> $record */
    private function isEntryPoint(array $record): bool
    {
        if (in_array($record['node']['kind'], ['route', 'command'], true)) {
            return true;
        }
        $entryRoles = [
            'application.controller', 'application.command', 'laravel.controller', 'laravel.command',
            'laravel.job', 'application.entry_point',
        ];
        foreach ($record['roles'] as $role) {
            if (in_array($role['role'], $entryRoles, true)) {
                return true;
            }
        }
        return false;
    }
    /** @param array<string, mixed> $node @param list<array<string, mixed>> $roles */
    private function isDeadCodeCandidate(array $node, array $roles): bool
    {
        if (!in_array($node['kind'], ['class', 'interface', 'trait', 'enum', 'function', 'method', 'module'], true)) {
            return false;
        }
        if (in_array($node['origin'], ['external', 'unresolved'], true)) {
            return false;
        }
        $entryRoles = [
            'application.controller', 'application.command', 'application.entry_point',
            'laravel.controller', 'laravel.command', 'laravel.job', 'laravel.listener',
        ];
        foreach ($roles as $role) {
            if (in_array($role['role'], $entryRoles, true)) {
                return false;
            }
        }
        return true;
    }
    /** @param list<array<string, mixed>> $roles */
    private function hasFrameworkRole(array $roles): bool
    {
        foreach ($roles as $role) {
            if (str_starts_with($role['role'], 'laravel.') || str_starts_with($role['origin'], 'framework_')) {
                return true;
            }
        }
        return false;
    }
    /** @param list<array<string, mixed>> $nodes @param list<array<string, mixed>> $edges @return array<string, mixed> */
    private function path(array $nodes, array $edges): array
    {
        $rank = ['possible' => 1, 'probable' => 2, 'certain' => 3];
        $semantic = ['routes_to', 'dispatches', 'handles', 'listens_to', 'binds', 'observes', 'uses_middleware'];
        $hops = [];
        $minimum = 3;
        $semanticCount = 0;
        foreach ($edges as $edge) {
            $minimum = min($minimum, $rank[$edge['confidence']]);
            if (in_array($edge['kind'], $semantic, true)) {
                ++$semanticCount;
            }
            $evidence = $edge['relative_path'] === null ? null : [
                'path' => $edge['relative_path'], 'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
            ];
            $hops[] = [
                'edge_id' => $edge['id'], 'kind' => $edge['kind'], 'source_id' => $edge['source_id'],
                'target_id' => $edge['target_id'], 'origin' => $edge['origin'], 'confidence' => $edge['confidence'],
                'attributes' => self::decode($edge['attributes_json']),
                'explanation' => sprintf('%s --%s (%s, %s)--> %s', $edge['source_name'], $edge['kind'], $edge['confidence'], $edge['origin'], $edge['target_name']),
                'evidence' => $evidence,
            ];
        }
        return [
            'nodes' => $nodes,
            'hops' => $hops,
            'score' => ['minimum_confidence' => $minimum, 'hops' => count($hops), 'semantic_edges' => $semanticCount],
            'signature' => implode('>', array_column($nodes, 'id')),
        ];
    }
    /** @return list<array{kind: string, count: int}> */
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
    private function scalar(string $sql, string $projectId): int
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['project' => $projectId]);
        return (int) $statement->fetchColumn();
    }
    /** @param array<string, list<string>> $adjacency */
    private function hasSelfLoop(string $nodeId, array $adjacency): bool
    {
        return in_array($nodeId, $adjacency[$nodeId] ?? [], true);
    }
    private function distinctCount(string $table, string $projectId, string $column = 'kind'): int
    {
        return $this->scalar(sprintf('SELECT COUNT(DISTINCT %s) FROM %s WHERE project_id = :project', $column, $table), $projectId);
    }
    /** @return list<array<string, mixed>> */
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
