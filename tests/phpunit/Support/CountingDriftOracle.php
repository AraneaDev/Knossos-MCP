<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Query\Drift\DriftCounts;
use Knossos\Query\Drift\DriftOracle;

/**
 * An oracle that counts how often it was consulted and otherwise answers
 * exactly as the one it wraps; with nothing to wrap, it reports no drift.
 */
final class CountingDriftOracle implements DriftOracle
{
    public int $calls = 0;

    public function __construct(private readonly ?DriftOracle $inner = null) {}

    /** Counts the probe, then defers to the wrapped oracle. */
    public function drift(string $projectId, string $activeScanId, string $root, ?string $finishedAt): ?DriftCounts
    {
        ++$this->calls;

        return $this->inner === null
            ? new DriftCounts(0, 0, 0)
            : $this->inner->drift($projectId, $activeScanId, $root, $finishedAt);
    }
}
