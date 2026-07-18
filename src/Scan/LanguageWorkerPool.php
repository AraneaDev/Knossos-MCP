<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\{ProcessScannerClient, WorkerExecutionPolicy};
use Throwable;

final class LanguageWorkerPool
{
    /** @var array<string, ProcessScannerClient> */
    private array $clients = [];
    private ?int $timeoutMs = null;

    public function prepare(WorkerExecutionPolicy $policy): void
    {
        if ($this->timeoutMs !== null && $this->timeoutMs !== $policy->requestTimeoutMs) {
            $this->shutdown();
        }
        $this->timeoutMs = $policy->requestTimeoutMs;
    }

    public function client(LanguageDescriptor $descriptor, WorkerExecutionPolicy $policy): ProcessScannerClient
    {
        $this->prepare($policy);
        return $this->clients[$descriptor->key] ??= new ProcessScannerClient($descriptor->command, $policy->limits());
    }

    public function shutdown(): void
    {
        foreach ($this->clients as $client) {
            try {
                $client->shutdown();
            } catch (Throwable) {
            }
        }
        $this->clients = [];
        $this->timeoutMs = null;
    }
}
