<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Closure;

/**
 * Cooperative cancellation for a long-running scan.
 *
 * Cancellation is advisory: the pipeline checks the token where abandoning work
 * leaves the graph consistent, rather than being interrupted mid-write. The
 * optional poll closure lets a transport surface an out-of-band signal — the
 * stdio server passes one that looks for a client's `notifications/cancelled`
 * without blocking on input.
 */
final class CancellationToken
{
    private bool $cancelled = false;

    /**
     * @param Closure|null $poll returns true once an external cancellation has
     *        arrived; consulted by {@see isCancelled()} until it first says so
     */
    public function __construct(private readonly ?Closure $poll = null) {}

    /** Request cancellation directly, without waiting on the poll closure. */
    public function cancel(): void
    {
        $this->cancelled = true;
    }

    /**
     * Whether cancellation has been requested.
     *
     * Latching: once true it stays true and the poll closure is never called
     * again, so a closure with side effects (draining a stream) runs only up to
     * the point it reports cancellation.
     */
    public function isCancelled(): bool
    {
        if (!$this->cancelled && $this->poll !== null && ($this->poll)()) {
            $this->cancelled = true;
        }
        return $this->cancelled;
    }

    /**
     * Abort the caller when cancellation has been requested.
     *
     * The checkpoint form, for loops with nothing to unwind.
     *
     * @throws ScanCancelledException
     */
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new ScanCancelledException('Scan was cancelled.');
        }
    }
}
