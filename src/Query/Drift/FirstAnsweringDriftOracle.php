<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

/**
 * The first oracle that can answer, wins.
 *
 * Ordering is the whole design: the exact, size-independent oracle is asked
 * first, and the approximate walk answers only for the projects it must.
 */
final readonly class FirstAnsweringDriftOracle implements DriftOracle
{
    /** @var list<DriftOracle> */
    private array $oracles;

    /** Accepts oracles in preference order; each is tried in turn until one answers. */
    public function __construct(DriftOracle ...$oracles)
    {
        $this->oracles = array_values($oracles);
    }

    /**
     * Drift as reported by the first oracle in the chain that can answer.
     *
     * A zero DriftCounts is a real answer and stops the chain; only null (an
     * oracle that could not decide) moves on to the next one. Collapsing that
     * distinction would either report a graph fresh because an earlier oracle
     * was unavailable, or run an expensive fallback after a cheap exact answer
     * had already arrived.
     */
    public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
    {
        foreach ($this->oracles as $oracle) {
            $counts = $oracle->drift($projectId, $activeScanId, $root, $finishedAt);
            if ($counts !== null) {
                return $counts;
            }
        }

        return null;
    }
}
