<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use JsonException;
use Knossos\Application;
use Knossos\Scan\CancellationToken;
use Throwable;

final class StdioServer
{
    public const PROTOCOL_VERSION = '2025-11-25';
    /** Ceiling on lines parked during cancellation polling, so a flood cannot grow memory without bound. */
    private const MAX_PENDING_LINES = 1024;
    private bool $initialized = false;
    /** @var resource|null */
    private $input = null;
    /** @var list<string> */
    private array $pendingLines = [];
    private string $inputBuffer = '';
    /** @var array<string, true> */
    private array $cancelledRequests = [];

    public function __construct(
        private readonly ToolService $tools,
        private readonly int $maxLineBytes = 1_048_576,
        private readonly int $maxResponseBytes = 1_048_576,
        private readonly ?ResourceService $resources = null,
        private readonly ?PromptService $prompts = null,
    ) {}

    /** @param resource $input @param resource $output @param resource $errors */
    public function run($input, $output, $errors): int
    {
        $this->input = $input;
        stream_set_read_buffer($input, 0);
        while (($line = $this->nextLine($input)) !== false) {
            if (strlen($line) > $this->maxLineBytes || !str_ends_with($line, "\n")) {
                $this->write($output, $this->error(null, -32700, 'Invalid or oversized JSON-RPC frame.'));
                continue;
            }
            try {
                $message = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($message) || array_is_list($message)) {
                    throw new JsonException('JSON-RPC message must be an object.');
                }
                $response = $this->handle($message);
                if ($response !== null) {
                    $this->write($output, $response);
                }
            } catch (JsonException $error) {
                fwrite($errors, $error->getMessage() . PHP_EOL);
                $this->write($output, $this->error(null, -32700, 'Parse error'));
            } catch (Throwable $error) {
                fwrite($errors, $error->getMessage() . PHP_EOL);
                $id = isset($message) && is_array($message) ? ($message['id'] ?? null) : null;
                $this->write($output, $this->error($id, -32603, 'Internal error'));
            }
        }
        $this->input = null;
        return 0;
    }

    /** @param array<string, mixed> $message @return array<string, mixed>|null */
    public function handle(array $message): ?array
    {
        $id = $message['id'] ?? null;
        if (($message['jsonrpc'] ?? null) !== '2.0' || !isset($message['method']) || !is_string($message['method'])) {
            return $this->error($id, -32600, 'Invalid Request');
        }
        $method = $message['method'];
        if (!array_key_exists('id', $message)) {
            if ($method === 'notifications/initialized') {
                $this->initialized = true;
            } elseif ($method === 'notifications/cancelled') {
                $requestId = $message['params']['requestId'] ?? null;
                if (is_int($requestId) || is_string($requestId)) {
                    // A cancel whose request never arrives would otherwise linger
                    // forever; evict the oldest entry once the map is full so the
                    // set of pending cancellations stays bounded.
                    if (count($this->cancelledRequests) >= self::MAX_PENDING_LINES) {
                        array_shift($this->cancelledRequests);
                    }
                    $this->cancelledRequests[(string) $requestId] = true;
                }
            }
            return null;
        }
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return $this->error($id, -32602, 'Params must be an object.');
        }

        if ($method === 'initialize') {
            $requested = $params['protocolVersion'] ?? null;
            if (!is_string($requested)) {
                return $this->error($id, -32602, 'protocolVersion must be a string.');
            }
            return $this->success($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                    ...($this->resources === null ? [] : ['resources' => ['subscribe' => false, 'listChanged' => false]]),
                    ...($this->prompts === null ? [] : ['prompts' => ['listChanged' => false]]),
                ],
                'serverInfo' => [
                    'name' => 'knossos', 'title' => 'Knossos Architecture Intelligence',
                    'version' => Application::VERSION,
                    'description' => 'Evidence-backed architecture graph for PHP and TypeScript projects.',
                ],
                'instructions' => 'Call scan_project first, then query its returned project_id.',
            ]);
        }
        if ($method === 'ping') {
            return $this->success($id, (object) []);
        }
        if (!$this->initialized) {
            // Distinct from -32002 (used below for a missing resource) so a
            // client can tell "not initialized yet" from "no such resource".
            return $this->error($id, -32003, 'Server has not received notifications/initialized.');
        }
        if ($method === 'tools/list') {
            return $this->success($id, ['tools' => $this->tools->definitions()]);
        }
        if ($method === 'tools/call') {
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                return $this->error($id, -32602, 'Tool name and object arguments are required.');
            }
            try {
                $cancellation = new CancellationToken(fn(): bool => $this->pollCancellation($id));
                $envelope = $this->tools->call($name, $arguments, $cancellation);
                $structured = $envelope->jsonSerialize();
                $response = $this->success($id, [
                    'content' => [['type' => 'text', 'text' => json_encode($structured, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)]],
                    'structuredContent' => $structured,
                    'isError' => false,
                ]);
            } catch (ToolInputException $invalid) {
                // Unknown tool or malformed arguments: a protocol-level invalid
                // params error, not a tool that ran and failed.
                $response = $this->error($id, -32602, $invalid->getMessage());
            } catch (\Knossos\Scan\ScanCancelledException $cancelled) {
                if (isset($this->cancelledRequests[(string) $id])) {
                    // The client asked to cancel this request and is no longer
                    // waiting; drop the entry and send nothing back.
                    unset($this->cancelledRequests[(string) $id]);
                    return null;
                }
                $response = $this->toolError($id, 'KNOSSOS_SCAN_CANCELLED', $cancelled->getMessage());
            } catch (Throwable $error) {
                $code = match (true) {
                    $error instanceof \Knossos\Scan\ScanBusyException => 'KNOSSOS_SCAN_BUSY',
                    $error instanceof \Knossos\Scanner\Worker\WorkerException => $error->diagnosticCode,
                    $error instanceof \Knossos\Discovery\DiscoveryException => 'KNOSSOS_UNSAFE_PATH',
                    $error instanceof \InvalidArgumentException => 'KNOSSOS_INVALID_ARGUMENT',
                    default => 'KNOSSOS_TOOL_ERROR',
                };
                if ($code === 'KNOSSOS_TOOL_ERROR') {
                    // Unexpected failure: log the raw detail, return a generic
                    // message so internals never leak to the client.
                    error_log('knossos tool error: ' . $error->getMessage());
                    $response = $this->toolError($id, $code, 'An unexpected error occurred while running the tool.');
                } else {
                    $response = $this->toolError($id, $code, $error->getMessage());
                }
            }
            unset($this->cancelledRequests[(string) $id]);
            return $response;
        }

        if ($this->resources !== null && $method === 'resources/list') {
            return $this->success($id, ['resources' => $this->resources->list()]);
        }
        if ($this->resources !== null && $method === 'resources/read') {
            $uri = $params['uri'] ?? null;
            if (!is_string($uri)) {
                return $this->error($id, -32602, 'uri must be a string.');
            }
            $result = $this->resources->read($uri);
            return $result === null
                ? $this->error($id, -32002, 'Resource not found: ' . $uri)
                : $this->success($id, $result);
        }

        if ($this->prompts !== null && $method === 'prompts/list') {
            return $this->success($id, ['prompts' => $this->prompts->list()]);
        }
        if ($this->prompts !== null && $method === 'prompts/get') {
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments)) {
                return $this->error($id, -32602, 'Prompt name and object arguments are required.');
            }
            $result = $this->prompts->get($name, array_filter($arguments, 'is_string'));
            return $result === null
                ? $this->error($id, -32602, 'Unknown prompt: ' . $name)
                : $this->success($id, $result);
        }

        return $this->error($id, -32601, 'Method not found');
    }

    /** @return array<string, mixed> */
    private function success(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /** A tools/call failure that ran the tool: reported as an isError result, not a JSON-RPC error. @return array<string, mixed> */
    private function toolError(mixed $id, string $code, string $message): array
    {
        return $this->success($id, [
            'content' => [['type' => 'text', 'text' => $code . ': ' . $message]],
            'structuredContent' => ['error' => ['code' => $code, 'message' => $message]],
            'isError' => true,
        ]);
    }

    /** @param resource $output @param array<string, mixed> $message */
    private function write($output, array $message): void
    {
        $encoded = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($encoded) > $this->maxResponseBytes) {
            $encoded = json_encode(
                $this->error($message['id'] ?? null, -32001, 'Response exceeds the configured byte limit.'),
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        }
        fwrite($output, $encoded . "\n");
        fflush($output);
    }

    /** @param resource $input */
    private function nextLine($input): string|false
    {
        if ($this->pendingLines !== []) {
            return array_shift($this->pendingLines);
        }
        while (true) {
            $newline = strpos($this->inputBuffer, "\n");
            if ($newline !== false) {
                $line = substr($this->inputBuffer, 0, $newline + 1);
                $this->inputBuffer = substr($this->inputBuffer, $newline + 1);
                return $line;
            }
            $chunk = fread($input, 8192);
            if ($chunk === false) {
                return false;
            }
            if ($chunk === '' && feof($input)) {
                if ($this->inputBuffer === '') {
                    return false;
                }
                $line = $this->inputBuffer;
                $this->inputBuffer = '';
                return $line;
            }
            if ($chunk !== '') {
                $this->inputBuffer .= $chunk;
            }
            if (strlen($this->inputBuffer) > $this->maxLineBytes) {
                // Oversized frame: skip forward to the newline that ends it
                // without accumulating the discarded bytes, so the buffer stays
                // bounded no matter how long the bad line is.
                while (!str_contains($this->inputBuffer, "\n") && !feof($input)) {
                    $discard = fread($input, 8192);
                    if ($discard === false || $discard === '') {
                        break;
                    }
                    // Keep only the freshly read chunk (plus a 1-byte carry that
                    // is irrelevant for a single-byte newline); everything before
                    // the eventual newline is discarded anyway.
                    $this->inputBuffer = $discard;
                }
                $newline = strpos($this->inputBuffer, "\n");
                $this->inputBuffer = $newline === false ? '' : substr($this->inputBuffer, $newline + 1);
                return str_repeat('x', $this->maxLineBytes + 1) . "\n";
            }
        }
    }

    private function pollCancellation(mixed $requestId): bool
    {
        if (isset($this->cancelledRequests[(string) $requestId])) {
            return true;
        }
        if (!is_resource($this->input)) {
            return false;
        }
        $cancelled = false;
        stream_set_blocking($this->input, false);
        try {
            // Drain available input, but stop once a single frame's worth is
            // buffered so a client that never sends a newline cannot grow memory.
            while (
                strlen($this->inputBuffer) <= $this->maxLineBytes
                && ($chunk = fread($this->input, 8192)) !== false
                && $chunk !== ''
            ) {
                $this->inputBuffer .= $chunk;
            }
            if (strlen($this->inputBuffer) > $this->maxLineBytes && !str_contains($this->inputBuffer, "\n")) {
                // An oversized frame with no terminator cannot be a valid
                // message; drop it rather than hold it.
                $this->inputBuffer = '';
            }
            while (($newline = strpos($this->inputBuffer, "\n")) !== false) {
                $line = substr($this->inputBuffer, 0, $newline + 1);
                $this->inputBuffer = substr($this->inputBuffer, $newline + 1);
                try {
                    $message = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                } catch (JsonException) {
                    $this->rememberPendingLine($line);
                    continue;
                }
                if (
                    is_array($message)
                    && ($message['method'] ?? null) === 'notifications/cancelled'
                    && (($message['params']['requestId'] ?? null) === $requestId)
                ) {
                    $cancelled = true;
                    $this->cancelledRequests[(string) $requestId] = true;
                    continue;
                }
                $this->rememberPendingLine($line);
            }
        } finally {
            stream_set_blocking($this->input, true);
        }
        return $cancelled || isset($this->cancelledRequests[(string) $requestId]);
    }

    /** Park a non-cancellation line for the main loop, capped so a flood cannot grow memory without bound. */
    private function rememberPendingLine(string $line): void
    {
        if (count($this->pendingLines) >= self::MAX_PENDING_LINES) {
            return;
        }
        $this->pendingLines[] = $line;
    }
}
