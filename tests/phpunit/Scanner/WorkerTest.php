<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use InvalidArgumentException;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class WorkerTest extends KnossosTestCase
{
    #[Group('worker')]
    public function testProcessScannerClientRejectsEmptyCommand(): void
    {
        $error = captureThrows(static fn() => new ProcessScannerClient([]), WorkerException::class);
        assertSame('WORKER_COMMAND_INVALID', $error->diagnosticCode);
    }

    #[Group('worker')]
    public function testWorkerSupervisorInitializesScansCancelsAndShutsDown(): void
    {
        $client = $this->fakeWorkerClient('compliant');
        $manifest = $client->initialize();
        assertSame('knossos.fake', $manifest->id);

        $contributions = iterator_to_array($client->scan(['request_id' => 'scan-1']));
        assertSame(1, count($contributions));
        assertSame('worker:file:src/Checkout.ts', $contributions[0]->ownerKey);
        assertSame('Checkout', $contributions[0]->nodes[0]->displayName);
        assertContains('fake worker scan log', $client->stderr());

        $client->cancel('scan-2');
        // An empty scan is the cheapest round trip left now that discover is
        // gone, and the fake worker echoes the ids it was told to cancel.
        assertSame([], iterator_to_array($client->scan(['root' => '/workspace', 'files' => []])));
        assertSame(['scan-2'], $client->lastScanResult()['cancelled']);
        $client->shutdown();
    }

    /**
     * Cancellation of a request that is still in flight.
     *
     * The test above only replays fake-worker state: it cancels an id after
     * that scan has already completed, then reads the echo back on the next
     * round trip. Nothing in it exercises the path that matters — a request
     * with no reply coming, a cancellation callback that turns true while the
     * host is still waiting, the notification that follows, and the worker
     * being torn down rather than left running.
     *
     * The request id is also asserted to arrive as an int. The host numbers
     * its own requests with integers, and a type-strict worker matching
     * `request_id === $active` never matches a stringified copy, so a cancel
     * that looked correct on the wire would silently cancel nothing.
     */
    #[Group('worker')]
    public function testCancellingAnActiveScanTerminatesTheWorkerAndSendsTheIntegerRequestId(): void
    {
        $record = sys_get_temp_dir() . '/knossos-blocked-scan-' . bin2hex(random_bytes(6));
        $client = new ProcessScannerClient([
            PHP_BINARY,
            self::repositoryRoot() . '/tests/Fixtures/workers/fake-worker.php',
            'blocked_scan',
            $record,
        ]);
        try {
            $client->initialize();
            $cancel = false;
            $received = 0;
            $error = captureThrows(
                static function () use ($client, &$cancel, &$received): void {
                    // The callback turns true only once a contribution has been
                    // handed back, which is the deterministic point at which the
                    // request is provably still active: the worker has answered
                    // part of it and will never answer the rest.
                    foreach ($client->scan(
                        ['root' => '/workspace', 'files' => ['src/Checkout.ts']],
                        // By reference: an arrow function would capture the flag
                        // by value and never see the loop body set it.
                        static function () use (&$cancel): bool {
                            return $cancel;
                        },
                    ) as $contribution) {
                        ++$received;
                        $cancel = true;
                    }
                },
                WorkerException::class,
            );
            assertSame('WORKER_CANCELLED', $error->diagnosticCode);
            assertSame(1, $received);

            $lines = array_values(array_filter(explode("\n", (string) file_get_contents($record))));
            $workerPid = (int) $lines[0];
            $cancel = json_decode($lines[1] ?? '', true, 512, JSON_THROW_ON_ERROR);
            // initialize() took id 1, so the scan the host cancelled is 2.
            assertSame(2, $cancel['request_id']);
            assertSame('int', $cancel['type']);
            assertSame(false, self::processIsAlive($workerPid));
        } finally {
            unset($client);
            @unlink($record);
        }
    }

    #[Group('worker')]
    public function testWorkerSupervisorRejectsProtocolMismatchesBeforeAnyProjectInput(): void
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
