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
    /** The most drifted paths an oracle names; the counts go on past it. */
    public const NAMED = 20;

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
        /**
         * The first drifted paths the oracle met, at most {@see self::NAMED},
         * each with how it drifted (`changed`, `added` or `deleted`), so a
         * reader can be told which files and not only how many. Fewer than
         * {@see self::total()} when the cap bit.
         *
         * @var list<array{path: string, change: string}>
         */
        public array $paths = [],
    ) {}

    /**
     * $paths with one more drifted path appended, unless it already names
     * {@see self::NAMED}: the oracles count every path but name only the first.
     *
     * @param list<array{path: string, change: string}> $paths
     * @return list<array{path: string, change: string}>
     */
    public static function name(array $paths, string $path, string $change): array
    {
        if (count($paths) < self::NAMED) {
            $paths[] = ['path' => $path, 'change' => $change];
        }

        return $paths;
    }

    /** Whether anything drifted at all, which is what decides the staleness state. */
    public function total(): int
    {
        return $this->changed + $this->added + $this->deleted;
    }
}
