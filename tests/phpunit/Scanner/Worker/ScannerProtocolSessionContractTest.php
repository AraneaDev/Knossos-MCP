<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Closure;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Worker\ProcessSupervisorInterface;
use Knossos\Scanner\Worker\RpcChannelInterface;
use Knossos\Scanner\Worker\ScannerProtocolSession;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * What the session puts on the wire, and what it does with each kind of reply.
 *
 * Driven through a scripted channel, one reply per read, so each case states
 * the worker's side of the conversation and checks the session's. The
 * existing tests exercised the happy paths; mutation testing showed that the
 * request ids, the fields of each message, the kind of close after a failure
 * and most of the validation of a reply could change with all of them green.
 */
#[Group('worker-protocol')]
final class ScannerProtocolSessionContractTest extends TestCase
{
    public function testEveryMessageIsJsonRpcWithIdsCountingUpFromOne(): void
    {
        [$session, $channel] = self::session([self::manifest(), self::done(), self::done()]);

        iterator_to_array($session->scan(['files' => ['a.php']]));
        iterator_to_array($session->scan(['files' => ['b.php']]));

        assertSame([1, 2, 3], array_column($channel->sent, 'id'));
        assertSame(['2.0', '2.0', '2.0'], array_column($channel->sent, 'jsonrpc'));
        assertSame(
            ['protocol_version' => Protocol::VERSION, 'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION],
            $channel->sent[0]['params'],
        );
    }

    /** A cancelled scan tells the worker which request to drop, kills it, and says so. */
    public function testACancelledScanCancelsTheRequestAndTerminatesTheWorker(): void
    {
        [$session, $channel, $process] = self::session([self::manifest(), self::done()]);

        $error = captureThrows(
            static fn() => iterator_to_array($session->scan(['files' => ['a.php']], static fn(): bool => true)),
            WorkerException::class,
        );

        assertSame('WORKER_CANCELLED', $error->diagnosticCode);
        assertSame(['jsonrpc' => '2.0', 'method' => Protocol::METHOD_CANCEL, 'params' => ['request_id' => 2]], $channel->sent[2]);
        assertSame([true], $process->closes, 'A cancelled worker is terminated once, not just released.');
    }

    /** A failed request terminates the worker, because its channel is no longer in step. */
    public function testAFailedRequestTerminatesTheWorkerAndKeepsTheWorkersMessage(): void
    {
        [$session, , $process] = self::session([static fn(int $id): array => ['id' => $id, 'error' => ['code' => -1, 'message' => 'no such method']]]);

        $error = captureThrows(static fn() => $session->initialize(), WorkerException::class);

        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
        assertSame('no such method', $error->getMessage());
        assertSame([true], $process->closes);
    }

    /** An error with no usable message still reads as an error, with a stated fallback. */
    public function testAnErrorWithoutAMessageUsesTheFallbackText(): void
    {
        foreach ([['code' => -1], 'boom', ['message' => 42]] as $shape) {
            [$session] = self::session([static fn(int $id): array => ['id' => $id, 'error' => $shape]]);

            $error = captureThrows(static fn() => $session->initialize(), WorkerException::class);

            assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
            assertSame('Scanner worker returned an unspecified JSON-RPC error.', $error->getMessage());
        }
    }

    /** A result must be an object: a list or a scalar is refused, and an empty object is fine. */
    public function testAReplyMustCarryAnObjectResult(): void
    {
        foreach ([[1, 2], 'text'] as $result) {
            [$session] = self::session([static fn(int $id): array => ['id' => $id, 'result' => $result]]);
            assertSame('WORKER_RESPONSE_INVALID', captureThrows(static fn() => $session->initialize(), WorkerException::class)->diagnosticCode);

            [$session] = self::session([self::manifest(), static fn(int $id): array => ['id' => $id, 'result' => $result]]);
            assertSame('WORKER_RESPONSE_INVALID', captureThrows(static fn() => iterator_to_array($session->scan([])), WorkerException::class)->diagnosticCode);
        }

        [$session] = self::session([self::manifest(), static fn(int $id): array => ['id' => $id, 'result' => []]]);
        iterator_to_array($session->scan([]));
        assertSame([], $session->lastScanResult());
    }

    public function testAScanReplyForAnotherRequestIsRefused(): void
    {
        [$session] = self::session([self::manifest(), static fn(int $id): array => ['id' => $id + 1, 'result' => ['files' => 1]]]);

        assertSame('WORKER_UNEXPECTED_RESPONSE', captureThrows(static fn() => iterator_to_array($session->scan([])), WorkerException::class)->diagnosticCode);
    }

    public function testContributionParamsMustBeAnObject(): void
    {
        foreach (['text', [1, 2]] as $params) {
            [$session] = self::session([self::manifest(), ['jsonrpc' => '2.0', 'method' => 'scan/contribution', 'params' => $params], self::done()]);

            $error = captureThrows(static fn() => iterator_to_array($session->scan([])), WorkerException::class);

            assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        }
    }

    /** A missing capability is named once, however often the caller listed it. */
    public function testAMissingCapabilityIsNamedOnce(): void
    {
        [$session, , $process] = self::session([self::manifest()]);

        $error = captureThrows(static fn() => $session->requireCapabilities(['call_graph', 'call_graph']), WorkerException::class);

        assertSame('Worker php-scanner does not provide required capabilities: call_graph.', $error->getMessage());
        assertSame([true], $process->closes);
    }

    /**
     * An abandoned scan drains its own reply, within a quarter of a second,
     * keeping the result only when it is that request's object, and keeping
     * the worker only when the reply was for that request.
     */
    public function testAnAbandonedScanDrainsOnlyItsOwnReply(): void
    {
        $cases = [
            'its own object' => [static fn(int $id): array => ['id' => $id, 'result' => ['files' => 3]], ['files' => 3], []],
            // Out of step: this request's own reply may still arrive, as the
            // apparent answer to the next request, so the worker is discarded.
            'another request' => [static fn(int $id): array => ['id' => $id + 1, 'result' => ['files' => 3]], [], [true]],
            'a list' => [static fn(int $id): array => ['id' => $id, 'result' => [1, 2]], [], []],
            'a scalar' => [static fn(int $id): array => ['id' => $id, 'result' => 'text'], [], []],
        ];
        foreach ($cases as $label => [$reply, $kept, $closes]) {
            [$session, $channel, $process] = self::session([
                self::manifest(),
                ['jsonrpc' => '2.0', 'method' => 'scan/contribution', 'params' => self::contribution()],
                $reply,
            ]);

            foreach ($session->scan([]) as $contribution) {
                break; // abandon the scan after its first contribution
            }

            assertSame($kept, $session->lastScanResult(), $label);
            assertSame($closes, $process->closes, $label . ': only a reply to its own request leaves the worker reusable.');
            $budget = end($channel->deadlines) - $channel->readAt[array_key_last($channel->readAt)];
            assertSame(true, $budget > 0 && $budget <= 250_000_000, sprintf('%s: drain budget %d ns.', $label, $budget));
        }
    }

    public function testAnAbandonedScanOnAStoppedWorkerReadsNothingMore(): void
    {
        [$session, $channel, $process] = self::session([
            self::manifest(),
            ['jsonrpc' => '2.0', 'method' => 'scan/contribution', 'params' => self::contribution()],
            self::done(),
        ]);

        foreach ($session->scan([]) as $contribution) {
            $process->running = false;
            break;
        }

        assertSame(2, $channel->reads, 'The manifest and the one contribution, and no drain.');
    }

    /** Shutdown always terminates the worker, whether or not it answered. */
    public function testShutdownTerminatesTheWorkerEvenWhenItFails(): void
    {
        [$session, , $process] = self::session([self::manifest(), self::done()]);
        $session->initialize();
        $session->shutdown();
        assertSame([true], $process->closes);

        [$session, , $process] = self::session([self::manifest(), new WorkerException('WORKER_EXITED', 'gone')]);
        $session->initialize();
        $session->shutdown();
        assertSame([true, true], $process->closes, 'The failed request closes it, and shutdown closes it again.');
    }

    /**
     * A session over a scripted channel. Each script entry answers one read: a
     * message, a closure given the id of the last request sent, or a Throwable.
     *
     * @param list<array<string, mixed>|Closure(int): array<string, mixed>|Throwable> $script
     * @return array{0: ScannerProtocolSession, 1: object, 2: object}
     */
    private static function session(array $script): array
    {
        $process = new class implements ProcessSupervisorInterface {
            public bool $running = true;
            /** @var list<bool> */
            public array $closes = [];

            public function start(): void {}

            public function isRunning(): bool
            {
                return $this->running;
            }

            public function stdin()
            {
                return fopen('php://memory', 'r+');
            }

            public function stdout()
            {
                return fopen('php://memory', 'r+');
            }

            public function stderr()
            {
                return fopen('php://memory', 'r+');
            }

            public function status(): array
            {
                return ['command' => '', 'pid' => 0, 'running' => $this->running, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0];
            }

            public function close(bool $terminate): void
            {
                $this->closes[] = $terminate;
            }
        };
        $channel = new class($script) implements RpcChannelInterface {
            /** @var list<array<string, mixed>> */
            public array $sent = [];
            /** @var list<int> */
            public array $deadlines = [];
            /** @var list<int> */
            public array $readAt = [];
            public int $reads = 0;
            private int $lastId = 0;

            /** @param list<mixed> $script */
            public function __construct(private array $script) {}

            public function beginRequest(): int
            {
                return hrtime(true) + 10_000_000_000;
            }

            public function send(array $message, ?callable $cancelled = null): void
            {
                $this->sent[] = $message;
                if (isset($message['id'])) {
                    $this->lastId = $message['id'];
                }
            }

            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                $this->deadlines[] = $deadline;
                $this->readAt[] = hrtime(true);
                ++$this->reads;
                $next = array_shift($this->script) ?? throw new WorkerException('WORKER_TIMEOUT', 'script exhausted');
                if ($next instanceof Throwable) {
                    throw $next;
                }

                return $next instanceof Closure ? $next($this->lastId) : $next;
            }

            public function stderr(): string
            {
                return '';
            }
        };

        return [new ScannerProtocolSession($process, $channel), $channel, $process];
    }

    private static function manifest(): Closure
    {
        return static fn(int $id): array => ['id' => $id, 'result' => [
            'id' => 'php-scanner',
            'version' => '1.0.0',
            'protocol_version' => Protocol::VERSION,
            'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
            'languages' => ['php'],
            'file_extensions' => ['.php'],
            'capabilities' => [],
        ]];
    }

    private static function done(): Closure
    {
        return static fn(int $id): array => ['id' => $id, 'result' => ['count' => 0]];
    }

    /** @return array<string, mixed> */
    private static function contribution(): array
    {
        return ['owner_key' => 'php:file:a.php', 'nodes' => [], 'edges' => [], 'diagnostics' => []];
    }
}
