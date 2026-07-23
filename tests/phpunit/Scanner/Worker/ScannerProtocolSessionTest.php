<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Worker\ProcessSupervisorInterface;
use Knossos\Scanner\Worker\RpcChannelInterface;
use Knossos\Scanner\Worker\ScannerProtocolSession;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class ScannerProtocolSessionTest extends TestCase
{
    /** @return array{ProcessSupervisorInterface, RpcChannelInterface} */
    private function mockDependencies(): array
    {
        $process = new class implements ProcessSupervisorInterface {
            public bool $closed = false;

            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->closed = true; }
        };

        $channel = new class implements RpcChannelInterface {
            public string $lastStderr = '';
            public int $beginCount = 0;
            /** @var list<array<string, mixed>> */
            public array $sent = [];
            /** @var list<array<string, mixed>> */
            public array $responses = [];
            public int $responseIndex = 0;

            public function beginRequest(): int { ++$this->beginCount; return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                if ($this->responseIndex < count($this->responses)) {
                    return $this->responses[$this->responseIndex++];
                }
                throw new WorkerException('WORKER_TIMEOUT', 'No more responses configured.');
            }
            public function stderr(): string { return $this->lastStderr; }
        };

        return [$process, $channel];
    }

    public function testCancelWhenProcessNotRunningReturnsEarly(): void
    {
        $process = new class implements ProcessSupervisorInterface {
            public bool $started = false;
            public function start(): void { $this->started = true; }
            public function isRunning(): bool { return false; }
            public function stdin() { throw new \RuntimeException('should not be called'); }
            public function stdout() { throw new \RuntimeException('should not be called'); }
            public function stderr() { throw new \RuntimeException('should not be called'); }
            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $channel = new class implements RpcChannelInterface {
            public bool $sendCalled = false;
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sendCalled = true; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array { return []; }
            public function stderr(): string { return ''; }
        };

        $session = new ScannerProtocolSession($process, $channel);
        $session->cancel('test-request');

        // send() should NOT have been called since process is not running
        assertSame(false, $channel->sendCalled);
    }

    public function testCloseDelegatesToProcessClose(): void
    {
        [$process, $channel] = $this->mockDependencies();
        $session = new ScannerProtocolSession($process, $channel);

        assertSame(false, $process->closed);

        $session->close(false);

        assertSame(true, $process->closed);
    }

    public function testCloseWithTerminateDelegatesTrue(): void
    {
        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; }
        };

        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array { return []; }
            public function stderr(): string { return ''; }
        };

        $session = new ScannerProtocolSession($process, $channel);
        $session->close(true);

        assertSame(true, $process->lastTerminate);
    }

    public function testStderrDelegatesToChannel(): void
    {
        [$process, $channel] = $this->mockDependencies();
        $channel->lastStderr = 'error output';

        $session = new ScannerProtocolSession($process, $channel);

        assertSame('error output', $session->stderr());
    }

    public function testLastScanResultReturnsEmptyArrayInitially(): void
    {
        [$process, $channel] = $this->mockDependencies();
        $session = new ScannerProtocolSession($process, $channel);

        assertSame([], $session->lastScanResult());
    }

    public function testShutdownSkipsWhenProcessNotRunning(): void
    {
        $process = new class implements ProcessSupervisorInterface {
            public bool $closeCalled = false;
            public function start(): void {}
            public function isRunning(): bool { return false; }
            public function stdin() { throw new \RuntimeException('should not be called'); }
            public function stdout() { throw new \RuntimeException('should not be called'); }
            public function stderr() { throw new \RuntimeException('should not be called'); }
            /** @return array{command: string, pid: int, running: bool, signaled: bool, stopped: bool, exitcode: int, termsig: int, stopsig: int} */
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->closeCalled = true; }
        };

        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array { return []; }
            public function stderr(): string { return ''; }
        };

        $session = new ScannerProtocolSession($process, $channel);
        $session->shutdown();

        // close should NOT be called when process is not running (shutdown skips)
        assertSame(false, $process->closeCalled);
    }

    // ── initialize() ───────────────────────────────────────────────────────

    private function makeManifestResult(string $protocolVersion = null, string $schemaVersion = null): array
    {
        return [
            'id' => 'php-scanner',
            'version' => '1.0.0',
            'protocol_version' => $protocolVersion ?? Protocol::VERSION,
            'output_schema_version' => $schemaVersion ?? Protocol::OUTPUT_SCHEMA_VERSION,
            'languages' => ['php'],
            'file_extensions' => ['.php'],
            'capabilities' => [],
        ];
    }

    public function testInitializeSendsInitializeAndReturnsManifest(): void
    {
        $channel = new class implements RpcChannelInterface {
            public array $sent = [];
            private bool $responseReturned = false;

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                if (!$this->responseReturned) {
                    $this->responseReturned = true;
                    return ['id' => 1, 'result' => [
                        'id' => 'php-scanner',
                        'version' => '1.0.0',
                        'protocol_version' => Protocol::VERSION,
                        'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                        'languages' => ['php'],
                        'file_extensions' => ['.php'],
                        'capabilities' => ['framework_detection'],
                    ]];
                }
                throw new WorkerException('WORKER_TIMEOUT', 'No more responses.');
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public bool $closed = false;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->closed = true; }
        };

        $session = new ScannerProtocolSession($process, $channel);
        $manifest = $session->initialize();

        assertSame('php-scanner', $manifest->id);
        assertSame(Protocol::VERSION, $manifest->protocolVersion);
        assertSame(['php'], $manifest->languages);
        $this->assertCount(1, $channel->sent);
        assertSame('initialize', $channel->sent[0]['method']);
    }

    public function testInitializeReturnsCachedManifestOnSecondCall(): void
    {
        $channel = new class implements RpcChannelInterface {
            public int $beginCount = 0;

            public function beginRequest(): int { ++$this->beginCount; return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => [
                    'id' => 'php-scanner',
                    'version' => '1.0.0',
                    'protocol_version' => Protocol::VERSION,
                    'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                    'languages' => ['php'],
                    'file_extensions' => ['.php'],
                    'capabilities' => [],
                ]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $session = new ScannerProtocolSession($process, $channel);
        $first = $session->initialize();
        $beginCountAfterFirst = $channel->beginCount;
        $second = $session->initialize();

        assertSame($first, $second);
        assertSame(1, $beginCountAfterFirst, 'beginRequest should be called once');
        assertSame(1, $channel->beginCount, 'beginRequest should NOT be called again on cache hit');
    }

    public function testInitializeClosesOnManifestInvalid(): void
    {
        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => ['id' => 42]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        assertThrows(
            static fn () => $session->initialize(),
            WorkerException::class,
        );
        assertSame(true, $process->lastTerminate);
    }

    public function testInitializeClosesOnProtocolVersionMismatch(): void
    {
        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => [
                    'id' => 'php-scanner',
                    'version' => '1.0.0',
                    'protocol_version' => '0.0.0',
                    'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                    'languages' => ['php'],
                    'file_extensions' => ['.php'],
                    'capabilities' => [],
                ]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        $error = captureThrows(
            static fn () => $session->initialize(),
            WorkerException::class,
        );
        assertSame('WORKER_PROTOCOL_VERSION_MISMATCH', $error->diagnosticCode);
        $this->assertStringContainsString('incompatible', $error->getMessage());
        assertSame(true, $process->lastTerminate);
    }

    public function testInitializeClosesOnSchemaVersionMismatch(): void
    {
        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => [
                    'id' => 'php-scanner',
                    'version' => '1.0.0',
                    'protocol_version' => Protocol::VERSION,
                    'output_schema_version' => '0.0.0',
                    'languages' => ['php'],
                    'file_extensions' => ['.php'],
                    'capabilities' => [],
                ]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        $error = captureThrows(
            static fn () => $session->initialize(),
            WorkerException::class,
        );
        assertSame('WORKER_OUTPUT_SCHEMA_MISMATCH', $error->diagnosticCode);
        $this->assertStringContainsString('incompatible', $error->getMessage());
        assertSame(true, $process->lastTerminate);
    }

    // ── requireCapabilities() ──────────────────────────────────────────────

    public function testRequireCapabilitiesReturnsManifestWhenAllPresent(): void
    {
        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => [
                    'id' => 'php-scanner',
                    'version' => '1.0.0',
                    'protocol_version' => Protocol::VERSION,
                    'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                    'languages' => ['php'],
                    'file_extensions' => ['.php'],
                    'capabilities' => ['framework_detection', 'symbol_extraction'],
                ]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $session = new ScannerProtocolSession($process, $channel);
        $manifest = $session->requireCapabilities(['framework_detection']);

        assertSame('php-scanner', $manifest->id);
    }

    public function testRequireCapabilitiesThrowsWhenMissing(): void
    {
        $channel = new class implements RpcChannelInterface {
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => [
                    'id' => 'php-scanner',
                    'version' => '1.0.0',
                    'protocol_version' => Protocol::VERSION,
                    'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                    'languages' => ['php'],
                    'file_extensions' => ['.php'],
                    'capabilities' => ['framework_detection'],
                ]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        $error = captureThrows(
            static fn () => $session->requireCapabilities(['missing_cap']),
            WorkerException::class,
        );
        assertSame('WORKER_CAPABILITY_MISMATCH', $error->diagnosticCode);
        $this->assertStringContainsString('required capabilities', $error->getMessage());
        assertSame(true, $process->lastTerminate);
    }

    // ── discover() ─────────────────────────────────────────────────────────

    public function testDiscoverSendsDiscoverAndReturnsResult(): void
    {
        $channel = new class implements RpcChannelInterface {
            public array $sent = [];
            private int $readCount = 0;

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                ++$this->readCount;
                // First read: initialize response
                if ($this->readCount === 1) {
                    return ['id' => 1, 'result' => [
                        'id' => 'php-scanner',
                        'version' => '1.0.0',
                        'protocol_version' => Protocol::VERSION,
                        'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                        'languages' => ['php'],
                        'file_extensions' => ['.php'],
                        'capabilities' => [],
                    ]];
                }
                // Second read: discover response
                return ['id' => 2, 'result' => ['files' => ['src/Foo.php']]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $session = new ScannerProtocolSession($process, $channel);
        $result = $session->discover(['path' => '/test']);

        assertSame(['files' => ['src/Foo.php']], $result);
        // Two requests: initialize (id=1) + discover (id=2)
        $this->assertCount(2, $channel->sent);
        assertSame('discover', $channel->sent[1]['method']);
    }

    // ── scan() ─────────────────────────────────────────────────────────────

    public function testScanYieldsContributionsAndReturnsResult(): void
    {
        $channel = new class implements RpcChannelInterface {
            private array $queue = [];
            private int $index = 0;

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                if ($this->index === 0) {
                    $this->queue = [
                        ['id' => 1, 'result' => [
                            'id' => 'php-scanner',
                            'version' => '1.0.0',
                            'protocol_version' => Protocol::VERSION,
                            'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                            'languages' => ['php'],
                            'file_extensions' => ['.php'],
                            'capabilities' => ['framework_detection'],
                        ]],
                        ['method' => 'scan/contribution', 'params' => [
                            'owner_key' => 'test.knossos:file:src/Foo.php',
                            'nodes' => [[
                                'local_id' => 'n1',
                                'kind' => 'class',
                                'canonical_name' => 'Foo',
                                'display_name' => 'Foo',
                                'origin' => 'derived',
                                'confidence' => 'possible',
                                'evidence' => ['path' => 'src/Foo.php', 'start_line' => 1, 'end_line' => 100],
                            ]],
                            'edges' => [],
                            'diagnostics' => [],
                        ]],
                        ['id' => 2, 'result' => ['files' => [], 'nodes' => [['id' => 'n1']]]],
                    ];
                }
                if ($this->index < count($this->queue)) {
                    return $this->queue[$this->index++];
                }
                throw new WorkerException('WORKER_TIMEOUT', 'No more responses.');
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $session = new ScannerProtocolSession($process, $channel);
        $contributions = [];
        foreach ($session->scan(['path' => '/test']) as $contribution) {
            $contributions[] = $contribution;
        }

        $this->assertCount(1, $contributions);
        assertSame('Foo', $contributions[0]->nodes[0]->canonicalName);
        assertSame(['files' => [], 'nodes' => [['id' => 'n1']]], $session->lastScanResult());
    }

    public function testScanCancelsWhenCallbackReturnsTrue(): void
    {
        $channel = new class implements RpcChannelInterface {
            public array $sent = [];
            private bool $initReturned = false;

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                if (!$this->initReturned) {
                    $this->initReturned = true;
                    return ['id' => 1, 'result' => [
                        'id' => 'php-scanner',
                        'version' => '1.0.0',
                        'protocol_version' => Protocol::VERSION,
                        'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                        'languages' => ['php'],
                        'file_extensions' => ['.php'],
                        'capabilities' => [],
                    ]];
                }
                // readMessage should not be reached in scan loop because cancel fires first
                throw new WorkerException('WORKER_TIMEOUT', 'should not reach');
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public bool $isRunning = true;
            public function start(): void {}
            public function isRunning(): bool { return $this->isRunning; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => $this->isRunning, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; $this->isRunning = false; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        assertThrows(
            static function () use ($session): void {
                foreach ($session->scan(['path' => '/test'], static fn () => true) as $_) {
                }
            },
            WorkerException::class,
        );
        assertSame(true, $process->lastTerminate);

        // The cancel notification must carry the int scan id verbatim — not a
        // stringified copy — so a type-strict worker can match the request.
        $cancel = $channel->sent[count($channel->sent) - 1];
        assertSame('cancel', $cancel['method']);
        assertSame(['request_id' => 2], $cancel['params']);
    }

    public function testAbandonedScanGeneratorDrainsPendingResponseInsteadOfPoisoning(): void
    {
        // A consumer that stops iterating early must not leave the worker with
        // an unread response frame; the generator's finally drains to the final
        // response so the pooled session stays usable.
        $channel = new class implements RpcChannelInterface {
            /** @var list<array<string, mixed>> */
            public array $queue = [];
            public int $index = 0;

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                if ($this->index === 0) {
                    $this->queue = [
                        ['id' => 1, 'result' => [
                            'id' => 'php-scanner',
                            'version' => '1.0.0',
                            'protocol_version' => Protocol::VERSION,
                            'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                            'languages' => ['php'],
                            'file_extensions' => ['.php'],
                            'capabilities' => [],
                        ]],
                        ['method' => 'scan/contribution', 'params' => [
                            'owner_key' => 'test.knossos:file:src/Foo.php',
                            'nodes' => [[
                                'local_id' => 'n1',
                                'kind' => 'class',
                                'canonical_name' => 'Foo',
                                'display_name' => 'Foo',
                                'origin' => 'derived',
                                'confidence' => 'possible',
                                'evidence' => ['path' => 'src/Foo.php', 'start_line' => 1, 'end_line' => 100],
                            ]],
                            'edges' => [],
                            'diagnostics' => [],
                        ]],
                        ['method' => 'scan/contribution', 'params' => [
                            'owner_key' => 'test.knossos:file:src/Bar.php',
                            'nodes' => [],
                            'edges' => [],
                            'diagnostics' => [],
                        ]],
                        ['id' => 2, 'result' => ['drained' => true]],
                    ];
                }
                if ($this->index < count($this->queue)) {
                    return $this->queue[$this->index++];
                }
                throw new WorkerException('WORKER_TIMEOUT', 'No more responses.');
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public bool $closed = false;
            public function start(): void {}
            public function isRunning(): bool { return !$this->closed; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => !$this->closed, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->closed = true; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        $generator = $session->scan(['path' => '/test']);
        foreach ($generator as $_contribution) {
            break; // abandon after the first contribution
        }
        unset($generator); // trigger the generator's finally

        // The pending final frame was drained, so the worker is not poisoned.
        assertSame(['drained' => true], $session->lastScanResult());
    }

    public function testScanThrowsWhenResultIsList(): void
    {
        $channel = new class implements RpcChannelInterface {
            private int $readCount = 0;

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                ++$this->readCount;
                if ($this->readCount === 1) {
                    return ['id' => 1, 'result' => [
                        'id' => 'php-scanner',
                        'version' => '1.0.0',
                        'protocol_version' => Protocol::VERSION,
                        'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                        'languages' => ['php'],
                        'file_extensions' => ['.php'],
                        'capabilities' => [],
                    ]];
                }
                return ['id' => 2, 'result' => [1, 2, 3]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        assertThrows(
            static function () use ($session): void {
                foreach ($session->scan(['path' => '/test']) as $_) {
                }
            },
            WorkerException::class,
        );
        assertSame(true, $process->lastTerminate);
    }

    // ── cancel() ────────────────────────────────────────────────────────────

    public function testCancelSendsCancelWhenRunning(): void
    {
        $channel = new class implements RpcChannelInterface {
            public array $sent = [];

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array { return []; }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $session = new ScannerProtocolSession($process, $channel);
        $session->cancel('test-request');

        $this->assertCount(1, $channel->sent);
        assertSame('cancel', $channel->sent[0]['method']);
        assertSame(['request_id' => 'test-request'], $channel->sent[0]['params']);
    }

    // ── shutdown() ─────────────────────────────────────────────────────────

    public function testShutdownSendsShutdownAndClosesWhenRunning(): void
    {
        $channel = new class implements RpcChannelInterface {
            public array $sent = [];

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => 1, 'result' => ['ok' => true]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public bool $closed = false;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; $this->closed = true; }
        };

        $session = new ScannerProtocolSession($process, $channel);
        $session->shutdown();

        $this->assertCount(1, $channel->sent);
        assertSame('shutdown', $channel->sent[0]['method']);
        assertSame(true, $process->lastTerminate);
        assertSame(true, $process->closed);
    }

    public function testShutdownCatchesWorkerExceptionAndStillCloses(): void
    {
        $channel = new class implements RpcChannelInterface {
            public array $sent = [];

            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void { $this->sent[] = $message; }
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                throw new WorkerException('WORKER_TIMEOUT', 'shutdown timeout');
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public ?bool $lastTerminate = null;
            public bool $closed = false;
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void { $this->lastTerminate = $terminate; $this->closed = true; }
        };

        $session = new ScannerProtocolSession($process, $channel);

        // Should not throw — WorkerException from request is caught in shutdown
        $session->shutdown();

        assertSame(true, $process->lastTerminate);
        assertSame(true, $process->closed);
    }

    // ── close() resets manifest ────────────────────────────────────────────

    public function testCloseResetsManifestCache(): void
    {
        $channel = new class implements RpcChannelInterface {
            public int $requestCount = 0;
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                return ['id' => ++$this->requestCount, 'result' => [
                    'id' => 'php-scanner',
                    'version' => '1.0.0',
                    'protocol_version' => Protocol::VERSION,
                    'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
                    'languages' => ['php'],
                    'file_extensions' => ['.php'],
                    'capabilities' => [],
                ]];
            }
            public function stderr(): string { return ''; }
        };

        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return true; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => true, 'signaled' => false, 'stopped' => false, 'exitcode' => -1, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        $session = new ScannerProtocolSession($process, $channel);
        $session->initialize();
        assertSame(1, $channel->requestCount);

        $session->close(false);
        $session->initialize();

        // After close(), manifest is null, so initialize() calls request again.
        assertSame(2, $channel->requestCount);
    }
}
