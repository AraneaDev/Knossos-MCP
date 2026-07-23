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
     * The write is driven by the request deadline captured in
     * {@see beginRequest()}: a large frame is streamed with a select loop
     * that drains the worker's stdout/stderr between partial writes (so a
     * worker blocked on its own output cannot deadlock the parent) and polls
     * the optional cancellation callback.
     *
     * @param array<string, mixed> $message
     * @param callable(): bool|null $cancelled
     */
    public function send(array $message, ?callable $cancelled = null): void;

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
