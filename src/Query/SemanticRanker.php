<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * Optional semantic re-ranking of location suggestions.
 *
 * An extension point, not a dependency: ranking works from deterministic
 * structural factors alone, and an implementation may only reorder bounded
 * candidate text within a timeout. Its id is recorded in the result's provenance,
 * so a suggestion influenced by a model is distinguishable from one that was not.
 */
interface SemanticRanker
{
    /** Return the stable provider identifier included in ranking provenance. */
    public function id(): string;

    /**
     * Score bounded candidate text without changing deterministic base factors.
     *
     * @param list<array{id: string, text: string}> $candidates
     * @return array<string, float|int> Candidate ID to normalized score [0, 1].
     */
    public function rank(string $featureDescription, array $candidates, int $timeoutMs): array;
}
