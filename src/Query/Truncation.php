<?php

declare(strict_types=1);

namespace Knossos\Query;

/**
 * The reasons a bounded traversal stopped short, in the order it met them.
 *
 * Every bounded query reports truncation twice over: a `truncated` flag on the
 * envelope and the list of reasons in its bounds. Kept as a flag and a list
 * side by side, each stop had to remember to set both, and a reason recorded
 * without the flag would read as a complete answer. Here the flag is derived
 * from the list, so the two cannot disagree.
 *
 * A reason is kept once, at the position it was first met: a walk that hits
 * the same bound on two nodes stopped for one reason, and the first reason is
 * the one a single-reason field reports.
 */
final class Truncation
{
    /** @var list<string> */
    private array $reasons = [];

    /** Record why the walk stopped short; a reason already recorded keeps its place. */
    public function add(string ...$reasons): void
    {
        foreach ($reasons as $reason) {
            if (!in_array($reason, $this->reasons, true)) {
                $this->reasons[] = $reason;
            }
        }
    }

    /** Whether any bound cut the walk short. */
    public function any(): bool
    {
        return $this->reasons !== [];
    }

    /**
     * Each distinct reason, in the order first met.
     *
     * @return list<string>
     */
    public function reasons(): array
    {
        return $this->reasons;
    }
}
