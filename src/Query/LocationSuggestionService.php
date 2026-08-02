<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Ranks where a described feature would fit the structure a project already has.
 *
 * The ranking is lexical and structural: how well the boundary's own name and
 * its members match the description's tokens, plus how self-contained the
 * boundary is. An optional semantic provider re-ranks on top, and any failure
 * there falls back to the deterministic score rather than to no answer — a
 * suggestion that silently depended on an external service would be worse than
 * one that says it ranked without it.
 *
 * Split out of ArchitecturePolicyQueryService, where it was a single 275-line
 * method sitting exactly on the repository's own function-length budget, next to
 * boundary-policy checking, which it shares nothing with beyond the word
 * "boundary".
 */
final readonly class LocationSuggestionService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private ?SemanticRanker $semanticRanker = null)
    {
        parent::__construct($pdo, $clock);
    }

    /** Rank where a described feature would fit the existing structure. */
    public function suggestLocation(string $projectId, string $featureDescription, int $limit = 5, int $maxMembers = 20_000, int $maxEdges = 100_000, int $timeoutMs = 1000, string $rankingMode = 'deterministic'): ResultEnvelope
    {
        $project = $this->project($projectId);
        $tokens = $this->assertArguments($featureDescription, $limit, $maxMembers, $maxEdges, $timeoutMs, $rankingMode);
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $truncated = false;
        $truncationReasons = [];

        $boundaryRows = $this->boundaryRows($projectId, $truncated, $truncationReasons);
        if ($boundaryRows === []) {
            return $this->noBoundariesResult($projectId, $project, $featureDescription, $tokens, $rankingMode, $limit, $maxMembers, $maxEdges, $timeoutMs);
        }
        [$memberRows, $membersByBoundary, $boundariesByNode, $roles] = $this->members($projectId, $maxMembers, $truncated, $truncationReasons);
        [$edges, $cohesion] = $this->cohesion($projectId, array_column($boundaryRows, 'id'), $boundariesByNode, $maxEdges, $deadline, $truncated, $truncationReasons);
        $candidates = $this->rank($boundaryRows, $membersByBoundary, $roles, $tokens, $cohesion, $deadline, $truncated, $truncationReasons);
        $ranking = $this->applySemanticRanking($candidates, $featureDescription, $rankingMode, $deadline);

        return $this->result(
            $projectId,
            $project,
            $featureDescription,
            $tokens,
            $ranking,
            $candidates,
            $membersByBoundary,
            ['limit' => $limit, 'max_members' => $maxMembers, 'max_edges' => $maxEdges, 'timeout_ms' => $timeoutMs],
            count($memberRows),
            count($edges),
            $truncated,
            $truncationReasons,
        );
    }

    /**
     * Reject an unusable request, and return the description's ranking tokens.
     *
     * @return list<string>
     */
    private function assertArguments(string $featureDescription, int $limit, int $maxMembers, int $maxEdges, int $timeoutMs, string $rankingMode): array
    {
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

        return $tokens;
    }

    /**
     * The project's boundaries, which are what a suggestion ranks between.
     *
     * @param list<string> $truncationReasons
     *
     * @return list<array<string, mixed>>
     */
    private function boundaryRows(string $projectId, bool &$truncated, array &$truncationReasons): array
    {
        $boundaryStatement = $this->pdo->prepare(
            'SELECT id, name, source, matcher_json FROM boundaries WHERE project_id = :project ORDER BY source, name, id LIMIT 1001',
        );
        $boundaryStatement->execute(['project' => $projectId]);
        $boundaryRows = $boundaryStatement->fetchAll();
        $truncated = count($boundaryRows) > 1000;
        $truncationReasons = $truncated ? ['boundary_limit'] : [];
        $boundaryRows = array_slice($boundaryRows, 0, 1000);

        return $boundaryRows;
    }

    /**
     * Boundary members, indexed both ways, with the roles ranking scores against.
     *
     * @param list<string> $truncationReasons
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, list<array<string, mixed>>>, 2: array<string, list<string>>, 3: array<string, list<array<string, mixed>>>}
     */
    private function members(string $projectId, int $maxMembers, bool &$truncated, array &$truncationReasons): array
    {
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

        return [$memberRows, $membersByBoundary, $boundariesByNode, $roles];
    }

    /**
     * How self-contained each boundary is: edges wholly inside it against edges touching it.
     *
     * @param list<string> $boundaryIds
     * @param array<string, list<string>> $boundariesByNode
     * @param list<string> $truncationReasons
     *
     * @return array{0: list<array<string, mixed>>, 1: array<string, array{internal: int, incident: int}>}
     */
    private function cohesion(string $projectId, array $boundaryIds, array $boundariesByNode, int $maxEdges, int $deadline, bool &$truncated, array &$truncationReasons): array
    {
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
                if (!isset($cohesion[$boundaryId])) {
                    // A membership can name a boundary beyond the boundary cap,
                    // which is not a candidate and has no counters to move.
                    continue;
                }
                ++$cohesion[$boundaryId]['incident'];
                if (in_array($boundaryId, $sourceBoundaries, true) && in_array($boundaryId, $targetBoundaries, true)) {
                    ++$cohesion[$boundaryId]['internal'];
                }
            }
        }

        return [$edges, $cohesion];
    }

    /**
     * Score every boundary against the description's tokens.
     *
     * @param list<array<string, mixed>> $boundaryRows
     * @param array<string, list<array<string, mixed>>> $membersByBoundary
     * @param array<string, list<array<string, mixed>>> $roles
     * @param list<string> $tokens
     * @param array<string, array{internal: int, incident: int}> $cohesion
     * @param list<string> $truncationReasons
     *
     * @return list<array<string, mixed>>
     */
    private function rank(array $boundaryRows, array $membersByBoundary, array $roles, array $tokens, array $cohesion, int $deadline, bool &$truncated, array &$truncationReasons): array
    {
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
            $boundaryWords = self::identifierWords($boundary['name']);
            foreach ($tokens as $token) {
                if (self::matchesWord($boundaryWords, $token)) {
                    $nameScore += 12;
                    $matchedTokens[$token] = true;
                }
            }
            foreach ($members as $member) {
                $memberWords = self::identifierWords($member['canonical_name'] . ' ' . $member['display_name']);
                $memberMatches = [];
                foreach ($tokens as $token) {
                    if (self::matchesWord($memberWords, $token)) {
                        $memberMatches[] = $token;
                        $matchedTokens[$token] = true;
                    }
                }
                $roleMatches = [];
                foreach ($roles[$member['id']] ?? [] as $role) {
                    $roleWords = self::identifierWords($role['role']);
                    foreach ($tokens as $token) {
                        if (self::matchesWord($roleWords, $token)) {
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
            // Density, not total: a summed member score grows with boundary
            // size, so the widest boundary won every ranking however diluted
            // its match was. What a location suggestion is asking is which
            // boundary is *most about* the description, not which contains the
            // most matching members.
            $memberCount = max(1, count($members));
            $memberRelevance = round(($memberScore / $memberCount) * 10, 3);
            $roleRelevance = round(($roleScore / $memberCount) * 10, 3);
            $score = round($nameScore + $memberRelevance + $roleRelevance + $cohesionScore, 3);
            $candidates[] = [
                'boundary' => ['id' => $boundary['id'], 'name' => $boundary['name'], 'source' => $boundary['source'], 'matcher' => self::decode($boundary['matcher_json'])],
                'score' => $score,
                'confidence' => count($matchedTokens) >= 2 ? 'probable' : 'possible',
                'factors' => [
                    'boundary_name_relevance' => $nameScore, 'member_relevance' => $memberRelevance,
                    'role_relevance' => $roleRelevance, 'internal_dependency_cohesion' => $cohesionScore,
                    'matching_members' => count($related), 'boundary_members' => count($members),
                    'internal_edges' => $cohesion[$boundary['id']]['internal'], 'incident_edges' => $incident,
                ],
                'matched_tokens' => array_map(strval(...), array_keys($matchedTokens)),
                'related_members' => array_slice($related, 0, 5),
                '_semantic_text' => substr($boundary['name'] . ' ' . implode(' ', array_map(
                    static fn(array $member): string => $member['canonical_name'] . ' ' . $member['display_name'],
                    array_slice($members, 0, 100),
                )), 0, 4000),
            ];
        }

        return $candidates;
    }

    /**
     * Re-rank with the configured semantic provider, falling back to the deterministic score.
     *
     * A provider that is missing, slow, or wrong about its own contract must not
     * cost the caller an answer, so every failure is recorded as a fallback
     * reason and the deterministic ranking stands.
     *
     * @param list<array<string, mixed>> $candidates
     *
     * @return array{requested_mode: string, applied_mode: string, provider: ?string, fallback_reason: ?string}
     */
    private function applySemanticRanking(array &$candidates, string $featureDescription, string $rankingMode, int $deadline): array
    {
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
        return $ranking;
    }

    /**
     * Sort, cap, and describe the ranking, with evidence for the members that earned it.
     *
     * @param array<string, mixed> $project
     * @param list<string> $tokens
     * @param array{requested_mode: string, applied_mode: string, provider: ?string, fallback_reason: ?string} $ranking
     * @param list<array<string, mixed>> $candidates
     * @param array<string, list<array<string, mixed>>> $membersByBoundary
     * @param array{limit: int, max_members: int, max_edges: int, timeout_ms: int} $bounds
     * @param list<string> $truncationReasons
     */
    private function result(string $projectId, array $project, string $featureDescription, array $tokens, array $ranking, array $candidates, array $membersByBoundary, array $bounds, int $membersExamined, int $edgesExamined, bool $truncated, array $truncationReasons): ResultEnvelope
    {
        $limit = $bounds['limit'];
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
                'bounds' => $bounds + [
                    'members_examined' => $membersExamined,
                    'edges_examined' => $edgesExamined, 'truncation_reasons' => $truncationReasons,
                ],
            ],
            $evidence,
            $warnings,
            $truncated,
        );
    }

    /** The answer when a project has no boundaries to rank between yet. */
    private function noBoundariesResult(string $projectId, array $project, string $featureDescription, array $tokens, string $rankingMode, int $limit, int $maxMembers, int $maxEdges, int $timeoutMs): ResultEnvelope
    {
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            'No architecture boundaries are available for location ranking.',
            ['feature_description' => $featureDescription, 'tokens' => $tokens, 'ranking' => [
                // No candidates means no ranking was attempted, so no provider
                // can have been unavailable for it.
                'requested_mode' => $rankingMode, 'applied_mode' => 'deterministic', 'provider' => null,
                'fallback_reason' => null,
            ], 'candidates' => [], 'bounds' => [
                // Same shape the ranked response reports, so a consumer reading
                // bounds does not have to branch on whether anything ranked.
                'limit' => $limit, 'max_members' => $maxMembers, 'max_edges' => $maxEdges,
                'timeout_ms' => $timeoutMs, 'members_examined' => 0, 'edges_examined' => 0,
                'truncation_reasons' => [],
            ]],
            [],
            ['Scan or configure boundaries before requesting a location suggestion.'],
        );
    }

    /**
     * Split an identifier into its lowercase words, across case and separators.
     *
     * `NdjsonRpcChannel` yields ndjson/rpc/channel, and
     * `testRunWithVersionAndJsonFlagReturnsZero` yields …/and/json/… — which is
     * the whole point: matching tokens as bare substrings made "ndjson" match
     * the latter, because "AndJson" contains it across a word boundary.
     *
     * @return list<string>
     */
    private static function identifierWords(string $text): array
    {
        $spaced = preg_replace(
            // camelCase, then an acronym run followed by a word (NDJSONChannel).
            ['/(\p{Ll}|\p{N})(\p{Lu})/u', '/(\p{Lu}+)(\p{Lu}\p{Ll})/u'],
            ['$1 $2', '$1 $2'],
            $text,
        ) ?? $text;
        $words = preg_split('/[^\pL\pN]+/u', mb_strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY);

        return is_array($words) ? $words : [];
    }

    /**
     * Whether a token names one of these words.
     *
     * A token matches a whole word or the start of one, so "worker" still finds
     * `workers` — a prefix at a word boundary is a real match, unlike a
     * substring that begins mid-word.
     *
     * @param list<string> $words
     */
    private static function matchesWord(array $words, string $token): bool
    {
        foreach ($words as $word) {
            if (str_starts_with($word, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tokenise a feature description into the terms ranking scores against.
     *
     * @return list<string>
     */
    private function featureTokens(string $description): array
    {
        // `mb_strtolower`, matching identifierWords(): ASCII-only lowering left
        // an accented query token in a case no indexed word is ever written in,
        // so it could never match the identifier it names.
        $parts = preg_split('/[^\pL\pN]+/u', mb_strtolower($description), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            return [];
        }
        $stopWords = [
            'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'can', 'for', 'from', 'has',
            'have', 'in', 'into', 'is', 'it', 'its', 'of', 'on', 'or', 'our', 'so', 'that',
            'the', 'their', 'this', 'to', 'via', 'we', 'will', 'with',
            'add', 'build', 'create', 'implement', 'make', 'new', 'feature', 'support',
        ];
        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3 || in_array($part, $stopWords, true)) {
                continue;
            }
            $tokens[$part] = true;
        }
        if ($tokens === []) {
            // A description made entirely of stop words or short tokens still
            // deserves ranking; fall back to the permissive pre-filter set.
            foreach ($parts as $part) {
                if (mb_strlen($part) >= 2) {
                    $tokens[$part] = true;
                }
            }
        }
        // PHP turns a numeric string key into an int, so a description mentioning
        // "2024" would yield a token the declared list<string> does not allow.
        return array_map(strval(...), array_keys($tokens));
    }
}
