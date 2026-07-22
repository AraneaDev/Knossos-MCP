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
        // maxLineBytes must be >= 128; the JSON line must exceed it.
        $channel = new NdjsonRpcChannel($process, new WorkerLimits(maxLineBytes: 128));
        $channel->beginRequest();

        assertThrows(
            static fn() => $channel->send(['data' => str_repeat('x', 200)]),
            WorkerException::class,
        );
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
}
