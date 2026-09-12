<?php

declare(strict_types=1);

namespace Knossos\Query\Drift;

/**
 * What an oracle found, split three ways.
 *
 * The split is not decoration: a pure deletion once read as both a deletion and
 * an addition, and only separate counts made that visible.
 */
final readonly class DriftCounts
{
    public function __construct(
        public int $changed,
        public int $added,
        public int $deleted,
    ) {}

    /** Whether anything drifted at all, which is what decides the staleness state. */
    public function total(): int
    {
        return $this->changed + $this->added + $this->deleted;
    }
}
