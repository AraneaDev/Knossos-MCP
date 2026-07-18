<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\ScannerClient;

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

    public function initialize(): ScannerManifest
    {
        return $this->session->initialize();
    }

    /** @param list<string> $required */
    public function requireCapabilities(array $required): ScannerManifest
    {
        return $this->session->requireCapabilities($required);
    }

    public function discover(array $project): array
    {
        return $this->session->discover($project);
    }

    public function scan(array $request, ?callable $cancelled = null): iterable
    {
        return $this->session->scan($request, $cancelled);
    }

    public function cancel(string $requestId): void
    {
        $this->session->cancel($requestId);
    }

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
