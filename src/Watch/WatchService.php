<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Throwable;

final readonly class WatchService
{
    private const MAX_BACKOFF_MS = 30_000;

    /** @param list<string> $allowedRoots */
    public function __construct(private ProjectScanner $scanner, private array $allowedRoots) {}

    /**
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
    ): ResultEnvelope {
        if ($pollMs < 1 || $pollMs > 60_000 || $debounceMs < 0 || $debounceMs > 60_000 || $maxQueue < 1 || $maxQueue > 10_000) {
            throw new \InvalidArgumentException('Watch poll, debounce, or queue limit is invalid.');
        }
        if ($maxPolls !== null && $maxPolls < 1) {
            throw new \InvalidArgumentException('maxPolls must be positive when provided.');
        }
        $cancellation ??= new CancellationToken();
        $events = [];
        $emit = static function (array $event) use (&$events, $observer): void {
            if (count($events) < 200) {
                $events[] = $event;
            }
            if ($observer !== null) {
                $observer($event);
            }
        };

        // Snapshot the fingerprint BEFORE the initial scan (matching the poll
        // loop's pre-snapshot ordering). Capturing it afterwards would silently
        // miss every file changed while the initial scan was running.
        $fingerprint = $this->fingerprint($root);
        $last = $this->scanner->scan($root, mode: 'auto', cancellation: $cancellation);
        $scans = 1;
        $incrementalScans = 0;
        $fullScans = 0;
        $coalesced = 0;
        $overflows = 0;
        $polls = 0;
        $pending = [];
        $overflow = false;
        $firstPendingAt = null;
        $scanErrors = 0;
        $consecutiveFailures = 0;
        $retryNotBefore = null;
        $terminalReason = null;
        $emit(['event' => 'ready', 'project_id' => $last->projectId, 'snapshot_id' => $last->snapshotId, 'files' => count($fingerprint)]);

        while (!$cancellation->isCancelled() && ($maxPolls === null || $polls < $maxPolls)) {
            usleep($pollMs * 1000);
            ++$polls;
            try {
                $current = $this->fingerprint($root);
            } catch (Throwable $error) {
                $emit(['event' => 'error', 'message' => $error->getMessage()]);
                continue;
            }
            $changes = $this->changes($fingerprint, $current);
            $fingerprint = $current;
            foreach ($changes as $path => $change) {
                if (isset($pending[$path])) {
                    ++$coalesced;
                }
                $pending[$path] = $change;
            }
            if (count($pending) > $maxQueue) {
                $pending = [];
                $overflow = true;
                ++$overflows;
                $emit(['event' => 'overflow', 'mode' => 'full', 'max_queue' => $maxQueue]);
            }
            if (($changes !== [] || $overflow) && $firstPendingAt === null) {
                $firstPendingAt = hrtime(true);
            }
            if ($firstPendingAt === null || (hrtime(true) - $firstPendingAt) < $debounceMs * 1_000_000) {
                continue;
            }
            if ($retryNotBefore !== null && hrtime(true) < $retryNotBefore) {
                continue;
            }
            $mode = $overflow ? 'full' : 'incremental';
            $emit(['event' => 'scan_started', 'mode' => $mode, 'changes' => count($pending)]);
            $attempt = WatchScanAttempt::run($this->scanner, $root, $mode, $cancellation);
            if ($attempt->isCancelled()) {
                break;
            }
            if ($attempt->isTerminal()) {
                ++$scanErrors;
                $emit(['event' => 'error', 'mode' => $mode, 'message' => (string) $attempt->errorMessage, 'retryable' => false]);
                $terminalReason = 'error';
                break;
            }
            if ($attempt->isRetryable() || $attempt->result === null) {
                ++$scanErrors;
                ++$consecutiveFailures;
                // Retain pending paths (later polls keep coalescing into them) so the
                // failed batch is retried instead of dropped; back off before retrying.
                $retryNotBefore = hrtime(true) + $this->backoffNanos($consecutiveFailures, $pollMs);
                $emit(['event' => 'error', 'mode' => $mode, 'message' => (string) $attempt->errorMessage, 'retryable' => true, 'attempt' => $consecutiveFailures]);
                continue;
            }
            $last = $attempt->result;
            ++$scans;
            if ($mode === 'full') {
                ++$fullScans;
            } else {
                ++$incrementalScans;
            }
            $emit(['event' => 'scan_completed', 'mode' => $mode, 'snapshot_id' => $last->snapshotId, 'parsed_files' => $last->data['parsed_files']]);
            $pending = [];
            $overflow = false;
            $firstPendingAt = null;
            $consecutiveFailures = 0;
            $retryNotBefore = null;
        }

        $reason = $terminalReason ?? ($cancellation->isCancelled() ? 'cancelled' : 'poll_limit');
        $emit(['event' => 'stopped', 'reason' => $reason]);
        return new ResultEnvelope($last->projectId, $last->snapshotId, sprintf('Watch stopped after %d polls and %d scans.', $polls, $scans), [
            'polls' => $polls,
            'scans' => $scans,
            'incremental_scans' => $incrementalScans,
            'full_scans' => $fullScans,
            'coalesced_changes' => $coalesced,
            'queue_overflows' => $overflows,
            'scan_errors' => $scanErrors,
            'pending_changes' => count($pending),
            'events' => $events,
        ]);
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

    /** @return array<string, string> */
    private function fingerprint(string $root): array
    {
        $configuration = ProjectConfigurationLoader::load($root, $this->allowedRoots);
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig(
            $this->allowedRoots,
            $configuration->ignores,
            $configuration->maxFiles ?? 100_000,
            $configuration->maxFileBytes ?? 2_000_000,
        )))->discover($root);
        $result = [];
        foreach ($discovery->files as $file) {
            $result[$file->relativePath] = $file->contentHash;
        }
        foreach ($discovery->units as $unit) {
            $result[$unit->configPath] = $unit->contentHash;
        }
        ksort($result, SORT_STRING);
        return $result;
    }

    /** @param array<string, string> $before @param array<string, string> $after @return array<string, string> */
    private function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $path => $hash) {
            if (!isset($before[$path])) {
                $changes[$path] = 'added';
            } elseif ($before[$path] !== $hash) {
                $changes[$path] = 'changed';
            }
        }
        foreach (array_diff_key($before, $after) as $path => $_hash) {
            $changes[$path] = 'deleted';
        }
        ksort($changes, SORT_STRING);
        return $changes;
    }
}
