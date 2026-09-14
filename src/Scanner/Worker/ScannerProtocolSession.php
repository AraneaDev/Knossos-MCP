<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Throwable;

/**
 * Enforces the worker protocol's lifecycle and version agreement.
 *
 * Rejects a worker whose protocol version differs rather than half-understanding
 * it, and keeps request/response ordering so a late reply is never matched to the
 * wrong request.
 */
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

    /** Handshake with the worker and verify its identity and protocol version. */
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

    /**
     * Refuse a worker that does not declare the capabilities this scan needs.
     *
     * @param list<string> $required
     */
    public function requireCapabilities(array $required): ScannerManifest
    {
        $manifest = $this->initialize();
        $missing = array_diff(array_unique($required), $manifest->capabilities);
        if ($missing !== []) {
            $this->close(true);
            throw new WorkerException(
                'WORKER_CAPABILITY_MISMATCH',
                sprintf('Worker %s does not provide required capabilities: %s.', $manifest->id, implode(', ', $missing)),
            );
        }
        return $manifest;
    }

    /**
     * Send a scan request and decode the contribution it returns.
     *
     * @param array<string, mixed> $request @return iterable<ScanContribution>
     */
    public function scan(array $request, ?callable $cancelled = null): iterable
    {
        $manifest = $this->initialize();
        $id = $this->nextId++;
        $deadline = $this->channel->beginRequest();
        $this->channel->send([
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => Protocol::METHOD_SCAN,
            'params' => $request,
        ], $cancelled);

        $completed = false;
        // Parts of this request's input_hashes map sent ahead of the result,
        // null until the first one arrives.
        $inputHashes = null;
        try {
            while (true) {
                if ($cancelled !== null && $cancelled()) {
                    // Terminated by the catch below, like any other failure.
                    $this->cancel($id);
                    throw new WorkerException('WORKER_CANCELLED', 'Scanner worker request was cancelled.');
                }
                $message = $this->channel->readMessage($deadline, $cancelled);
                if (!array_key_exists('id', $message)) {
                    if (($message['method'] ?? null) === Protocol::NOTIFICATION_INPUT_HASHES) {
                        // Merge mutates an owned variable by reference so that
                        // accumulating many parts stays linear in their total
                        // size; `$inputHashes ?? []` would be a new expression
                        // each time and PHP cannot bind a by-reference
                        // parameter to it.
                        $inputHashes ??= [];
                        InputHashesMap::merge($inputHashes, $this->decodeInputHashesPart($message, $manifest));
                        continue;
                    }
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
                if ($inputHashes !== null) {
                    $result = $this->withInputHashesParts($result, $inputHashes, $manifest);
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

    /** Discard a cancelled scan's pending reply so the channel is reusable rather than desynchronised. */
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
                if (($message['id'] ?? null) !== $id) {
                    // A reply to some other request: the channel is out of step,
                    // and this request's own reply may still be on its way, to be
                    // read as the answer to the next request on this worker.
                    $this->close(true);
                    return;
                }
                $result = $message['result'] ?? null;
                if (is_array($result) && !($result !== [] && array_is_list($result))) {
                    $this->lastScanResult = $result;
                }
                return;
            }
        } catch (Throwable) {
            $this->close(true);
        }
    }

    /** Ask the worker to exit cleanly, then release the channel. */
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

    /** Release the channel without expecting a reply, for a worker already gone. */
    public function close(bool $terminate): void
    {
        $this->process->close($terminate);
        $this->manifest = null;
    }

    /** Whatever the worker wrote to stderr, surfaced in diagnostics rather than discarded. */
    public function stderr(): string
    {
        return $this->channel->stderr();
    }

    /**
     * The most recent scan reply, retained for diagnostics.
     *
     * @return array<string, mixed>
     */
    public function lastScanResult(): array
    {
        return $this->lastScanResult;
    }

    /**
     * Send one request and read its reply, under the deadline and size caps.
     *
     * @param array<string, mixed> $params @return array<string, mixed>
     */
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

    /**
     * Validate one `scan/input_hashes` notification into the part of the map it carries.
     *
     * @param array<string, mixed> $message
     * @return array<string, string|null>
     */
    private function decodeInputHashesPart(array $message, ScannerManifest $manifest): array
    {
        $workerId = $manifest->id;
        $params = $message['params'] ?? null;
        if (!is_array($params) || array_is_list($params) || !array_key_exists(InputHashesMap::FIELD, $params)) {
            throw InputHashesMap::invalid($workerId, sprintf('sent a %s notification whose params are not an object carrying %s', Protocol::NOTIFICATION_INPUT_HASHES, InputHashesMap::FIELD));
        }

        return InputHashesMap::decode($params[InputHashesMap::FIELD], $workerId);
    }

    /**
     * Fold the parts sent ahead of a result into the result's own map.
     *
     * The result's `input_hashes` stays the marker that the worker finished
     * reporting: a declaring worker that sent parts but no field is left
     * without one, which the scan refuses as it would any missing field. A
     * worker that never declared the capability owes no marker, and the parts
     * it sent are still evidence to check.
     *
     * @param array<string, mixed> $result
     * @param array<string, string|null> $parts
     * @return array<string, mixed>
     */
    private function withInputHashesParts(array $result, array $parts, ScannerManifest $manifest): array
    {
        if (array_key_exists(InputHashesMap::FIELD, $result)) {
            $own = InputHashesMap::decode($result[InputHashesMap::FIELD], $manifest->id);
            $result[InputHashesMap::FIELD] = InputHashesMap::merge($parts, $own);
        } elseif (!in_array(Protocol::CAPABILITY_INPUT_HASHES, $manifest->capabilities, true)) {
            $result[InputHashesMap::FIELD] = $parts;
        }

        return $result;
    }

    /**
     * Validate a reply into a contribution, rejecting anything malformed.
     *
     * @param array<string, mixed> $message
     */
    private function decodeContribution(array $message): ?ScanContribution
    {
        if (($message['method'] ?? null) !== Protocol::NOTIFICATION_CONTRIBUTION) {
            return null;
        }
        $params = $message['params'] ?? null;
        if (!is_array($params) || array_is_list($params)) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'Contribution params must be an object.');
        }
        return ContributionDecoder::decode($params);
    }

    /**
     * Reject a reply whose id does not match the request, so a late answer is never mismatched.
     *
     * @param array<string, mixed> $message
     */
    private function assertResponseId(array $message, int $expected): void
    {
        if (($message['id'] ?? null) !== $expected) {
            throw new WorkerException('WORKER_UNEXPECTED_RESPONSE', 'Worker response ID does not match the active request.');
        }
    }

    /**
     * Turn a protocol error reply into a WorkerException with a stable diagnostic code.
     *
     * @param array<string, mixed> $message
     */
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
