<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;
use Throwable;

final readonly class ArchitecturePolicyQueryService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private ?SemanticRanker $semanticRanker = null)
    {
        parent::__construct($pdo, $clock);
    }

    /** @param list<array<string, mixed>> $policies */
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
        $statement = $this->pdo->prepare(
            'SELECT e.*, f.relative_path, source.kind AS source_kind, source.canonical_name AS source_name, ' .
            'target.kind AS target_kind, target.canonical_name AS target_name FROM edges e ' .
            'JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            'LEFT JOIN files f ON f.id = e.file_id WHERE e.project_id = ? ' .
            sprintf('AND e.kind IN (%s) ', $placeholders) .
            "AND CASE e.confidence WHEN 'certain' THEN 3 WHEN 'probable' THEN 2 ELSE 1 END >= CAST(? AS INTEGER) " .
            'ORDER BY e.source_id, e.target_id, e.kind, e.id LIMIT ?',
        );
        $statement->execute([$projectId, ...$allKinds, $confidenceRank[$minConfidence], $maxEdges + 1]);
        $edges = $statement->fetchAll();
        $truncated = count($edges) > $maxEdges;
        $truncationReasons = $truncated ? ['edge_limit'] : [];
        $edges = array_slice($edges, 0, $maxEdges);
        $nodeIds = [];
        foreach ($edges as $edge) {
            $nodeIds[$edge['source_id']] = true;
            $nodeIds[$edge['target_id']] = true;
        }
        $boundaries = $this->boundaryNames(array_keys($nodeIds));

        $violations = [];
        $evidence = [];
        $violationCount = 0;
        $edgesExamined = 0;
        foreach ($edges as $edge) {
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
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Found %d declared architecture policy violation%s.', count($violations), count($violations) === 1 ? '' : 's'),
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

    public function suggestLocation(string $projectId, string $featureDescription, int $limit = 5, int $maxMembers = 20_000, int $maxEdges = 20_000, int $timeoutMs = 1000, string $rankingMode = 'deterministic'): ResultEnvelope
    {
        $project = $this->project($projectId);
        if (trim($featureDescription) === '' || strlen($featureDescription) > 2000) {
            throw new InvalidArgumentException('feature_description must contain between 1 and 2000 bytes.');
        }
        if ($limit < 1 || $limit > 20) {
            throw new InvalidArgumentException('limit must be between 1 and 20.');
        }
        if ($maxMembers < 1 || $maxMembers > 50_000) {
            throw new InvalidArgumentException('max_members must be between 1 and 50000.');
        }
        if ($maxEdges < 1 || $maxEdges > 100_000) {
            throw new InvalidArgumentException('max_edges must be between 1 and 100000.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 5000.');
        }
        if (!in_array($rankingMode, ['deterministic', 'semantic_if_available'], true)) {
            throw new InvalidArgumentException('ranking_mode must be deterministic or semantic_if_available.');
        }
        $tokens = $this->featureTokens($featureDescription);
        if ($tokens === []) {
            throw new InvalidArgumentException('feature_description must contain at least one meaningful letter or number token.');
        }
        $deadline = $this->now() + ($timeoutMs * 1_000_000);

        $boundaryStatement = $this->pdo->prepare(
            'SELECT id, name, source, matcher_json FROM boundaries WHERE project_id = :project ORDER BY source, name, id LIMIT 1001',
        );
        $boundaryStatement->execute(['project' => $projectId]);
        $boundaryRows = $boundaryStatement->fetchAll();
        $truncated = count($boundaryRows) > 1000;
        $truncationReasons = $truncated ? ['boundary_limit'] : [];
        $boundaryRows = array_slice($boundaryRows, 0, 1000);
        if ($boundaryRows === []) {
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                'No architecture boundaries are available for location ranking.',
                ['feature_description' => $featureDescription, 'tokens' => $tokens, 'ranking' => [
                    'requested_mode' => $rankingMode, 'applied_mode' => 'deterministic', 'provider' => null,
                    'fallback_reason' => $rankingMode === 'semantic_if_available' ? 'provider_unavailable' : null,
                ], 'candidates' => [], 'bounds' => [
                    'limit' => $limit, 'max_members' => $maxMembers, 'max_edges' => $maxEdges,
                    'timeout_ms' => $timeoutMs, 'truncation_reasons' => [],
                ]],
                [],
                ['Scan or configure boundaries before requesting a location suggestion.'],
            );
        }
        $boundaryIds = array_column($boundaryRows, 'id');
        $memberStatement = $this->pdo->prepare(
            'SELECT bm.boundary_id, n.id, n.kind, n.canonical_name, n.display_name, n.start_line, n.end_line, f.relative_path ' .
            'FROM boundary_memberships bm JOIN nodes n ON n.id = bm.node_id LEFT JOIN files f ON f.id = n.file_id ' .
            'WHERE bm.project_id = :project ORDER BY bm.boundary_id, n.canonical_name, n.id LIMIT :limit',
        );
        $memberStatement->bindValue(':project', $projectId);
        $memberStatement->bindValue(':limit', $maxMembers + 1, PDO::PARAM_INT);
        $memberStatement->execute();
        $memberRows = $memberStatement->fetchAll();
        if (count($memberRows) > $maxMembers) {
            $truncated = true;
            $truncationReasons[] = 'member_limit';
        }
        $memberRows = array_slice($memberRows, 0, $maxMembers);
        $membersByBoundary = [];
        $boundariesByNode = [];
        foreach ($memberRows as $member) {
            $membersByBoundary[$member['boundary_id']][] = $member;
            $boundariesByNode[$member['id']][] = $member['boundary_id'];
        }
        $roles = $this->roles(array_values(array_unique(array_column($memberRows, 'id'))));

        $edgeStatement = $this->pdo->prepare(
            'SELECT source_id, target_id FROM edges WHERE project_id = :project ORDER BY source_id, target_id, id LIMIT :limit',
        );
        $edgeStatement->bindValue(':project', $projectId);
        $edgeStatement->bindValue(':limit', $maxEdges + 1, PDO::PARAM_INT);
        $edgeStatement->execute();
        $edges = $edgeStatement->fetchAll();
        if (count($edges) > $maxEdges) {
            $truncated = true;
            $truncationReasons[] = 'edge_limit';
        }
        $edges = array_slice($edges, 0, $maxEdges);
        $cohesion = [];
        foreach ($boundaryIds as $boundaryId) {
            $cohesion[$boundaryId] = ['internal' => 0, 'incident' => 0];
        }
        foreach ($edges as $index => $edge) {
            if (($index % 256) === 0 && $this->now() > $deadline) {
                $truncated = true;
                $truncationReasons[] = 'time_limit';
                break;
            }
            $sourceBoundaries = $boundariesByNode[$edge['source_id']] ?? [];
            $targetBoundaries = $boundariesByNode[$edge['target_id']] ?? [];
            foreach (array_values(array_unique([...$sourceBoundaries, ...$targetBoundaries])) as $boundaryId) {
                ++$cohesion[$boundaryId]['incident'];
                if (in_array($boundaryId, $sourceBoundaries, true) && in_array($boundaryId, $targetBoundaries, true)) {
                    ++$cohesion[$boundaryId]['internal'];
                }
            }
        }

        $candidates = [];
        foreach ($boundaryRows as $boundary) {
            if ($this->now() > $deadline) {
                $truncated = true;
                $truncationReasons[] = 'time_limit';
                break;
            }
            $members = $membersByBoundary[$boundary['id']] ?? [];
            $matchedTokens = [];
            $related = [];
            $nameScore = $memberScore = $roleScore = 0;
            $boundaryText = strtolower($boundary['name']);
            foreach ($tokens as $token) {
                if (str_contains($boundaryText, $token)) {
                    $nameScore += 12;
                    $matchedTokens[$token] = true;
                }
            }
            foreach ($members as $member) {
                $memberText = strtolower($member['canonical_name'] . ' ' . $member['display_name']);
                $memberMatches = [];
                foreach ($tokens as $token) {
                    if (str_contains($memberText, $token)) {
                        $memberMatches[] = $token;
                        $matchedTokens[$token] = true;
                    }
                }
                $roleMatches = [];
                foreach ($roles[$member['id']] ?? [] as $role) {
                    foreach ($tokens as $token) {
                        if (str_contains(strtolower($role['role']), $token)) {
                            $roleMatches[] = $token;
                            $matchedTokens[$token] = true;
                        }
                    }
                }
                if ($memberMatches !== [] || $roleMatches !== []) {
                    $memberScore += 4 * count(array_unique($memberMatches));
                    $roleScore += 2 * count(array_unique($roleMatches));
                    $related[] = [
                        'id' => $member['id'], 'kind' => $member['kind'], 'canonical_name' => $member['canonical_name'],
                        'matched_tokens' => array_values(array_unique([...$memberMatches, ...$roleMatches])),
                    ];
                }
            }
            $incident = $cohesion[$boundary['id']]['incident'];
            $ratio = $incident === 0 ? 0.0 : $cohesion[$boundary['id']]['internal'] / $incident;
            $cohesionScore = round($ratio * 10, 3);
            $score = $nameScore + $memberScore + $roleScore + $cohesionScore;
            $candidates[] = [
                'boundary' => ['id' => $boundary['id'], 'name' => $boundary['name'], 'source' => $boundary['source'], 'matcher' => self::decode($boundary['matcher_json'])],
                'score' => $score,
                'confidence' => count($matchedTokens) >= 2 ? 'probable' : 'possible',
                'factors' => [
                    'boundary_name_relevance' => $nameScore, 'member_relevance' => $memberScore,
                    'role_relevance' => $roleScore, 'internal_dependency_cohesion' => $cohesionScore,
                    'internal_edges' => $cohesion[$boundary['id']]['internal'], 'incident_edges' => $incident,
                ],
                'matched_tokens' => array_keys($matchedTokens),
                'related_members' => array_slice($related, 0, 5),
                '_semantic_text' => substr($boundary['name'] . ' ' . implode(' ', array_map(
                    static fn(array $member): string => $member['canonical_name'] . ' ' . $member['display_name'],
                    array_slice($members, 0, 100),
                )), 0, 4000),
            ];
        }
        $ranking = [
            'requested_mode' => $rankingMode,
            'applied_mode' => 'deterministic',
            'provider' => null,
            'fallback_reason' => null,
        ];
        if ($rankingMode === 'semantic_if_available') {
            if ($this->semanticRanker === null) {
                $ranking['fallback_reason'] = 'provider_unavailable';
            } else {
                $ranking['provider'] = $this->semanticRanker->id();
                try {
                    $remainingMs = max(1, (int) (($deadline - $this->now()) / 1_000_000));
                    $semanticInput = array_map(static fn(array $candidate): array => [
                        'id' => $candidate['boundary']['id'], 'text' => $candidate['_semantic_text'],
                    ], $candidates);
                    $scores = $this->semanticRanker->rank($featureDescription, $semanticInput, $remainingMs);
                    if ($this->now() > $deadline) {
                        throw new InvalidArgumentException('Semantic ranker exceeded the query deadline.');
                    }
                    $expectedIds = array_column($semanticInput, 'id');
                    sort($expectedIds, SORT_STRING);
                    $actualIds = array_keys($scores);
                    sort($actualIds, SORT_STRING);
                    if ($actualIds !== $expectedIds) {
                        throw new InvalidArgumentException('Semantic ranker must score every candidate exactly once.');
                    }
                    foreach ($scores as $score) {
                        if (!is_int($score) && !is_float($score)) {
                            throw new InvalidArgumentException('Semantic scores must be numeric.');
                        }
                        if (!is_finite((float) $score) || $score < 0 || $score > 1) {
                            throw new InvalidArgumentException('Semantic scores must be finite values from 0 to 1.');
                        }
                    }
                    foreach ($candidates as &$candidate) {
                        $semanticScore = round((float) $scores[$candidate['boundary']['id']] * 20, 3);
                        $candidate['factors']['semantic_relevance'] = $semanticScore;
                        $candidate['score'] += $semanticScore;
                    }
                    unset($candidate);
                    $ranking['applied_mode'] = 'semantic';
                } catch (Throwable $error) {
                    $ranking['fallback_reason'] = 'provider_failed: ' . substr($error->getMessage(), 0, 200);
                }
            }
        }
        foreach ($candidates as &$candidate) {
            unset($candidate['_semantic_text']);
        }
        unset($candidate);
        usort($candidates, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: ($a['boundary']['source'] <=> $b['boundary']['source'])
            ?: ($a['boundary']['name'] <=> $b['boundary']['name'])
            ?: ($a['boundary']['id'] <=> $b['boundary']['id']));
        if (count($candidates) > $limit) {
            $truncated = true;
            $truncationReasons[] = 'result_limit';
        }
        $candidates = array_slice($candidates, 0, $limit);
        $evidence = [];
        foreach ($candidates as $candidateIndex => $candidate) {
            foreach ($candidate['related_members'] as $related) {
                $member = null;
                foreach ($membersByBoundary[$candidate['boundary']['id']] ?? [] as $candidateMember) {
                    if ($candidateMember['id'] === $related['id']) {
                        $member = $candidateMember;
                        break;
                    }
                }
                if ($member !== null && $member['relative_path'] !== null) {
                    $evidence[] = [
                        'candidate_index' => $candidateIndex, 'component_id' => $member['id'], 'path' => $member['relative_path'],
                        'start_line' => $member['start_line'], 'end_line' => $member['end_line'],
                    ];
                }
            }
        }
        $truncationReasons = array_values(array_unique($truncationReasons));
        $warnings = ['Suggestions rank existing indexed boundaries; they do not prove a uniquely correct design location.'];
        if ($ranking['fallback_reason'] !== null) {
            $warnings[] = 'Semantic ranking was not applied; deterministic fallback: ' . $ranking['fallback_reason'];
        }
        if ($candidates === [] || count($candidates[0]['matched_tokens']) < 2) {
            $warnings[] = 'The top candidate has weak lexical evidence; treat the ranking as exploratory.';
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Ranked %d existing architecture location candidate%s.', count($candidates), count($candidates) === 1 ? '' : 's'),
            [
                'feature_description' => $featureDescription, 'tokens' => $tokens, 'ranking' => $ranking, 'candidates' => $candidates,
                'bounds' => [
                    'limit' => $limit, 'max_members' => $maxMembers, 'max_edges' => $maxEdges,
                    'timeout_ms' => $timeoutMs, 'members_examined' => count($memberRows),
                    'edges_examined' => count($edges), 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            $warnings,
            $truncated,
        );
    }

    /** @param array<string, mixed> $policy @return list<string> */
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
    /** @return list<string> */
    private function featureTokens(string $description): array
    {
        $parts = preg_split('/[^\pL\pN]+/u', strtolower($description), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }
        $stopWords = ['a', 'an', 'and', 'for', 'in', 'of', 'on', 'the', 'to', 'with', 'add', 'build', 'create', 'new', 'feature'];
        $tokens = [];
        foreach ($parts as $part) {
            if (strlen($part) < 2 || in_array($part, $stopWords, true)) {
                continue;
            }
            $tokens[$part] = true;
        }
        return array_keys($tokens);
    }
}
