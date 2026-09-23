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
     * One page of the classified candidates in report order, how many there
     * are in all and how many of those only tests reach, the exclusion
     * tallies, the convention-excluded count, and whether the deadline cut the
     * search short.
     *
     * Report order is reachability class, `unreferenced` first, then canonical
     * name and id. `$confidence` `probable` leaves out the `possible` ones
     * before anything is counted or paged.
     *
     * Memory follows the page, not the project: the searches return ids only,
     * each chunk loads its own rows, and of each class only the first
     * `$offset + $limit` candidates are kept. Holding every candidate at once
     * exhausted PHP's default memory on a project of 50,000 of them.
     *
     * @param list<string> $edgeKinds
     * @return array{candidates: list<array<string, mixed>>, total: int, test_only: int, excluded: array<string, int>, convention_excluded: int, truncated: bool}
     */
    public function find(string $projectId, array $edgeKinds, int $minConfidenceRank, bool $includeTests, int $deadline, string $confidence, int $offset, int $limit): array
    {
        $found = [
            'kept' => ['unreferenced' => [], 'test_only' => []], 'keep' => $offset + $limit, 'confidence' => $confidence,
            'total' => 0, 'test_only' => 0, 'excluded' => [], 'convention_excluded' => 0, 'truncated' => false,
        ];
        $this->search($projectId, $edgeKinds, $minConfidenceRank, $includeTests, $deadline, $found);
        $page = [];
        foreach ($found['kept'] as $kept) {
            $page = [...$page, ...self::ordered($kept)];
        }

        return [
            'candidates' => $this->hydrate(array_slice($page, $offset, $limit)),
            'total' => $found['total'], 'test_only' => $found['test_only'], 'excluded' => $found['excluded'],
            'convention_excluded' => $found['convention_excluded'], 'truncated' => $found['truncated'],
        ];
    }

    /**
     * Both searches and the classification of what they found, into `$found`,
     * until the deadline passes.
     *
     * @param list<string> $edgeKinds
     * @param array<string, mixed> $found
     */
    private function search(string $projectId, array $edgeKinds, int $minConfidenceRank, bool $includeTests, int $deadline, array &$found): void
    {
        if ($this->now() > $deadline) {
            $found['truncated'] = true;

            return;
        }
        $reachability = [];
        foreach ($this->unreferencedIds($projectId, $edgeKinds, $minConfidenceRank) as $id) {
            $reachability[$id] = 'unreferenced';
        }
        if (!$includeTests) {
            if ($this->now() > $deadline) {
                $found['truncated'] = true;

                return;
            }
            foreach ($this->testOnlyIds($projectId, $edgeKinds, $minConfidenceRank) as $id) {
                $reachability[$id] = 'test_only';
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
    }

    /**
     * Candidates in canonical-name order, then id, compared byte by byte as
     * the searches' SQL orders them.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private static function ordered(array $candidates): array
    {
        usort($candidates, static fn(array $a, array $b): int => strcmp((string) $a['component']['canonical_name'], (string) $b['component']['canonical_name'])
            ?: strcmp((string) $a['component']['id'], (string) $b['component']['id']));

        return $candidates;
    }

    /**
     * One chunk: roles and boundaries loaded, the convention-discovered set
     * counted, the rest classified, counted, and kept when they can still
     * fall on the page. `$found` is taken by reference: returning it copied
     * everything kept so far once per chunk.
     *
     * @param array<string, string> $chunk id => reachability
     * @param list<string> $edgeKinds
     * @param array<string, mixed> $found
     */
    private function classifyChunk(string $projectId, array $chunk, CandidateGraphFacts $facts, DeadCodeAnalysis $analysis, array $edgeKinds, int $minConfidenceRank, bool $includeTests, array &$found): void
    {
        $ids = array_map('strval', array_keys($chunk));
        $rows = $this->rows($ids);
        $roles = $this->roles($ids);
        $boundaries = $this->boundaryNames($ids);
        $facts->degrees($ids);
        $provisional = [];
        foreach ($chunk as $id => $reachability) {
            $row = $rows[$id] ?? null;
            if ($row === null) {
                continue;
            }
            $nodeRoles = $roles[$id] ?? [];
            if ($analysis->isConventionExcluded($row, $nodeRoles)) {
                ++$found['convention_excluded'];
                continue;
            }
            if (!$analysis->isCandidate($row, $nodeRoles)) {
                continue;
            }
            $provisional[$id] = [
                'component' => self::component((string) $id, $row, $nodeRoles, $boundaries[$id] ?? []),
                'row' => $row, 'roles' => $nodeRoles, 'out_degree' => $facts->outDegree((string) $id), 'reachability' => $reachability,
            ];
        }
        $classified = $analysis->classify($projectId, $provisional, $facts, $edgeKinds, $minConfidenceRank, $includeTests);
        foreach ($classified['candidates'] as $candidate) {
            // A large project's page of 100 filled with framework methods
            // marked only possible, hiding every probable candidate behind
            // them; the filter and the offset let a caller see past that.
            if ($found['confidence'] === 'probable' && $candidate['confidence'] !== 'probable') {
                continue;
            }
            ++$found['total'];
            // Ordered by reachability class before name, so `limit` slices
            // along a meaningful line rather than an alphabetical accident:
            // test-only candidates that happen to sort first would otherwise
            // fill the page and hide every component nothing references at
            // all. `unreferenced` leads because it is the stronger claim.
            $class = $candidate['reachability'] === 'test_only' ? 'test_only' : 'unreferenced';
            if ($class === 'test_only') {
                ++$found['test_only'];
            }
            // Compact until the page is known: ordering reads only the id and
            // name, and hydrate() rebuilds the rest for the page.
            $candidate['component'] = ['id' => $candidate['component']['id'], 'canonical_name' => $candidate['component']['canonical_name']];
            $found['kept'][$class][] = $candidate;
            // Containers whose members only tests use turn test-only here, so
            // a class does not arrive in order; sorting and trimming at twice
            // the bound keeps its first candidates at amortized cost.
            if (count($found['kept'][$class]) > 2 * $found['keep']) {
                $found['kept'][$class] = array_slice(self::ordered($found['kept'][$class]), 0, $found['keep']);
            }
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
     * @return list<string>
     */
    private function unreferencedIds(string $projectId, array $edgeKinds, int $minConfidenceRank): array
    {
        return $this->candidateIds($projectId, $edgeKinds, $minConfidenceRank, 'NOT EXISTS (' . $this->inbound($edgeKinds) . ')');
    }

    /**
     * Nodes whose inbound edges of the selected kinds all come from test code:
     * reached, but only by something the product does not run.
     *
     * @param list<string> $edgeKinds
     * @return list<string>
     */
    private function testOnlyIds(string $projectId, array $edgeKinds, int $minConfidenceRank): array
    {
        $test = $this->pdo->quote(ReportableComponent::TEST_ROLE);
        $production = $this->inbound($edgeKinds)
            . " AND NOT EXISTS (SELECT 1 FROM classifications c WHERE c.node_id = e.source_id AND c.role = {$test})";

        return $this->candidateIds(
            $projectId,
            $edgeKinds,
            $minConfidenceRank,
            'EXISTS (' . $this->inbound($edgeKinds) . ') AND NOT EXISTS (' . $production . ')',
            2,
        );
    }

    /**
     * The reported page's candidates with their full components (kind, names,
     * origin, confidence, roles, boundaries), which classification leaves out.
     *
     * @param list<array<string, mixed>> $candidates
     * @return list<array<string, mixed>>
     */
    private function hydrate(array $candidates): array
    {
        $ids = array_map(static fn(array $candidate): string => (string) $candidate['component']['id'], $candidates);
        $rows = $this->rows($ids);
        $roles = $this->roles($ids);
        $boundaries = $this->boundaryNames($ids);
        foreach ($candidates as $index => $candidate) {
            $id = (string) $candidate['component']['id'];
            if (isset($rows[$id])) {
                $candidates[$index]['component'] = self::component($id, $rows[$id], $roles[$id] ?? [], $boundaries[$id] ?? []);
            }
        }

        return $candidates;
    }

    /**
     * A candidate's component as architecture_health reports it.
     *
     * @param array<string, mixed> $row
     * @param list<array<string, mixed>> $roles
     * @param list<array<string, mixed>> $boundaries
     * @return array<string, mixed>
     */
    private static function component(string $id, array $row, array $roles, array $boundaries): array
    {
        return [
            'id' => $id, 'kind' => $row['kind'], 'canonical_name' => $row['canonical_name'],
            'display_name' => $row['display_name'], 'origin' => $row['origin'], 'confidence' => $row['confidence'],
            'roles' => $roles, 'boundaries' => $boundaries,
        ];
    }

    /**
     * The ids of nodes of reportable kinds matching `$condition`, which binds
     * the edge kinds and the confidence floor `$inboundUses` times, in
     * canonical-name order.
     *
     * @param list<string> $edgeKinds
     * @return list<string>
     */
    private function candidateIds(string $projectId, array $edgeKinds, int $minConfidenceRank, string $condition, int $inboundUses = 1): array
    {
        $kinds = implode(',', array_fill(0, count(DeadCodeAnalysis::CANDIDATE_KINDS), '?'));
        $statement = $this->pdo->prepare(
            'SELECT n.id FROM nodes n '
            . sprintf('WHERE n.project_id = ? AND n.kind IN (%s) AND ', $kinds) . $condition
            . ' ORDER BY n.canonical_name, n.id',
        );
        $inbound = [];
        for ($use = 0; $use < $inboundUses; ++$use) {
            $inbound = [...$inbound, ...$edgeKinds, $minConfidenceRank];
        }
        $statement->execute([$projectId, ...DeadCodeAnalysis::CANDIDATE_KINDS, ...$inbound]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * The node rows of `$ids`, keyed by id, with the columns classification
     * and evidence read. Loaded per chunk, and for the reported page.
     *
     * @param list<string> $ids
     * @return array<string, array<string, mixed>>
     */
    public function rows(array $ids): array
    {
        $rows = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::CHUNK) as $chunk) {
            $statement = $this->pdo->prepare(
                'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.origin, n.confidence, n.attributes_json, '
                . 'n.start_line, n.end_line, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id '
                . sprintf('WHERE n.id IN (%s)', implode(',', array_fill(0, count($chunk), '?'))),
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $rows[(string) $row['id']] = $row;
            }
        }

        return $rows;
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
