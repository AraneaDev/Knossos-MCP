<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * Abstraction over an OS process used by the NDJSON RPC channel and the
 * protocol session. The sole production implementation is
 * WorkerProcessSupervisor; tests provide anonymous or stub implementations
 * to exercise error paths that require OS-level conditions.
 */
interface ProcessSupervisorInterface
{
    /** Spawn the process. Throws rather than returning a half-started supervisor. */
    public function start(): void;

    /** Whether the process is still alive, as of the last status refresh. */
    public function isRunning(): bool;

    /**
     * The process's standard input, for writing requests.
     *
     * @return resource
     */
    public function stdin();

    /**
     * The process's standard output, carrying protocol frames only.
     *
     * @return resource
     */
    public function stdout();

    /**
     * The process's standard error, drained so a chatty worker cannot block on a full pipe.
     *
     * @return resource
     */
    public function stderr();

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
    public function status(): array;

    /**
     * Release the process and its pipes.
     *
     * @param bool $terminate true to signal the process rather than waiting for it
     *                        to exit on its own — used when a deadline has passed.
     */
    public function close(bool $terminate): void;
}
