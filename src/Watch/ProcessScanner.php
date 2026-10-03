<?php

declare(strict_types=1);

namespace Knossos\Watch;

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
 */
final readonly class ProcessScanner
{
    /** How long to wait between looks at a running scan. */
    private const POLL_US = 20_000;

    /**
     * @param string $installationRoot where `bin/knossos` lives
     * @param string $databasePath the graph the scan writes
     */
    public function __construct(
        private string $installationRoot,
        private string $databasePath,
    ) {}

    /** Runs one scan of `$root` in `$mode` and reads back its result. */
    public function scan(string $root, ?string $mode = null, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $argv = [PHP_BINARY, $this->installationRoot . '/bin/knossos', 'scan', $root, '--db=' . $this->databasePath, '--json'];
        if ($mode !== null) {
            $argv[] = '--mode=' . $mode;
        }
        $process = proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not start a scan process.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        do {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if ($cancellation?->isCancelled() === true) {
                proc_terminate($process);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new ScanCancelledException('The scan was cancelled.');
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
}
