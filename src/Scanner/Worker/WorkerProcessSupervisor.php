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
     * Process-group id the worker was placed in at spawn, when the platform
     * allowed it. Only set when the child is verified to be its own group
     * leader, so a group-directed signal can never reach the parent's group.
     */
    private ?int $processGroupId = null;

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
        // A neutral working directory and an explicit, minimal environment keep
        // the untrusted-source parser from inheriting the server's cwd, PATH
        // secrets, DB credentials, or tokens.
        $process = @proc_open(
            $this->command,
            $descriptors,
            $pipes,
            $this->workingDirectory(),
            $this->resolveEnvironment(),
        );
        if (!is_resource($process)) {
            throw new WorkerException('WORKER_START_FAILED', 'Unable to start scanner worker.');
        }

        $this->process = $process;
        $this->pipes = $pipes;
        // Non-blocking stdin lets NdjsonRpcChannel::send() stream a large request
        // through a select loop (draining stdout meanwhile) instead of blocking
        // on a full pipe and deadlocking against a worker blocked on its output.
        stream_set_blocking($this->pipes[0], false);
        stream_set_blocking($this->pipes[1], false);
        stream_set_blocking($this->pipes[2], false);

        $this->placeInOwnProcessGroup();
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

        if ($terminate) {
            $this->terminateTree();
        }

        proc_close($this->process);
        $this->process = null;
        $this->processGroupId = null;
    }

    private function terminateTree(): void
    {
        $status = proc_get_status($this->process);
        $pid = (int) $status['pid'];

        // Grace window: let the worker exit cooperatively on stdin EOF.
        $graceDeadline = hrtime(true) + 100_000_000;
        do {
            usleep(10_000);
            $status = proc_get_status($this->process);
        } while ($status['running'] && hrtime(true) < $graceDeadline);

        if (PHP_OS_FAMILY === 'Windows') {
            if ($status['running']) {
                proc_terminate($this->process);
            }
            return;
        }

        // SIGTERM pass. Descendants are re-enumerated here (not once, up front),
        // and the tree is signalled even when the direct child already exited,
        // because grandchildren spawned during the grace window can outlive it.
        $this->signalTree($pid, 15);
        if ($status['running']) {
            proc_terminate($this->process);
        }

        $terminationDeadline = hrtime(true) + 250_000_000;
        while ($status['running'] && hrtime(true) < $terminationDeadline) {
            usleep(10_000);
            $status = proc_get_status($this->process);
        }

        // SIGKILL pass. Re-enumerate once more so freshly reparented or newly
        // spawned descendants are covered too.
        $this->signalTree($pid, 9);
        if ($status['running']) {
            proc_terminate($this->process, 9);
        }
    }

    private function signalTree(int $pid, int $signal): void
    {
        if (!function_exists('posix_kill')) {
            return;
        }

        // Whole-group kill: reliably reaps grandchildren the parent never sees.
        // Guarded so we only ever target a group the worker actually leads —
        // never the parent's own group.
        if ($this->processGroupId !== null && $this->processGroupId > 1) {
            @posix_kill(-$this->processGroupId, $signal);
        }

        // Belt-and-suspenders per-PID pass for platforms/cases where the group
        // could not be established. Re-enumerated on every call.
        foreach ($this->descendantsWithStartTime($pid) as $descendant => $startTime) {
            if ($signal === 9 && $startTime !== null) {
                // Guard against a reused PID: if the process at this PID no
                // longer has the start-time we enumerated, it is a different
                // process and must not be killed.
                $current = $this->processStartTime($descendant);
                if ($current !== null && $current !== $startTime) {
                    continue;
                }
            }
            @posix_kill($descendant, $signal);
        }
    }

    private function placeInOwnProcessGroup(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_setpgid') || !function_exists('posix_getpgid')) {
            return;
        }
        $status = proc_get_status($this->process);
        $pid = (int) $status['pid'];
        if ($pid <= 1) {
            return;
        }
        // Best effort: the child may already have exec'd, in which case the
        // kernel refuses (EACCES) and we fall back to descendant enumeration.
        @posix_setpgid($pid, $pid);
        $pgid = @posix_getpgid($pid);
        if ($pgid === $pid) {
            $this->processGroupId = $pid;
        }
    }

    private function workingDirectory(): string
    {
        $temp = sys_get_temp_dir();
        return is_dir($temp) ? $temp : (getcwd() ?: '.');
    }

    /**
     * Minimal explicit environment for the worker. A caller-supplied
     * environment is honoured verbatim; otherwise only a small allowlist of
     * neutral, functionally-required variables is forwarded so application
     * secrets never reach an untrusted-source parser.
     *
     * @return array<string, string>
     */
    private function resolveEnvironment(): array
    {
        if ($this->environment !== null) {
            return $this->environment;
        }

        $allowed = [
            'PATH', 'HOME', 'USER', 'LOGNAME', 'SHELL',
            'LANG', 'LC_ALL', 'LC_CTYPE',
            'TMPDIR', 'TMP', 'TEMP',
            'LD_LIBRARY_PATH', 'DYLD_LIBRARY_PATH',
            'SYSTEMROOT', 'SystemRoot', 'WINDIR', 'COMSPEC', 'PATHEXT',
        ];
        $environment = [];
        foreach ($allowed as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                $environment[$name] = $value;
            }
        }
        if (!isset($environment['PATH'])) {
            $environment['PATH'] = PHP_OS_FAMILY === 'Windows' ? 'C:\\Windows\\System32' : '/usr/bin:/bin';
        }

        return $environment;
    }

    /**
     * Enumerate descendant PIDs deepest-first, each paired with the start-time
     * captured at enumeration (null when unavailable).
     *
     * @return array<int, int|null>
     */
    private function descendantsWithStartTime(int $pid): array
    {
        $descendants = [];
        foreach ($this->descendantPids($pid) as $child) {
            $descendants[$child] = $this->processStartTime($child);
        }
        return $descendants;
    }

    /** @return list<int> */
    private function descendantPids(int $pid): array
    {
        if ($pid <= 0) {
            return [];
        }
        if (PHP_OS_FAMILY === 'Linux') {
            return $this->descendantPidsProc($pid);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return $this->descendantPidsPgrep($pid);
        }
        return [];
    }

    /** @return list<int> */
    private function descendantPidsProc(int $pid): array
    {
        $children = @file_get_contents(sprintf('/proc/%d/task/%d/children', $pid, $pid));
        if (!is_string($children) || trim($children) === '') {
            return [];
        }
        $descendants = [];
        foreach (preg_split('/\s+/', trim($children)) ?: [] as $child) {
            $childPid = (int) $child;
            if ($childPid > 0) {
                $descendants = array_merge($descendants, $this->descendantPidsProc($childPid), [$childPid]);
            }
        }
        return array_values(array_unique($descendants));
    }

    /** @return list<int> */
    private function descendantPidsPgrep(int $pid): array
    {
        $output = [];
        $exit = 0;
        @exec(sprintf('pgrep -P %d 2>/dev/null', $pid), $output, $exit);
        $descendants = [];
        foreach ($output as $line) {
            $childPid = (int) trim($line);
            if ($childPid > 0) {
                $descendants = array_merge($descendants, $this->descendantPidsPgrep($childPid), [$childPid]);
            }
        }
        return array_values(array_unique($descendants));
    }

    private function processStartTime(int $pid): ?int
    {
        if ($pid <= 0 || PHP_OS_FAMILY !== 'Linux') {
            return null;
        }
        $stat = @file_get_contents(sprintf('/proc/%d/stat', $pid));
        if (!is_string($stat)) {
            return null;
        }
        // Field 22 (starttime) is safe to parse positionally from the last ')'
        // because comm (field 2, in parentheses) may itself contain spaces.
        $close = strrpos($stat, ')');
        if ($close === false) {
            return null;
        }
        $fields = preg_split('/\s+/', trim(substr($stat, $close + 1))) ?: [];
        // After the ')' the next field is state (index 0 == field 3); starttime
        // is field 22, i.e. index 19 in this tail slice.
        return isset($fields[19]) ? (int) $fields[19] : null;
    }
}
