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
    public function start(): void;

    public function isRunning(): bool;

    /** @return resource */
    public function stdin();

    /** @return resource */
    public function stdout();

    /** @return resource */
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

    public function close(bool $terminate): void;
}
