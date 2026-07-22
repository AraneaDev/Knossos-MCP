<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Worker\{ProcessScannerClient, WorkerExecutionPolicy};
use Throwable;

/**
 * NOT marked `final`: PHPUnit 12.5's createMock does not honour the PHPDoc
 * @final annotation (only the language-level final keyword), so this class
 * must be non-final for direct mocking in PHPUnit. Callers should treat it as
 * semantically final — there is no use case for subclassing it.
 */
class LanguageWorkerPool
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
