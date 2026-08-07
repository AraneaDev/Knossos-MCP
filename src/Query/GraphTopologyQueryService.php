<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

/**
 * Whole-graph structural questions: summaries, flows, cycles, hubs, dead code.
 *
 * Every traversal here is bounded by node, edge, and time limits, because these
 * are the queries that would otherwise walk a large graph without end. Dead-code
 * answers report absence of evidence rather than proven absence — nothing static
 * analysis sees can rule out reflection.
 */
final readonly class GraphTopologyQueryService extends AbstractArchitectureQueryService
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

    /**
     * Strongly connected components, bounded by node, edge, and time limits.
     *
     * @param list<string> $edgeKinds
     */
    public function dependencyCycles(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 10_000, int $maxEdges = 100_000, int $timeoutMs = 1000, bool $includeSelfLoops = false): ResultEnvelope
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
        $nodes = [];
        $edges = [];
        $nodeLimitHit = false;
        // Streamed rather than fetchAll(): the deadline used to be checked only
        // after every row was materialised, so a timeout_ms of 1000 could not
        // bound the phase that dominates the cost, and 100,001 joined rows were
        // held in memory before the first check.
        $truncationReasons = $this->streamBounded(
            $statement,
            $maxEdges,
            $deadline,
            function (array $row) use (&$nodes, &$edges, &$nodeLimitHit, $maxNodes): bool {
                foreach (['source', 'target'] as $side) {
                    $id = $row[$side . '_id'];
                    if (!isset($nodes[$id]) && count($nodes) >= $maxNodes) {
                        $nodeLimitHit = true;

                        return false;
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

                return true;
            },
        );
        // The node cap is the one stop condition streamBounded cannot see, so it
        // reports itself here rather than being folded into a row reason.
        if ($nodeLimitHit) {
            $truncationReasons[] = 'node_limit';
        }
        $truncated = $truncationReasons !== [];

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
        // A single self-recursive symbol is ordinary recursion, not an
        // architectural tangle, so self-loops are opt-in.
        $components = array_values(array_filter(
            $componentScan['components'],
            fn(array $component): bool => count($component) > 1
                || ($includeSelfLoops && $this->hasSelfLoop($component[0], $adjacency)),
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
            // Per-cycle member/edge trimming is real result truncation; surface
            // it on the envelope so dependency_cycles never reports truncated:false
            // over demonstrably truncated cycle detail.
            if ($memberTruncated) {
                $truncated = true;
                $truncationReasons[] = 'member_limit';
            }
            if ($edgeTruncated) {
                $truncated = true;
                $truncationReasons[] = 'internal_edge_limit';
            }
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
                // Full membership (pre-slice) so callers such as architecture_health
                // can flag every participant, not just the first 100.
                'member_ids' => $component,
                'members' => array_map(static fn(string $id): array => $nodes[$id] + ['boundaries' => $boundaryMap[$id] ?? []], $memberIds),
                'relationships' => $cycleEdges,
                'truncated' => $edgeTruncated || $memberTruncated,
                'truncation_reasons' => array_values(array_filter([$memberTruncated ? 'member_limit' : null, $edgeTruncated ? 'internal_edge_limit' : null])),
            ];
        }

        $truncationReasons = array_values(array_unique($truncationReasons));
        // A bounded search must not read as an exhaustive one: "Found 0
        // dependency cycle components" is the same sentence a genuinely acyclic
        // project gets, and a cycle living beyond the edge cap is invisible in
        // it. Naming the bound in the summary is what lets a caller tell the two
        // apart without reading bounds.truncation_reasons.
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
                    'timeout_ms' => $timeoutMs, 'nodes_examined' => count($nodes),
                    'edges_examined' => count($edges), 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            ['Cycles are derived from the selected static dependency relationships and confidence threshold.'],
            $truncated,
        );
    }

    /**
     * Hubs, hotspots, and unreferenced-code candidates, ranked for where to look first.
     *
     * @param list<string> $edgeKinds
     */
    public function architectureHealth(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 10_000, int $maxEdges = 100_000, int $timeoutMs = 1000, bool $includeExternal = false, bool $includeTests = false): ResultEnvelope
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
        $nodes = [];
        // Streamed for the same reason as the edge walk below: max_nodes reaches
        // 50,000 joined rows, and fetchAll() read every one of them before the
        // deadline was ever consulted.
        $truncationReasons = $this->streamBounded($nodeStatement, $maxNodes, $deadline, function (array $row) use (&$nodes): bool {
            $nodes[$row['id']] = $row;

            return true;
        }, 'node_limit');
        $truncated = $truncationReasons !== [];

        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $edgeStatement = $this->pdo->prepare(
            'SELECT e.id, e.kind, e.source_id, e.target_id, e.confidence FROM edges e WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $edgeStatement->execute([$projectId, ...$edgeKinds, $confidenceRank[$minConfidence], $maxEdges + 1]);

        $nodeIds = array_keys($nodes);
        $roles = $this->roles($nodeIds);
        $boundaries = $this->boundaryNames($nodeIds);
        $repositoryWide = array_keys($this->repositoryWideBoundaryIds($projectId));
        $metrics = [];
        foreach ($nodeIds as $id) {
            $metrics[$id] = ['in_degree' => 0, 'out_degree' => 0, 'cross_boundary_degree' => 0];
        }
        // Inbound `implements`/`extends` counted separately: a type being
        // implemented is not evidence that anything uses it, so the contract
        // gate below has to be able to discount them.
        $inheritanceInDegree = [];
        $edgesExamined = 0;
        $edgeReasons = $this->streamBounded($edgeStatement, $maxEdges, $deadline, function (array $edge) use (&$edgesExamined, &$metrics, &$inheritanceInDegree, $nodes, $boundaries, $repositoryWide): bool {
            ++$edgesExamined;
            if (!isset($nodes[$edge['source_id']], $nodes[$edge['target_id']])) {
                return true;
            }
            ++$metrics[$edge['source_id']]['out_degree'];
            ++$metrics[$edge['target_id']]['in_degree'];
            if (in_array($edge['kind'], ['implements', 'extends'], true)) {
                $inheritanceInDegree[$edge['target_id']] = ($inheritanceInDegree[$edge['target_id']] ?? 0) + 1;
            }
            $sourceBoundaries = array_diff(array_column($boundaries[$edge['source_id']] ?? [], 'id'), $repositoryWide);
            $targetBoundaries = array_diff(array_column($boundaries[$edge['target_id']] ?? [], 'id'), $repositoryWide);
            if ($sourceBoundaries !== [] && $targetBoundaries !== [] && array_intersect($sourceBoundaries, $targetBoundaries) === []) {
                ++$metrics[$edge['source_id']]['cross_boundary_degree'];
                ++$metrics[$edge['target_id']]['cross_boundary_degree'];
            }

            return true;
        });
        if ($edgeReasons !== []) {
            $truncated = true;
            $truncationReasons = array_merge($truncationReasons, $edgeReasons);
        }

        $cycleMembers = [];
        $cycleScanTruncated = false;
        if ($this->now() <= $deadline) {
            $remainingMs = max(1, min(5000, (int) (($deadline - $this->now()) / 1_000_000)));
            $cycleResult = $this->dependencyCycles($projectId, $edgeKinds, $minConfidence, 100, $maxNodes, $maxEdges, $remainingMs);
            $cycleScanTruncated = $cycleResult->truncated;
            foreach ($cycleResult->data['cycles'] as $cycle) {
                // Collect from the full pre-slice membership so participants
                // beyond the 100-member detail cap still earn the cycle signal.
                foreach ($cycle['member_ids'] as $memberId) {
                    $cycleMembers[$memberId] = true;
                }
            }
        } else {
            $truncated = true;
            $truncationReasons[] = 'time_limit';
            $cycleScanTruncated = true;
        }

        $deadCode = new DeadCodeAnalysis($this->pdo, $this->clock);
        $hubs = $hotspots = $deadCandidates = [];
        $provisional = [];
        $excludedExternal = $excludedTests = 0;
        foreach ($nodes as $id => $row) {
            $degree = $metrics[$id]['in_degree'] + $metrics[$id]['out_degree'];
            $component = [
                'id' => $id, 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                'roles' => $roles[$id] ?? [], 'boundaries' => $boundaries[$id] ?? [],
            ];
            if ($degree > 0) {
                $externalNode = ReportableComponent::isExternal((string) $row['kind'], $row['origin']);
                $testNode = ReportableComponent::isTest(array_column($roles[$id] ?? [], 'role'));
                if (!$includeExternal && $externalNode) {
                    ++$excludedExternal;
                } elseif (!$includeTests && $testNode) {
                    ++$excludedTests;
                } else {
                    $hubs[] = ['component' => $component, 'metrics' => $metrics[$id], 'score' => $degree];
                    $hotspots[] = [
                        'component' => $component,
                        'factors' => $metrics[$id] + ['cycle_participant' => isset($cycleMembers[$id])],
                        'score' => $degree + (2 * $metrics[$id]['cross_boundary_degree']) + (isset($cycleMembers[$id]) ? 3 : 0),
                    ];
                }
            }
            if ($metrics[$id]['in_degree'] === 0 && $deadCode->isCandidate($row, $roles[$id] ?? [])) {
                $provisional[$id] = ['component' => $component, 'row' => $row, 'roles' => $roles[$id] ?? [], 'out_degree' => $metrics[$id]['out_degree']];
            }
        }
        // The in-degree above only counts edges from the slice actually read, so
        // a zero is provisional: node/edge/time limits drop edges, and a dropped
        // inbound edge makes a referenced symbol look unreferenced. Re-check the
        // survivors against the whole edge table before calling anything dead.
        if ($provisional !== [] && array_intersect(['node_limit', 'edge_limit', 'time_limit'], $truncationReasons) !== []) {
            foreach ($deadCode->referencedNodes($projectId, array_keys($provisional), $edgeKinds, $confidenceRank[$minConfidence]) as $referencedId) {
                unset($provisional[$referencedId]);
            }
        }
        $classified = $deadCode->classify($projectId, $provisional, $nodes, $metrics, $inheritanceInDegree);
        $deadCandidates = $classified['candidates'];
        $excluded = $classified['excluded'];
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
                    'excluded_external_components' => $excludedExternal, 'excluded_test_components' => $excludedTests,
                    'excluded_inherited_methods' => $excluded['inherited'],
                    'excluded_contract_methods' => $excluded['contracts'],
                    'excluded_constructors' => $excluded['constructors'],
                    'excluded_entry_scripts' => $excluded['entry_scripts'],
                    'suppressed_candidates' => $excluded['suppressed'],
                    'annotated_false_positives' => $excluded['annotated_false_positives'],
                    'cycle_scan_truncated' => $cycleScanTruncated, 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            [
                'Hotspots are static structural signals, not change-frequency or defect predictions.',
                'Dead-code results are candidates only; reflection, configuration, templates, registry arrays, callbacks, dispatch tables, and framework conventions may reference a component without a visible static edge.',
            ],
            $truncated,
        );
    }

    /**
     * Paths by which one component can reach another, with the edges that justify each hop.
     *
     * @param list<string> $edgeKinds
     */
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
        $endpointTruncated = false;
        $sourceSet = $this->flowEndpointSet($projectId, $source, $endpointTruncated);
        $targetSet = $this->flowEndpointSet($projectId, $target, $endpointTruncated);
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $queue = [];
        foreach ($sourceSet as $start) {
            $queue[] = [[$start], [], [$start['id'] => true]];
        }
        $paths = [];
        // Keyed by signature: the cap and the max_paths budget are both about
        // distinct ROUTES, and two call sites between the same pair of symbols
        // are one route written twice. Counting the copies let a hot pair spend
        // the whole search before any other route was reached.
        $visited = 0;
        $truncated = false;
        $truncationReasons = [];
        $flowEdgesTruncated = false;
        $candidateCap = $maxPaths * 20;
        while ($queue !== [] && count($paths) < $candidateCap) {
            if ($this->now() > $deadline || $visited >= 10_000) {
                $truncated = true;
                $truncationReasons[] = $visited >= 10_000 ? 'visit_limit' : 'time_limit';
                break;
            }
            [$nodes, $hops, $seen] = array_shift($queue);
            ++$visited;
            if (count($hops) >= $maxDepth) {
                continue;
            }
            $last = $nodes[array_key_last($nodes)];
            foreach ($this->flowEdges($projectId, $last['id'], $edgeKinds, $confidenceRank[$minConfidence], $flowEdgesTruncated) as $edge) {
                // The goal may be reached even when it is already in $seen: the
                // source is pre-seeded, so a self-flow (from == to) depends on
                // matching the target before the visited-guard skips it.
                $isTarget = isset($targetSet[$edge['target_id']]);
                if (isset($seen[$edge['target_id']]) && !$isTarget) {
                    continue;
                }
                $next = $this->node($edge['target_id']);
                if ($next === null) {
                    continue;
                }
                $newNodes = [...$nodes, $next];
                $newHops = [...$hops, $edge];
                if ($isTarget) {
                    $candidate = $this->path($newNodes, $newHops);
                    $existing = $paths[$candidate['signature']] ?? null;
                    // Keep the best-scoring witness, not merely the first one
                    // found: two call sites for the same route can carry
                    // different confidence, and the answer should show the
                    // strongest evidence that route has.
                    if ($existing === null || self::comparePaths($candidate, $existing) < 0) {
                        $paths[$candidate['signature']] = $candidate;
                    }
                    if (count($paths) >= $candidateCap) {
                        $truncated = true;
                        $truncationReasons[] = 'candidate_limit';
                        break 2;
                    }
                    continue;
                }
                $newSeen = $seen;
                $newSeen[$next['id']] = true;
                $queue[] = [$newNodes, $newHops, $newSeen];
            }
        }
        if ($flowEdgesTruncated) {
            // A node with more than 500 outbound edges of the selected kinds had
            // some silently dropped; record it rather than claim completeness.
            $truncated = true;
            $truncationReasons[] = 'per_node_edge_limit';
        }
        if ($endpointTruncated) {
            $truncated = true;
            $truncationReasons[] = 'endpoint_expansion_limit';
        }
        // One entry per distinct route already; this only orders them, which
        // decides which survive the max_paths budget.
        $paths = array_values($paths);
        usort($paths, static fn(array $left, array $right): int => self::comparePaths($left, $right));
        if (count($paths) > $maxPaths) {
            $paths = array_slice($paths, 0, $maxPaths);
            $truncated = true;
            // Appended, never overwriting an earlier time/visit reason: a
            // timed-out search must not report only path trimming.
            $truncationReasons[] = 'path_limit';
        }
        $truncationReasons = array_values(array_unique($truncationReasons));
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
                'bounds' => ['max_depth' => $maxDepth, 'max_paths' => $maxPaths, 'timeout_ms' => $timeoutMs, 'visited_states' => $visited, 'truncation_reason' => $truncationReasons[0] ?? null, 'truncation_reasons' => $truncationReasons],
            ],
            $evidence,
            ['Flows are plausible statically supported paths, not proof of runtime execution.'],
            $truncated,
        );
    }

    /**
     * Conservative static blast radius of changing a symbol; over-reports by design and says so.
     *
     * @param list<string> $edgeKinds
     */
    public function impactAnalysis(string $projectId, string $symbol, int $maxDepth = 4, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000, ?int $deadline = null): ResultEnvelope
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
                ['query' => $symbol, 'candidates' => $candidates, 'dependants' => [], 'counts' => ['by_distance' => [], 'by_confidence' => ['certain' => 0, 'probable' => 0, 'possible' => 0]], 'entry_points' => []],
                [],
                ['Use a returned stable component ID to disambiguate the request.'],
            );
        }
        $target = $candidates[0];
        // A caller (e.g. changed_files_impact fanning out over many components)
        // can pass one shared deadline so the whole request is bounded, instead
        // of each analysis resetting its own timeout.
        $deadline ??= $this->now() + ($timeoutMs * 1_000_000);
        $queue = [[$target, 0, 3]];
        $seen = [$target['id'] => true];
        $dependants = [];
        $recordIndex = [];
        $truncated = false;
        $truncationReason = null;
        $edgesTruncated = false;
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
            foreach ($this->impactEdges($projectId, $current['id'], $edgeKinds, $confidenceRank[$minConfidence], $edgesTruncated) as $edge) {
                $edgeConfidence = $confidenceRank[$edge['confidence']];
                if (isset($seen[$edge['source_id']])) {
                    // Already discovered: if this equal-distance alternate path
                    // carries higher confidence, prefer it (max per distance)
                    // rather than keeping the first-discovered, weaker value.
                    $existingIndex = $recordIndex[$edge['source_id']] ?? null;
                    if ($existingIndex !== null && $dependants[$existingIndex]['distance'] === $distance + 1) {
                        $candidateRank = min($pathConfidence, $edgeConfidence);
                        if ($candidateRank > $confidenceRank[$dependants[$existingIndex]['path_confidence']]) {
                            $dependants[$existingIndex]['path_confidence'] = array_search($candidateRank, $confidenceRank, true);
                        }
                    }
                    continue;
                }
                $node = $this->node($edge['source_id']);
                if ($node === null) {
                    continue;
                }
                $seen[$node['id']] = true;
                $dependants[] = [
                    'node' => $node,
                    'distance' => $distance + 1,
                    'path_confidence' => array_search(min($pathConfidence, $edgeConfidence), $confidenceRank, true),
                    'via' => $this->impactHop($edge),
                ];
                // limit+1 semantics: keep exactly $limit, flag truncation only
                // when a further dependant actually exists.
                if (count($dependants) > $limit) {
                    array_pop($dependants);
                    $truncated = true;
                    $truncationReason = 'result_limit';
                    break 2;
                }
                $recordIndex[$node['id']] = array_key_last($dependants);
                $queue[] = [$node, $distance + 1, min($pathConfidence, $edgeConfidence)];
            }
        }
        if ($edgesTruncated) {
            // A hub with more than 500 inbound edges of the selected kinds had
            // some silently dropped; surface it instead of claiming completeness.
            $truncated = true;
            $truncationReason ??= 'per_node_edge_limit';
        }
        // Resolve roles for all dependants in one batched pass rather than one
        // query per accepted dependant during the BFS.
        $roleMap = $this->roles(array_map(static fn(array $record): string => $record['node']['id'], $dependants));
        foreach ($dependants as &$dependant) {
            $dependant['roles'] = $roleMap[$dependant['node']['id']] ?? [];
        }
        unset($dependant);
        $boundaryMap = $this->boundaryNames(array_map(static fn(array $record): string => $record['node']['id'], $dependants));
        foreach ($dependants as &$record) {
            $record['boundaries'] = $boundaryMap[$record['node']['id']] ?? [];
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
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d potential static dependant%s within depth %d.', count($dependants), count($dependants) === 1 ? '' : 's', $maxDepth),
            [
                'target' => $target,
                'dependants' => array_map(
                    static fn(array $r): array => ['node' => $r['node'], 'distance' => $r['distance'], 'path_confidence' => $r['path_confidence'], 'via' => $r['via']],
                    $dependants,
                ),
                'counts' => [
                    'by_distance' => array_map('count', $byDistance),
                    'by_confidence' => array_map('count', $byConfidence),
                ],
                'entry_points' => $entryPoints,
                'bounds' => ['max_depth' => $maxDepth, 'limit' => $limit, 'timeout_ms' => $timeoutMs, 'visited_states' => $visited, 'truncation_reason' => $truncationReason],
            ],
            $evidence,
            ['Impact is a conservative static blast radius; it does not guarantee that a dependant will break.'],
            $truncated,
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

    /**
     * A `class`- or `interface`-kind endpoint stands for itself and its contained members:
     * `contains` is not a flow edge, so without expansion a class query can never
     * descend into the methods that actually carry calls/constructs edges.
     *
     * Expansion follows `contains` edges regardless of the query's `min_confidence`:
     * containment is structural, not a flow relationship, so it isn't filtered by the
     * same confidence threshold as calls/constructs edges.
     *
     * @param array<string, mixed> $node
     * @return array<string, array<string, mixed>> id => node row
     */
    private function flowEndpointSet(string $projectId, array $node, bool &$truncated): array
    {
        $set = [$node['id'] => $node];
        if (!in_array($node['kind'], ['class', 'interface'], true)) {
            return $set;
        }
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence FROM edges e ' .
            'JOIN nodes n ON n.id = e.target_id WHERE e.project_id = :project AND e.source_id = :source ' .
            "AND e.kind = 'contains' ORDER BY n.canonical_name, n.id LIMIT 201",
        );
        $statement->execute(['project' => $projectId, 'source' => $node['id']]);
        $rows = $statement->fetchAll();
        if (count($rows) > 200) {
            $truncated = true;
            $rows = array_slice($rows, 0, 200);
        }
        foreach ($rows as $row) {
            $set[$row['id']] = $row;
        }
        return $set;
    }

    /**
     * Edge kinds that count as control or data flow for reachability.
     *
     * @param list<string> $edgeKinds @return list<array<string, mixed>>
     */
    private function flowEdges(string $projectId, string $sourceId, array $edgeKinds, int $minimumConfidence, bool &$truncated = false): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND e.source_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY CASE e.confidence WHEN \'certain\' THEN 3 WHEN \'probable\' THEN 2 ELSE 1 END DESC, e.kind, e.id LIMIT 501',
        );
        $statement->execute([$projectId, $sourceId, ...$edgeKinds, $minimumConfidence]);
        $rows = $statement->fetchAll();
        if (count($rows) > 500) {
            $truncated = true;
            $rows = array_slice($rows, 0, 500);
        }
        return $rows;
    }
    /**
     * Edge kinds that count as a dependency for impact, a wider set than flow.
     *
     * @param list<string> $edgeKinds @return list<array<string, mixed>>
     */
    private function impactEdges(string $projectId, string $targetId, array $edgeKinds, int $minimumConfidence, bool &$truncated = false): array
    {
        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? AND e.target_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY CASE e.confidence WHEN \'certain\' THEN 3 WHEN \'probable\' THEN 2 ELSE 1 END DESC, e.kind, e.id LIMIT 501',
        );
        $statement->execute([$projectId, $targetId, ...$edgeKinds, $minimumConfidence]);
        $rows = $statement->fetchAll();
        if (count($rows) > 500) {
            $truncated = true;
            $rows = array_slice($rows, 0, 500);
        }
        return $rows;
    }
    /**
     * One breadth-first step outward, carrying the weakest confidence seen along the path.
     *
     * @param array<string, mixed> $edge @return array<string, mixed>
     */
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
    /**
     * Whether a node is reachable from outside: a route, command, or listener.
     *
     * @param array<string, mixed> $record
     */
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
    /**
     * Rank one flow against another: strongest evidence first.
     *
     * Shared by the sort and by the choice between two witnesses of the same
     * route, so a duplicate is resolved on exactly the criteria that decide the
     * final order rather than on discovery order.
     *
     * @param array<string, mixed> $left @param array<string, mixed> $right
     */
    private static function comparePaths(array $left, array $right): int
    {
        return ($right['score']['minimum_confidence'] <=> $left['score']['minimum_confidence'])
            ?: ($left['score']['hops'] <=> $right['score']['hops'])
            ?: ($right['score']['semantic_edges'] <=> $left['score']['semantic_edges'])
            ?: ($left['signature'] <=> $right['signature']);
    }

    /**
     * The evidence path for a node, or null when it has none.
     *
     * @param list<array<string, mixed>> $nodes @param list<array<string, mixed>> $edges @return array<string, mixed>
     */
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
    /**
     * Result tallies grouped by distance and confidence, so a caller can weigh the answer.
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
    /**
     * Whether a node depends on itself, reported separately since that is rarely what a caller means by a cycle.
     *
     * @param array<string, list<string>> $adjacency
     */
    private function hasSelfLoop(string $nodeId, array $adjacency): bool
    {
        return in_array($nodeId, $adjacency[$nodeId] ?? [], true);
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
