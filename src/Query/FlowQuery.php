<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * How one component can reach another: `explain_flow`'s bounded path search.
 *
 * Walks forward along the flow relationships only, a narrower set than the
 * dependency kinds impact and cycles read, because a flow is meant to read as
 * a route a request could take. Every walk is bounded by depth, by the states
 * it may queue, and by a deadline, and a cut walk says which bound cut it.
 */
final readonly class FlowQuery extends AbstractArchitectureQueryService
{
    /**
     * States explain_flow may queue. It also bounds the states visited, since
     * a state is visited at most once and only after it was queued, so no
     * separate visit bound is needed.
     */
    private const MAX_FLOW_STATES = 10_000;

    /**
     * Paths by which one component can reach another, with the edges that justify each hop.
     *
     * Reads as its phases: validate the bounds, resolve both endpoints to one
     * component each, search, rank the routes found, and report them.
     *
     * @param list<string> $edgeKinds
     */
    public function explainFlow(string $projectId, string $from, string $to, int $maxDepth = 6, int $maxPaths = 5, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        $project = $this->project($projectId);
        [$confidenceRank, $edgeKinds] = $this->flowArguments($maxDepth, $maxPaths, $edgeKinds, $minConfidence, $timeoutMs);

        $fromCandidates = $this->resolve($projectId, $from);
        $toCandidates = $this->resolve($projectId, $to);
        if (count($fromCandidates) !== 1 || count($toCandidates) !== 1) {
            return self::unresolvedFlow($project, $from, $to, $fromCandidates, $toCandidates);
        }
        $source = $fromCandidates[0];
        $target = $toCandidates[0];
        $endpointTruncated = false;
        $sourceSet = $this->flowEndpointSet($projectId, $source, $endpointTruncated);
        $targetSet = $this->flowEndpointSet($projectId, $target, $endpointTruncated);
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $truncation = new Truncation();
        $search = $this->searchFlows($projectId, $sourceSet, $targetSet, $maxDepth, $maxPaths, $edgeKinds, $confidenceRank[$minConfidence], $deadline, $truncation);
        if ($endpointTruncated) {
            $truncation->add('endpoint_expansion_limit');
        }
        $paths = self::rankedPaths($search['paths'], $maxPaths, $truncation);

        return self::flowEnvelope(
            $project,
            ['from' => $source, 'to' => $target, 'paths' => $paths],
            ['max_depth' => $maxDepth, 'max_paths' => $maxPaths, 'timeout_ms' => $timeoutMs, 'visited_states' => $search['visited'], 'queued_states' => $search['queued']],
            $truncation,
        );
    }

    /**
     * The validated bounds of an explain_flow call, with the confidence ranks
     * and the flow edge kinds the search then reads.
     *
     * @param list<string> $edgeKinds
     * @return array{0: array<string, int>, 1: list<string>}
     */
    private function flowArguments(int $maxDepth, int $maxPaths, array $edgeKinds, string $minConfidence, int $timeoutMs): array
    {
        if ($maxDepth < 1 || $maxDepth > 8) {
            throw new InvalidArgumentException('max_depth must be between 1 and 8.');
        }
        if ($maxPaths < 1 || $maxPaths > 20) {
            throw new InvalidArgumentException('max_paths must be between 1 and 20.');
        }
        $confidenceRank = $this->confidenceThreshold($timeoutMs, $minConfidence);

        return [$confidenceRank, self::selectedEdgeKinds($edgeKinds, self::FLOW_EDGE_KINDS, 'flow')];
    }

    /**
     * The answer when an endpoint resolved to no component or to several.
     *
     * Names the endpoint that failed, and says which way it failed: with two
     * endpoints, "one unambiguous component each" leaves a caller guessing
     * both which end to fix and whether to search for it or narrow it.
     *
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $fromCandidates
     * @param list<array<string, mixed>> $toCandidates
     */
    private static function unresolvedFlow(array $project, string $from, string $to, array $fromCandidates, array $toCandidates): ResultEnvelope
    {
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
            $project['id'],
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

    /**
     * The breadth-first search from the source set to the target set, one
     * witness kept per distinct route.
     *
     * Routes are keyed by signature: the cap and the max_paths budget are both
     * about distinct ROUTES, and two call sites between the same pair of
     * symbols are one route written twice. Counting the copies let a hot pair
     * spend the whole search before any other route was reached.
     *
     * @param array<string, array<string, mixed>> $sourceSet
     * @param array<string, array<string, mixed>> $targetSet
     * @param list<string> $edgeKinds
     * @return array{paths: array<string, array<string, mixed>>, visited: int, queued: int}
     */
    private function searchFlows(string $projectId, array $sourceSet, array $targetSet, int $maxDepth, int $maxPaths, array $edgeKinds, int $minimumRank, int $deadline, Truncation $truncation): array
    {
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
        $visited = 0;
        $flowEdgesTruncated = false;
        $candidateCap = $maxPaths * 20;
        while (isset($queue[$head]) && count($paths) < $candidateCap) {
            if ($this->now() > $deadline) {
                $truncation->add('time_limit');
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
            foreach ($this->flowEdges($projectId, $last['id'], $edgeKinds, $minimumRank, $flowEdgesTruncated) as $index => $edge) {
                // Up to 500 edges per node: the clock is read on the first and
                // every 64th, so one wide node cannot run past the deadline.
                if ($index % 64 === 0 && $this->now() > $deadline) {
                    $truncation->add('time_limit');
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
                        $truncation->add('candidate_limit');
                        break 2;
                    }
                    continue;
                }
                if ($queued >= self::MAX_FLOW_STATES) {
                    // A full queue refuses new states but keeps searching the
                    // ones it holds: they cost no more memory, and any of them
                    // may be one hop from the target, as this edge's target
                    // check above already allows.
                    $truncation->add('queue_limit');
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
            $truncation->add('per_node_edge_limit');
        }

        return ['paths' => $paths, 'visited' => $visited, 'queued' => $queued];
    }

    /**
     * The routes found, strongest first, trimmed to the max_paths budget.
     *
     * One entry per distinct route already; this only orders them, which
     * decides which survive the budget.
     *
     * @param array<string, array<string, mixed>> $paths
     * @return list<array<string, mixed>>
     */
    private static function rankedPaths(array $paths, int $maxPaths, Truncation $truncation): array
    {
        $paths = array_values($paths);
        usort($paths, static fn(array $left, array $right): int => self::comparePaths($left, $right));
        if (count($paths) > $maxPaths) {
            $paths = array_slice($paths, 0, $maxPaths);
            // Recorded after any time or visit reason, never in place of one:
            // a timed-out search must not report only path trimming.
            $truncation->add('path_limit');
        }

        return $paths;
    }

    /**
     * The explain_flow envelope: the routes, the evidence for each hop, and
     * the bounds that say how complete the search was.
     *
     * @param array<string, mixed> $project
     * @param array{from: array<string, mixed>, to: array<string, mixed>, paths: list<array<string, mixed>>} $flow
     * @param array{max_depth: int, max_paths: int, timeout_ms: int, visited_states: int, queued_states: int} $bounds
     */
    private static function flowEnvelope(array $project, array $flow, array $bounds, Truncation $truncation): ResultEnvelope
    {
        $truncationReasons = $truncation->reasons();
        $evidence = [];
        foreach ($flow['paths'] as $pathIndex => $path) {
            foreach ($path['hops'] as $hopIndex => $hop) {
                if ($hop['evidence'] !== null) {
                    $evidence[] = ['path_index' => $pathIndex, 'hop_index' => $hopIndex] + $hop['evidence'];
                }
            }
        }
        $count = count($flow['paths']);
        $summary = $count === 0 ? 'No supported static flow was found within the configured bounds.' : sprintf('Found %d plausible static flow%s.', $count, $count === 1 ? '' : 's');
        if ($truncation->any()) {
            $summary .= sprintf(' The search was truncated (%s).', implode(', ', $truncationReasons));
        }

        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            $summary,
            $flow + [
                'bounds' => $bounds + ['truncation_reason' => $truncationReasons[0] ?? null, 'truncation_reasons' => $truncationReasons],
            ],
            $evidence,
            ['Flows are plausible statically supported paths, not proof of runtime execution.'],
            $truncation->any(),
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
}
