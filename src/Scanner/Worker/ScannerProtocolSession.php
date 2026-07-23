<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Throwable;

final class ScannerProtocolSession
{
    private int $nextId = 1;
    private ?ScannerManifest $manifest = null;
    /** @var array<string, mixed> */
    private array $lastScanResult = [];

    public function __construct(
        private readonly ProcessSupervisorInterface $process,
        private readonly RpcChannelInterface $channel,
    ) {}

    public function initialize(): ScannerManifest
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $result = $this->request(Protocol::METHOD_INITIALIZE, [
            'protocol_version' => Protocol::VERSION,
            'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
        ]);

        try {
            $manifest = ScannerManifest::fromArray($result);
        } catch (Throwable $error) {
            $this->close(true);
            throw new WorkerException('WORKER_MANIFEST_INVALID', $error->getMessage(), $error);
        }

        if ($manifest->protocolVersion !== Protocol::VERSION) {
            $this->close(true);
            throw new WorkerException(
                'WORKER_PROTOCOL_VERSION_MISMATCH',
                sprintf('Worker protocol %s is incompatible with core protocol %s.', $manifest->protocolVersion, Protocol::VERSION),
            );
        }
        if ($manifest->outputSchemaVersion !== Protocol::OUTPUT_SCHEMA_VERSION) {
            $this->close(true);
            throw new WorkerException(
                'WORKER_OUTPUT_SCHEMA_MISMATCH',
                sprintf(
                    'Worker output schema %s is incompatible with core schema %s.',
                    $manifest->outputSchemaVersion,
                    Protocol::OUTPUT_SCHEMA_VERSION,
                ),
            );
        }

        return $this->manifest = $manifest;
    }

    /** @param list<string> $required */
    public function requireCapabilities(array $required): ScannerManifest
    {
        $manifest = $this->initialize();
        $missing = array_values(array_diff(array_unique($required), $manifest->capabilities));
        if ($missing !== []) {
            $this->close(true);
            throw new WorkerException(
                'WORKER_CAPABILITY_MISMATCH',
                sprintf('Worker %s does not provide required capabilities: %s.', $manifest->id, implode(', ', $missing)),
            );
        }
        return $manifest;
    }

    /** @param array<string, mixed> $project @return array<string, mixed> */
    public function discover(array $project): array
    {
        $this->initialize();
        return $this->request(Protocol::METHOD_DISCOVER, $project);
    }

    /** @param array<string, mixed> $request @return iterable<ScanContribution> */
    public function scan(array $request, ?callable $cancelled = null): iterable
    {
        $this->initialize();
        $id = $this->nextId++;
        $deadline = $this->channel->beginRequest();
        $this->channel->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => Protocol::METHOD_SCAN,
            'params' => $request,
        ], $cancelled);

        $completed = false;
        try {
            while (true) {
                if ($cancelled !== null && $cancelled()) {
                    $this->cancel($id);
                    $this->close(true);
                    throw new WorkerException('WORKER_CANCELLED', 'Scanner worker request was cancelled.');
                }
                $message = $this->channel->readMessage($deadline, $cancelled);
                if (!array_key_exists('id', $message)) {
                    $contribution = $this->decodeContribution($message);
                    if ($contribution !== null) {
                        yield $contribution;
                    }
                    continue;
                }

                $this->assertResponseId($message, $id);
                $this->throwRpcError($message);
                $result = $message['result'] ?? null;
                if (!is_array($result) || ($result !== [] && array_is_list($result))) {
                    throw new WorkerException('WORKER_RESPONSE_INVALID', 'Worker scan result must be an object.');
                }
                $this->lastScanResult = $result;
                $completed = true;
                return;
            }
        } catch (WorkerException $error) {
            $this->close(true);
            throw $error;
        } finally {
            // If the caller abandons the generator before the final response
            // (early break, unset, or an exception unwinding past it), the
            // worker still holds an unread response frame that would fail the
            // NEXT request on this pooled session with a protocol error. Drain
            // it to the final response; if that cannot complete promptly,
            // discard the worker so it is never reused in a poisoned state.
            if (!$completed) {
                $this->drainAbandonedScan($id);
            }
        }
    }

    /**
     * @param int|string $requestId Sent verbatim so the id type stays
     * consistent end-to-end: an int scan id must not be stringified, or a
     * type-strict worker will never match the in-flight request.
     */
    public function cancel(int|string $requestId): void
    {
        if (!$this->process->isRunning()) {
            return;
        }

        $this->channel->send([
            'jsonrpc' => '2.0',
            'method' => Protocol::METHOD_CANCEL,
            'params' => ['request_id' => $requestId],
        ]);
    }

    private function drainAbandonedScan(int $id): void
    {
        if (!$this->process->isRunning()) {
            return;
        }

        // Bounded budget: already-buffered frames drain in microseconds; a
        // worker still computing must not stall generator destruction, so we
        // fall back to closing it.
        $deadline = hrtime(true) + 250_000_000;
        try {
            while (true) {
                $message = $this->channel->readMessage($deadline);
                if (!array_key_exists('id', $message)) {
                    continue;
                }
                if (($message['id'] ?? null) === $id) {
                    $result = $message['result'] ?? null;
                    if (is_array($result) && !($result !== [] && array_is_list($result))) {
                        $this->lastScanResult = $result;
                    }
                }
                return;
            }
        } catch (Throwable) {
            $this->close(true);
        }
    }

    public function shutdown(): void
    {
        if (!$this->process->isRunning()) {
            return;
        }

        try {
            $this->request(Protocol::METHOD_SHUTDOWN, []);
        } catch (WorkerException) {
            // Shutdown remains best-effort; process cleanup below is authoritative.
        } finally {
            $this->close(true);
        }
    }

    public function close(bool $terminate): void
    {
        $this->process->close($terminate);
        $this->manifest = null;
    }

    public function stderr(): string
    {
        return $this->channel->stderr();
    }

    /** @return array<string, mixed> */
    public function lastScanResult(): array
    {
        return $this->lastScanResult;
    }

    /** @param array<string, mixed> $params @return array<string, mixed> */
    private function request(string $method, array $params): array
    {
        $id = $this->nextId++;
        $deadline = $this->channel->beginRequest();
        $this->channel->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        try {
            while (true) {
                $message = $this->channel->readMessage($deadline);
                if (!array_key_exists('id', $message)) {
                    continue;
                }
                $this->assertResponseId($message, $id);
                $this->throwRpcError($message);
                $result = $message['result'] ?? null;
                if (!is_array($result) || ($result !== [] && array_is_list($result))) {
                    throw new WorkerException('WORKER_RESPONSE_INVALID', 'Worker result must be an object.');
                }

                return $result;
            }
        } catch (WorkerException $error) {
            $this->close(true);
            throw $error;
        }
    }

    /** @param array<string, mixed> $message */
    private function decodeContribution(array $message): ?ScanContribution
    {
        if (($message['method'] ?? null) !== 'scan/contribution') {
            return null;
        }
        $params = $message['params'] ?? null;
        if (!is_array($params) || array_is_list($params)) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'Contribution params must be an object.');
        }
        return ContributionDecoder::decode($params);
    }

    /** @param array<string, mixed> $message */
    private function assertResponseId(array $message, int $expected): void
    {
        if (($message['id'] ?? null) !== $expected) {
            throw new WorkerException('WORKER_UNEXPECTED_RESPONSE', 'Worker response ID does not match the active request.');
        }
    }

    /** @param array<string, mixed> $message */
    private function throwRpcError(array $message): void
    {
        if (!isset($message['error'])) {
            return;
        }
        $error = $message['error'];
        $detail = is_array($error) && is_string($error['message'] ?? null)
            ? $error['message']
            : 'Scanner worker returned an unspecified JSON-RPC error.';
        throw new WorkerException('WORKER_RPC_ERROR', $detail);
    }
}
