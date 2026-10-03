<?php

declare(strict_types=1);

namespace Knossos\Watch;

/**
 * One watch run's moving parts: the tree as last seen, what is waiting to be
 * scanned, the counters its result reports and the retry backoff. Kept apart
 * from {@see WatchService}, which is immutable and may run many times.
 */
final class WatchState
{
    /** @var array<string, string> the tree as last fingerprinted */
    public array $fingerprint = [];

    /** @var array<string, string> changed paths waiting for a scan, by kind of change */
    public array $pending = [];

    /** @var list<array<string, mixed>> the first events, kept for the result */
    public array $events = [];

    public bool $overflow = false;

    public ?int $firstPendingAt = null;

    public ?int $retryNotBefore = null;

    public ?int $lastBeatAt = null;

    public ?string $projectId = null;

    public ?string $snapshotId = null;

    public ?string $terminalReason = null;

    public int $scans = 0;

    public int $incrementalScans = 0;

    public int $fullScans = 0;

    public int $absorbed = 0;

    public int $coalesced = 0;

    public int $overflows = 0;

    public int $polls = 0;

    public int $scanErrors = 0;

    public int $consecutiveFailures = 0;

    /** Clears what waits for a scan once a scan (or another writer) took it in. */
    public function settle(): void
    {
        $this->pending = [];
        $this->overflow = false;
        $this->firstPendingAt = null;
        $this->consecutiveFailures = 0;
        $this->retryNotBefore = null;
    }
}
