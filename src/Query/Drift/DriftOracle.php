<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

/** How far a project's files have moved since a scan, or null when undecidable. */
interface DriftOracle
{
    /**
     * Drift since the given scan, or null when this oracle cannot answer.
     *
     * Null is not zero. Returning zero claims the tree is unchanged; returning
     * null says freshness is unverified, and the caller must say so rather than
     * report a graph as fresh it could not check.
     *
     * @param ?string $finishedAt when the active scan finished, already read by the caller
     */
    public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts;
}
