<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

final class WorkerProcessSupervisor implements ProcessSupervisorInterface
{
    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string>|null $environment
     */
    public function __construct(
        private readonly array $command,
        private readonly ?array $environment = null,
    ) {}

    public function __destruct()
    {
        $this->close(true);
    }

    public function start(): void
    {
        if ($this->process !== null) {
            return;
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $pipes = [];
        $process = proc_open($this->command, $descriptors, $pipes, null, $this->environment);
        if (!is_resource($process)) {
            throw new WorkerException('WORKER_START_FAILED', 'Unable to start scanner worker.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        stream_set_blocking($this->pipes[0], true);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);
    }

    public function isRunning(): bool
    {
        return $this->process !== null;
    }

    /** @return resource */
    public function stdin()
    {
        $this->start();
        return $this->pipes[0];
    }

    /** @return resource */
    public function stdout()
    {
        $this->start();
        return $this->pipes[1];
    }

    /** @return resource */
    public function stderr()
    {
        $this->start();
        return $this->pipes[2];
    }

    /**
     * @return array{
     *     command: string,
     *     pid: int,
     *     running: bool,
     *     signaled: bool,
     *     stopped: bool,
     *     exitcode: int,
     *     termsig: int,
     *     stopsig: int
     * }
     */
    public function status(): array
    {
        if ($this->process === null) {
            return [
                'command' => '',
                'pid' => 0,
                'running' => false,
                'signaled' => false,
                'stopped' => false,
                'exitcode' => -1,
                'termsig' => 0,
                'stopsig' => 0,
            ];
        }

        return proc_get_status($this->process);
    }

    public function close(bool $terminate): void
    {
        if ($this->process === null) {
            return;
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];

        $status = proc_get_status($this->process);
        $descendants = $terminate && $status['running'] ? $this->descendantPids((int) $status['pid']) : [];
        if ($terminate && $status['running']) {
            $graceDeadline = hrtime(true) + 100_000_000;
            do {
                usleep(10_000);
                $status = proc_get_status($this->process);
            } while ($status['running'] && hrtime(true) < $graceDeadline);

            if ($status['running']) {
                $this->signalProcesses($descendants, 15);
                proc_terminate($this->process);
            }
            $terminationDeadline = hrtime(true) + 250_000_000;
            while ($status['running'] && hrtime(true) < $terminationDeadline) {
                usleep(10_000);
                $status = proc_get_status($this->process);
            }

            if ($status['running'] && PHP_OS_FAMILY !== 'Windows') {
                $this->signalProcesses($descendants, 9);
                proc_terminate($this->process, 9);
            }
        }
        proc_close($this->process);
        $this->process = null;
    }

    /** @return list<int> */
    private function descendantPids(int $pid): array
    {
        if (PHP_OS_FAMILY !== 'Linux' || $pid <= 0) {
            return [];
        }
        $children = @file_get_contents(sprintf('/proc/%d/task/%d/children', $pid, $pid));
        if (!is_string($children) || trim($children) === '') {
            return [];
        }
        $descendants = [];
        foreach (preg_split('/\s+/', trim($children)) ?: [] as $child) {
            $childPid = (int) $child;
            if ($childPid > 0) {
                $descendants = array_merge($descendants, $this->descendantPids($childPid), [$childPid]);
            }
        }
        return array_values(array_unique($descendants));
    }

    /** @param list<int> $pids */
    private function signalProcesses(array $pids, int $signal): void
    {
        if (!function_exists('posix_kill')) {
            return;
        }
        foreach ($pids as $pid) {
            @posix_kill($pid, $signal);
        }
    }
}
