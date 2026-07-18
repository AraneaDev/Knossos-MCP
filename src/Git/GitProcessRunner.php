<?php

declare(strict_types=1);

namespace Knossos\Git;

use RuntimeException;
use Throwable;

/** Runs bounded, timeout-controlled read-only Git subprocesses. */
final readonly class GitProcessRunner
{
    public function __construct(private int $maxOutputBytes = 2_000_000, private int $maxErrorBytes = 65_536) {}

    /** @param non-empty-list<string> $command */
    public function run(array $command, int $timeoutMs, string $operation): string
    {
        $pipes = [];
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $observedExit = -1;
        $deadline = hrtime(true) + ($timeoutMs * 1_000_000);
        try {
            while (true) {
                $status = proc_get_status($process);
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                if (strlen($stdout) > $this->maxOutputBytes || strlen($stderr) > $this->maxErrorBytes) {
                    throw new RuntimeException(sprintf('Git %s output exceeded its configured byte limit.', $operation));
                }
                if (!$status['running']) {
                    $observedExit = $status['exitcode'];
                    break;
                }
                if (hrtime(true) > $deadline) {
                    throw new RuntimeException(sprintf('Git %s timed out.', $operation));
                }
                $read = [$pipes[1], $pipes[2]];
                $write = $except = [];
                @stream_select($read, $write, $except, 0, 100_000);
            }
        } catch (Throwable $error) {
            proc_terminate($process, 9);
            throw $error;
        } finally {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
        }
        $closedExit = proc_close($process);
        $exit = $observedExit >= 0 ? $observedExit : $closedExit;
        if ($exit !== 0) {
            throw new RuntimeException(sprintf('Git %s unavailable: %s', $operation, substr(trim($stderr), 0, 500)));
        }
        return $stdout;
    }
}
