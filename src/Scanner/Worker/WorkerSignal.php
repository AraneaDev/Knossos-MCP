<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * What the signal that ended a worker says about who ended it.
 *
 * Knossos never signals a worker that still owes a response, so any signal
 * seen mid-request came from somewhere else. Where it came from decides both
 * the wording and whether a retry can help:
 *
 * - SIGTERM and SIGKILL are what a memory guard (earlyoom, systemd-oomd) or the
 *   kernel's OOM killer send. A fresh worker on a smaller batch needs less
 *   memory at its peak, so these are retried.
 * - SIGHUP and SIGINT come from a person or a supervisor. The worker runs in
 *   its own session with no controlling terminal, so neither a closed terminal
 *   nor Ctrl-C reaches it by accident. Someone meant to stop it, and retrying
 *   would fight them, so these are not retried.
 * - Anything else (SIGSEGV, SIGABRT, SIGBUS, SIGILL, SIGFPE, SIGPIPE, ...) is
 *   what a process raises against itself when it crashes. A smaller batch does
 *   not stop a crash, so these are not retried either.
 */
final class WorkerSignal
{
    private const NAMES = [
        1 => 'SIGHUP',
        2 => 'SIGINT',
        4 => 'SIGILL',
        6 => 'SIGABRT',
        7 => 'SIGBUS',
        8 => 'SIGFPE',
        9 => 'SIGKILL',
        11 => 'SIGSEGV',
        13 => 'SIGPIPE',
        15 => 'SIGTERM',
    ];

    /** Whether the signal is one a host memory guard or the OOM killer sends. */
    public static function isMemoryPressureKill(int $signal): bool
    {
        return $signal === 9 || $signal === 15;
    }

    /** Whether the signal is one only a person or a supervisor sends on purpose. */
    public static function isDeliberateStop(int $signal): bool
    {
        return $signal === 1 || $signal === 2;
    }

    /** Why a worker that died of this signal before responding is gone, as one or two sentences. */
    public static function describe(int $signal): string
    {
        $named = isset(self::NAMES[$signal]) ? sprintf('%d (%s)', $signal, self::NAMES[$signal]) : (string) $signal;
        if (self::isMemoryPressureKill($signal)) {
            return sprintf(
                'Scanner worker was killed by signal %s before responding, and Knossos did not send it: Knossos stops '
                . 'a worker only after a request has already failed, and reports that reason instead. A host memory '
                . 'guard such as earlyoom or systemd-oomd sends SIGTERM to the largest process when memory runs low; '
                . "the kernel's own OOM killer sends SIGKILL (9).",
                $named,
            );
        }
        if (self::isDeliberateStop($signal)) {
            return sprintf(
                'Scanner worker was stopped by signal %s before responding, and Knossos did not send it. The worker '
                . 'runs in its own session with no terminal, so this signal came from a person or a supervisor.',
                $named,
            );
        }

        return sprintf('Scanner worker crashed with signal %s before responding.', $named);
    }
}
