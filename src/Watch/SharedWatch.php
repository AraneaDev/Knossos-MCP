<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;
use Knossos\Cancellation\CancellationToken;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\AllowedRoots;
use Knossos\Result\ResultEnvelope;
use Knossos\Scan\ScanLedger;
use Knossos\Scan\ScanTarget;
use Knossos\Scan\TreeFingerprint;
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
 * `following` (with the leader's `owner_pid` and `snapshot_id`, again whenever
 * the leader stops or starts answering), `leading`,
 * then the leader's {@see WatchService} events, `snapshot` when another
 * writer's scan moved the active snapshot, and `stopped` last. `ready`,
 * `leading` and `following` carry this process's `pid`.
 */
final readonly class SharedWatch
{
    /** How often the leader rewrites its lock state, idle or scanning. */
    private const HEARTBEAT_MS = 15_000;

    /** The longest one scan of the watcher's may run before it is stopped, unless the project sets `limits.watch_scan_timeout_ms`. */
    public const DEFAULT_SCAN_TIMEOUT_MS = 300_000;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param string $databasePath where $pdo lives: locates `roots.json` and the lock directory beside it
     * @param string $installationRoot where the scanner workers live
     * @param (Closure(string): int)|null $scanLimit how long a scan of the given project root may run, asked before each scan; {@see self::scanTimeoutMs()} when null
     */
    public function __construct(
        private PDO $pdo,
        private string $databasePath,
        private string $installationRoot,
        private ?Closure $scanLimit = null,
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
        // Who is watching: the session that started this process signals it by this id when it ends.
        $emit = static fn(array $event) => $emit(in_array($event['event'] ?? null, ['ready', 'leading', 'following'], true) ? $event + ['pid' => getmypid()] : $event);
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
    private function lead(WatchLock $lock, string $root, string $projectId, ScanLedger $ledger, AllowedRoots $allowed, int $pollMs, int $debounceMs, CancellationToken $cancellation, callable $emit, ?Closure $alive, ?int $maxPolls): void
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
        // Bounded in time, stopped with the watcher, and still beating while it runs: a follower sees a leader that
        // scans, and only one that stopped answering reads as stuck.
        // The limit is read for each scan, so an edit to `limits.watch_scan_timeout_ms` holds from the next one.
        $limit = $this->scanLimit ?? static fn(string $root): int => self::scanTimeoutMs($root, $allowed);
        $scanner = fn(string $root, ?string $mode, CancellationToken $cancellation): ResultEnvelope => (new ProcessScanner($this->installationRoot, $this->databasePath, $limit($root), $alive, static fn() => $beat(null, 'scanning'), self::HEARTBEAT_MS))->scan($root, $mode, $cancellation);
        $observer = static function (array $event) use ($emit, $say): void {
            $phase = ['ready' => 'idle', 'scan_started' => 'scanning', 'scan_completed' => 'idle', 'absorbed' => 'idle'][$event['event']] ?? null;
            if ($phase !== null) {
                $say(isset($event['snapshot_id']) ? (string) $event['snapshot_id'] : null, $phase);
            }
            $emit($event);
        };
        (new WatchService($scanner, $allowed))->run($root, $pollMs, $debounceMs, 1000, $cancellation, $observer, $maxPolls, $hooks);
    }

    /**
     * How long one scan of the watcher's may run: the project's
     * `limits.watch_scan_timeout_ms`, else {@see self::DEFAULT_SCAN_TIMEOUT_MS}.
     * A configuration that cannot be loaded gets the default and does not
     * stop the watch: the scan reports that fault itself.
     */
    public static function scanTimeoutMs(string $root, AllowedRoots $allowed): int
    {
        try {
            return ProjectConfigurationLoader::load($root, $allowed)->watchScanTimeoutMs ?? self::DEFAULT_SCAN_TIMEOUT_MS;
        } catch (Throwable) {
            return self::DEFAULT_SCAN_TIMEOUT_MS;
        }
    }
}
