<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

/**
 * Host-process and host-binary lookups shared by the tests that spawn or
 * inspect real processes.
 *
 * Each of these was written out separately in two test classes, so a change to
 * the rule — which `setsid` paths count, how a zombie is told from a live
 * process — had to be made twice and was only ever made once.
 */
trait Processes
{
    /** The git binary, or null when the host has none. */
    public static function locateGit(): ?string
    {
        $path = trim((string) @shell_exec('command -v git 2>/dev/null'));

        return $path === '' ? null : $path;
    }

    /**
     * Whether the host has a `setsid` where WorkerProcessSupervisor looks for
     * one.
     *
     * The same two candidates {@see \Knossos\Scanner\Worker\WorkerProcessSupervisor::setsidPath()}
     * accepts: a test that checks only `/usr/bin/setsid` skips on a host that
     * provides `/bin/setsid`, where the mechanism under test works fine and the
     * regression it pins therefore goes uncovered.
     */
    public static function hasSetsid(): bool
    {
        return is_executable('/usr/bin/setsid') || is_executable('/bin/setsid');
    }

    /** Whether this host exposes the procfs that {@see processState()} prefers. */
    private static function hasProcfs(): bool
    {
        return @is_readable('/proc/self/stat');
    }

    /**
     * Whether this host can tell a live process from a terminated one at all.
     *
     * A liveness assertion is only worth making where the answer can be wrong.
     * Without procfs and without a BSD-style `ps`, every probe below reports
     * 'gone' unconditionally — so `assertSame(false, processIsAlive($pid))`
     * would pass on a host where the process is still running, which is the
     * exact failure those assertions exist to catch. Tests guard on this and
     * skip rather than record a pass they did not earn.
     *
     * Probed against this very process, which is by definition alive: a probe
     * that cannot see its own caller is not a working probe, whatever the
     * platform claims.
     */
    public static function hasProcessStateProbe(): bool
    {
        return self::hasProcfs() || self::psState((int) getmypid()) !== 'gone';
    }

    /**
     * A process's scheduler state letter, or 'gone'.
     *
     * `posix_kill($pid, 0)` and `/proc/$pid` both still report a zombie as
     * present, so neither can answer "was it terminated" — only "has its parent
     * got round to reaping it". The state letter separates the two.
     *
     * The state is the field after the executable name, which is itself
     * parenthesised and may contain parentheses and spaces, so it is found from
     * the LAST `)` rather than by splitting on whitespace.
     *
     * procfs is consulted only where procfs exists, rather than as a first
     * attempt that falls through on failure: on Linux an unreadable
     * `/proc/<pid>/stat` is the answer — that process is gone — and treating it
     * as a failed lookup would spawn a `ps` per call, which the callers that
     * poll this every 10ms for up to ten seconds cannot afford.
     */
    public static function processState(int $pid): string
    {
        if (!self::hasProcfs()) {
            return self::psState($pid);
        }
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        $close = is_string($stat) ? strrpos($stat, ')') : false;
        if (!is_string($stat) || $close === false) {
            return 'gone';
        }
        $state = substr($stat, $close + 2, 1);

        return $state === 'Z' ? 'gone' : $state;
    }

    /**
     * The same state letter via `ps`, for hosts without procfs — macOS above
     * all, where `/proc` does not exist and the procfs branch would otherwise
     * report every process, running or not, as 'gone'.
     *
     * BSD `ps` decorates the state with flags (`S+`, `Ss`, `R<`), so only the
     * first character is the state proper. Empty output means `ps` knows of no
     * such pid.
     */
    private static function psState(int $pid): string
    {
        $output = @shell_exec('ps -o state= -p ' . $pid . ' 2>/dev/null');
        $state = is_string($output) ? substr(trim($output), 0, 1) : '';

        return $state === '' || $state === 'Z' ? 'gone' : $state;
    }

    /**
     * Whether a pid names a process that is still running, as opposed to one
     * that has been killed and is waiting to be reaped.
     */
    public static function processIsAlive(int $pid): bool
    {
        return self::processState($pid) !== 'gone';
    }
}
