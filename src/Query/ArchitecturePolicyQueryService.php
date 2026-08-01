<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;

/**
 * Evaluates declared architecture policies and budgets against the graph.
 *
 * Answers whether a relationship crosses a boundary it should not, and whether a
 * change breaches a budget relative to a baseline snapshot. Violations carry the
 * offending edge and its evidence, because a policy failure a developer cannot
 * locate is a failure they will disable.
 */
final readonly class ArchitecturePolicyQueryService extends AbstractArchitectureQueryService
{
    /**
     * Evaluate declared policies, returning each violation with the edge that breaches it.
     *
     * @param list<array<string, mixed>> $policies
     */
    public function checkArchitecture(string $projectId, array $policies, string $minConfidence = 'possible', int $limit = 100, int $maxEdges = 20_000, int $timeoutMs = 1000): ResultEnvelope
    {
        $project = $this->project($projectId);
        self::assertLimit($limit);
        $confidenceRank = $this->confidenceQueryBounds($maxEdges, $timeoutMs, $minConfidence);
        if (!array_is_list($policies) || $policies === [] || count($policies) > 50) {
            throw new InvalidArgumentException('policies must contain between 1 and 50 declarations.');
        }

        $boundaryRows = $this->pdo->prepare('SELECT id, name, source FROM boundaries WHERE project_id = :project ORDER BY source, name, id');
        $boundaryRows->execute(['project' => $projectId]);
        $availableBoundaries = $boundaryRows->fetchAll();
        $compiled = [];
        $policyIds = [];
        $allKinds = [];
        foreach ($policies as $policy) {
            if (!is_array($policy)) {
                throw new InvalidArgumentException('Each policy must be an object.');
            }
            $unknown = array_diff(array_keys($policy), ['id', 'from_boundary', 'allow_targets', 'deny_targets', 'edge_kinds']);
            if ($unknown !== []) {
                throw new InvalidArgumentException('Policy contains unknown fields: ' . implode(', ', $unknown));
            }
            $id = $policy['id'] ?? null;
            $from = $policy['from_boundary'] ?? null;
            if (!is_string($id) || trim($id) === '' || strlen($id) > 100) {
                throw new InvalidArgumentException('Policy id must be a non-empty string of at most 100 bytes.');
            }
            if (isset($policyIds[$id])) {
                throw new InvalidArgumentException('Policy ids must be unique: ' . $id);
            }
            $policyIds[$id] = true;
            if (!is_string($from) || trim($from) === '') {
                throw new InvalidArgumentException('Policy from_boundary must be a non-empty boundary ID or name.');
            }
            $allow = $this->policyList($policy, 'allow_targets');
            $deny = $this->policyList($policy, 'deny_targets');
            if ($allow === [] && $deny === []) {
                throw new InvalidArgumentException('Policy must declare allow_targets or deny_targets.');
            }
            $kinds = $this->policyList($policy, 'edge_kinds');
            $kinds = $kinds === [] ? self::IMPACT_EDGE_KINDS : array_values(array_unique($kinds));
            if (array_diff($kinds, self::IMPACT_EDGE_KINDS) !== []) {
                throw new InvalidArgumentException('Policy edge_kinds contains an unsupported dependency relationship.');
            }
            $compiledAllow = array_map(fn(string $value): string => $value === '@unassigned' ? $value : $this->resolvePolicyBoundary($value, $availableBoundaries), $allow);
            $compiledDeny = array_map(fn(string $value): string => $value === '@unassigned' ? $value : $this->resolvePolicyBoundary($value, $availableBoundaries), $deny);
            $compiled[] = [
                'id' => $id,
                'from_id' => $this->resolvePolicyBoundary($from, $availableBoundaries),
                'allow' => array_values(array_unique($compiledAllow)),
                'deny' => array_values(array_unique($compiledDeny)),
                'edge_kinds' => $kinds,
            ];
            $allKinds = [...$allKinds, ...$kinds];
        }
        $allKinds = array_values(array_unique($allKinds));
        sort($allKinds, SORT_STRING);

        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $placeholders = implode(',', array_fill(0, count($allKinds), '?'));
        // Only the columns the evaluation and the violation record read. `e.*`
        // dragged attributes_json along for every edge, which is most of the
        // width of a row and is never looked at here.
        $statement = $this->pdo->prepare(
            'SELECT e.id, e.kind, e.source_id, e.target_id, e.confidence, e.origin, e.start_line, e.end_line, ' .
            'f.relative_path, source.kind AS source_kind, source.canonical_name AS source_name, ' .
            'target.kind AS target_kind, target.canonical_name AS target_name FROM edges e ' .
            'JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $statement->execute([$projectId, ...$allKinds, $confidenceRank[$minConfidence], $maxEdges + 1]);
        // Streamed rather than collected: a gate asks for the largest bound the
        // checker accepts, and holding that many joined rows exhausted a 128 MB
        // limit. Memory is now the boundary map plus the violations kept, both
        // bounded by the graph's shape and the result limit rather than by the
        // edge count.
        $boundaries = $this->projectBoundaryNames($projectId);

        $truncated = false;
        $truncationReasons = [];
        $violations = [];
        $evidence = [];
        $violationCount = 0;
        $edgesExamined = 0;
        while (($edge = $statement->fetch()) !== false) {
            if ($edgesExamined >= $maxEdges) {
                $truncated = true;
                $truncationReasons[] = 'edge_limit';
                break;
            }
            ++$edgesExamined;
            if ($this->now() > $deadline) {
                $truncated = true;
                $truncationReasons[] = 'time_limit';
                break;
            }
            $sourceBoundaryIds = array_column($boundaries[$edge['source_id']] ?? [], 'id');
            $targetBoundaryIds = array_column($boundaries[$edge['target_id']] ?? [], 'id');
            $effectiveTargets = $targetBoundaryIds === [] ? ['@unassigned'] : $targetBoundaryIds;
            foreach ($compiled as $policy) {
                if (!in_array($policy['from_id'], $sourceBoundaryIds, true) || !in_array($edge['kind'], $policy['edge_kinds'], true)) {
                    continue;
                }
                $reasons = [];
                $denied = array_values(array_intersect($effectiveTargets, $policy['deny']));
                if ($denied !== []) {
                    $reasons[] = ['type' => 'denied_target', 'targets' => $denied];
                }
                $internal = in_array($policy['from_id'], $effectiveTargets, true);
                if ($policy['allow'] !== [] && !$internal && array_intersect($effectiveTargets, $policy['allow']) === []) {
                    $reasons[] = ['type' => 'target_not_allowed', 'targets' => $effectiveTargets];
                }
                if ($reasons === []) {
                    continue;
                }
                // Keep an exact count past the collection limit so callers (e.g.
                // quality_gate) can compare a budget against the true violation
                // total rather than the capped, collected subset.
                ++$violationCount;
                if (count($violations) < $limit) {
                    $violations[] = [
                        'policy_id' => $policy['id'],
                        'relationship' => [
                            'id' => $edge['id'], 'kind' => $edge['kind'], 'confidence' => $edge['confidence'],
                            'origin' => $edge['origin'], 'source_id' => $edge['source_id'], 'target_id' => $edge['target_id'],
                        ],
                        'source' => ['id' => $edge['source_id'], 'kind' => $edge['source_kind'], 'canonical_name' => $edge['source_name']],
                        'target' => ['id' => $edge['target_id'], 'kind' => $edge['target_kind'], 'canonical_name' => $edge['target_name']],
                        'source_boundaries' => $boundaries[$edge['source_id']] ?? [],
                        'target_boundaries' => $boundaries[$edge['target_id']] ?? [],
                        'reasons' => $reasons,
                    ];
                    if ($edge['relative_path'] !== null && count($evidence) < $limit) {
                        $evidence[] = [
                            'policy_id' => $policy['id'], 'edge_id' => $edge['id'], 'path' => $edge['relative_path'],
                            'start_line' => $edge['start_line'], 'end_line' => $edge['end_line'],
                        ];
                    }
                } elseif (!in_array('result_limit', $truncationReasons, true)) {
                    $truncated = true;
                    $truncationReasons[] = 'result_limit';
                }
            }
        }
        $truncationReasons = array_values(array_unique($truncationReasons));
        // Report the total found, not the page returned. Announcing "Found 4
        // declared architecture policy violations" over a listing capped at 4,
        // while bounds.violation_count said 322, understated the finding by two
        // orders of magnitude to anyone reading only the summary.
        $summary = sprintf('Found %d declared architecture policy violation%s.', $violationCount, $violationCount === 1 ? '' : 's');
        if ($violationCount > count($violations)) {
            $summary .= sprintf(' Listing the first %d; raise limit to see more.', count($violations));
        }
        if ($truncationReasons !== [] && !in_array('result_limit', $truncationReasons, true)) {
            $summary .= sprintf(' The search was truncated (%s), so violations beyond that bound are not counted.', implode(', ', $truncationReasons));
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $summary,
            [
                'violations' => $violations,
                'policies_evaluated' => array_map(static fn(array $policy): array => [
                    'id' => $policy['id'], 'from_boundary_id' => $policy['from_id'], 'allow_target_ids' => $policy['allow'],
                    'deny_target_ids' => $policy['deny'], 'edge_kinds' => $policy['edge_kinds'],
                ], $compiled),
                'bounds' => [
                    'limit' => $limit, 'max_edges' => $maxEdges, 'timeout_ms' => $timeoutMs,
                    'edges_examined' => $edgesExamined, 'violation_count' => $violationCount,
                    'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            ['Policy violations are static graph findings; runtime behavior and dynamic dependencies may differ.'],
            $truncated,
        );
    }

    /**
     * Validate and normalise the policy definitions.
     *
     * @param array<string, mixed> $policy @return list<string>
     */
    private function policyList(array $policy, string $key): array
    {
        if (!array_key_exists($key, $policy)) {
            return [];
        }
        $values = $policy[$key];
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            throw new InvalidArgumentException(sprintf('Policy %s must be a list of at most 50 values.', $key));
        }
        foreach ($values as $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > 200) {
                throw new InvalidArgumentException(sprintf('Policy %s values must be non-empty strings of at most 200 bytes.', $key));
            }
        }
        return array_values(array_unique($values));
    }
}
