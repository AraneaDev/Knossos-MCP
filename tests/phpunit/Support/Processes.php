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
     */
    public static function processState(int $pid): string
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        $close = is_string($stat) ? strrpos($stat, ')') : false;
        if (!is_string($stat) || $close === false) {
            return 'gone';
        }
        $state = substr($stat, $close + 2, 1);

        return $state === 'Z' ? 'gone' : $state;
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
