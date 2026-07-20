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
        assertSame('knossos.fake', $client->requireCapabilities(['discover', 'cancel'])->id);
        $client->shutdown();
        $error = captureThrows(fn() => $this->fakeWorkerClient('compliant')->requireCapabilities(['incremental']), WorkerException::class);
        assertSame('WORKER_CAPABILITY_MISMATCH', $error->diagnosticCode);

        $process = proc_open([
            PHP_BINARY,
            self::repositoryRoot() . '/tools/scanner-conformance',
            '--require=discover',
            '--',
            PHP_BINARY,
            self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php',
            'compliant',
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start scanner conformance runner.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new RuntimeException('Conformance runner failed: ' . ($stderr === false ? '' : $stderr));
        }
        $report = json_decode($stdout === false ? '' : $stdout, true, 512, JSON_THROW_ON_ERROR);
        assertSame(true, $report['conformant']);
        assertSame(['initialize', 'discover', 'empty_scan', 'shutdown'], array_column($report['checks'], 'name'));
    }
}
