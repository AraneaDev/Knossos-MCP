<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\SemanticRanker;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class SemanticTest extends KnossosTestCase
{
    #[Group('semantic')]
    public function testOptionalSemanticLocationRankingValidatesProvidersAndFallsBackDeterministically(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);

        $deterministicQuery = new ArchitectureQueryService($pdo);
        $deterministic = $deterministicQuery->suggestLocation($ids['project'], 'checkout service');
        $unavailable = $deterministicQuery->suggestLocation($ids['project'], 'checkout service', rankingMode: 'semantic_if_available');
        assertSame($deterministic->data['candidates'], $unavailable->data['candidates']);
        assertSame('provider_unavailable', $unavailable->data['ranking']['fallback_reason']);
        assertContains('deterministic fallback', $unavailable->warnings[1]);

        $ranker = new class ($billing, $backend) implements SemanticRanker {
            public function __construct(private string $billing, private string $backend) {}
            public function id(): string
            {
                return 'fixture.semantic.v1';
            }
            public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
            {
                assertSame(true, $timeoutMs >= 1);
                assertContains('Checkout', implode(' ', array_column($candidates, 'text')));
                return [$this->billing => 1.0, $this->backend => 0.0];
            }
        };
        $semantic = (new ArchitectureQueryService($pdo, semanticRanker: $ranker))->suggestLocation(
            $ids['project'],
            'checkout service',
            rankingMode: 'semantic_if_available',
        );
        assertSame('Billing', $semantic->data['candidates'][0]['boundary']['name']);
        assertSame('semantic', $semantic->data['ranking']['applied_mode']);
        assertSame('fixture.semantic.v1', $semantic->data['ranking']['provider']);
        assertSame(20.0, $semantic->data['candidates'][0]['factors']['semantic_relevance']);

        $invalidRanker = new class implements SemanticRanker {
            public function id(): string
            {
                return 'fixture.invalid';
            }
            public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
            {
                return [$candidates[0]['id'] => 2.0];
            }
        };
        $invalid = (new ArchitectureQueryService($pdo, semanticRanker: $invalidRanker))->suggestLocation(
            $ids['project'],
            'checkout service',
            rankingMode: 'semantic_if_available',
        );
        assertSame($deterministic->data['candidates'], $invalid->data['candidates']);
        assertContains('provider_failed:', $invalid->data['ranking']['fallback_reason']);

        $failingRanker = new class implements SemanticRanker {
            public function id(): string
            {
                return 'fixture.failure';
            }
            public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
            {
                throw new RuntimeException('offline');
            }
        };
        $failed = (new ArchitectureQueryService($pdo, semanticRanker: $failingRanker))->suggestLocation(
            $ids['project'],
            'checkout service',
            rankingMode: 'semantic_if_available',
        );
        assertSame($deterministic->data['candidates'], $failed->data['candidates']);
        assertContains('offline', $failed->data['ranking']['fallback_reason']);
        assertThrows(fn() => $deterministicQuery->suggestLocation($ids['project'], 'checkout', rankingMode: 'semantic'), InvalidArgumentException::class);
    }
}
