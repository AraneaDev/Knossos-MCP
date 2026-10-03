<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;
use Knossos\Query\ScanLedger;
use Knossos\Scan\CancellationToken;

/**
 * A session's stand-in while another session's watcher leads: it scans
 * nothing, reads the project's active snapshot once a poll (one row) and
 * says when it moves, and gives way as soon as the lead is free.
 */
final readonly class WatchFollower
{
    /** A leader whose heartbeat is older than this, in seconds, is reported stale: alive, but not polling. */
    private const STALE_AFTER_SECONDS = 60;

    /** How often a follower says on stdout that it is still there. */
    private const HEARTBEAT_SECONDS = 15;

    public function __construct(
        private ScanLedger $ledger,
        private string $lockDir,
        private string $projectId,
    ) {}

    /**
     * Follows until the lead is free, then holds it (the lock it took), or
     * null when it must stop instead: cancelled, orphaned, or past `$maxPolls`.
     *
     * @param callable(array<string, mixed>): void $emit
     * @param (Closure(): bool)|null $alive
     */
    public function follow(int $pollMs, CancellationToken $cancellation, callable $emit, ?Closure $alive, ?int $maxPolls): ?WatchLock
    {
        $snapshot = $this->ledger->activeSnapshot($this->projectId);
        $owner = WatchLock::owner($this->lockDir, $this->projectId);
        $emit([
            'event' => 'following',
            'owner_pid' => isset($owner['pid']) ? (int) $owner['pid'] : null,
            'stale' => isset($owner['heartbeat']) && time() - (int) $owner['heartbeat'] > self::STALE_AFTER_SECONDS,
            'snapshot_id' => $snapshot,
        ]);
        $polls = 0;
        $beat = hrtime(true);
        while (!$cancellation->isCancelled() && ($maxPolls === null || $polls < $maxPolls)) {
            usleep($pollMs * 1000);
            ++$polls;
            if ($alive !== null && !$alive()) {
                return null;
            }
            $active = $this->ledger->activeSnapshot($this->projectId);
            if ($active !== null && $active !== $snapshot) {
                $snapshot = $active;
                $emit(['event' => 'snapshot', 'snapshot_id' => $active]);
            }
            $lock = WatchLock::acquire($this->lockDir, $this->projectId);
            if ($lock !== null) {
                return $lock;
            }
            if (hrtime(true) - $beat >= self::HEARTBEAT_SECONDS * 1_000_000_000) {
                $beat = hrtime(true);
                $emit(['event' => 'heartbeat']);
            }
        }
        return null;
    }
}
