<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

/**
 * The milliseconds each reconciliation phase took, in the order they ran.
 *
 * Each mark closes the phase that began at the previous mark (or at
 * construction) and opens the next, so the phases tile the whole reconcile
 * with no gap between them: their sum is the reconcile's duration, and a
 * phase that is not marked is folded into the one that is.
 */
final class PhaseTimer
{
    /** @var array<string, float> phase name to milliseconds */
    private array $phases = [];

    private int|float $started;

    /** Opens the first phase. */
    public function __construct()
    {
        $this->started = hrtime(true);
    }

    /** Close the running phase under this name and open the next. */
    public function mark(string $phase): void
    {
        $this->phases[$phase] = round((hrtime(true) - $this->started) / 1_000_000, 3);
        $this->started = hrtime(true);
    }

    /**
     * Every phase marked so far.
     *
     * @return array<string, float>
     */
    public function phases(): array
    {
        return $this->phases;
    }
}
