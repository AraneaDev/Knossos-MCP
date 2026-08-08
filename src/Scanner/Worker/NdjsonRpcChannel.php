<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use JsonException;

/**
 * Newline-delimited JSON-RPC over a worker's pipes.
 *
 * Reads are non-blocking with a deadline and a size cap, because a worker that
 * floods stdout or stops answering must not hang the scan. stderr is drained
 * alongside so a chatty worker cannot deadlock on a full pipe.
 */
final class NdjsonRpcChannel implements RpcChannelInterface
{
    private string $stdoutBuffer = '';
    private string $stderrBuffer = '';
    private int $stdoutBytes = 0;
    private int $stderrBytes = 0;
    private int $deadline = 0;

    /**
     * Maximum size of an outbound request frame. Kept independent of the
     * response frame limit ({@see WorkerLimits::$maxLineBytes}) so a large
     * scan request is not rejected against — nor gated by — the tighter cap
     * used to bound a single worker response line.
     */
    private readonly int $maxRequestLineBytes;

    public function __construct(
        private readonly ProcessSupervisorInterface $process,
        private readonly WorkerLimits $limits,
        ?int $maxRequestLineBytes = null,
    ) {
        $this->maxRequestLineBytes = $maxRequestLineBytes ?? max($limits->maxLineBytes, $limits->maxOutputBytes);
    }

    /** {@inheritDoc} */
    public function beginRequest(): int
    {
        $this->process->start();
        $this->stdoutBuffer = '';
        $this->stdoutBytes = 0;
        $this->stderrBuffer = '';
        $this->stderrBytes = 0;

        return $this->deadline = hrtime(true) + ($this->limits->requestTimeoutMs * 1_000_000);
    }

    /**
     * Write one request frame to the worker.
     *
     * @param array<string, mixed> $message
     * @param callable(): bool|null $cancelled
     */
    public function send(array $message, ?callable $cancelled = null): void
    {
        try {
            $line = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        } catch (JsonException $error) {
            throw new WorkerException('WORKER_REQUEST_INVALID', $error->getMessage(), $error);
        }
        $length = strlen($line);
        if ($length > $this->maxRequestLineBytes) {
            throw new WorkerException('WORKER_REQUEST_TOO_LARGE', 'Worker request exceeds the request frame limit.');
        }

        $stdin = $this->process->stdin();
        $stdout = $this->process->stdout();
        $stderr = $this->process->stderr();
        // An exhausted descriptor is permanently "ready", so a worker that
        // closed one of its output pipes while the parent is still writing
        // would make every stream_select() return instantly and turn this wait
        // into a busy loop — and with $cancelled === null there is no 100 ms
        // cap on the wait either, so that spin runs for the whole request
        // timeout. Track both output descriptors and drop each from the select
        // set once it is genuinely at EOF — never merely because one read came
        // back empty, or the last diagnostics of a dying worker would be lost.
        $stderrOpen = true;
        $stdoutOpen = true;
        $written = 0;
        while ($written < $length) {
            if ($cancelled !== null && $cancelled()) {
                throw new WorkerException('WORKER_CANCELLED', 'Scanner worker request was cancelled.');
            }

            $remaining = $this->deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new WorkerException('WORKER_TIMEOUT', $this->withStderr('Scanner worker request timed out while sending.'));
            }

            // Watch stdin for writability while simultaneously draining the
            // worker's stdout/stderr, so a worker blocked writing to its own
            // (bounded) output pipe cannot deadlock a >64 KB parent write.
            // `null`, not `[]`, once both are exhausted: stream_select() still
            // has the writable $stdin to wait on and stays bounded by the
            // timeout, but an empty array would be a third state to reason
            // about at every use of $read below.
            $read = match (true) {
                $stdoutOpen && $stderrOpen => [$stdout, $stderr],
                $stdoutOpen => [$stdout],
                $stderrOpen => [$stderr],
                default => null,
            };
            $write = [$stdin];
            $except = null;
            $wait = $cancelled === null ? $remaining : min($remaining, 100_000_000);
            $selected = @stream_select(
                $read,
                $write,
                $except,
                intdiv($wait, 1_000_000_000),
                intdiv($wait % 1_000_000_000, 1_000),
            );
            if ($selected === false) {
                throw new WorkerException('WORKER_IO_FAILED', 'Unable to write to scanner worker.');
            }
            if ($selected === 0) {
                continue;
            }

            foreach ($read ?? [] as $stream) {
                if (!$this->absorb($stream, $stderr)) {
                    continue;
                }
                if ($stream === $stderr) {
                    $stderrOpen = false;
                } else {
                    $stdoutOpen = false;
                }
            }

            foreach ($write as $writable) {
                $bytes = @fwrite($writable, substr($line, $written));
                if ($bytes === false) {
                    throw new WorkerException('WORKER_PIPE_BROKEN', 'Unable to write to scanner worker.');
                }
                $written += $bytes;
            }
        }
        @fflush($stdin);
    }

    /**
     * Read one reply within the deadline, without blocking indefinitely on a silent worker.
     *
     * @return array<string, mixed>
     */
    public function readMessage(int $deadline, ?callable $cancelled = null): array
    {
        $stdout = $this->process->stdout();
        $stderr = $this->process->stderr();
        // An EOF descriptor is permanently "ready", so keeping a closed stderr
        // in the select set turns this wait into a busy loop against a worker
        // that is still alive — the liveness check below never fires for it.
        // Drop stderr once exhausted; stdout's own EOF is handled by that
        // check. The flag is per-call, so a restarted worker (an adaptive
        // budget retry) is polled on its fresh stderr again.
        $stderrOpen = true;
        while (true) {
            if ($cancelled !== null && $cancelled()) {
                throw new WorkerException('WORKER_CANCELLED', 'Scanner worker request was cancelled.');
            }
            $message = $this->extractMessage();
            if ($message !== null) {
                return $message;
            }
            if (strlen($this->stdoutBuffer) > $this->limits->maxLineBytes) {
                throw new WorkerException('WORKER_FRAME_TOO_LARGE', 'Worker frame exceeds the line limit.');
            }

            $remaining = $deadline - hrtime(true);
            if ($remaining <= 0) {
                throw new WorkerException('WORKER_TIMEOUT', $this->withStderr('Scanner worker request timed out.'));
            }

            $read = $stderrOpen ? [$stdout, $stderr] : [$stdout];
            $write = null;
            $except = null;
            $wait = $cancelled === null ? $remaining : min($remaining, 100_000_000);
            $selected = @stream_select(
                $read,
                $write,
                $except,
                intdiv($wait, 1_000_000_000),
                intdiv($wait % 1_000_000_000, 1_000),
            );
            if ($selected === false) {
                throw new WorkerException('WORKER_IO_FAILED', 'Unable to read scanner worker pipes.');
            }
            if ($selected === 0 && $cancelled !== null) {
                continue;
            }
            if ($selected === 0) {
                throw new WorkerException('WORKER_TIMEOUT', $this->withStderr('Scanner worker request timed out.'));
            }

            foreach ($read as $stream) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new WorkerException('WORKER_IO_FAILED', 'Unable to read scanner worker output.');
                }
                if ($stream === $stderr) {
                    if (self::isExhausted($stderr, $chunk)) {
                        // Exhausted, not merely quiet: everything the worker
                        // wrote has been drained, so stop selecting on it.
                        $stderrOpen = false;
                        continue;
                    }
                    $this->appendStderr($chunk);
                    continue;
                }
                if ($chunk === '') {
                    // Empty read on stdout: end-of-stream, not necessarily "no
                    // data yet". The terminal check below decides based on the
                    // worker's liveness so we never busy-spin on an EOF fd.
                    continue;
                }
                $this->appendStdout($chunk);
            }

            // A worker that exited (crashed) without a terminating newline can
            // never complete the frame — surface WORKER_EXITED immediately
            // instead of busy-spinning on the instantly-ready EOF fd until the
            // deadline and mislabelling the crash as TIMEOUT. The buffer here is
            // only ever a partial frame (a complete one was returned by
            // extractMessage() above), so the old stdoutBuffer==='' guard is
            // intentionally dropped.
            $status = $this->process->status();
            if (!$status['running'] && feof($stdout)) {
                throw new WorkerException(
                    'WORKER_EXITED',
                    $this->withStderr(sprintf('Scanner worker exited before responding (exit %d).', $status['exitcode'])),
                );
            }
        }
    }

    /** {@inheritDoc} */
    public function stderr(): string
    {
        return $this->stderrBuffer;
    }

    /**
     * Drain a readable stream during a send(): stderr is accumulated for
     * diagnostics, stdout is buffered for the pending response.
     *
     * @param resource $stream
     * @param resource $stderr
     * @return bool True once the stream is exhausted — an empty read at EOF —
     *              so the caller may drop the descriptor from its select set.
     */
    private function absorb($stream, $stderr): bool
    {
        $chunk = fread($stream, 8192);
        if ($chunk === false || $chunk === '') {
            return is_string($chunk) && self::isExhausted($stream, $chunk);
        }
        if ($stream === $stderr) {
            $this->appendStderr($chunk);
            return false;
        }
        $this->appendStdout($chunk);

        return false;
    }

    /**
     * Whether a drained descriptor is genuinely finished — an empty read *at
     * EOF* — rather than merely quiet for one pass.
     *
     * Both conditions matter and in opposite directions. Drop a descriptor that
     * is only quiet and the last words of a dying worker are lost, which is
     * usually the only clue to why it died. Keep one that is exhausted and
     * stream_select() reports it ready forever, turning the wait into a spin
     * that pins a core until the deadline.
     *
     * Extracted so the rule lives once and can be pinned once: its two callers
     * both reach it through stream_select(), and no in-process test can make
     * select() report a descriptor ready that then yields an empty read while
     * still open — the very case the feof() term exists for. Dropping that term
     * therefore left the whole suite green.
     *
     * @param resource $stream
     */
    private static function isExhausted($stream, string $chunk): bool
    {
        return $chunk === '' && feof($stream);
    }

    /**
     * Take a complete frame from the buffer, leaving any partial remainder.
     *
     * @return array<string, mixed>|null
     */
    private function extractMessage(): ?array
    {
        $newline = strpos($this->stdoutBuffer, "\n");
        if ($newline === false) {
            return null;
        }
        $line = substr($this->stdoutBuffer, 0, $newline);
        $this->stdoutBuffer = substr($this->stdoutBuffer, $newline + 1);
        if ($line === '' || strlen($line) > $this->limits->maxLineBytes) {
            throw new WorkerException('WORKER_FRAME_INVALID', 'Worker emitted an empty or oversized frame.');
        }

        try {
            $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new WorkerException('WORKER_JSON_INVALID', 'Worker emitted invalid JSON.', $error);
        }
        if (!is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            throw new WorkerException('WORKER_FRAME_INVALID', 'Worker emitted an invalid JSON-RPC object.');
        }

        return $message;
    }
    /** Buffer stdout, enforcing the byte cap so a flooding worker cannot exhaust memory. */

    private function appendStdout(string $chunk): void
    {
        $this->stdoutBuffer .= $chunk;
        $this->stdoutBytes += strlen($chunk);
        if ($this->stdoutBytes > $this->limits->maxOutputBytes) {
            throw new WorkerException('WORKER_OUTPUT_LIMIT', 'Worker output exceeds the request limit.');
        }
    }
    /** Buffer stderr under its own cap, so diagnostics survive without competing with frames. */

    private function appendStderr(string $chunk): void
    {
        $this->stderrBytes += strlen($chunk);
        if ($this->stderrBytes > $this->limits->maxStderrBytes) {
            throw new WorkerException('WORKER_STDERR_LIMIT', 'Worker stderr exceeds the request limit.');
        }
        $this->stderrBuffer .= $chunk;
    }
    /** Attach the captured stderr to an error, which is usually the only clue to why a worker failed. */

    private function withStderr(string $message): string
    {
        $stderr = trim($this->stderrBuffer);
        return $stderr === '' ? $message : $message . ' Worker stderr: ' . $stderr;
    }
}
