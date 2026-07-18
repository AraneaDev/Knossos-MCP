<?php

declare(strict_types=1);

namespace Knossos\Query;

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
