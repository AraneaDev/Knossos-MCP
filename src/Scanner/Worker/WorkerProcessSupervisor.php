<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * Owns a worker's OS process and pipes.
 *
 * Termination is deliberate: a worker that ignores a graceful stop is signalled,
 * because a scan must not leave orphaned analysers holding memory after it exits.
 */
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
    /** Terminate the process tree, so an abandoned supervisor leaves nothing running. */

    public function __destruct()
    {
        $this->close(true);
    }

    /** {@inheritDoc} */
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
        $setsid = self::setsidPath();
        $environment = $this->resolveEnvironment();
        // Checked before spawning rather than relying on proc_open() to fail:
        // once the command is wrapped (below) it is `setsid` that proc_open
        // starts, and setsid starts perfectly well before failing to exec a
        // command that does not exist. Without this, an unrunnable worker
        // stopped being a loud WORKER_START_FAILED at spawn and became a
        // protocol timeout thirty seconds later.
        if (!self::isRunnable($this->command[0], $environment['PATH'] ?? '')) {
            throw new WorkerException('WORKER_START_FAILED', 'Unable to start scanner worker.');
        }
        // A neutral working directory and an explicit, minimal environment keep
        // the untrusted-source parser from inheriting the server's cwd, PATH
        // secrets, DB credentials, or tokens.
        $process = @proc_open(
            $setsid === null ? $this->command : [$setsid, ...$this->command],
            $descriptors,
            $pipes,
            $this->workingDirectory(),
            $environment,
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

        $this->placeInOwnProcessGroup($setsid !== null);
    }

    /** {@inheritDoc} */
    public function isRunning(): bool
    {
        return $this->process !== null;
    }

    /**
     * The worker's standard input.
     *
     * @return resource
     */
    public function stdin()
    {
        $this->start();
        return $this->pipes[0];
    }

    /**
     * The worker's standard output, carrying protocol frames only.
     *
     * @return resource
     */
    public function stdout()
    {
        $this->start();
        return $this->pipes[1];
    }

    /**
     * The worker's standard error, drained so a chatty process cannot block on a full pipe.
     *
     * @return resource
     */
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

    /** {@inheritDoc} */
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
    /** Stop the worker and everything it spawned, so a scan leaves no orphaned analysers holding memory. */

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
    /** Signal the process group, escalating only after a graceful stop was given a chance. */

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
    /**
     * Whether a command names something this host can actually execute.
     *
     * Deliberately permissive where it cannot know: a bare name with no PATH to
     * search against is accepted rather than rejected, because the child's own
     * `execvp()` has resolution rules of its own and a false rejection here
     * would refuse to start a worker that works. It exists to keep the one
     * answer it CAN give with certainty — this path does not exist, or is not
     * executable — as loud as it was before the spawn was wrapped.
     */
    private static function isRunnable(string $binary, string $path): bool
    {
        if ($binary === '') {
            return false;
        }
        if (str_contains($binary, DIRECTORY_SEPARATOR) || str_contains($binary, '/')) {
            return is_executable($binary);
        }
        if ($path === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if ($directory !== '' && is_executable(rtrim($directory, '/') . '/' . $binary)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `setsid`, so the worker leads its own session and process group from the
     * first instruction it executes — or null where the binary is unavailable.
     *
     * This is what makes the group-directed kill in {@see self::signalTree()}
     * real. Placing the child in its own group from the parent afterwards
     * cannot work: `proc_open()` returns once the fork has happened, the child
     * reaches `execve()` first, and `setpgid()` on an already-exec'd child is
     * refused with EACCES — measured at 40 failures out of 40 spawns on this
     * host, i.e. always. The group was therefore never established, leaving a
     * point-in-time walk of `/proc/<worker>/task/<worker>/children` as the only
     * reaper, and that walk loses to both cases that matter: a worker that
     * spawns an analyser while it is being terminated, and a worker that exits
     * before the walk runs, whose children are re-parented to init the instant
     * it dies and are then unreachable from its pid. A process group has
     * neither problem — it outlives its leader for as long as any member
     * remains, so the escalation to SIGKILL still reaches everything.
     *
     * `setsid` execs in place when its caller is not already a group leader,
     * which a `proc_open()` child never is, so the pid, the pipes and
     * `proc_get_status()` all still describe the worker itself. Absent the
     * binary the command is spawned unchanged and termination falls back to
     * the descendant walk, exactly as before. An absolute path rather than a
     * PATH lookup: the worker environment is a deliberate allow-list and the
     * spawn boundary is not the place to start resolving names.
     */
    private static function setsidPath(): ?string
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return null;
        }
        foreach (['/usr/bin/setsid', '/bin/setsid'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * Record the worker's own process group, so signalling it cannot reach this
     * process or its siblings.
     *
     * The group is only ever recorded once the worker has been OBSERVED to lead
     * it. That check is not optional bookkeeping: `posix_kill(-$pgid, SIGKILL)`
     * against an unverified group id is a kill of whatever group the child
     * happens to be in, which without `setsid` is the parent's own.
     *
     * @param bool $viaSetsid whether the spawn was wrapped, in which case the
     *     group appears a moment after `proc_open()` returns rather than at
     *     once — `setsid` is a program that has to be scheduled and reach its
     *     own `setsid()` call first. Measured at 0.28-0.92 ms across 30 spawns
     *     (median 0.55), so the ceiling below is some 270x the worst case
     *     observed and is not a timing assumption so much as a refusal to wait
     *     forever. Unwrapped spawns never wait at all: nothing would change.
     */
    private function placeInOwnProcessGroup(bool $viaSetsid): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_setpgid') || !function_exists('posix_getpgid')) {
            return;
        }
        $status = proc_get_status($this->process);
        $pid = (int) $status['pid'];
        if ($pid <= 1) {
            return;
        }
        // Best effort, and on Linux always refused: kept because it costs one
        // failed syscall and wins outright on any platform where the child has
        // not exec'd yet.
        @posix_setpgid($pid, $pid);
        $pgid = @posix_getpgid($pid);
        $deadline = hrtime(true) + 250_000_000;
        while ($viaSetsid && $pgid !== $pid && hrtime(true) < $deadline) {
            usleep(200);
            $pgid = @posix_getpgid($pid);
        }
        if ($pgid === $pid) {
            $this->processGroupId = $pid;
        }
    }
    /** The directory the worker runs in, fixed so relative evidence paths mean the same thing to both sides. */

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

    /**
     * Child process ids, gathered so termination covers the whole tree rather than just the parent.
     *
     * @return list<int>
     */
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

    /**
     * Read descendants from /proc, the reliable route where it is mounted.
     *
     * @return list<int>
     */
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

    /**
     * Fall back to pgrep where /proc is unavailable, such as inside some containers.
     *
     * @return list<int>
     */
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
    /** A process's start time, used to confirm a pid was not recycled before signalling it. */

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
