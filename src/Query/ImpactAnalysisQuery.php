<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * What depends on a component: `impact_analysis`'s conservative static blast radius.
 *
 * Walks backward along the dependency relationships, the wider set the
 * cycle search reads too, so a change's dependants are everything that could
 * notice it rather than only what a request passes through. It over-reports
 * by design and says so; change impact builds on it, one analysis per
 * changed component, under one shared deadline.
 */
final readonly class ImpactAnalysisQuery extends AbstractArchitectureQueryService
{
    /**
     * Conservative static blast radius of changing a symbol; over-reports by design and says so.
     *
     * Reads as its phases: validate the bounds, resolve the symbol to one
     * component, walk its dependants breadth-first, and report them grouped by
     * distance and confidence.
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
        $confidenceRank = $this->confidenceThreshold($timeoutMs, $minConfidence);
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'impact');
        $candidates = $this->resolve($projectId, $symbol);
        if (count($candidates) !== 1) {
            return self::unresolvedImpact($project, $symbol, $candidates);
        }
        $target = $candidates[0];
        // A caller (e.g. changed_files_impact fanning out over many components)
        // can pass one shared deadline so the whole request is bounded, instead
        // of each analysis resetting its own timeout.
        $deadline ??= $this->now() + ($timeoutMs * 1_000_000);
        $truncation = new Truncation();
        $search = $this->searchDependants($projectId, $target, $maxDepth, $limit, $edgeKinds, $confidenceRank[$minConfidence], $deadline, $truncation);

        return $this->impactEnvelope(
            $project,
            $target,
            $search['dependants'],
            ['max_depth' => $maxDepth, 'limit' => $limit, 'timeout_ms' => $timeoutMs, 'visited_states' => $search['visited']],
            $truncation,
        );
    }

    /**
     * The answer when the symbol resolved to no component or to several.
     *
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>> $candidates
     */
    private static function unresolvedImpact(array $project, string $symbol, array $candidates): ResultEnvelope
    {
        return new ResultEnvelope(
            $project['id'],
            $project['active_scan_id'],
            $candidates === [] ? sprintf('No component matched "%s".', $symbol) : 'Impact analysis requires one unambiguous component.',
            ['query' => $symbol, 'candidates' => $candidates, 'dependants' => [], 'counts' => ['by_distance' => [], 'by_confidence' => ['certain' => 0, 'probable' => 0, 'possible' => 0]], 'entry_points' => []],
            [],
            [$candidates === [] ? self::UNMATCHED_ADVICE : self::AMBIGUOUS_ADVICE],
        );
    }

    /**
     * The breadth-first walk outward from the target along inbound edges,
     * each dependant recorded at its shortest distance with the strongest
     * confidence any path of that length gives it.
     *
     * @param array<string, mixed> $target
     * @param list<string> $edgeKinds
     * @return array{dependants: list<array<string, mixed>>, visited: int}
     */
    private function searchDependants(string $projectId, array $target, int $maxDepth, int $limit, array $edgeKinds, int $minimumRank, int $deadline, Truncation $truncation): array
    {
        // A head index instead of array_shift, which re-indexes the whole queue
        // on every pop; the visit order is the same first-in, first-out order.
        $queue = [[$target, 0]];
        $head = 0;
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
        $edgesTruncated = false;
        $visited = 0;
        while (isset($queue[$head])) {
            // No visit bound: only accepted dependants are queued, and at most
            // $limit (100) are accepted, so at most $limit + 1 states are visited.
            if ($this->now() > $deadline) {
                $truncation->add('time_limit');
                break;
            }
            [$current, $distance] = $queue[$head];
            unset($queue[$head]);
            ++$head;
            $pathConfidence = $bestRank[$current['id']];
            ++$visited;
            if ($distance >= $maxDepth) {
                continue;
            }
            foreach ($this->impactEdges($projectId, $current['id'], $edgeKinds, $minimumRank, $edgesTruncated) as $edge) {
                $edgeConfidence = self::CONFIDENCE_RANK[$edge['confidence']];
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
                    $truncation->add('result_limit');
                    break 2;
                }
                $recordIndex[$node['id']] = array_key_last($dependants);
                $queue[] = [$node, $distance + 1];
            }
        }
        if ($edgesTruncated) {
            // A hub with more than 500 inbound edges of the selected kinds had
            // some silently dropped; surface it instead of claiming completeness.
            $truncation->add('per_node_edge_limit');
        }

        return ['dependants' => $dependants, 'visited' => $visited];
    }

    /**
     * The impact_analysis envelope: the dependants with their roles and
     * boundaries, tallied by distance and confidence, and the entry points
     * among them.
     *
     * Only the first truncation reason is reported. The walk stops at the
     * deadline or at the result limit, whichever comes first, and a dropped
     * edge on some hub is the reason only when neither stopped it.
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $target
     * @param list<array<string, mixed>> $dependants
     * @param array{max_depth: int, limit: int, timeout_ms: int, visited_states: int} $bounds
     */
    private function impactEnvelope(array $project, array $target, array $dependants, array $bounds, Truncation $truncation): ResultEnvelope
    {
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
            $project['id'],
            $project['active_scan_id'],
            sprintf('Found %d potential static dependant%s within depth %d.', count($dependants), count($dependants) === 1 ? '' : 's', $bounds['max_depth']),
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
                'bounds' => $bounds + ['truncation_reason' => $truncation->reasons()[0] ?? null],
            ],
            $evidence,
            ['Impact is a conservative static blast radius; it does not guarantee that a dependant will break.'],
            $truncation->any(),
        );
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
}
