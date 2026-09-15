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
            // How the process ended, so a test can distinguish a worker that
            // chose its exit code from one something else killed.
            public bool $signaled = false;
            public int $termsig = 0;
            public int $exitcode = -1;

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
                    'signaled' => $this->signaled,
                    'stopped' => false,
                    'exitcode' => $this->exitcode,
                    'termsig' => $this->termsig,
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

    public function testSendReportsAFailedReadInsteadOfKeepingTheDescriptor(): void
    {
        // A read that fails outright is an I/O failure, not a quiet
        // descriptor. Answered as "not exhausted" the stream stayed in the
        // select set, and one that still reports ready spun until the deadline
        // and surfaced the error as a TIMEOUT.
        //
        // Reached through reflection because stream_select() will not hand
        // send() a descriptor whose read fails: the one portable way to make
        // fread() fail — a write-only handle — is exactly what select()
        // reports as never readable.
        $path = tempnam(sys_get_temp_dir(), 'knossos-absorb-');
        $writeOnly = fopen($path, 'w');
        $stderr = fopen('php://temp', 'r+');
        if (@fread($writeOnly, 8) !== false) {
            fclose($writeOnly);
            fclose($stderr);
            unlink($path);
            $this->markTestSkipped('This platform does not fail reads on a write-only handle.');
        }

        $channel = new NdjsonRpcChannel($this->mockProcess(), new WorkerLimits());
        $absorb = new \ReflectionMethod($channel, 'absorb');

        $error = captureThrows(
            static fn() => $absorb->invoke($channel, $writeOnly, $stderr),
            WorkerException::class,
        );

        assertSame('WORKER_IO_FAILED', $error->diagnosticCode);

        fclose($writeOnly);
        fclose($stderr);
        unlink($path);
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

    /**
     * A write fails because the worker is gone, and what it printed on the way
     * out is the only account of why. The worker dying of heap exhaustion and
     * the host being unable to write to it are the same event seen from the
     * two ends, so reporting only the host's end ("Unable to write to scanner
     * worker") names the symptom and drops the cause.
     */
    public function testPipeBrokenReportsWhatTheWorkerPrintedBeforeItDied(): void
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair)) {
            $this->markTestSkipped('stream_socket_pair is not available on this platform.');
        }
        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $pair[0];
        $process->stdoutPipe = fopen('php://temp', 'r+');

        $stderr = fopen('php://temp', 'r+');
        fwrite($stderr, "FATAL ERROR: JavaScript heap out of memory\n");
        rewind($stderr);
        $process->stderrPipe = $stderr;

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());
        $channel->beginRequest();
        fclose($pair[1]);

        $error = captureThrows(
            static fn() => $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']),
            WorkerException::class,
        );

        assertSame('WORKER_PIPE_BROKEN', $error->diagnosticCode);
        assertContains('JavaScript heap out of memory', $error->getMessage());
    }

    /**
     * The fatal is printed in one request and discovered in the next.
     *
     * V8 reports heap exhaustion, the process aborts, and the host only learns
     * of it when the following send hits a broken pipe. Clearing the buffer at
     * the start of that request threw away the one line explaining the death,
     * so a real scan failed repeatedly with "Unable to write to scanner
     * worker" and nothing else.
     */
    public function testAWorkersDyingWordsSurviveIntoTheRequestThatFindsItGone(): void
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair)) {
            $this->markTestSkipped('stream_socket_pair is not available on this platform.');
        }
        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $pair[0];
        $process->stdoutPipe = fopen('php://temp', 'r+');

        $stderr = fopen('php://temp', 'r+');
        fwrite($stderr, "FATAL ERROR: JavaScript heap out of memory\n");
        rewind($stderr);
        $process->stderrPipe = $stderr;

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        // The request in which the worker printed and died. The send succeeds;
        // the pipe is still open.
        $channel->beginRequest();
        $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']);

        // The next request, which is where the death is discovered.
        $channel->beginRequest();
        fclose($pair[1]);
        $error = captureThrows(
            static fn() => $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']),
            WorkerException::class,
        );

        assertSame('WORKER_PIPE_BROKEN', $error->diagnosticCode);
        assertContains('JavaScript heap out of memory', $error->getMessage());
        assertContains('before it died', $error->getMessage());
    }

    public function testStderrFromTwoRequestsAgoIsNotBlamedOnThisFailure(): void
    {
        // Keeping the last NON-EMPTY output meant a request that printed, a
        // silent one after it, and then a failure would report the first
        // request's words as the dying words of the third. A silent request
        // is evidence there was nothing to say, not a reason to reach further
        // back.
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($pair)) {
            $this->markTestSkipped('stream_socket_pair is not available on this platform.');
        }
        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $pair[0];
        $process->stdoutPipe = fopen('php://temp', 'r+');

        $stderr = fopen('php://temp', 'r+');
        fwrite($stderr, "a warning from long ago\n");
        rewind($stderr);
        $process->stderrPipe = $stderr;

        $channel = new NdjsonRpcChannel($process, new WorkerLimits());

        // Request A prints.
        $channel->beginRequest();
        $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']);

        // Request B says nothing.
        $channel->beginRequest();
        $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']);

        // Request C fails, with nothing of its own and nothing before it.
        $channel->beginRequest();
        fclose($pair[1]);
        $error = captureThrows(
            static fn() => $channel->send(['jsonrpc' => '2.0', 'method' => 'ping']),
            WorkerException::class,
        );

        assertSame('WORKER_PIPE_BROKEN', $error->diagnosticCode);
        assertSame(false, str_contains($error->getMessage(), 'a warning from long ago'));
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

    public function testAWorkerKilledBySignalSaysSoRatherThanReportingExitMinusOne(): void
    {
        // The real case: the host's out-of-memory killer SIGTERMs the worker.
        // It prints nothing, so the exit status is the only evidence there is,
        // and "exit -1" names this code's own placeholder for a process whose
        // handle is gone. That reads as a Knossos fault and sent a real
        // investigation looking inside the scanner for hours.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 100));
        $deadline = $channel->beginRequest();

        $process->running = false;
        $process->signaled = true;
        $process->termsig = 15;
        ftruncate($process->pipes[1], 0);
        fclose($process->pipes[1]);
        $process->pipes[1] = fopen('php://temp', 'r');

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertSame('WORKER_EXITED', $error->diagnosticCode);
        assertContains('killed by signal 15', $error->getMessage());
        assertContains('out-of-memory killer', $error->getMessage());
    }

    public function testAWorkerThatChoseItsExitCodeStillReportsThatCode(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 100));
        $deadline = $channel->beginRequest();

        $process->running = false;
        $process->exitcode = 134;
        ftruncate($process->pipes[1], 0);
        fclose($process->pipes[1]);
        $process->pipes[1] = fopen('php://temp', 'r');

        $error = captureThrows(
            static fn() => $channel->readMessage($deadline),
            WorkerException::class,
        );

        assertContains('exit 134', $error->getMessage());
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

    /**
     * The exhaustion rule itself: empty AND at EOF, never either alone.
     *
     * Both callers reach this only through stream_select(), and nothing a test
     * can do in-process makes select() report a descriptor ready that then
     * yields an empty read while the peer is still open — which is exactly the
     * case the feof() term defends against. Deleting that term therefore left
     * every channel test green. It is asserted directly instead, the way
     * GitProcessRunner's driver cap is.
     */
    public function testExhaustionRequiresEofAndNotMerelyAnEmptyRead(): void
    {
        $method = new \ReflectionMethod(NdjsonRpcChannel::class, 'isExhausted');
        [$reader, $writer] = $this->socketPair();
        stream_set_blocking($reader, false);

        // Quiet but open: the peer is alive and may still write its last words.
        assertSame(false, $method->invoke(null, $reader, ''));
        // Data is data, whatever the descriptor's state.
        assertSame(false, $method->invoke(null, $reader, 'ImportError'));

        fclose($writer);
        fread($reader, 8192);

        assertSame(true, $method->invoke(null, $reader, ''));

        fclose($reader);
    }

    /**
     * Only stderr's exhaustion drops stderr from the select set.
     *
     * absorb() reports exhaustion for whichever descriptor it drained, so
     * without the per-descriptor split a worker that closed its STDOUT first —
     * an ordinary shutdown order — took stderr out of the select set with it,
     * and every diagnostic written afterwards was lost. Here stdout is at EOF
     * for the whole run and 'TAIL' is written to stderr once stdout's EOF has
     * already been observed, so only a run that kept stderr selected ever sees
     * it. "Once" and not "on the Nth pass": how many passes the loop makes is a
     * property of the select set this test is meant to be independent of, so
     * the write is gated on a flag rather than on a pass number.
     */
    public function testStdoutReachingEofDoesNotDropStderrFromTheSelectSet(): void
    {
        $stdinPair = $this->socketPair();
        stream_set_blocking($stdinPair[0], false);
        @fwrite($stdinPair[0], str_repeat('x', 4_000_000)); // Fill it: never writable.
        $stdoutPair = $this->socketPair();
        fclose($stdoutPair[1]); // Worker closed stdout: permanently ready, always empty.
        $stderrPair = $this->socketPair();

        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $stdinPair[0];
        $process->stdoutPipe = $stdoutPair[0];
        $process->stderrPipe = $stderrPair[0];

        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 200));
        $channel->beginRequest();

        $passes = 0;
        $announced = false;
        captureThrows(
            static function () use ($channel, &$passes, &$announced, $stderrPair): void {
                $channel->send(['data' => str_repeat('y', 8_000_000)], static function () use (&$passes, &$announced, $stderrPair): bool {
                    // Any pass after the first: stdout's EOF has been observed
                    // by then, so this is reachable only while stderr is still
                    // in the select set. Written once, so the assertion below
                    // pins the content and not the pass count.
                    if (++$passes > 1 && !$announced) {
                        $announced = true;
                        fwrite($stderrPair[1], 'TAIL');
                    }

                    return false;
                });
            },
            WorkerException::class,
        );

        assertSame('TAIL', $channel->stderr());

        fclose($stdinPair[1]);
        fclose($stderrPair[1]);
    }

    /**
     * The other half of the same rule: an exhausted STDOUT leaves the select
     * set too.
     *
     * A worker that closes stdout while the parent is still writing a large
     * frame leaves a descriptor stream_select() reports ready on every pass
     * and that yields nothing, so the send loop spun at full speed until the
     * deadline. Only stderr's exhaustion was acted on, so this half was left
     * open when the stderr half was fixed.
     *
     * Counted rather than timed: the wait is capped at 100 ms whenever a
     * cancellation callback is supplied, so a loop that actually waits reaches
     * a handful of passes inside a 200 ms deadline, while a spinning one
     * reaches thousands. The bound below is two orders of magnitude clear of
     * both.
     */
    public function testAnExhaustedStdoutStopsTheSendLoopFromSpinning(): void
    {
        $stdinPair = $this->socketPair();
        stream_set_blocking($stdinPair[0], false);
        @fwrite($stdinPair[0], str_repeat('x', 4_000_000)); // Fill it: never writable.
        $stdoutPair = $this->socketPair();
        fclose($stdoutPair[1]); // Worker closed stdout: permanently ready, always empty.
        $stderrPair = $this->socketPair();

        $process = $this->pipeOnlyProcess();
        $process->stdinPipe = $stdinPair[0];
        $process->stdoutPipe = $stdoutPair[0];
        $process->stderrPipe = $stderrPair[0];

        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 200));
        $channel->beginRequest();

        $passes = 0;
        captureThrows(
            static function () use ($channel, &$passes): void {
                $channel->send(['data' => str_repeat('y', 8_000_000)], static function () use (&$passes): bool {
                    ++$passes;

                    return false;
                });
            },
            WorkerException::class,
        );

        self::assertLessThan(50, $passes, sprintf('send() made %d passes in 200 ms; the exhausted stdout is still selected', $passes));

        fclose($stdinPair[1]);
        fclose($stderrPair[1]);
    }

    /**
     * A `scan/input_hashes` frame of exactly $bytes bytes, newline included.
     */
    private static function inputHashesFrame(int $bytes): string
    {
        $empty = json_encode(['jsonrpc' => '2.0', 'method' => 'scan/input_hashes', 'params' => ['input_hashes' => ['' => null]]]) . "\n";
        $path = str_repeat('p', $bytes - strlen($empty));

        return json_encode(['jsonrpc' => '2.0', 'method' => 'scan/input_hashes', 'params' => ['input_hashes' => [$path => null]]]) . "\n";
    }

    /** @return list<array<string, mixed>> */
    private static function readAll(NdjsonRpcChannel $channel, int $deadline, int $count): array
    {
        $messages = [];
        for ($i = 0; $i < $count; ++$i) {
            $messages[] = $channel->readMessage($deadline);
        }

        return $messages;
    }

    public function testInputHashesFramesAreNotChargedToTheOutputBudget(): void
    {
        // Six 10 KB frames against a 20 KB output budget: charged as output they
        // would fail the request as WORKER_OUTPUT_LIMIT, which halves a batch
        // that the map's size does not depend on.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 20_000, maxOutputBytes: 20_000));
        $deadline = $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat(self::inputHashesFrame(10_000), 6) . '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n");
        rewind($process->pipes[1]);

        $messages = self::readAll($channel, $deadline, 7);

        assertSame('scan/input_hashes', $messages[5]['method']);
        assertSame(1, $messages[6]['id']);
    }

    public function testAnInputHashesFrameArrivingWhenOutputIsNearTheBudgetIsNotChargedWhileItArrives(): void
    {
        // 15 KB of ordinary output against a 20 KB budget, then a 10 KB part.
        // Charged by the byte as it arrives, the part's first read chunk
        // already pushed the total past the budget before the frame was
        // complete enough to be recognised as exempt.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 20_000, maxOutputBytes: 20_000));
        $deadline = $channel->beginRequest();
        $ordinary = str_replace('scan\\/input_hashes', 'scan\\/input_hashez', self::inputHashesFrame(15_000));
        fwrite($process->pipes[1], $ordinary . self::inputHashesFrame(10_000) . '{"jsonrpc":"2.0","id":1,"result":{}}' . "\n");
        rewind($process->pipes[1]);

        $messages = self::readAll($channel, $deadline, 3);

        assertSame('scan/input_hashes', $messages[1]['method']);
        assertSame(1, $messages[2]['id']);
    }

    public function testAnOversizedPartialFrameWithinTheOutputBudgetIsTooLarge(): void
    {
        // A frame longer than the line limit can never be an exempt part, so
        // it is charged as output: within that budget it is a frame too large.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128, maxOutputBytes: 100_000));
        $deadline = $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat('x', 20_000));
        rewind($process->pipes[1]);

        $error = captureThrows(static fn() => $channel->readMessage($deadline), WorkerException::class);

        assertSame('WORKER_FRAME_TOO_LARGE', $error->diagnosticCode);
    }

    public function testAnOversizedPartialFrameBeyondTheOutputBudgetIsAnOutputLimit(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128, maxOutputBytes: 128));
        $deadline = $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat('x', 200));
        rewind($process->pipes[1]);

        $error = captureThrows(static fn() => $channel->readMessage($deadline), WorkerException::class);

        assertSame('WORKER_OUTPUT_LIMIT', $error->diagnosticCode);
    }

    /**
     * A real worker process that writes $frames 8 KB notifications before it
     * reads its request line, then answers that request.
     */
    private function floodingProcess(int $frames): ProcessSupervisorInterface
    {
        $script = sprintf(
            '$line = json_encode(["jsonrpc" => "2.0", "method" => "scan/progress", "params" => ["p" => str_repeat("x", 8000)]]) . "\n";'
            . ' for ($i = 0; $i < %d; ++$i) { fwrite(STDOUT, $line); }'
            . ' fgets(STDIN); fwrite(STDOUT, "{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{}}\n");',
            $frames,
        );

        return new class ($script) implements ProcessSupervisorInterface {
            /** @var resource|null */
            private $handle = null;
            /** @var array<int, resource> */
            private array $pipes = [];

            public function __construct(private readonly string $script) {}

            public function start(): void
            {
                $handle = proc_open([PHP_BINARY, '-r', $this->script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $this->pipes);
                if (!is_resource($handle)) {
                    throw new \RuntimeException('Unable to start the flooding worker.');
                }
                $this->handle = $handle;
                foreach ($this->pipes as $pipe) {
                    stream_set_blocking($pipe, false);
                }
            }

            public function isRunning(): bool
            {
                return true;
            }

            /** @return resource */
            public function stdin()
            {
                return $this->pipes[0];
            }

            /** @return resource */
            public function stdout()
            {
                return $this->pipes[1];
            }

            /** @return resource */
            public function stderr()
            {
                return $this->pipes[2];
            }

            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array
            {
                return proc_get_status($this->handle);
            }

            public function close(bool $terminate): void
            {
                foreach ($this->pipes as $pipe) {
                    @fclose($pipe);
                }
                if (is_resource($this->handle)) {
                    proc_terminate($this->handle, 9);
                    proc_close($this->handle);
                }
            }
        };
    }

    public function testAWorkerFloodingStdoutBeforeReadingItsRequestFailsTheSendAsAnOutputLimit(): void
    {
        // Nothing classifies what a send() buffers, and a worker answers only
        // once it has read the whole request, so more than one frame's worth
        // there is a flood. Capped only by the sum of both budgets (84 MB), it
        // was held in full, and then drained one quadratic copy per frame.
        $process = $this->floodingProcess(400);
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 30_000));
        $channel->beginRequest();
        try {
            $error = captureThrows(
                static fn() => $channel->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'scan', 'params' => ['pad' => str_repeat('y', 2_000_000)]]),
                WorkerException::class,
            );
        } finally {
            $process->close(true);
        }

        assertSame('WORKER_OUTPUT_LIMIT', $error->diagnosticCode);
    }

    public function testOneFrameWrittenBeforeTheRequestIsReadStillArrives(): void
    {
        $process = $this->floodingProcess(1);
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(requestTimeoutMs: 30_000));
        $deadline = $channel->beginRequest();
        try {
            $channel->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'scan', 'params' => ['pad' => str_repeat('y', 2_000_000)]]);
            $messages = self::readAll($channel, $deadline, 2);
        } finally {
            $process->close(true);
        }

        assertSame('scan/progress', $messages[0]['method']);
        assertSame(1, $messages[1]['id']);
    }

    public function testManyBufferedFramesAreTakenInOrderAcrossReads(): void
    {
        // Frames are taken by offset; a partial frame left behind is kept
        // intact when the next bytes arrive.
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits());
        $deadline = $channel->beginRequest();
        $frames = '';
        for ($i = 0; $i < 3_000; ++$i) {
            $frames .= json_encode(['jsonrpc' => '2.0', 'method' => 'scan/progress', 'params' => ['i' => $i]]) . "\n";
        }
        fwrite($process->pipes[1], $frames . '{"jsonrpc":"2.0","id":7,"result":{}}' . "\n");
        rewind($process->pipes[1]);

        $messages = self::readAll($channel, $deadline, 3_001);

        assertSame(range(0, 2_999), array_map(static fn(array $message): int => $message['params']['i'], array_slice($messages, 0, 3_000)));
        assertSame(7, $messages[3_000]['id']);
    }

    public function testOtherNotificationsAreStillChargedToTheOutputBudget(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 20_000, maxOutputBytes: 20_000));
        $deadline = $channel->beginRequest();
        $frame = str_replace('scan\\/input_hashes', 'scan\\/input_hashez', self::inputHashesFrame(10_000));
        fwrite($process->pipes[1], str_repeat($frame, 6));
        rewind($process->pipes[1]);

        $error = captureThrows(static fn() => self::readAll($channel, $deadline, 6), WorkerException::class);

        assertSame('WORKER_OUTPUT_LIMIT', $error->diagnosticCode);
    }

    public function testInputHashesFramesBeyondTheirOwnBudgetFailTheRequest(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxInputHashesBytes: 29_999));
        $deadline = $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat(self::inputHashesFrame(10_000), 3));
        rewind($process->pipes[1]);

        $error = captureThrows(static fn() => self::readAll($channel, $deadline, 3), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertSame('Worker scan/input_hashes frames exceed the 29999-byte limit for one scan request.', $error->getMessage());
    }

    public function testInputHashesFramesExactlyFillingTheirBudgetPass(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxInputHashesBytes: 30_000));
        $deadline = $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat(self::inputHashesFrame(10_000), 3));
        rewind($process->pipes[1]);

        assertSame(3, count(self::readAll($channel, $deadline, 3)));
    }

    public function testEachRequestGetsAFreshInputHashesBudget(): void
    {
        $process = $this->mockProcess();
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxInputHashesBytes: 20_000));
        $deadline = hrtime(true) + 5_000_000_000;
        $channel->beginRequest();
        fwrite($process->pipes[1], str_repeat(self::inputHashesFrame(10_000), 2));
        rewind($process->pipes[1]);
        self::readAll($channel, $deadline, 2);

        $channel->beginRequest();
        $position = (int) ftell($process->pipes[1]);
        fwrite($process->pipes[1], str_repeat(self::inputHashesFrame(10_000), 2));
        fseek($process->pipes[1], $position);

        assertSame(2, count(self::readAll($channel, $deadline, 2)));
    }
}
