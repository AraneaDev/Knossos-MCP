<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use InvalidArgumentException;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class WorkerTest extends KnossosTestCase
{
    #[Group('worker')]
    public function testWorkerSupervisorInitializesDiscoversScansCancelsAndShutsDown(): void
    {
        $client = $this->fakeWorkerClient('compliant');
        $manifest = $client->initialize();
        assertSame('knossos.fake', $manifest->id);
        assertSame('/workspace', $client->discover(['root' => '/workspace'])['root']);

        $contributions = iterator_to_array($client->scan(['request_id' => 'scan-1']));
        assertSame(1, count($contributions));
        assertSame('worker:file:src/Checkout.ts', $contributions[0]->ownerKey);
        assertSame('Checkout', $contributions[0]->nodes[0]->displayName);
        assertContains('fake worker scan log', $client->stderr());

        $client->cancel('scan-2');
        assertSame(['scan-2'], $client->discover(['root' => '/workspace'])['cancelled']);
        $client->shutdown();
    }

    #[Group('worker')]
    public function testWorkerSupervisorRejectsProtocolMismatchesBeforeDiscovery(): void
    {
        $client = $this->fakeWorkerClient('mismatch');
        $error = captureThrows(fn() => $client->initialize(), WorkerException::class);
        assertSame('WORKER_PROTOCOL_VERSION_MISMATCH', $error->diagnosticCode);
    }

    #[Group('worker')]
    public function testWorkerSupervisorContainsMalformedCrashedAndUnexpectedWorkers(): void
    {
        $cases = [
            'malformed' => 'WORKER_JSON_INVALID',
            'crash' => 'WORKER_EXITED',
            'unexpected_id' => 'WORKER_UNEXPECTED_RESPONSE',
        ];
        foreach ($cases as $mode => $code) {
            $error = captureThrows(fn() => $this->fakeWorkerClient($mode)->initialize(), WorkerException::class);
            assertSame($code, $error->diagnosticCode);
        }
    }

    #[Group('worker')]
    public function testWorkerSupervisorEnforcesTimeoutAndStreamLimits(): void
    {
        $timeout = $this->fakeWorkerClient('slow', new WorkerLimits(requestTimeoutMs: 30));
        $error = captureThrows(fn() => $timeout->initialize(), WorkerException::class);
        assertSame('WORKER_TIMEOUT', $error->diagnosticCode);

        $stderr = $this->fakeWorkerClient('stderr_flood', new WorkerLimits(maxStderrBytes: 100));
        $error = captureThrows(fn() => iterator_to_array($stderr->scan([])), WorkerException::class);
        assertSame('WORKER_STDERR_LIMIT', $error->diagnosticCode);

        $output = $this->fakeWorkerClient('output_flood', new WorkerLimits(maxLineBytes: 1024, maxOutputBytes: 2048));
        $error = captureThrows(fn() => iterator_to_array($output->scan([])), WorkerException::class);
        assertSame('WORKER_OUTPUT_LIMIT', $error->diagnosticCode);
    }

    #[Group('worker')]
    public function testProductionWorkerExecutionPolicyPermitsValidRequestsBeyondFiveSecondsWithinAFiniteCeiling(): void
    {
        $policy = new WorkerExecutionPolicy();
        assertSame(30_000, $policy->requestTimeoutMs);
        assertSame(120_000, $policy->metadata()['maximum_request_timeout_ms']);
        assertThrows(fn() => new WorkerExecutionPolicy(999), InvalidArgumentException::class);
        assertThrows(fn() => new WorkerExecutionPolicy(120_001), InvalidArgumentException::class);

        $client = $this->fakeWorkerClient('valid_over_five_seconds', $policy->limits());
        $contributions = iterator_to_array($client->scan(['files' => ['src/Checkout.ts']]));
        assertSame(1, count($contributions));
        assertSame(1, $client->lastScanResult()['count']);
        $client->shutdown();
    }

    #[Group('worker')]
    public function testWorkerSupervisorSchemaValidatesContributions(): void
    {
        $client = $this->fakeWorkerClient('invalid_contribution');
        $error = captureThrows(fn() => iterator_to_array($client->scan([])), WorkerException::class);
        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
    }
}
