<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\ScannerClient;

/** Drives a scanner running as a child process: initialize, discover, scan, shutdown. */
final class ProcessScannerClient implements ScannerClient
{
    private readonly ScannerProtocolSession $session;

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string>|null $environment
     */
    public function __construct(
        array $command,
        WorkerLimits $limits = new WorkerLimits(),
        ?array $environment = null,
    ) {
        if ($command === []) {
            throw new WorkerException('WORKER_COMMAND_INVALID', 'Worker command must not be empty.');
        }

        $process = new WorkerProcessSupervisor($command, $environment);
        $this->session = new ScannerProtocolSession($process, new NdjsonRpcChannel($process, $limits));
    }

    public function __destruct()
    {
        $this->session->close(true);
    }

    /** {@inheritDoc} */
    public function initialize(): ScannerManifest
    {
        return $this->session->initialize();
    }

    /** @param list<string> $required */
    public function requireCapabilities(array $required): ScannerManifest
    {
        return $this->session->requireCapabilities($required);
    }

    /** {@inheritDoc} */
    public function discover(array $project): array
    {
        return $this->session->discover($project);
    }

    /** {@inheritDoc} */
    public function scan(array $request, ?callable $cancelled = null): iterable
    {
        return $this->session->scan($request, $cancelled);
    }

    /** {@inheritDoc} */
    public function cancel(string $requestId): void
    {
        $this->session->cancel($requestId);
    }

    /** {@inheritDoc} */
    public function shutdown(): void
    {
        $this->session->shutdown();
    }

    public function stderr(): string
    {
        return $this->session->stderr();
    }

    /** @return array<string, mixed> */
    public function lastScanResult(): array
    {
        return $this->session->lastScanResult();
    }
}
