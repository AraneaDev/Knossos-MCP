<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ScanCancelledException;
use RuntimeException;

/**
 * Runs each scan as a `knossos scan` process of its own, for a watcher that
 * lives for hours.
 *
 * A scan of a real project peaks at a couple of hundred megabytes. In one
 * long-lived PHP process most of that stays allocated after the scan ends:
 * the few allocations still alive pin the memory chunks they sit in, so a
 * watcher that once scanned would hold over a hundred megabytes while it
 * idles. A process per scan gives every byte back when it exits; the
 * watcher itself stays at what polling needs.
 *
 * A scan process never outlives its bounds: past `$timeoutMs`, on
 * cancellation (the watcher shutting down) or once whoever started the
 * watcher is gone, it is asked to stop (SIGINT, which a scan answers by
 * shutting its workers down) and, if it has not within a grace period,
 * killed with everything in its process group. It is started in a process
 * group of its own (`setsid`) so that kill reaches what it started and
 * nothing of the watcher's.
 */
final readonly class ProcessScanner
{
    /** How long to wait between looks at a running scan. */
    private const POLL_US = 20_000;

    /** How long a scan asked to stop may take before it is killed. */
    private const GRACE_MS = 3_000;

    /**
     * @param string $installationRoot where `bin/knossos` lives
     * @param string $databasePath the graph the scan writes
     * @param int $timeoutMs the longest one scan may run
     * @param (Closure(): bool)|null $alive false once whoever started the watcher is gone: the scan is stopped
     * @param (Closure(): void)|null $heartbeat called every `$heartbeatMs` while a scan runs, so the watcher still says it is there
     */
    public function __construct(
        private string $installationRoot,
        private string $databasePath,
        private int $timeoutMs = 300_000,
        private ?Closure $alive = null,
        private ?Closure $heartbeat = null,
        private int $heartbeatMs = 15_000,
    ) {}

    /** Runs one scan of `$root` in `$mode` and reads back its result. */
    public function scan(string $root, ?string $mode = null, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $argv = [PHP_BINARY, $this->installationRoot . '/bin/knossos', 'scan', $root, '--db=' . $this->databasePath, '--json'];
        if ($mode !== null) {
            $argv[] = '--mode=' . $mode;
        }
        $setsid = self::setsid();
        $process = proc_open($setsid === null ? $argv : [$setsid, ...$argv], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a scan process.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $pid = (int) proc_get_status($process)['pid'];
        $group = $setsid !== null && self::leadsGroup($pid);
        $started = hrtime(true);
        $beat = $started;
        $stdout = '';
        $stderr = '';
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $stop = match (true) {
                $cancellation?->isCancelled() === true => 'cancelled',
                $this->alive !== null && !($this->alive)() => 'cancelled',
                hrtime(true) - $started >= $this->timeoutMs * 1_000_000 => 'timeout',
                default => null,
            };
            if ($stop !== null) {
                self::stop($process, $pid, $group);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw $stop === 'cancelled'
                    ? new ScanCancelledException('The scan was cancelled.')
                    : new RuntimeException(sprintf('The scan ran past its %d s limit and was stopped.', intdiv($this->timeoutMs, 1000)));
            }
            if ($this->heartbeat !== null && hrtime(true) - $beat >= $this->heartbeatMs * 1_000_000) {
                $beat = hrtime(true);
                ($this->heartbeat)();
            }
            $status = proc_get_status($process);
            if ($status['running']) {
                usleep(self::POLL_US);
            }
        } while ($status['running']);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $result = json_decode($stdout, true);
        if ($status['exitcode'] !== 0 || !is_array($result) || !is_string($result['project_id'] ?? null) || !is_string($result['snapshot_id'] ?? null)) {
            throw new RuntimeException(trim($stderr) !== '' ? trim($stderr) : sprintf('The scan process failed (exit %d).', $status['exitcode']));
        }
        return new ResultEnvelope($result['project_id'], $result['snapshot_id'], (string) ($result['summary'] ?? ''), is_array($result['data'] ?? null) ? $result['data'] : []);
    }

    /**
     * Asks the scan to stop, then kills it and its process group when it has
     * not stopped within {@see self::GRACE_MS}.
     *
     * @param resource $process
     * @param bool $group whether the scan was seen to lead a process group of its own
     */
    private static function stop(mixed $process, int $pid, bool $group): void
    {
        $posix = function_exists('posix_kill');
        if ($posix && $pid > 1) {
            @posix_kill($pid, 2);
        }
        $deadline = hrtime(true) + self::GRACE_MS * 1_000_000;
        while (proc_get_status($process)['running'] && hrtime(true) < $deadline) {
            usleep(self::POLL_US);
        }
        // The group outlives its leader while anything it started is left: killed whether or not the scan itself stopped.
        if ($group && $posix) {
            @posix_kill(-$pid, 9);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
    }

    /**
     * Whether the process leads its own process group: `setsid` takes a
     * moment to get there after the spawn, so this waits a little for it.
     * Only a group seen to be the scan's own is ever signalled.
     */
    private static function leadsGroup(int $pid): bool
    {
        if ($pid <= 1 || !function_exists('posix_getpgid')) {
            return false;
        }
        $deadline = hrtime(true) + 250_000_000;
        while (@posix_getpgid($pid) !== $pid && hrtime(true) < $deadline) {
            usleep(200);
        }
        return @posix_getpgid($pid) === $pid;
    }

    /** `setsid`, so the scan leads a process group of its own; null where there is none. */
    private static function setsid(): ?string
    {
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (PHP_OS_FAMILY !== 'Windows' && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }
}
