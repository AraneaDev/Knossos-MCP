<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;
use Knossos\Query\ScanLedger;
use Knossos\Query\ScanTarget;
use Knossos\Scan\CancellationToken;
use PDO;
use Throwable;

/**
 * The live watcher the Claude Code mod starts: `knossos watch <path> --shared`.
 *
 * It watches only what a turn brief may scan, an existing project in an
 * allowed root, and never creates a database or a project. Sessions that
 * share a data directory share one watcher per project ({@see WatchLock}):
 * the first to ask leads and scans; any other follows, polling the project's
 * active snapshot (one row, never a scan) and saying when it moves, and
 * takes the lead over when the leader is gone.
 *
 * The leader's scans are recorded in the {@see ScanLedger}, so a turn brief
 * that finds its edits already scanned can still tell what they changed.
 * Changes another writer already scanned are taken in without a scan.
 *
 * Events, one JSON object each: `refused` (with the brief commands' status),
 * `following` (with the leader's `owner_pid` and `snapshot_id`), `leading`,
 * then the leader's {@see WatchService} events, `snapshot` when another
 * writer's scan moved the active snapshot, and `stopped` last.
 */
final readonly class SharedWatch
{
    /** How often the leader rewrites its lock state while idle. */
    private const HEARTBEAT_MS = 15_000;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param string $databasePath where $pdo lives: locates `roots.json` and the lock directory beside it
     * @param string $installationRoot where the scanner workers live
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath,
        private string $installationRoot,
    ) {}

    /**
     * Watches `$path`'s project until cancelled, orphaned (`$alive` false) or
     * `$maxPolls` polls have run.
     *
     * @param callable(array<string, mixed>): void $emit
     * @param (Closure(): bool)|null $alive
     */
    public function run(string $path, int $pollMs, int $debounceMs, CancellationToken $cancellation, callable $emit, ?Closure $alive = null, ?int $maxPolls = null): void
    {
        [$allowed, $project, $refusal] = (new ScanTarget($this->pdo, $this->databasePath))->resolve(realpath($path) ?: $path);
        if ($project === null) {
            $emit(['event' => 'refused'] + ($refusal ?? ['status' => 'unscanned']));
            return;
        }
        $projectId = (string) $project['id'];
        $root = (string) $project['root_realpath'];
        $ledger = new ScanLedger($this->pdo);
        $dir = dirname($this->databasePath) . '/watch';
        $lock = WatchLock::acquire($dir, $projectId);
        if ($lock === null) {
            $lock = (new WatchFollower($ledger, $dir, $projectId))->follow($pollMs, $cancellation, $emit, $alive, $maxPolls);
            if ($lock === null) {
                $orphaned = $alive !== null && !$cancellation->isCancelled() && !$alive();
                $emit(['event' => 'stopped', 'reason' => $cancellation->isCancelled() ? 'cancelled' : ($orphaned ? 'orphaned' : 'poll_limit')]);
                return;
            }
            $emit(['event' => 'leading', 'snapshot_id' => $ledger->activeSnapshot($projectId)]);
        }
        try {
            $this->lead($lock, $root, $projectId, $ledger, $allowed, $pollMs, $debounceMs, $cancellation, $emit, $alive, $maxPolls);
        } finally {
            $lock->release();
        }
    }

    /**
     * Watches as the leader, keeping the lock's state current.
     *
     * @param callable(array<string, mixed>): void $emit
     * @param (Closure(): bool)|null $alive
     */
    private function lead(WatchLock $lock, string $root, string $projectId, ScanLedger $ledger, \Knossos\Discovery\AllowedRoots $allowed, int $pollMs, int $debounceMs, CancellationToken $cancellation, callable $emit, ?Closure $alive, ?int $maxPolls): void
    {
        $known = $ledger->activeSnapshot($projectId);
        $say = static function (?string $snapshot, string $phase) use ($lock, $projectId, $root, &$known): void {
            $known = $snapshot ?? $known;
            $lock->write(['project_id' => $projectId, 'project_root' => $root, 'snapshot_id' => $known, 'phase' => $phase]);
        };
        $say(null, 'starting');
        $current = function (array $fingerprint, ?array $paths) use ($ledger, $projectId, $root, $allowed): bool {
            $graph = $ledger->hashes($projectId);
            if ($paths === null) {
                try {
                    return TreeFingerprint::changes($graph, TreeFingerprint::of($root, $allowed, false)) === [];
                } catch (Throwable) {
                    return false;
                }
            }
            foreach ($paths as $p) {
                // A path on disk the graph never held is a new file or a configuration unit, which it holds no hash for: scan.
                if (($fingerprint[$p] ?? null) !== ($graph[$p] ?? null)) {
                    return false;
                }
            }
            return true;
        };
        // The heartbeat goes to stdout too: a reader that waits for a line notices it is still there (and can stop it).
        $beat = static function (?string $snapshot, string $phase) use ($say, $emit): void {
            $say($snapshot, $phase);
            $emit(['event' => 'heartbeat']);
        };
        $hooks = new WatchHooks($projectId, $current, static fn(): ?string => $ledger->activeSnapshot($projectId), $alive, $beat, self::HEARTBEAT_MS);
        // Each scan in a process of its own: the watcher idles for hours, and a scan's memory goes with its process.
        // That process (`knossos scan`) records the scan in the ledger itself, under the scan's own write lease.
        $scanner = new ProcessScanner($this->installationRoot, $this->databasePath);
        $observer = static function (array $event) use ($emit, $say): void {
            $phase = ['ready' => 'idle', 'scan_started' => 'scanning', 'scan_completed' => 'idle', 'absorbed' => 'idle'][$event['event']] ?? null;
            if ($phase !== null) {
                $say(isset($event['snapshot_id']) ? (string) $event['snapshot_id'] : null, $phase);
            }
            $emit($event);
        };
        (new WatchService($scanner->scan(...), $allowed))->run($root, $pollMs, $debounceMs, 1000, $cancellation, $observer, $maxPolls, $hooks);
    }
}
