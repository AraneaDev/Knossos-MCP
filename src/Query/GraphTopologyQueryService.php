<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;
use PDOStatement;

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
    /**
     * The first in-degree of each bucket of the health check's in-degree
     * histogram: nothing depends on it, a few, some, many, and the hubs.
     */
    private const IN_DEGREE_FROM = [0, 1, 6, 21, 101];

    /**
     * States explain_flow may queue. It also bounds the states visited, since
     * a state is visited at most once and only after it was queued, so no
     * separate visit bound is needed.
     */
    private const MAX_FLOW_STATES = 10_000;

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
        $edgeKinds = $edgeKinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::IMPACT_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported dependency relationship.');
        }

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
     * Hubs, hotspots, and unreferenced-code candidates, ranked for where to look first.
     *
     * @param list<string> $edgeKinds
     */
    public function architectureHealth(string $projectId, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 20, int $maxNodes = 50_000, int $maxEdges = 100_000, int $timeoutMs = 1000, bool $includeExternal = false, bool $includeTests = false, string $candidateConfidence = 'possible', int $candidateOffset = 0, int $candidateTimeoutMs = 5000): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        if (!in_array($candidateConfidence, ['probable', 'possible'], true)) {
            throw new InvalidArgumentException('candidate_confidence must be probable or possible.');
        }
        if ($candidateOffset < 0) {
            throw new InvalidArgumentException('candidate_offset must not be negative.');
        }
        if ($candidateTimeoutMs < 1 || $candidateTimeoutMs > 60_000) {
            throw new InvalidArgumentException('candidate_timeout_ms must be between 1 and 60000.');
        }
        if ($maxNodes < 1 || $maxNodes > 50_000) {
            throw new InvalidArgumentException('max_nodes must be between 1 and 50000.');
        }
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        $edgeKinds = $edgeKinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($edgeKinds));
        if (count($edgeKinds) > 20 || array_diff($edgeKinds, self::IMPACT_EDGE_KINDS) !== []) {
            throw new InvalidArgumentException('edge_kinds contains an unsupported dependency relationship.');
        }

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        // The walk keeps three facts per node, not its row: names, files,
        // roles and boundaries are loaded for the reported page alone. A full
        // row per node, with its roles and boundaries, needed more than 128 MB
        // well before the 50,000-node default.
        $slice = $this->healthSlice($projectId, $maxNodes, $deadline);
        $truncationReasons = $slice['truncation_reasons'];
        $truncated = $truncationReasons !== [];

        $placeholders = implode(',', array_fill(0, count($edgeKinds), '?'));
        $edgeStatement = $this->pdo->prepare(
            'SELECT e.source_id, e.target_id FROM edges e WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $edgeStatement->execute([$projectId, ...$edgeKinds, $confidenceRank[$minConfidence], $maxEdges + 1]);
        $walk = $this->walkDegrees($edgeStatement, $slice, $maxEdges, $deadline);
        $metrics = $walk['metrics'];
        $edgesExamined = $walk['edges_examined'];
        if ($walk['truncation_reasons'] !== []) {
            $truncated = true;
            $truncationReasons = array_merge($truncationReasons, $walk['truncation_reasons']);
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

        $ranked = $this->rankNodes($slice, $metrics, $cycleMembers, $includeExternal, $includeTests, $limit);
        $excludedExternal = $ranked['excluded_external'];
        $excludedTests = $ranked['excluded_tests'];
        $candidateDeadline = $this->now() + ($candidateTimeoutMs * 1_000_000);
        $candidateSearch = new DeadCodeCandidates($this->pdo, $this->clock);
        $found = $candidateSearch->find($projectId, $edgeKinds, $confidenceRank[$minConfidence], $includeTests, $candidateDeadline, $candidateConfidence, $candidateOffset, $limit);
        $deadCandidates = $found['candidates'];
        $excluded = $found['excluded'] + [
            'inherited' => 0, 'contracts' => 0, 'constructors' => 0, 'entry_scripts' => 0,
            'type_declarations' => 0, 'suppressed' => 0, 'annotated_false_positives' => 0, 'annotated_intentional' => 0,
        ];
        $excludedConventionDiscovered = $found['convention_excluded'];
        // Tallied on the FULL list, not the page: ordering test_only last means
        // the page hides them first, and a summary built from it would then
        // report 0 test-only findings whenever there were enough unreferenced
        // ones to fill the limit on their own.
        $testOnlyCandidates = $found['test_only'];
        $candidatesTotal = $found['total'];
        foreach (['hub_total', 'hotspot_total'] as $total) {
            if ($ranked[$total] > $limit) {
                $truncated = true;
                $truncationReasons[] = 'result_limit';
            }
        }
        // The candidate list reports its own truncation: `truncation_reasons`
        // describe the hub ranking, and a full page of candidates said the
        // hubs had been cut when they had not.
        $candidateTruncationReasons = [];
        if ($found['truncated']) {
            $candidateTruncationReasons[] = 'time_limit';
        }
        if ($candidatesTotal > $candidateOffset + $limit) {
            $candidateTruncationReasons[] = 'result_limit';
        }
        $reported = [];
        foreach ([...array_keys($ranked['hubs']), ...array_keys($ranked['hotspots'])] as $index) {
            $reported[$slice['ids'][$index]] = true;
        }
        foreach ($deadCandidates as $item) {
            $reported[$item['component']['id']] = true;
        }
        // Rows for the reported page only: the ranked components and the candidates.
        $rows = $candidateSearch->rows(array_map('strval', array_keys($reported)));
        [$hubs, $hotspots] = $this->rankedComponents($ranked, $slice, $metrics, $rows, $cycleMembers);
        $evidence = [];
        foreach (array_keys($reported) as $id) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
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
            self::healthSummary(count($hubs), count($hotspots), $candidatesTotal, count($deadCandidates), $testOnlyCandidates, $truncationReasons, $candidateTruncationReasons),
            [
                'hubs' => $hubs, 'static_hotspots' => $hotspots, 'dead_code_candidates' => $deadCandidates, 'in_degree_histogram' => $ranked['in_degree'],
                'bounds' => [
                    'limit' => $limit, 'max_nodes' => $maxNodes, 'max_edges' => $maxEdges, 'timeout_ms' => $timeoutMs,
                    'candidate_confidence' => $candidateConfidence, 'candidate_offset' => $candidateOffset,
                    'candidate_timeout_ms' => $candidateTimeoutMs,
                    'candidates_total' => $candidatesTotal,
                    'candidates_truncated' => $candidateTruncationReasons !== [],
                    'candidate_truncation_reasons' => $candidateTruncationReasons,
                    'nodes_examined' => count($slice['ids']), 'edges_examined' => $edgesExamined,
                    'excluded_external_components' => $excludedExternal, 'excluded_test_components' => $excludedTests,
                    'excluded_inherited_methods' => $excluded['inherited'],
                    'excluded_contract_methods' => $excluded['contracts'],
                    'excluded_constructors' => $excluded['constructors'],
                    'excluded_entry_scripts' => $excluded['entry_scripts'],
                    'excluded_type_declarations' => $excluded['type_declarations'],
                    'excluded_convention_discovered' => $excludedConventionDiscovered,
                    'suppressed_candidates' => $excluded['suppressed'],
                    'annotated_false_positives' => $excluded['annotated_false_positives'],
                    'annotated_intentional' => $excluded['annotated_intentional'],
                    'cycle_scan_truncated' => $cycleScanTruncated, 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            [
                'Hotspots are static structural signals, not change-frequency or defect predictions.',
                'Dead-code results are candidates only; reflection, configuration, templates, registry arrays, callbacks, dispatch tables, and framework conventions may reference a component without a visible static edge.',
                'Each candidate carries a reachability class: `unreferenced` means nothing references it at all, `test_only` means the only references come from test code. Components reached by convention — controllers, commands, entry points, config — are excluded rather than reported, and counted in bounds.excluded_convention_discovered.',
            ],
            $truncated,
        );
    }


    /**
     * The nodes architecture_health ranks, as the three facts the ranking reads.
     *
     * Each node in the slice is numbered in name order and keeps whether it is
     * external, whether it is test code, and which boundaries it belongs to;
     * nothing else about it is held. The boundaries are kept as one of a few
     * distinct sets (most nodes share their set with many others), with the
     * repository-wide boundaries left out because they partition nothing.
     *
     * @return array{ids: list<string>, index: array<string, int>, external: list<bool>, test: array<int, true>, boundary_set: array<int, int>, boundary_sets: list<list<string>>, truncation_reasons: list<string>}
     */
    private function healthSlice(string $projectId, int $maxNodes, int $deadline): array
    {
        $slice = ['ids' => [], 'index' => [], 'external' => [], 'test' => [], 'boundary_set' => [], 'boundary_sets' => [], 'truncation_reasons' => []];
        $statement = $this->pdo->prepare('SELECT n.id, n.kind, n.origin FROM nodes n WHERE n.project_id = :project ORDER BY n.canonical_name, n.id LIMIT :limit');
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':limit', $maxNodes + 1, PDO::PARAM_INT);
        $statement->execute();
        // Streamed: max_nodes reaches 50,000 rows, and fetchAll() read every
        // one of them before the deadline was ever consulted.
        $slice['truncation_reasons'] = $this->streamBounded($statement, $maxNodes, $deadline, static function (array $row) use (&$slice): bool {
            $slice['index'][$row['id']] = count($slice['ids']);
            $slice['ids'][] = (string) $row['id'];
            $slice['external'][] = ReportableComponent::isExternal((string) $row['kind'], $row['origin']);

            return true;
        }, 'node_limit');

        $tests = $this->pdo->prepare('SELECT DISTINCT node_id FROM classifications WHERE project_id = ? AND role = ?');
        $tests->execute([$projectId, ReportableComponent::TEST_ROLE]);
        while (($nodeId = $tests->fetchColumn()) !== false) {
            if (isset($slice['index'][$nodeId])) {
                $slice['test'][$slice['index'][$nodeId]] = true;
            }
        }

        $repositoryWide = $this->repositoryWideBoundaryIds($projectId);
        $memberships = $this->pdo->prepare(
            'SELECT bm.node_id, b.id FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id ' .
            'WHERE bm.project_id = ? ORDER BY bm.node_id, b.id',
        );
        $memberships->execute([$projectId]);
        // Rows arrive grouped by node: each group becomes that node's set.
        $setIndex = [];
        $current = null;
        $members = [];
        while (($row = $memberships->fetch()) !== false) {
            $nodeId = (string) $row['node_id'];
            if ($nodeId !== $current) {
                if ($current !== null) {
                    self::assignBoundarySet($slice, $setIndex, $current, $members);
                }
                $current = $nodeId;
                $members = [];
            }
            if (!isset($repositoryWide[(string) $row['id']])) {
                $members[] = (string) $row['id'];
            }
        }
        if ($current !== null) {
            self::assignBoundarySet($slice, $setIndex, $current, $members);
        }

        return $slice;
    }

    /**
     * Gives a node of the slice its boundary set, adding the set when it is the first node to hold it.
     *
     * A node outside the slice, or one whose boundaries are all repository-wide, gets none.
     *
     * @param array{index: array<string, int>, boundary_set: array<int, int>, boundary_sets: list<list<string>>} $slice
     * @param array<string, int> $setIndex the sets so far, keyed by their members
     * @param list<string> $members the node's boundary ids, sorted
     */
    private static function assignBoundarySet(array &$slice, array &$setIndex, string $nodeId, array $members): void
    {
        if ($members === [] || !isset($slice['index'][$nodeId])) {
            return;
        }
        $key = implode("\0", $members);
        if (!isset($setIndex[$key])) {
            $setIndex[$key] = count($slice['boundary_sets']);
            $slice['boundary_sets'][] = $members;
        }
        $slice['boundary_set'][$slice['index'][$nodeId]] = $setIndex[$key];
    }

    /**
     * One streamed pass over the edge slice, producing the degrees the hub and
     * hotspot rankings read: in/out degree, and the cross-boundary degree
     * hotspots rank on. An edge counts only when both ends are in the node slice.
     *
     * Extracted from architectureHealth because that method is up against the
     * repository's own function-length budget, and this is the seam that pays:
     * everything here is the edge walk and the counters it fills, and nothing
     * here decides what any of it means. Dead-code candidates are decided over
     * the whole graph by DeadCodeCandidates, not from this slice.
     *
     * @param array{index: array<string, int>, boundary_set: array<int, int>, boundary_sets: list<list<string>>} $slice
     * @return array{
     *     metrics: array{in: list<int>, out: list<int>, cross: list<int>},
     *     edges_examined: int,
     *     truncation_reasons: list<string>,
     * }
     */
    private function walkDegrees(PDOStatement $edges, array $slice, int $maxEdges, int $deadline): array
    {
        $count = count($slice['index']);
        $metrics = ['in' => array_fill(0, $count, 0), 'out' => array_fill(0, $count, 0), 'cross' => array_fill(0, $count, 0)];
        $edgesExamined = 0;
        // Two nodes cross a boundary when both belong to some boundary and
        // they share none; decided once per pair of distinct sets.
        $disjoint = [];
        $reasons = $this->streamBounded($edges, $maxEdges, $deadline, static function (array $edge) use (&$edgesExamined, &$metrics, &$disjoint, $slice): bool {
            ++$edgesExamined;
            $source = $slice['index'][$edge['source_id']] ?? null;
            $target = $slice['index'][$edge['target_id']] ?? null;
            if ($source === null || $target === null) {
                return true;
            }
            ++$metrics['out'][$source];
            ++$metrics['in'][$target];
            $a = $slice['boundary_set'][$source] ?? null;
            $b = $slice['boundary_set'][$target] ?? null;
            if ($a !== null && $b !== null && $a !== $b) {
                $pair = $a < $b ? $a . ':' . $b : $b . ':' . $a;
                $disjoint[$pair] ??= array_intersect($slice['boundary_sets'][$a], $slice['boundary_sets'][$b]) === [];
                if ($disjoint[$pair]) {
                    ++$metrics['cross'][$source];
                    ++$metrics['cross'][$target];
                }
            }

            return true;
        });

        return ['metrics' => $metrics, 'edges_examined' => $edgesExamined, 'truncation_reasons' => $reasons];
    }

    /**
     * One pass over the node slice, scoring each component for the two
     * rankings architecture_health reports: hubs and static hotspots. Every
     * component the rankings could hold (degree zero included) is also counted
     * into the in-degree histogram, so a bucket says how many there are and
     * the ranking which few are listed.
     *
     * Only the first `$limit` of each ranking are kept, by score and then by
     * name, which is the slice's own order; the totals say how many there were.
     * Dead-code candidates are not drawn from the slice; DeadCodeCandidates
     * finds them over the whole project. Nothing here reads the database.
     *
     * @param array{ids: list<string>, external: list<bool>, test: array<int, true>} $slice
     * @param array{in: list<int>, out: list<int>, cross: list<int>} $metrics
     * @param array<string, true> $cycleMembers
     * @return array{hubs: array<int, int>, hotspots: array<int, int>, hub_total: int, hotspot_total: int, excluded_external: int, excluded_tests: int, in_degree: list<array{from: int, to: int|null, components: int}>}
     */
    private function rankNodes(array $slice, array $metrics, array $cycleMembers, bool $includeExternal, bool $includeTests, int $limit): array
    {
        $hubs = $hotspots = [];
        $excludedExternal = $excludedTests = 0;
        $histogram = array_fill(0, count(self::IN_DEGREE_FROM), 0);
        foreach ($slice['ids'] as $index => $id) {
            $degree = $metrics['in'][$index] + $metrics['out'][$index];
            $external = !$includeExternal && $slice['external'][$index];
            $test = !$external && !$includeTests && isset($slice['test'][$index]);
            if (!$external && !$test) {
                ++$histogram[self::inDegreeBucket($metrics['in'][$index])];
            }
            if ($degree === 0) {
                continue;
            }
            if ($external) {
                ++$excludedExternal;
            } elseif ($test) {
                ++$excludedTests;
            } else {
                $hubs[$index] = $degree;
                $hotspots[$index] = $degree + (2 * $metrics['cross'][$index]) + (isset($cycleMembers[$id]) ? 3 : 0);
            }
        }
        // Stable: equal scores keep the slice's name order.
        arsort($hubs);
        arsort($hotspots);

        return [
            'hubs' => array_slice($hubs, 0, $limit, true), 'hotspots' => array_slice($hotspots, 0, $limit, true),
            'hub_total' => count($hubs), 'hotspot_total' => count($hotspots),
            'excluded_external' => $excludedExternal, 'excluded_tests' => $excludedTests, 'in_degree' => self::histogram($histogram),
        ];
    }

    /**
     * The ranked page as reported: each component with its row, roles and boundaries, loaded for the page alone.
     *
     * A component whose row is gone (a scan replaced it since the walk) is left out.
     *
     * @param array{hubs: array<int, int>, hotspots: array<int, int>} $ranked
     * @param array{ids: list<string>} $slice
     * @param array{in: list<int>, out: list<int>, cross: list<int>} $metrics
     * @param array<string, array<string, mixed>> $rows
     * @param array<string, true> $cycleMembers
     * @return array{list<array<string, mixed>>, list<array<string, mixed>>}
     */
    private function rankedComponents(array $ranked, array $slice, array $metrics, array $rows, array $cycleMembers): array
    {
        $ids = [];
        foreach ([...array_keys($ranked['hubs']), ...array_keys($ranked['hotspots'])] as $index) {
            $ids[$slice['ids'][$index]] = true;
        }
        $ids = array_map('strval', array_keys($ids));
        $roles = $this->roles($ids);
        $boundaries = $this->boundaryNames($ids);
        $entries = [];
        foreach (['hubs', 'hotspots'] as $list) {
            $entries[$list] = [];
            foreach ($ranked[$list] as $index => $score) {
                $id = $slice['ids'][$index];
                $row = $rows[$id] ?? null;
                if ($row === null) {
                    continue;
                }
                $component = [
                    'id' => $id, 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                    'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                    'roles' => $roles[$id] ?? [], 'boundaries' => $boundaries[$id] ?? [],
                ];
                $degrees = ['in_degree' => $metrics['in'][$index], 'out_degree' => $metrics['out'][$index], 'cross_boundary_degree' => $metrics['cross'][$index]];
                $entries[$list][] = $list === 'hubs'
                    ? ['component' => $component, 'metrics' => $degrees, 'score' => $score]
                    : ['component' => $component, 'factors' => $degrees + ['cycle_participant' => isset($cycleMembers[$id])], 'score' => $score];
            }
        }

        return [$entries['hubs'], $entries['hotspots']];
    }

    /** The bucket of the in-degree histogram `$inDegree` falls in: the last whose lower bound it reaches. */
    private static function inDegreeBucket(int $inDegree): int
    {
        $bucket = 0;
        foreach (self::IN_DEGREE_FROM as $index => $from) {
            if ($inDegree >= $from) {
                $bucket = $index;
            }
        }

        return $bucket;
    }

    /**
     * The histogram's counts as buckets: each one's first and last in-degree
     * (null for the open top bucket) and how many components fall in it.
     *
     * @param list<int> $counts
     * @return list<array{from: int, to: int|null, components: int}>
     */
    private static function histogram(array $counts): array
    {
        $buckets = [];
        foreach (self::IN_DEGREE_FROM as $index => $from) {
            $next = self::IN_DEGREE_FROM[$index + 1] ?? null;
            $buckets[] = ['from' => $from, 'to' => $next === null ? null : $next - 1, 'components' => $counts[$index] ?? 0];
        }

        return $buckets;
    }

    /**
     * The one-line summary for architecture_health, naming the bound when the
     * ranking was truncated.
     *
     * A bounded ranking must not read as an exhaustive one: "Ranked 0 hubs, 0
     * static hotspots, and 0 unreferenced-code candidates" is the same sentence
     * a genuinely clean project gets, and a hub sitting beyond the node, edge,
     * time, or result cap is invisible in it. Naming the bound here is what lets
     * a caller tell the two apart without reading bounds.truncation_reasons —
     * the same contract {@see self::dependencyCycles()} keeps.
     *
     * Extracted rather than inlined because architectureHealth is up against
     * the repository's own function-length budget.
     *
     * The test-only tally is named separately because it is the half of the
     * list worth acting on first: a symbol nothing references may be waiting on
     * a caller nobody has written yet, but one its own test is the sole caller
     * of is finished work no product path reaches, and both it and the test
     * guarding it can go.
     *
     * `$testOnlyCandidates` has to be counted by the caller BEFORE `limit`
     * slices the candidate list, not passed in as the (already sliced) list
     * itself: candidates are ordered unreferenced-first, so once there are
     * enough of those to fill the limit on their own, every test_only finding
     * sits past the cut and a tally taken from the slice reads as zero while
     * the full list still has some.
     *
     * The hub walk's truncation and the candidate search's are reported
     * apart: the node bound limits the ranking only, and a candidate search
     * that ran out of time must not read as a complete list.
     *
     * The candidate count is the whole list's, the one the test-only tally is
     * taken from; the page's own size is named apart when it differs. A page
     * of 1 once read "1 unreferenced-code candidates, 87 of them reached only
     * by tests".
     *
     * @param list<string> $truncationReasons
     * @param list<string> $candidateTruncationReasons
     */
    private static function healthSummary(int $hubs, int $hotspots, int $candidatesTotal, int $candidatesOnPage, int $testOnlyCandidates, array $truncationReasons, array $candidateTruncationReasons): string
    {
        $summary = sprintf(
            'Ranked %d hubs, %d static hotspots, and %d unreferenced-code candidates, %d of them reached only by tests.',
            $hubs,
            $hotspots,
            $candidatesTotal,
            $testOnlyCandidates,
        );
        if ($candidatesOnPage !== $candidatesTotal) {
            $summary .= sprintf(' This page lists %d of them.', $candidatesOnPage);
        }
        if ($truncationReasons !== []) {
            $summary .= sprintf(' The ranking was truncated (%s), so hubs and hotspots beyond that bound are not reported.', implode(', ', $truncationReasons));
        }
        if (in_array('time_limit', $candidateTruncationReasons, true)) {
            $summary .= ' The candidate search ran out of time, so the candidate list is partial.';
        }
        if (in_array('result_limit', $candidateTruncationReasons, true)) {
            $summary .= ' More candidates follow this page; candidate_offset pages past it.';
        }

        return $summary;
    }

    /**
     * The top hubs on architecture_health's terms, at a session start's price.
     *
     * {@see architectureHealth()} is the authority on what a hub is, and this
     * shares its two exclusions verbatim through {@see ReportableComponent}:
     * vendor code and unresolved references are not this project's structure,
     * and test code is measured by coverage rather than by architecture. It
     * shares the degree definition too — inbound plus outbound over
     * {@see AbstractArchitectureQueryService::IMPACT_EDGE_KINDS}, which is what
     * keeps `contains` out of the tally, a relationship every declaration has
     * with its own members and which therefore ranks nothing.
     *
     * What it does not share is the rest of that method. `architecture_health`
     * also computes hotspots, runs a full cycle detection, and finds dead-code
     * candidates across the whole project, and it pays for all three before it
     * can hand back hubs. Measured against this repository's own graph (6,058 components,
     * 35,162 relationships) that is around 0.4s, against roughly 0.05s here.
     * The session brief is billed on every session start, resume and compact,
     * behind a hook that bounds itself at three seconds, so the whole report is
     * the wrong thing to buy for one ranked list — and the gap widens with the
     * graph, since the parts not needed here are the ones that scale worst.
     *
     * Filtering happens in PHP rather than in SQL so the predicates stay in one
     * place instead of being restated as a WHERE clause that could drift from
     * them. That is affordable because the ranking is consumed lazily: rows
     * arrive in degree order and the loop stops as soon as $limit survivors are
     * found, which on this repository means reading 21 rows to keep 5.
     * $maxRowsExamined bounds the pathological case — a graph whose entire head
     * is vendor code — so a brief can never turn into a full table scan.
     *
     * @return list<array{display_name: string, kind: string, degree: int}>
     */
    public function hubRanking(string $projectId, int $limit = 5, int $maxRowsExamined = 500): array
    {
        $placeholders = implode(',', array_fill(0, count(self::IMPACT_EDGE_KINDS), '?'));
        $degrees = $this->pdo->prepare(
            'SELECT n.id, n.display_name, n.kind, n.origin, d.degree FROM (' .
            'SELECT node_id, COUNT(*) AS degree FROM (' .
            sprintf('SELECT source_id AS node_id FROM edges WHERE project_id = ? AND kind IN (%s) ', $placeholders) .
            'UNION ALL ' .
            sprintf('SELECT target_id AS node_id FROM edges WHERE project_id = ? AND kind IN (%s)', $placeholders) .
            ') GROUP BY node_id) d JOIN nodes n ON n.id = d.node_id AND n.project_id = ? ' .
            'ORDER BY d.degree DESC, n.canonical_name',
        );
        $degrees->execute([$projectId, ...self::IMPACT_EDGE_KINDS, $projectId, ...self::IMPACT_EDGE_KINDS, $projectId]);
        $roles = $this->pdo->prepare('SELECT role FROM classifications WHERE project_id = ? AND node_id = ?');

        $hubs = [];
        $examined = 0;
        while (count($hubs) < $limit && $examined < $maxRowsExamined) {
            $row = $degrees->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                break;
            }
            ++$examined;
            if (ReportableComponent::isExternal((string) $row['kind'], $row['origin'])) {
                continue;
            }
            // Looked up per surviving candidate rather than joined for the
            // whole ranking: the join would classify every component in the
            // graph to report five of them.
            $roles->execute([$projectId, $row['id']]);
            /** @var list<string> $nodeRoles */
            $nodeRoles = $roles->fetchAll(PDO::FETCH_COLUMN);
            if (ReportableComponent::isTest($nodeRoles)) {
                continue;
            }
            $hubs[] = [
                'display_name' => (string) $row['display_name'],
                'kind' => (string) $row['kind'],
                'degree' => (int) $row['degree'],
            ];
        }
        // The cursor is abandoned mid-result on every call that stops early,
        // and SQLite holds its read lock until it is closed.
        $degrees->closeCursor();

        return $hubs;
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
            // Name the endpoint that failed, and say which way it failed: with two
            // endpoints, "one unambiguous component each" leaves a caller guessing
            // both which end to fix and whether to search for it or narrow it.
            $unmatched = [];
            if ($fromCandidates === []) {
                $unmatched[] = $from;
            }
            if ($toCandidates === []) {
                $unmatched[] = $to;
            }
            $warnings = [];
            if ($unmatched !== []) {
                $warnings[] = self::UNMATCHED_ADVICE;
            }
            if (count($fromCandidates) > 1 || count($toCandidates) > 1) {
                $warnings[] = self::AMBIGUOUS_ADVICE;
            }
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                $unmatched === []
                    ? 'Flow endpoints require one unambiguous component each.'
                    : sprintf('No component matched %s.', implode(' or ', array_map(static fn(string $name): string => sprintf('"%s"', $name), $unmatched))),
                [
                    'from' => ['query' => $from, 'candidates' => $fromCandidates],
                    'to' => ['query' => $to, 'candidates' => $toCandidates],
                    'paths' => [],
                ],
                [],
                $warnings,
            );
        }
        $source = $fromCandidates[0];
        $target = $toCandidates[0];
        $endpointTruncated = false;
        $sourceSet = $this->flowEndpointSet($projectId, $source, $endpointTruncated);
        $targetSet = $this->flowEndpointSet($projectId, $target, $endpointTruncated);
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        // A head index instead of array_shift, which re-indexes the whole queue
        // on every pop; each popped slot is unset so its path copy is released.
        $queue = [];
        $head = 0;
        foreach ($sourceSet as $start) {
            $queue[] = [[$start], [], [$start['id'] => true]];
        }
        // Every queued state carries a copy of its path, so the visit bound
        // alone let one wide node queue far more than it could ever visit.
        $queued = count($queue);
        $paths = [];
        // Keyed by signature: the cap and the max_paths budget are both about
        // distinct ROUTES, and two call sites between the same pair of symbols
        // are one route written twice. Counting the copies let a hot pair spend
        // the whole search before any other route was reached.
        $visited = 0;
        $queueFull = false;
        $truncated = false;
        $truncationReasons = [];
        $flowEdgesTruncated = false;
        $candidateCap = $maxPaths * 20;
        while (isset($queue[$head]) && count($paths) < $candidateCap) {
            if ($this->now() > $deadline) {
                $truncated = true;
                $truncationReasons[] = 'time_limit';
                break;
            }
            [$nodes, $hops, $seen] = $queue[$head];
            unset($queue[$head]);
            ++$head;
            ++$visited;
            if (count($hops) >= $maxDepth) {
                continue;
            }
            $last = $nodes[array_key_last($nodes)];
            foreach ($this->flowEdges($projectId, $last['id'], $edgeKinds, $confidenceRank[$minConfidence], $flowEdgesTruncated) as $index => $edge) {
                // Up to 500 edges per node: the clock is read on the first and
                // every 64th, so one wide node cannot run past the deadline.
                if ($index % 64 === 0 && $this->now() > $deadline) {
                    $truncated = true;
                    $truncationReasons[] = 'time_limit';
                    break 2;
                }
                // The goal may be reached even when it is already in $seen: the
                // source is pre-seeded, so a self-flow (from == to) depends on
                // matching the target before the visited-guard skips it.
                $isTarget = isset($targetSet[$edge['target_id']]);
                if (isset($seen[$edge['target_id']]) && !$isTarget) {
                    continue;
                }
                // The edge query joins the target row, so a dangling target is
                // already excluded and no lookup per edge is needed.
                $next = [
                    'id' => $edge['target_id'], 'kind' => $edge['target_kind'], 'canonical_name' => $edge['target_canonical_name'],
                    'display_name' => $edge['target_display_name'], 'confidence' => $edge['target_confidence'],
                ];
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
                if ($queued >= self::MAX_FLOW_STATES) {
                    // A full queue refuses new states but keeps searching the
                    // ones it holds: they cost no more memory, and any of them
                    // may be one hop from the target, as this edge's target
                    // check above already allows.
                    if (!$queueFull) {
                        $queueFull = true;
                        $truncated = true;
                        $truncationReasons[] = 'queue_limit';
                    }
                    continue;
                }
                $newSeen = $seen;
                $newSeen[$next['id']] = true;
                $queue[] = [$newNodes, $newHops, $newSeen];
                ++$queued;
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
        $summary = $count === 0 ? 'No supported static flow was found within the configured bounds.' : sprintf('Found %d plausible static flow%s.', $count, $count === 1 ? '' : 's');
        if ($truncated) {
            $summary .= sprintf(' The search was truncated (%s).', implode(', ', $truncationReasons));
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $summary,
            [
                'from' => $source, 'to' => $target, 'paths' => $paths,
                'bounds' => ['max_depth' => $maxDepth, 'max_paths' => $maxPaths, 'timeout_ms' => $timeoutMs, 'visited_states' => $visited, 'queued_states' => $queued, 'truncation_reason' => $truncationReasons[0] ?? null, 'truncation_reasons' => $truncationReasons],
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
        $confidenceRank = self::CONFIDENCE_RANK;
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
                $candidates === [] ? sprintf('No component matched "%s".', $symbol) : 'Impact analysis requires one unambiguous component.',
                ['query' => $symbol, 'candidates' => $candidates, 'dependants' => [], 'counts' => ['by_distance' => [], 'by_confidence' => ['certain' => 0, 'probable' => 0, 'possible' => 0]], 'entry_points' => []],
                [],
                [$candidates === [] ? self::UNMATCHED_ADVICE : self::AMBIGUOUS_ADVICE],
            );
        }
        $target = $candidates[0];
        // A caller (e.g. changed_files_impact fanning out over many components)
        // can pass one shared deadline so the whole request is bounded, instead
        // of each analysis resetting its own timeout.
        $deadline ??= $this->now() + ($timeoutMs * 1_000_000);
        $queue = [[$target, 0]];
        $seen = [$target['id'] => true];
        // Best confidence rank found for each node at its shortest distance.
        // Read at dequeue rather than carried in the queued tuple: every
        // rediscovery at distance d+1 comes from a level-d node, and FIFO order
        // dequeues all level-d nodes before any level-d+1 node, so by the time
        // a node is dequeued no further upgrade of its rank is possible and the
        // value read here is final.
        $bestRank = [$target['id'] => 3];
        $dependants = [];
        $recordIndex = [];
        $truncated = false;
        $truncationReason = null;
        $edgesTruncated = false;
        $visited = 0;
        while ($queue !== []) {
            // No visit bound: only accepted dependants are queued, and at most
            // $limit (100) are accepted, so at most $limit + 1 states are visited.
            if ($this->now() > $deadline) {
                $truncated = true;
                $truncationReason = 'time_limit';
                break;
            }
            [$current, $distance] = array_shift($queue);
            $pathConfidence = $bestRank[$current['id']];
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
                        if ($candidateRank > $bestRank[$edge['source_id']]) {
                            // Raise the record, the hop that justifies it (and
                            // so its evidence), and the rank its own dependants
                            // inherit together, so the three never disagree.
                            $bestRank[$edge['source_id']] = $candidateRank;
                            $dependants[$existingIndex]['path_confidence'] = self::rankName($candidateRank);
                            $dependants[$existingIndex]['via'] = $this->impactHop($edge);
                        }
                    }
                    continue;
                }
                $node = $this->node($edge['source_id']);
                if ($node === null) {
                    continue;
                }
                $seen[$node['id']] = true;
                $bestRank[$node['id']] = min($pathConfidence, $edgeConfidence);
                $dependants[] = [
                    'node' => $node,
                    'distance' => $distance + 1,
                    'path_confidence' => self::rankName($bestRank[$node['id']]),
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
                $queue[] = [$node, $distance + 1];
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
            'SELECT e.*, f.relative_path, source.display_name AS source_name, target.display_name AS target_name, ' .
            'target.kind AS target_kind, target.canonical_name AS target_canonical_name, ' .
            'target.display_name AS target_display_name, target.confidence AS target_confidence ' .
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
     * Whether a dependant is a way into the system, by the definition the briefs share.
     *
     * @param array<string, mixed> $record
     */
    private function isEntryPoint(array $record): bool
    {
        return EntryPointCriteria::matches((string) $record['node']['kind'], array_column($record['roles'], 'role'));
    }
    /**
     * The confidence name for a rank in {@see self::CONFIDENCE_RANK}.
     *
     * @param int $rank 1 (possible) to 3 (certain)
     */
    private static function rankName(int $rank): string
    {
        return (string) array_search($rank, self::CONFIDENCE_RANK, true);
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
        $rank = self::CONFIDENCE_RANK;
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
        $truncationReasons = $graph['truncation_reasons'];
        $componentScan = $this->stronglyConnectedComponents($graph['adjacency'], $graph['reverse'], $deadline);
        if ($componentScan['timed_out']) {
            $truncationReasons[] = 'time_limit';
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
            $truncationReasons[] = 'result_limit';
        }

        $detail = $this->cycleDetails($components, $graph, $confidenceRank);

        return [
            'nodes_examined' => count($nodeIds), 'edges_examined' => count($graph['edge_ids']),
            'truncation_reasons' => array_values(array_unique([...$truncationReasons, ...$detail['truncation_reasons']])),
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
        foreach (array_chunk(array_values(array_unique($ids)), 500) as $chunk) {
            $statement = $this->pdo->prepare(sprintf($sql, implode(',', array_fill(0, count($chunk), '?'))));
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $rows[$row['id']] = $row;
            }
        }

        return $rows;
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
