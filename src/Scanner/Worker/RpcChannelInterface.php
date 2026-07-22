<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

/**
 * Abstraction over an NDJSON-RPC channel to a scanner worker process.
 * The sole production implementation is NdjsonRpcChannel; tests provide
 * anonymous or stub implementations to exercise protocol error paths
 * without a real process.
 */
interface RpcChannelInterface
{
    /**
     * Start the underlying process (if not already running) and reset
     * I/O buffers. Returns the absolute deadline (hrtime nanoseconds)
     * for read operations.
     */
    public function beginRequest(): int;

    /**
     * Serialise $message as NDJSON and write it to the channel.
     *
     * @param array<string, mixed> $message
     */
    public function send(array $message): void;

    /**
     * Read and parse one JSON-RPC message from the channel within the
     * remaining deadline window. Returns the decoded associative array.
     *
     * @param callable(): bool|null $cancelled
     * @return array<string, mixed>
     */
    public function readMessage(int $deadline, ?callable $cancelled = null): array;

    /** Accumulated stderr content from the worker process. */
    public function stderr(): string;
}
