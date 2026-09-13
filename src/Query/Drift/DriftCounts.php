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
        /**
         * Whether the additions walk stopped at its own ceiling rather than
         * running out of entries, which makes $added a floor and not a count.
         *
         * Carried because nothing downstream could otherwise tell "exactly
         * this many" from "at least this many, I stopped looking", and one of
         * those two can be costed while the other cannot: an estimate built
         * on a saturated count sits below the real one by however much the
         * walk never saw, which is how a refresh larger than the budget gets
         * allowed to run inside a query the caller is waiting on.
         */
        public bool $additionsTruncated = false,
    ) {}

    /** Whether anything drifted at all, which is what decides the staleness state. */
    public function total(): int
    {
        return $this->changed + $this->added + $this->deleted;
    }
}
