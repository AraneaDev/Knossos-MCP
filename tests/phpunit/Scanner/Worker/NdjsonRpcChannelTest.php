<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\NdjsonRpcChannel;
use Knossos\Scanner\Worker\ProcessSupervisorInterface;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerLimits;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class NdjsonRpcChannelTest extends TestCase
{
    private function mockProcess(): ProcessSupervisorInterface
    {
        return new class implements ProcessSupervisorInterface {
            /** @var array<int, resource> */
            public array $pipes = [];

            public bool $started = false;
            public bool $running = true;

            public function start(): void
            {
                $this->started = true;
                if (!isset($this->pipes[0])) {
                    $this->pipes[0] = fopen('php://temp', 'r+');
                    $this->pipes[1] = fopen('php://temp', 'r+');
                    $this->pipes[2] = fopen('php://temp', 'r+');
                }
            }

            public function isRunning(): bool
            {
                return $this->running;
            }

            public function stdin()
            {
                $this->start();
                return $this->pipes[0];
            }

            public function stdout()
            {
                $this->start();
                return $this->pipes[1];
            }

            public function stderr()
            {
                $this->start();
                return $this->pipes[2];
            }

            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array
            {
                return [
                    'command' => '',
                    'pid' => 0,
                    'running' => $this->running,
                    'signaled' => false,
                    'stopped' => false,
                    'exitcode' => -1,
                    'termsig' => 0,
                    'stopsig' => 0,
                ];
            }

            public function close(bool $terminate): void
            {
                foreach ($this->pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                $this->pipes = [];
                $this->running = false;
            }
        };
    }

    private function pipeOnlyProcess(): ProcessSupervisorInterface
    {
        return new class implements ProcessSupervisorInterface {
            /** @var resource|null */
            public $stdinPipe = null;
            /** @var resource|null */
            public $stdoutPipe = null;
            /** @var resource|null */
            public $stderrPipe = null;

            public bool $started = false;

            /** Number of liveness probes, i.e. completed readMessage() loop iterations. */
            public int $statusChecks = 0;

            public function start(): void
            {
                $this->started = true;
            }

            public function isRunning(): bool
            {
                return true;
            }

            /** @return resource|null */
            public function stdin()
            {
                return $this->stdinPipe;
            }

            /** @return resource|null */
            public function stdout()
            {
                return $this->stdoutPipe;
            }

            /** @return resource|null */
            public function stderr()
            {
                return $this->stderrPipe;
            }

            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array
            {
                ++$this->statusChecks;

                return [
                    'command' => '',
                    'pid' => 0,
                    'running' => true,
                    'signaled' => false,
                    'stopped' => false,
                    'exitcode' => -1,
                    'termsig' => 0,
                    'stopsig' => 0,
                ];
            }

            public function close(bool $terminate): void
            {
                $this->stdinPipe = null;
                $this->stdoutPipe = null;
                $this->stderrPipe = null;
            }
        };
    }

    // ----- send() tests -----

    public function testSendEncodesMessageAndWritesToStdin(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits());
        $channel->beginRequest();

        $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']);

        rewind($process->pipes[0]);
        $written = stream_get_contents($process->pipes[0]);
        $expected = '{"jsonrpc":"2.0","method":"ping"}' . "\n";

        assertSame($expected, $written);
    }

    public function testSendRejectsOversizedLine(): void
    {
        $process = $this->mockProcess();
        // The explicit request-frame limit (3rd ctor arg) caps outbound frames
        // independently of the response line/output limits.
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128), 128);
        $channel->beginRequest();

        $error = captureThrows(
            static fn() => $channel->send(['data' => str_repeat('x', 200)]),
            WorkerException::class,
        );

        assertSame('WORKER_REQUEST_TOO_LARGE', $error->diagnosticCode);
    }

    public function testSendAllowsRequestLargerThanResponseLineLimit(): void
    {
        // Request framing is independent of response framing: a request far
        // above the (response) maxLineBytes cap is still written when the
        // request-frame limit permits it, so big/first scans do not hard-fail.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel(
            $process,
            new WorkerLimits(maxLineBytes: 128, maxOutputBytes: 1_000_000),
            maxRequestLineBytes: 1_000_000,
        );
        $channel->beginRequest();

        $payload = str_repeat('x', 4096); // ~4 KB, far above the 128-byte response cap
        $channel->send(['data' => $payload]);

        rewind($process->pipes[0]);
        $written = stream_get_contents($process->pipes[0]);
        assertSame(true, str_contains($written, $payload));
        assertSame(true, str_ends_with($written, "\n"));
    }

    public function testSendThrowsCancelledWhenCallbackReturnsTrue(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits());
        $channel->beginRequest();

        $error = captureThrows(
            static fn() => $channel->send(['jsonrpc' => '2.0', 'method' => 'ping'], static fn(): bool => true),
            WorkerException::class,
        );

        assertSame('WORKER_CANCELLED', $error->diagnosticCode);
    }

    public function testSendTimesOutWhenDeadlineElapsedAndPipeNeverDrains(): void
    {
        // A stdin that is never writable plus a short deadline must surface a
        // bounded WORKER_TIMEOUT rather than blocking forever.
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair)) {
            $this->markTestSkipped('stream_socket_pair is not available on this platform.');
        }
        stream_set_blocking($pair[0], false);
        // Fill the socket buffer so it is not writable.
        @fwrite($pair[0], str_repeat('x', 4_000_000));

        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $pair[0];
        $process->stdoutPipe = fopen('php://temp', 'r+'); // never readable data, but memory-ready
        $process->stderrPipe = fopen('php://temp', 'r+');

        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 1));
        $channel->beginRequest();

        $error = captureThrows(
            static fn() => $channel->send(['data' => str_repeat('y', 8_000_000)]),
            WorkerException::class,
        );

        // Either the buffer is genuinely full (TIMEOUT) or the peer state
        // reports a broken pipe; both are bounded, non-deadlocking outcomes.
        assertSame(true, in_array($error->diagnosticCode, ['WORKER_TIMEOUT', 'WORKER_PIPE_BROKEN'], true));

        fclose($pair[1]);
    }

    public function testSendThrowsOnPipeBroken(): void
    {
        // Use stream_socket_pair to get a real socket pair where writing to
        // a closed socket returns false (simulating a broken pipe).
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair)) {
            $this->markTestSkipped('stream_socket_pair is not available on this platform.');
        }
        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $pair[0];
        $process->stdoutPipe = fopen('php://temp', 'r+');
        $process->stderrPipe = fopen('php://temp', 'r+');

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());
        $channel->beginRequest();

        // Close the read end; writing to the now-orphaned socket returns false.
        fclose($pair[1]);

        $error = captureThrows(
            static fn() => $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']),
            WorkerException::class,
        );

        assertSame('WORKER_PIPE_BROKEN', $error->diagnosticCode);
    }

    // ----- readMessage() tests -----

    public function testReadMessageReturnsParsedJsonRpcMessage(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        $channel->beginRequest();
        fwrite($process->pipes[1], '{"jsonrpc":"2.0","id":1,"result":{"ok":true}}' . "\n");
        fflush($process->pipes[1]);
        rewind($process->pipes[1]);

        // Use a 5-second deadline ceiling so the test fails fast if
        // stream_select blocks (unlikely with pre-written data and a
        // rewound php://temp stream).
        $deadline = hrtime(true) + 5_000_000_000;
        $message = $channel->readMessage($deadline);

        assertSame('2.0', $message['jsonrpc']);
        assertSame(1, $message['id']);
        assertSame(['ok' => true], $message['result']);
    }

    public function testReadMessageThrowsOnCancellation(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        $deadline = $channel->beginRequest();

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline, static function (): bool {
                return true;
            }),
            WorkerException::class,
        );

        assertSame('WORKER_CANCELLED', $error->diagnosticCode);
    }

    public function testReadMessageThrowsOnTimeout(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 1));

        // Use a deadline that's already in the past
        $deadline = $channel->beginRequest() - 1;

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_TIMEOUT', $error->diagnosticCode);
    }

    public function testStderrReturnsEmptyInitially(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        assertSame('', $channel->stderr());
    }

    public function testBeginRequestStartsProcessAndResetsState(): void
    {
        $process = $this->mockProcess();

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        assertSame(false, $process->started);

        $deadline = $channel->beginRequest();

        assertSame(true, $process->started);
        assertSame(true, is_int($deadline));
        assertSame(true, $deadline > 0);
    }

    // ----- extractMessage() edge cases -----

    public function testReadMessageRejectsEmptyLine(): void
    {
        // extractMessage() with $line === '' throws WORKER_FRAME_INVALID.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128));

        $channel->beginRequest();
        fwrite($process->pipes[1], "\n");
        fflush($process->pipes[1]);
        rewind($process->pipes[1]);
        $deadline = hrtime(true) + 5_000_000_000;

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_FRAME_INVALID', $error->diagnosticCode);
    }

    public function testReadMessageRejectsInvalidJson(): void
    {
        // extractMessage() with invalid JSON throws WORKER_JSON_INVALID.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128));

        $channel->beginRequest();
        fwrite($process->pipes[1], "not valid json\n");
        fflush($process->pipes[1]);
        rewind($process->pipes[1]);
        $deadline = hrtime(true) + 5_000_000_000;

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_JSON_INVALID', $error->diagnosticCode);
    }

    public function testReadMessageRejectsNonRpcMessage(): void
    {
        // extractMessage() with valid JSON but not a JSON-RPC object
        // throws WORKER_FRAME_INVALID.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128));

        $channel->beginRequest();
        fwrite($process->pipes[1], "{\"foo\":\"bar\"}\n");
        fflush($process->pipes[1]);
        rewind($process->pipes[1]);
        $deadline = hrtime(true) + 5_000_000_000;

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_FRAME_INVALID', $error->diagnosticCode);
    }

    // ----- output/error limits -----

    public function testReadMessageThrowsOnOutputLimit(): void
    {
        // stdoutBytes > maxOutputBytes throws WORKER_OUTPUT_LIMIT.
        // maxOutputBytes must be >= maxLineBytes which must be >= 128.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128, maxOutputBytes: 128));

        $deadline = $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat('x', 200) . "\n");
        fflush($process->pipes[1]);
        rewind($process->pipes[1]);

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_OUTPUT_LIMIT', $error->diagnosticCode);
    }

    public function testReadMessageThrowsOnStderrLimit(): void
    {
        // appendStderr() when stderrBytes > maxStderrBytes throws
        // WORKER_STDERR_LIMIT.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128, maxStderrBytes: 16));

        $deadline = $channel->beginRequest();
        fwrite($process->pipes[2], str_repeat('e', 50));
        fflush($process->pipes[2]);
        rewind($process->pipes[2]);

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_STDERR_LIMIT', $error->diagnosticCode);
    }

    // ----- WORKER_EXITED -----

    public function testReadMessageThrowsWhenProcessExitsPrematurely(): void
    {
        // When the process stops running, stdout is at EOF, and the
        // buffer is empty, readMessage throws WORKER_EXITED.
        $process = $this->mockProcess();

        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 100));
        $deadline = $channel->beginRequest();

        // Set process as not running AFTER beginRequest (which calls start)
        $process->running = false;
        // Truncate and close the stdout pipe so feof($stdout) is true
        ftruncate($process->pipes[1], 0);
        fclose($process->pipes[1]);
        // Reopen as empty readable stream
        $process->pipes[1] = fopen('php://temp', 'r');
        // feof on an empty php://temp with no data returns true

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_EXITED', $error->diagnosticCode);
    }

    public function testReadMessageThrowsExitedOnPartialLineWithoutBusySpin(): void
    {
        // A worker that crashes mid-line leaves a newline-less partial frame in
        // the buffer. The old code required stdoutBuffer==='' to declare
        // WORKER_EXITED, so this case busy-spun until the (long) timeout and
        // then mislabelled the crash as WORKER_TIMEOUT. It must now surface
        // WORKER_EXITED promptly, well inside a generous deadline.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 60_000));
        $deadline = $channel->beginRequest();

        // Partial frame already delivered on stdout (no trailing newline).
        fwrite($process->pipes[1], '{"jsonrpc":"2.0","id":1,"resul');
        fflush($process->pipes[1]);
        rewind($process->pipes[1]);

        // Worker has since exited (crashed).
        $process->running = false;

        $started = hrtime(true);
        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );
        $elapsedMs = (hrtime(true) - $started) / 1_000_000;

        assertSame('WORKER_EXITED', $error->diagnosticCode);
        // Must not have spun for anywhere near the 60s deadline.
        assertSame(true, $elapsedMs < 1_000);
    }

    // ----- exhausted stderr descriptor -----

    /**
     * A connected socket pair, or a skipped test where the platform lacks one.
     *
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair)) {
            $this->markTestSkipped('stream_socket_pair is not available on this platform.');
        }

        return $pair;
    }

    public function testAnEofStderrDoesNotSpinTheReadLoop(): void
    {
        // A live worker that closed stderr leaves a permanently-ready
        // descriptor: stream_select() returns instantly forever, the read
        // yields '', and the liveness check never fires because the worker is
        // alive. That pinned a core until the deadline.
        $stdoutPair = $this->socketPair();
        $stderrPair = $this->socketPair();
        fclose($stderrPair[1]); // Worker closed its stderr; nothing was written.

        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = fopen('php://temp', 'r+');
        $process->stdoutPipe = $stdoutPair[0]; // Peer alive and silent: never readable.
        $process->stderrPipe = $stderrPair[0];

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        $error = captureThrows(
            static fn() => $channel->readMessage(hrtime(true) + 200_000_000),
            WorkerException::class,
        );

        assertSame('WORKER_TIMEOUT', $error->diagnosticCode);
        // Each loop iteration probes liveness exactly once, so this counts the
        // spin. Dropping the exhausted descriptor leaves a couple of passes.
        assertSame(true, $process->statusChecks < 10);

        fclose($stdoutPair[1]);
    }

    public function testStderrWrittenBeforeCloseIsStillReportedOnTimeout(): void
    {
        // The descriptor must be dropped only once genuinely exhausted, never
        // on a single empty read, or the last words of a dying worker are lost.
        $stdoutPair = $this->socketPair();
        $stderrPair = $this->socketPair();
        fwrite($stderrPair[1], 'ImportError: no module named knossos');
        fclose($stderrPair[1]);

        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = fopen('php://temp', 'r+');
        $process->stdoutPipe = $stdoutPair[0];
        $process->stderrPipe = $stderrPair[0];

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        $error = captureThrows(
            static fn() => $channel->readMessage(hrtime(true) + 200_000_000),
            WorkerException::class,
        );

        assertSame('WORKER_TIMEOUT', $error->diagnosticCode);
        assertSame(true, str_contains($error->getMessage(), 'ImportError: no module named knossos'));
        assertSame('ImportError: no module named knossos', $channel->stderr());
        assertSame(true, $process->statusChecks < 10);

        fclose($stdoutPair[1]);
    }

    public function testAnEofStderrDoesNotSpinTheSendLoop(): void
    {
        // send() drains the worker's pipes while waiting for stdin to accept
        // more bytes, and had the same permanently-ready-descriptor hazard.
        $stdinPair = $this->socketPair();
        stream_set_blocking($stdinPair[0], false);
        @fwrite($stdinPair[0], str_repeat('x', 4_000_000)); // Fill it: never writable.
        $stdoutPair = $this->socketPair();
        $stderrPair = $this->socketPair();
        fwrite($stderrPair[1], 'warming up');
        fclose($stderrPair[1]);

        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $stdinPair[0];
        $process->stdoutPipe = $stdoutPair[0];
        $process->stderrPipe = $stderrPair[0];

        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 200));
        $channel->beginRequest();

        // The cancellation probe runs once per loop pass, so it counts the spin.
        $passes = 0;
        $error = captureThrows(
            static function () use ($channel, &$passes): void {
                $channel->send(['data' => str_repeat('y', 8_000_000)], static function () use (&$passes): bool {
                    ++$passes;

                    return false;
                });
            },
            WorkerException::class,
        );

        assertSame(true, in_array($error->diagnosticCode, ['WORKER_TIMEOUT', 'WORKER_PIPE_BROKEN'], true));
        assertSame(true, $passes < 25);
        assertSame('warming up', $channel->stderr());

        fclose($stdinPair[1]);
        fclose($stdoutPair[1]);
    }
}
