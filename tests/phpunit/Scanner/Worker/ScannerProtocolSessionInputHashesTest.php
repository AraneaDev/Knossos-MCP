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

/**
 * `scan/input_hashes` notifications: parts of a request's map sent ahead of the
 * result, merged with the result's own field.
 */
#[Group('scanner-worker')]
final class ScannerProtocolSessionInputHashesTest extends TestCase
{
    public function testPartsAndTheResultFieldMergeIntoOneMap(): void
    {
        $session = $this->session([
            self::part(['src/a.ts' => self::hash('a')]),
            self::contribution(),
            self::part(['src/b.ts' => null, '7' => self::hash('seven')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['count' => 1, 'input_hashes' => ['src/c.ts' => self::hash('c')]]],
        ]);

        $contributions = iterator_to_array($session->scan(['files' => ['src/a.ts']]), false);

        assertSame(1, count($contributions));
        assertSame([
            'count' => 1,
            'input_hashes' => [
                'src/a.ts' => self::hash('a'),
                'src/b.ts' => null,
                '7' => self::hash('seven'),
                'src/c.ts' => self::hash('c'),
            ],
        ], $session->lastScanResult());
    }

    public function testAPathTwoPartsReportDifferentlyBecomesNull(): void
    {
        $session = $this->session([
            self::part(['src/a.ts' => self::hash('a'), 'src/b.ts' => self::hash('b')]),
            self::part(['src/a.ts' => self::hash('changed'), 'src/b.ts' => self::hash('b')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => ['src/b.ts' => null]]],
        ]);

        iterator_to_array($session->scan(['files' => []]), false);

        assertSame(['input_hashes' => ['src/a.ts' => null, 'src/b.ts' => null]], $session->lastScanResult());
    }

    public function testAResultWithoutPartsIsLeftAsTheWorkerSentIt(): void
    {
        $session = $this->session([
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => ['/absolute' => 'not a hash']]],
        ]);

        iterator_to_array($session->scan(['files' => []]), false);

        assertSame(['input_hashes' => ['/absolute' => 'not a hash']], $session->lastScanResult());
    }

    public function testADeclaringWorkerThatSentPartsButNoResultFieldIsLeftWithoutOne(): void
    {
        // The result's field is the marker that the worker finished reporting;
        // the scan refuses its absence (ScanInputHashes), parts or not.
        $session = $this->session([
            self::part(['src/a.ts' => self::hash('a')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['count' => 0]],
        ]);

        iterator_to_array($session->scan(['files' => []]), false);

        assertSame(['count' => 0], $session->lastScanResult());
    }

    public function testPartsFromAWorkerThatDidNotDeclareTheCapabilityAreStillEvidence(): void
    {
        $session = $this->session([
            self::part(['src/a.ts' => self::hash('a')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['count' => 0]],
        ], capabilities: []);

        iterator_to_array($session->scan(['files' => []]), false);

        assertSame(['count' => 0, 'input_hashes' => ['src/a.ts' => self::hash('a')]], $session->lastScanResult());
    }

    public function testAMalformedPartFailsTheRequest(): void
    {
        $session = $this->session([
            self::part(['src/../secret.ts' => self::hash('a')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => []]],
        ]);

        $error = captureThrows(static fn() => iterator_to_array($session->scan(['files' => []]), false), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertSame('knossos.fake sent input_hashes key src/../secret.ts contains an invalid path segment.', $error->getMessage());
    }

    public function testAPartWhoseParamsCarryNoMapFailsTheRequest(): void
    {
        $session = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'scan/input_hashes', 'params' => ['hashes' => []]],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => []]],
        ]);

        $error = captureThrows(static fn() => iterator_to_array($session->scan(['files' => []]), false), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertSame('knossos.fake sent a scan/input_hashes notification whose params are not an object carrying input_hashes.', $error->getMessage());
    }

    public function testAPartWithListParamsFailsTheRequest(): void
    {
        $session = $this->session([
            ['jsonrpc' => '2.0', 'method' => 'scan/input_hashes', 'params' => [['input_hashes' => []]]],
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => []]],
        ]);

        $error = captureThrows(static fn() => iterator_to_array($session->scan(['files' => []]), false), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
    }

    public function testAMalformedResultFieldAfterPartsFailsTheRequest(): void
    {
        $session = $this->session([
            self::part(['src/a.ts' => self::hash('a')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => 'src/a.ts']],
        ]);

        $error = captureThrows(static fn() => iterator_to_array($session->scan(['files' => []]), false), WorkerException::class);

        assertSame('WORKER_RESPONSE_INVALID', $error->diagnosticCode);
        assertSame('knossos.fake sent input_hashes that is not an object; it must be an object keyed by path.', $error->getMessage());
    }

    public function testPartsDoNotCarryOverToTheNextRequest(): void
    {
        $session = $this->session([
            self::part(['src/a.ts' => self::hash('a')]),
            ['jsonrpc' => '2.0', 'id' => 2, 'result' => ['input_hashes' => []]],
            ['jsonrpc' => '2.0', 'id' => 3, 'result' => ['input_hashes' => ['src/b.ts' => self::hash('b')]]],
        ]);

        iterator_to_array($session->scan(['files' => []]), false);
        iterator_to_array($session->scan(['files' => []]), false);

        assertSame(['input_hashes' => ['src/b.ts' => self::hash('b')]], $session->lastScanResult());
    }

    /**
     * @param list<array<string, mixed>> $scanMessages
     * @param list<string> $capabilities
     */
    private function session(array $scanMessages, array $capabilities = ['content_hash', 'input_hashes']): ScannerProtocolSession
    {
        $manifest = ['jsonrpc' => '2.0', 'id' => 1, 'result' => [
            'id' => 'knossos.fake',
            'version' => '1.0.0',
            'protocol_version' => Protocol::VERSION,
            'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
            'languages' => ['typescript'],
            'file_extensions' => ['.ts'],
            'capabilities' => $capabilities,
        ]];
        $channel = new class ([$manifest, ...$scanMessages]) implements RpcChannelInterface {
            /** @param list<array<string, mixed>> $messages */
            public function __construct(private array $messages) {}
            public function beginRequest(): int { return hrtime(true) + 10_000_000_000; }
            public function send(array $message, ?callable $cancelled = null): void {}
            public function readMessage(int $deadline, ?callable $cancelled = null): array
            {
                $message = array_shift($this->messages);
                if ($message === null) {
                    throw new WorkerException('WORKER_TIMEOUT', 'No more messages.');
                }

                return $message;
            }
            public function stderr(): string { return ''; }
        };
        $process = new class implements ProcessSupervisorInterface {
            public function start(): void {}
            public function isRunning(): bool { return false; }
            public function stdin() { return fopen('php://temp', 'r+'); }
            public function stdout() { return fopen('php://temp', 'r+'); }
            public function stderr() { return fopen('php://temp', 'r+'); }
            public function status(): array { return ['command' => '', 'pid' => 0, 'running' => false, 'signaled' => false, 'stopped' => false, 'exitcode' => 0, 'termsig' => 0, 'stopsig' => 0]; }
            public function close(bool $terminate): void {}
        };

        return new ScannerProtocolSession($process, $channel);
    }

    /**
     * @param array<string, string|null> $inputHashes
     * @return array<string, mixed>
     */
    private static function part(array $inputHashes): array
    {
        return ['jsonrpc' => '2.0', 'method' => 'scan/input_hashes', 'params' => ['input_hashes' => $inputHashes]];
    }

    /** @return array<string, mixed> */
    private static function contribution(): array
    {
        return ['jsonrpc' => '2.0', 'method' => 'scan/contribution', 'params' => [
            'owner_key' => 'knossos.fake:file:src/a.ts',
            'nodes' => [],
            'edges' => [],
            'diagnostics' => [],
        ]];
    }

    private static function hash(string $content): string
    {
        return hash('sha256', $content);
    }
}
