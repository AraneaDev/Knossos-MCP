<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Sdk\FixtureBuilder;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class ScannerSdkTest extends KnossosTestCase
{
    #[Group('scanner-sdk')]
    public function testScannerSdkFixturesCapabilitiesSchemasAndConformanceRunnerAgree(): void
    {
        $node = FixtureBuilder::node('demo:class:Checkout', 'class', 'Demo\\Checkout', 'Checkout', 'src/Checkout.demo', 2, 4);
        $edge = FixtureBuilder::edge('calls', 'demo:function:run', 'demo:class:Checkout', 'src/Checkout.demo', 6);
        $contribution = FixtureBuilder::contribution('demo:file:src/Checkout.demo', [$node], [$edge]);
        $decoded = \Knossos\Scanner\Worker\ContributionDecoder::decode($contribution);
        assertSame('Demo\\Checkout', $decoded->nodes[0]->canonicalName);
        assertSame('calls', $decoded->edges[0]->kind);

        foreach (['manifest.schema.json', 'contribution.schema.json'] as $schema) {
            $decodedSchema = json_decode((string) file_get_contents(self::repositoryRoot() . '/schemas/scanner/v1/' . $schema), true, 512, JSON_THROW_ON_ERROR);
            assertSame('https://json-schema.org/draft/2020-12/schema', $decodedSchema['$schema']);
        }
        $golden = json_decode((string) file_get_contents(self::repositoryRoot() . '/tests/Fixtures/scanner-sdk/golden.json'), true, 512, JSON_THROW_ON_ERROR);
        assertSame(Protocol::VERSION, $golden['initialize']['protocol_version']);

        $client = $this->fakeWorkerClient('compliant');
        assertSame('knossos.fake', $client->requireCapabilities(['partial_ast'])->id);
        $client->shutdown();
        $error = captureThrows(fn() => $this->fakeWorkerClient('compliant')->requireCapabilities(['incremental']), WorkerException::class);
        assertSame('WORKER_CAPABILITY_MISMATCH', $error->diagnosticCode);

        [$exitCode, $report] = $this->runConformance(['--require=partial_ast'], 'compliant');
        if ($exitCode !== 0) {
            throw new RuntimeException('Conformance runner failed: ' . json_encode($report));
        }
        assertSame(true, $report['conformant']);
        assertSame(['initialize', 'empty_scan', 'shutdown'], array_column($report['checks'], 'name'));
    }

    /**
     * @param list<string> $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runConformance(array $options, string $mode, bool $relativeWorkerPath = false): array
    {
        $worker = 'tests/Fixtures/workers/fake-worker.php';
        $process = proc_open([
            PHP_BINARY,
            self::repositoryRoot() . '/tools/scanner-conformance',
            ...$options,
            '--',
            PHP_BINARY,
            $relativeWorkerPath ? $worker : self::repositoryRoot() . '/' . $worker,
            $mode,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, self::repositoryRoot());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start scanner conformance runner.');
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, json_decode($stdout === false ? '' : $stdout, true, 512, JSON_THROW_ON_ERROR)];
    }

    #[Group('scanner-sdk')]
    public function testConformanceChecksTheContentHashOfAWorkerThatDeclaresIt(): void
    {
        [$exit, $report] = $this->runConformance([], 'hash_honest');
        assertSame(0, $exit);
        assertSame(['initialize', 'empty_scan', 'content_hash', 'shutdown'], array_column($report['checks'], 'name'));

        [$exit, $report] = $this->runConformance([], 'hash_missing');
        assertSame(1, $exit);
        assertSame(false, $report['conformant']);
        assertSame('fail', array_column($report['checks'], 'status', 'name')['content_hash']);

        // A worker that does not declare it is not asked.
        [$exit, $report] = $this->runConformance([], 'compliant');
        assertSame(0, $exit);
        assertSame(['initialize', 'empty_scan', 'shutdown'], array_column($report['checks'], 'name'));
    }

    /**
     * The worker runs with the fixture root as its working directory, so the
     * documented `-- python3 worker.py` form only works because the tool
     * anchors a relative path to the directory it was started from.
     */
    #[Group('scanner-sdk')]
    public function testConformanceAcceptsAWorkerPathRelativeToTheCallersDirectory(): void
    {
        [$exit, $report] = $this->runConformance([], 'hash_honest', relativeWorkerPath: true);

        assertSame(0, $exit, json_encode($report, JSON_THROW_ON_ERROR));
        assertSame(true, $report['conformant']);
        assertSame(['initialize', 'empty_scan', 'content_hash', 'shutdown'], array_column($report['checks'], 'name'));
    }
}
