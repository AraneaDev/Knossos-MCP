<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * Every dead-code candidate in a project, found with the graph's own queries.
 *
 * The hub ranking reads a bounded window of nodes; a candidate drawn from it
 * was reported only when its name sorted inside the window. This finds the
 * nodes nothing references, and the nodes only tests reference, over the whole
 * project, then classifies them in chunks, checking a deadline between stages
 * so a very large project is cut short visibly, never silently.
 */
final readonly class DeadCodeCandidates extends AbstractArchitectureQueryService
{
    private const CHUNK = 500;

    /**
     * The classified candidates, their node rows for evidence, the exclusion
     * tallies, the convention-excluded count, and whether the deadline cut the
     * search short.
     *
     * @param list<string> $edgeKinds
     * @return array{candidates: list<array<string, mixed>>, rows: array<string, array<string, mixed>>, excluded: array<string, int>, convention_excluded: int, truncated: bool}
     */
    public function find(string $projectId, array $edgeKinds, int $minConfidenceRank, bool $includeTests, int $deadline): array
    {
        $found = ['candidates' => [], 'rows' => [], 'excluded' => [], 'convention_excluded' => 0, 'truncated' => false];
        if ($this->now() > $deadline) {
            return ['truncated' => true] + $found;
        }
        $reachability = [];
        foreach ($this->unreferencedRows($projectId, $edgeKinds, $minConfidenceRank) as $row) {
            $reachability[(string) $row['id']] = [$row, 'unreferenced'];
        }
        if (!$includeTests) {
            if ($this->now() > $deadline) {
                return ['truncated' => true] + $found;
            }
            foreach ($this->testOnlyRows($projectId, $edgeKinds, $minConfidenceRank) as $row) {
                $reachability[(string) $row['id']] = [$row, 'test_only'];
            }
        }
        $facts = new CandidateGraphFacts($this->pdo, $projectId, $edgeKinds, $minConfidenceRank);
        $analysis = new DeadCodeAnalysis($this->pdo, $this->clock);
        foreach (array_chunk($reachability, self::CHUNK, true) as $chunk) {
            if ($this->now() > $deadline) {
                $found['truncated'] = true;
                break;
            }
            $this->classifyChunk($projectId, $chunk, $facts, $analysis, $edgeKinds, $minConfidenceRank, $includeTests, $found);
        }

        return $found;
    }

    /**
     * One chunk: roles and boundaries loaded, the convention-discovered set
     * counted, the rest classified and added to what was found so far.
     * `$found` is taken by reference: returning it copied every candidate
     * found so far once per chunk.
     *
     * @param array<string, array{0: array<string, mixed>, 1: string}> $chunk
     * @param list<string> $edgeKinds
     * @param array{candidates: list<array<string, mixed>>, rows: array<string, array<string, mixed>>, excluded: array<string, int>, convention_excluded: int, truncated: bool} $found
     */
    private function classifyChunk(string $projectId, array $chunk, CandidateGraphFacts $facts, DeadCodeAnalysis $analysis, array $edgeKinds, int $minConfidenceRank, bool $includeTests, array &$found): void
    {
        $ids = array_map('strval', array_keys($chunk));
        $roles = $this->roles($ids);
        $boundaries = $this->boundaryNames($ids);
        $facts->degrees($ids);
        $provisional = [];
        foreach ($chunk as $id => [$row, $reachability]) {
            $nodeRoles = $roles[$id] ?? [];
            if ($analysis->isConventionExcluded($row, $nodeRoles)) {
                ++$found['convention_excluded'];
                continue;
            }
            if (!$analysis->isCandidate($row, $nodeRoles)) {
                continue;
            }
            $provisional[$id] = [
                'component' => [
                    'id' => $id, 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
                    'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
                    'roles' => $nodeRoles, 'boundaries' => $boundaries[$id] ?? [],
                ],
                'row' => $row, 'roles' => $nodeRoles, 'out_degree' => $facts->outDegree((string) $id), 'reachability' => $reachability,
            ];
        }
        $classified = $analysis->classify($projectId, $provisional, $facts, $edgeKinds, $minConfidenceRank, $includeTests);
        foreach ($classified['candidates'] as $candidate) {
            $found['candidates'][] = $candidate;
            $found['rows'][(string) $candidate['component']['id']] = $provisional[$candidate['component']['id']]['row'];
        }
        foreach ($classified['excluded'] as $reason => $count) {
            $found['excluded'][$reason] = ($found['excluded'][$reason] ?? 0) + $count;
        }
    }

    /**
     * Nodes of a reportable kind with no inbound edge of the selected kinds at
     * or above the floor, anywhere in the project.
     *
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function unreferencedRows(string $projectId, array $edgeKinds, int $minConfidenceRank): array
    {
        return $this->candidateRows($projectId, $edgeKinds, $minConfidenceRank, 'NOT EXISTS (' . $this->inbound($edgeKinds) . ')');
    }

    /**
     * Nodes whose inbound edges of the selected kinds all come from test code:
     * reached, but only by something the product does not run.
     *
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function testOnlyRows(string $projectId, array $edgeKinds, int $minConfidenceRank): array
    {
        $test = $this->pdo->quote(ReportableComponent::TEST_ROLE);
        $production = $this->inbound($edgeKinds)
            . " AND NOT EXISTS (SELECT 1 FROM classifications c WHERE c.node_id = e.source_id AND c.role = {$test})";

        return $this->candidateRows(
            $projectId,
            $edgeKinds,
            $minConfidenceRank,
            'EXISTS (' . $this->inbound($edgeKinds) . ') AND NOT EXISTS (' . $production . ')',
            2,
        );
    }

    /**
     * The node rows of reportable kinds matching `$condition`, which binds the
     * edge kinds and the confidence floor `$inboundUses` times.
     *
     * @param list<string> $edgeKinds
     * @return list<array<string, mixed>>
     */
    private function candidateRows(string $projectId, array $edgeKinds, int $minConfidenceRank, string $condition, int $inboundUses = 1): array
    {
        $kinds = implode(',', array_fill(0, count(DeadCodeAnalysis::CANDIDATE_KINDS), '?'));
        $statement = $this->pdo->prepare(
            'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.origin, n.confidence, n.attributes_json, '
            . 'n.start_line, n.end_line, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id '
            . sprintf('WHERE n.project_id = ? AND n.kind IN (%s) AND ', $kinds) . $condition
            . ' ORDER BY n.canonical_name, n.id',
        );
        $inbound = [];
        for ($use = 0; $use < $inboundUses; ++$use) {
            $inbound = [...$inbound, ...$edgeKinds, $minConfidenceRank];
        }
        $statement->execute([$projectId, ...DeadCodeAnalysis::CANDIDATE_KINDS, ...$inbound]);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * An inbound edge of the selected kinds at or above the floor, as a
     * correlated subquery on `n`.
     *
     * @param list<string> $edgeKinds
     */
    private function inbound(array $edgeKinds): string
    {
        return 'SELECT 1 FROM edges e WHERE e.project_id = n.project_id AND e.target_id = n.id '
            . sprintf('AND e.kind IN (%s) ', implode(',', array_fill(0, count($edgeKinds), '?')))
            . "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER)";
    }
}
