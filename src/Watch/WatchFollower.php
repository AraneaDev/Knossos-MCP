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
 *
 * It also says when the leader starts a scan (`leader_scanning`, seen in the
 * lock's state within a poll): the session behind it learns when the changes
 * that scan takes in were noticed, as a leader's own `scan_started` tells
 * its session.
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
        $stale = self::isStale($owner);
        $scanning = self::isScanning($owner);
        $emit($this->following($snapshot, $stale));
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
            $owner = WatchLock::owner($this->lockDir, $this->projectId);
            // A scan the leader starts is said once, when it is first seen.
            if (self::isScanning($owner) !== $scanning) {
                $scanning = !$scanning;
                if ($scanning) {
                    $emit(['event' => 'leader_scanning']);
                }
            }
            // A leader that stops answering (stuck, or its process hung) is said at once, and so is its return.
            if (self::isStale($owner) !== $stale) {
                $stale = !$stale;
                $emit($this->following($snapshot, $stale));
            }
            if (hrtime(true) - $beat >= self::HEARTBEAT_SECONDS * 1_000_000_000) {
                $beat = hrtime(true);
                $emit(['event' => 'heartbeat']);
            }
        }
        return null;
    }

    /**
     * Whether the leader's last heartbeat is older than {@see self::STALE_AFTER_SECONDS}.
     *
     * @param array<string, mixed>|null $owner the lock's state
     */
    private static function isStale(?array $owner): bool
    {
        return isset($owner['heartbeat']) && time() - (int) $owner['heartbeat'] > self::STALE_AFTER_SECONDS;
    }

    /**
     * Whether the leader says it is scanning.
     *
     * @param array<string, mixed>|null $owner the lock's state
     */
    private static function isScanning(?array $owner): bool
    {
        return ($owner['phase'] ?? null) === 'scanning';
    }

    /**
     * The `following` event: who leads, whether it stopped answering, and
     * whether the process that started it also started this one (a session
     * after a `/clear` or a resume meeting its own earlier watcher, which is
     * on its way out): that is no other session.
     *
     * @return array<string, mixed>
     */
    private function following(?string $snapshot, bool $stale): array
    {
        $owner = WatchLock::owner($this->lockDir, $this->projectId);
        $parent = function_exists('posix_getppid') ? posix_getppid() : null;
        return [
            'event' => 'following',
            'owner_pid' => isset($owner['pid']) ? (int) $owner['pid'] : null,
            'stale' => $stale,
            'same_process' => $parent !== null && isset($owner['parent_pid']) && (int) $owner['parent_pid'] === $parent,
            'snapshot_id' => $snapshot,
        ];
    }
}
