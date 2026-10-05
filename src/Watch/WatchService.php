<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Throwable;

/**
 * Rescans a project as its files change.
 *
 * Polls a content fingerprint rather than using filesystem notifications, so
 * behaviour is identical across platforms and inside containers where inotify is
 * unreliable. Between fingerprints a {@see StatGate} stats what the last one
 * saw, so an idle tree costs a few hundred stat calls per poll instead of
 * hashing every file. Bursts are debounced and coalesced into one rescan, and
 * repeated failures back off exponentially so a persistently broken tree does
 * not spin.
 *
 * With {@see WatchHooks} the watcher shares its graph with other writers: a
 * change another writer already scanned is taken in without a scan of its
 * own (`absorbed`), another writer's snapshot is announced (`snapshot`), and
 * the watcher stops when whoever started it is gone (`orphaned`).
 */
final readonly class WatchService
{
    private const MAX_BACKOFF_MS = 30_000;

    private \Knossos\Discovery\AllowedRoots $roots;

    /**
     * @param ProjectScanner|\Closure(string, ?string, CancellationToken): ResultEnvelope $scanner a scanner, or a closure taking the root, mode and cancellation
     * @param \Knossos\Discovery\AllowedRoots|list<string> $allowedRoots
     */
    public function __construct(private ProjectScanner|\Closure $scanner, \Knossos\Discovery\AllowedRoots|array $allowedRoots)
    {
        $this->roots = \Knossos\Discovery\AllowedRoots::of($allowedRoots);
    }

    /**
     * Watch a root and rescan on change, until cancelled or a terminal failure.
     *
     * @param callable(array<string, mixed>): void|null $observer
     */
    public function run(
        string $root,
        int $pollMs = 500,
        int $debounceMs = 300,
        int $maxQueue = 1000,
        ?CancellationToken $cancellation = null,
        ?callable $observer = null,
        ?int $maxPolls = null,
        ?WatchHooks $hooks = null,
    ): ResultEnvelope {
        if ($pollMs < 1 || $pollMs > 60_000 || $debounceMs < 0 || $debounceMs > 60_000 || $maxQueue < 1 || $maxQueue > 10_000) {
            throw new \InvalidArgumentException('Watch poll, debounce, or queue limit is invalid.');
        }
        if ($maxPolls !== null && $maxPolls < 1) {
            throw new \InvalidArgumentException('maxPolls must be positive when provided.');
        }
        $cancellation ??= new CancellationToken();
        $hooks ??= new WatchHooks();
        $state = new WatchState();
        $emit = static function (array $event) use ($state, $observer): void {
            if (count($state->events) < 200) {
                $state->events[] = $event;
            }
            if ($observer !== null) {
                $observer($event);
            }
        };
        $gate = new StatGate($root);

        // Snapshot the fingerprint BEFORE the initial scan (matching the poll
        // loop's pre-snapshot ordering). Capturing it afterwards would silently
        // miss every file changed while the initial scan was running.
        $state->fingerprint = TreeFingerprint::of($root, $this->roots);
        $gate->remember($state->fingerprint);
        $scanned = $hooks->current === null || !($hooks->current)($state->fingerprint, null);
        if ($scanned) {
            $initial = $this->scanner instanceof \Closure ? ($this->scanner)($root, 'auto', $cancellation) : $this->scanner->scan($root, mode: 'auto', cancellation: $cancellation);
            [$state->projectId, $state->snapshotId] = [$initial->projectId, $initial->snapshotId];
        } else {
            [$state->projectId, $state->snapshotId] = [$hooks->projectId, $hooks->activeSnapshot === null ? null : ($hooks->activeSnapshot)()];
        }
        $state->scans = $scanned ? 1 : 0;
        self::giveBackMemory();
        $state->lastBeatAt = hrtime(true);
        $emit(['event' => 'ready', 'project_id' => $state->projectId, 'snapshot_id' => $state->snapshotId, 'files' => count($state->fingerprint), 'scanned' => $scanned]);

        while (!$cancellation->isCancelled() && ($maxPolls === null || $state->polls < $maxPolls)) {
            usleep($pollMs * 1000);
            ++$state->polls;
            if ($hooks->alive !== null && !($hooks->alive)()) {
                $state->terminalReason = 'orphaned';
                break;
            }
            $this->beat($state, $hooks, $emit);
            if (!$this->poll($root, $state, $gate, $maxQueue, $emit) || !$this->due($state, $debounceMs)) {
                continue;
            }
            if ($hooks->current !== null && ($hooks->current)($state->fingerprint, array_keys($state->pending))) {
                $this->absorb($state, $hooks, $emit);
                continue;
            }
            if (!$this->scan($root, $state, $pollMs, $cancellation, $emit)) {
                break;
            }
        }

        $reason = $state->terminalReason ?? ($cancellation->isCancelled() ? 'cancelled' : 'poll_limit');
        $emit(['event' => 'stopped', 'reason' => $reason]);
        return new ResultEnvelope((string) $state->projectId, $state->snapshotId, sprintf('Watch stopped after %d polls and %d scans.', $state->polls, $state->scans), [
            'polls' => $state->polls,
            'scans' => $state->scans,
            'incremental_scans' => $state->incrementalScans,
            'full_scans' => $state->fullScans,
            'absorbed_changes' => $state->absorbed,
            'coalesced_changes' => $state->coalesced,
            'queue_overflows' => $state->overflows,
            'scan_errors' => $state->scanErrors,
            'pending_changes' => count($state->pending),
            'events' => $state->events,
        ]);
    }

    /**
     * The heartbeat when it is due, and a snapshot another writer made since
     * the last one the watcher knew (announced once, as `snapshot`).
     *
     * @param callable(array<string, mixed>): void $emit
     */
    private function beat(WatchState $state, WatchHooks $hooks, callable $emit): void
    {
        if ($hooks->activeSnapshot !== null) {
            $active = ($hooks->activeSnapshot)();
            if ($active !== null && $active !== $state->snapshotId) {
                $state->snapshotId = $active;
                $emit(['event' => 'snapshot', 'snapshot_id' => $active]);
            }
        }
        if ($hooks->heartbeat !== null && $hooks->heartbeatMs > 0 && hrtime(true) - (int) $state->lastBeatAt >= $hooks->heartbeatMs * 1_000_000) {
            $state->lastBeatAt = hrtime(true);
            ($hooks->heartbeat)($state->snapshotId, $state->pending === [] ? 'idle' : 'pending');
        }
    }

    /**
     * Takes a fresh fingerprint when the stat gate says the tree may have
     * moved, and queues what changed. True when something waits for a scan.
     *
     * @param callable(array<string, mixed>): void $emit
     */
    private function poll(string $root, WatchState $state, StatGate $gate, int $maxQueue, callable $emit): bool
    {
        if ($gate->mayHaveChanged()) {
            try {
                $current = TreeFingerprint::of($root, $this->roots);
            } catch (Throwable $error) {
                $emit(['event' => 'error', 'message' => $error->getMessage()]);
                return $state->pending !== [] || $state->overflow;
            }
            $gate->remember($current);
            $changes = TreeFingerprint::changes($state->fingerprint, $current);
            $state->fingerprint = $current;
            if ($changes !== [] && $state->pending === [] && !$state->overflow) {
                $emit(['event' => 'changes', 'changes' => count($changes)]);
            }
            foreach ($changes as $path => $change) {
                if (isset($state->pending[$path])) {
                    ++$state->coalesced;
                }
                $state->pending[$path] = $change;
            }
            if (count($state->pending) > $maxQueue) {
                $state->pending = [];
                $state->overflow = true;
                ++$state->overflows;
                $emit(['event' => 'overflow', 'mode' => 'full', 'max_queue' => $maxQueue]);
            }
            if (($changes !== [] || $state->overflow) && $state->firstPendingAt === null) {
                $state->firstPendingAt = hrtime(true);
            }
        }
        return $state->pending !== [] || $state->overflow;
    }

    /** Whether what waits has waited out the debounce and any retry backoff. */
    private function due(WatchState $state, int $debounceMs): bool
    {
        if ($state->firstPendingAt === null || (hrtime(true) - $state->firstPendingAt) < $debounceMs * 1_000_000) {
            return false;
        }
        return $state->retryNotBefore === null || hrtime(true) >= $state->retryNotBefore;
    }

    /**
     * Takes in changes another writer already scanned: no scan of its own.
     *
     * @param callable(array<string, mixed>): void $emit
     */
    private function absorb(WatchState $state, WatchHooks $hooks, callable $emit): void
    {
        $state->absorbed += count($state->pending);
        $state->snapshotId = ($hooks->activeSnapshot === null ? null : ($hooks->activeSnapshot)()) ?? $state->snapshotId;
        $emit(['event' => 'absorbed', 'changes' => count($state->pending), 'snapshot_id' => $state->snapshotId]);
        $state->settle();
    }

    /**
     * One scan of what waits. False when the watch must stop: cancelled, or
     * a failure no retry can fix.
     *
     * @param callable(array<string, mixed>): void $emit
     */
    private function scan(string $root, WatchState $state, int $pollMs, CancellationToken $cancellation, callable $emit): bool
    {
        $mode = $state->overflow ? 'full' : 'incremental';
        $emit(['event' => 'scan_started', 'mode' => $mode, 'changes' => count($state->pending)]);
        $attempt = WatchScanAttempt::run($this->scanner, $root, $mode, $cancellation);
        if ($attempt->isCancelled()) {
            return false;
        }
        if ($attempt->isTerminal()) {
            ++$state->scanErrors;
            $emit(['event' => 'error', 'mode' => $mode, 'message' => (string) $attempt->errorMessage, 'retryable' => false]);
            $state->terminalReason = 'error';
            return false;
        }
        if ($attempt->isRetryable() || $attempt->result === null) {
            ++$state->scanErrors;
            ++$state->consecutiveFailures;
            // Retain pending paths (later polls keep coalescing into them) so the
            // failed batch is retried instead of dropped; back off before retrying.
            $state->retryNotBefore = hrtime(true) + $this->backoffNanos($state->consecutiveFailures, $pollMs);
            $emit(['event' => 'error', 'mode' => $mode, 'message' => (string) $attempt->errorMessage, 'retryable' => true, 'attempt' => $state->consecutiveFailures]);
            return true;
        }
        $last = $attempt->result;
        [$state->projectId, $state->snapshotId] = [$last->projectId, $last->snapshotId];
        ++$state->scans;
        if ($mode === 'full') {
            ++$state->fullScans;
        } else {
            ++$state->incrementalScans;
        }
        $emit(['event' => 'scan_completed', 'mode' => $mode, 'snapshot_id' => $last->snapshotId, 'parsed_files' => $last->data['parsed_files']]);
        $state->settle();
        self::giveBackMemory();
        return true;
    }

    /**
     * Hands what a scan used back to the system. A scan of a real project
     * peaks at a couple of hundred megabytes; PHP keeps freed memory for
     * reuse, so a watcher that idles for hours after one scan would hold it
     * all that time.
     */
    private static function giveBackMemory(): void
    {
        gc_collect_cycles();
        gc_mem_caches();
    }

    /**
     * Exponential backoff bounded by {@see MAX_BACKOFF_MS}, expressed in
     * nanoseconds for comparison against hrtime().
     */
    private function backoffNanos(int $failures, int $pollMs): int
    {
        $exponent = min(max($failures - 1, 0), 10);
        $backoffMs = min($pollMs * (2 ** $exponent), self::MAX_BACKOFF_MS);
        return $backoffMs * 1_000_000;
    }
}
