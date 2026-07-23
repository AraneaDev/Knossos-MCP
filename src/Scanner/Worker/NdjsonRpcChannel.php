<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use JsonException;

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
            $read = [$stdout, $stderr];
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

            foreach ($read as $stream) {
                $this->absorb($stream, $stderr);
            }

            if ($write === []) {
                continue;
            }
            $bytes = @fwrite($stdin, substr($line, $written));
            if ($bytes === false) {
                throw new WorkerException('WORKER_PIPE_BROKEN', 'Unable to write to scanner worker.');
            }
            $written += $bytes;
        }
        @fflush($stdin);
    }

    /** @return array<string, mixed> */
    public function readMessage(int $deadline, ?callable $cancelled = null): array
    {
        $stdout = $this->process->stdout();
        $stderr = $this->process->stderr();
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

            $read = [$stdout, $stderr];
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
     */
    private function absorb($stream, $stderr): void
    {
        $chunk = fread($stream, 8192);
        if ($chunk === false || $chunk === '') {
            return;
        }
        if ($stream === $stderr) {
            $this->appendStderr($chunk);
            return;
        }
        $this->appendStdout($chunk);
    }

    /** @return array<string, mixed>|null */
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

    private function appendStdout(string $chunk): void
    {
        $this->stdoutBuffer .= $chunk;
        $this->stdoutBytes += strlen($chunk);
        if ($this->stdoutBytes > $this->limits->maxOutputBytes) {
            throw new WorkerException('WORKER_OUTPUT_LIMIT', 'Worker output exceeds the request limit.');
        }
    }

    private function appendStderr(string $chunk): void
    {
        $this->stderrBytes += strlen($chunk);
        if ($this->stderrBytes > $this->limits->maxStderrBytes) {
            throw new WorkerException('WORKER_STDERR_LIMIT', 'Worker stderr exceeds the request limit.');
        }
        $this->stderrBuffer .= $chunk;
    }

    private function withStderr(string $message): string
    {
        $stderr = trim($this->stderrBuffer);
        return $stderr === '' ? $message : $message . ' Worker stderr: ' . $stderr;
    }
}
