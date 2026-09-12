<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ArchitectureQueryService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * How a location suggestion is scored, term by term.
 *
 * LocationSuggestionService scored 56% under mutation testing, the lowest of the
 * query services after review. Its tests assert which boundary comes first,
 * which every weight in the formula can change without disturbing, so the name
 * weight, the member and role multipliers, the density scaling, the cohesion
 * ratio and the counters behind it were all unpinned.
 *
 * The service reports each term under `factors`, so one exact assertion per
 * shaped fixture states the whole formula rather than its ordering.
 */
final class LocationScoringTest extends KnossosTestCase
{
    /**
     * A boundary whose name matches, holding both ends of one relationship.
     *
     * Name match 12, no member matches, and a relationship with both ends inside
     * the boundary: one internal edge out of one incident, which is total
     * cohesion and scores ten.
     */
    #[Group('query')]
    public function testANameMatchAndTotalCohesionScoreTheirOwnTerms(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $candidate = self::candidateNamed($pdo, $ids['project'], 'billing', 'Billing');

        assertSame(
            [
                'boundary_name_relevance' => 12,
                'member_relevance' => 0.0,
                'role_relevance' => 0.0,
                'internal_dependency_cohesion' => 10.0,
                'matching_members' => 0,
                'boundary_members' => 2,
                'internal_edges' => 1,
                'incident_edges' => 1,
            ],
            $candidate['factors'],
        );
        assertSame(22.0, $candidate['score']);
        assertSame('possible', $candidate['confidence'], 'One matched token is a possibility, not a probability.');
    }

    /**
     * A token matching a member scores against the boundary's size, not its
     * total: four per matched member, spread over the members there are.
     */
    #[Group('query')]
    public function testAMemberMatchIsScoredAsDensityRatherThanTotal(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $candidate = self::candidateNamed($pdo, $ids['project'], 'checkout', 'Billing');

        assertSame(0, $candidate['factors']['boundary_name_relevance']);
        assertSame(20.0, $candidate['factors']['member_relevance'], 'One matched member of two: four, halved, times ten.');
        assertSame(1, $candidate['factors']['matching_members']);
        assertSame(30.0, $candidate['score']);
    }

    /** A boundary no relationship touches has no cohesion, rather than perfect cohesion. */
    #[Group('query')]
    public function testABoundaryNoRelationshipTouchesHasNoCohesion(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $lonely = StableId::symbol($ids['project'], 'php', 'class', 'App\\Lonely');
        $repository->saveNode($lonely, $ids['project'], 'php', 'class', 'App\\Lonely', 'Lonely', null, $ids['file'], 70, 74, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $boundary = StableId::boundary($ids['project'], 'Lonely', 'explicit');
        $repository->saveBoundary($boundary, $ids['project'], 'Lonely', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($boundary, $ids['project'], $lonely, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $candidate = self::candidateNamed($pdo, $ids['project'], 'lonely', 'Lonely');

        assertSame(0, $candidate['factors']['incident_edges']);
        assertSame(0, $candidate['factors']['internal_edges']);
        assertSame(0.0, $candidate['factors']['internal_dependency_cohesion'], 'No relationships is no evidence of cohesion, not proof of it.');
        // Name 12, plus its one member matching out of one member: 4, times ten.
        assertSame(52.0, $candidate['score']);
    }

    /**
     * A relationship with one end outside the boundary is incident to it, not
     * internal to it.
     *
     * Cohesion asks how much of a boundary's traffic stays inside, so an
     * outgoing dependency counts against it, not for it. Every other fixture
     * here holds both ends of the relationship or neither, where counting edges
     * within and edges touching cannot be told apart.
     */
    #[Group('query')]
    public function testARelationshipLeavingTheBoundaryIsIncidentNotInternal(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        // Only the source of the fixture's Checkout to Invoice call is a member.
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $candidate = self::candidateNamed($pdo, $ids['project'], 'billing', 'Billing');

        assertSame(1, $candidate['factors']['incident_edges']);
        assertSame(0, $candidate['factors']['internal_edges'], 'One end outside makes the relationship incident, not internal.');
        assertSame(0.0, $candidate['factors']['internal_dependency_cohesion']);
        assertSame(12.0, $candidate['score']);
    }

    /**
     * A token matching a member's role scores on its own account, at half the
     * weight of a match on the member itself.
     *
     * The token is chosen to match the role and nothing else: not the boundary
     * name, and not the member's own name, so the role term is the only one that
     * can move.
     */
    #[Group('query')]
    public function testARoleMatchScoresOnItsOwnAccount(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $node = StableId::symbol($ids['project'], 'php', 'class', 'App\\Lonely');
        $repository->saveNode($node, $ids['project'], 'php', 'class', 'App\\Lonely', 'Lonely', null, $ids['file'], 70, 74, 'ast', 'certain', [], 'php:file:src/Checkout.php', $ids['scan']);
        $repository->saveClassification(
            StableId::classification($ids['project'], $node, 'infrastructure.gateway', 'rule.gateway'),
            $ids['project'],
            $node,
            'infrastructure.gateway',
            'derived',
            'probable',
            'rule.gateway',
            $ids['file'],
            70,
            74,
            [],
            $ids['scan'],
        );
        $boundary = StableId::boundary($ids['project'], 'Lonely', 'explicit');
        $repository->saveBoundary($boundary, $ids['project'], 'Lonely', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($boundary, $ids['project'], $node, $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $candidate = self::candidateNamed($pdo, $ids['project'], 'gateway', 'Lonely');

        assertSame(0, $candidate['factors']['boundary_name_relevance']);
        assertSame(0.0, $candidate['factors']['member_relevance'], 'The token matches the role, not the member.');
        assertSame(20.0, $candidate['factors']['role_relevance'], 'Two per matched role, over one member, times ten.');
        assertSame(20.0, $candidate['score']);
    }

    /** Two matched tokens make a suggestion probable; one leaves it possible. */
    #[Group('query')]
    public function testTwoMatchedTokensMakeASuggestionProbable(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        assertSame('possible', self::candidateNamed($pdo, $ids['project'], 'billing', 'Billing')['confidence']);
        assertSame('probable', self::candidateNamed($pdo, $ids['project'], 'billing checkout', 'Billing')['confidence']);
    }

    /**
     * The suggestion for one named boundary.
     *
     * @return array<string, mixed>
     */
    private static function candidateNamed(PDO $pdo, string $projectId, string $description, string $name): array
    {
        $result = (new ArchitectureQueryService($pdo))->suggestLocation($projectId, $description);
        foreach ($result->data['candidates'] as $candidate) {
            if ($candidate['boundary']['name'] === $name) {
                return $candidate;
            }
        }
        self::fail(sprintf('No candidate named %s in %s.', $name, json_encode(array_column(array_column($result->data['candidates'], 'boundary'), 'name'))));
    }
}
