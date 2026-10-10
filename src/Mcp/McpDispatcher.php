<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Closure;
use Knossos\Application;
use Knossos\Mcp\Protocol\ProtocolNegotiator;
use Knossos\Mcp\Protocol\ProtocolProfile;
use Knossos\Mcp\Protocol\UnsupportedProtocolVersionException;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ScanCancelledException;
use Throwable;

/**
 * The JSON-RPC session and method dispatch shared by every MCP transport.
 *
 * One instance is one connection's state: whether the handshake completed, which
 * revision the last request selected, and which requests the client has asked to
 * cancel. Stdio keeps one for the life of the process; HTTP builds a fresh one per
 * request and resumes the session it stored itself. Framing, byte caps on the
 * wire, and reading input stay with the transport, so which protocol revision
 * governs a message is decided here, per message, and both supported revisions
 * and both transports share one dispatcher.
 */
final class McpDispatcher
{
    /**
     * The revision reported by the `initialize` handshake.
     *
     * Handshake-era clients are by definition `2025-11-25` clients; newer
     * revisions removed `initialize` and announce themselves per request. The
     * full supported set lives in {@see ProtocolNegotiator::SUPPORTED} and is
     * advertised through `server/discover`.
     */
    public const PROTOCOL_VERSION = '2025-11-25';
    private const INSTRUCTIONS = 'Call scan_project first, then query its returned project_id.';
    /** Ceiling on pending cancellations, so cancels for requests that never arrive cannot grow memory without bound. */
    private const MAX_PENDING_CANCELLATIONS = 1024;
    private bool $initialized = false;
    private readonly ProtocolNegotiator $negotiator;
    /** The revision governing the most recent request; null until one arrives. */
    private ?ProtocolProfile $profile = null;
    /** The stream lifecycle notes go to; null unless a transport supplies one. @var resource|null */
    private $notes = null;
    /** The revision last written to the log, so one connection logs one line. */
    private ?string $notedProtocol = null;
    /** @var array<string, int|string> pending cancels: cancelKey() => the request id they name */
    private array $cancelledRequests = [];

    public function __construct(
        private readonly ToolService $tools,
        private readonly ?ResourceService $resources = null,
        private readonly ?PromptService $prompts = null,
    ) {
        $this->negotiator = new ProtocolNegotiator();
    }

    /**
     * Continue a session whose handshake happened on an earlier request.
     *
     * For a transport that keeps the handshake in its own session store and
     * builds a fresh dispatcher per request: the dispatcher starts initialized and
     * pinned to the revision the session negotiated, so a `_meta`-less request
     * gets that revision's envelope and error codes, as it would over stdio.
     *
     * @throws UnsupportedProtocolVersionException when the revision is not on offer
     */
    public function resumeSession(string $protocolVersion): void
    {
        $this->negotiator->pin($protocolVersion);
        $this->initialized = true;
    }

    /**
     * Send the once-per-connection protocol line to $notes from now on.
     *
     * Only a transport with a side channel for diagnostics supplies one; without
     * it the line is simply not written.
     *
     * @param resource $notes
     */
    public function logProtocolTo($notes): void
    {
        $this->notes = $notes;
    }

    /** The revision that governed the most recent request, or null before the first one. */
    public function profile(): ?ProtocolProfile
    {
        return $this->profile;
    }

    /**
     * Handle one JSON-RPC message, selecting the protocol revision and decorating the result.
     *
     * $pollCancellation is how a transport that can read ahead while a tool runs
     * reports a cancel it found for the running request id. A transport that
     * cannot passes null, and a running tool then sees only cancels this
     * dispatcher has already been handed.
     *
     * @param array<string, mixed> $message
     * @param (Closure(int|string): bool)|null $pollCancellation
     * @return array<string, mixed>|null
     */
    public function handle(array $message, ?Closure $pollCancellation = null): ?array
    {
        $id = JsonRpcId::reply($message['id'] ?? null);
        if (($message['jsonrpc'] ?? null) !== '2.0') {
            return self::error($id, -32600, 'Invalid Request');
        }
        if (!isset($message['method'])) {
            // A JSON-RPC response — id plus exactly one of result or error, no
            // method — such as the client's reply to a keepalive ping. It needs
            // no answer, so acknowledge it silently. Frames missing an id, or
            // carrying both result and error, are malformed and still get the
            // -32600 error rather than being dropped.
            $hasResult = array_key_exists('result', $message);
            $hasError = array_key_exists('error', $message);
            if (array_key_exists('id', $message) && $hasResult !== $hasError) {
                return null;
            }
            return self::error($id, -32600, 'Invalid Request');
        }
        if (!is_string($message['method'])) {
            return self::error($id, -32600, 'Invalid Request');
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
                    // set of pending cancellations stays bounded. Keys are
                    // prefixed, never numeric, so array_shift keeps insertion
                    // order instead of renumbering the remaining ids.
                    if (count($this->cancelledRequests) >= self::MAX_PENDING_CANCELLATIONS) {
                        array_shift($this->cancelledRequests);
                    }
                    $this->cancelledRequests[self::cancelKey($requestId)] = $requestId;
                }
            }
            return null;
        }
        if (JsonRpcId::isInvalid($message)) {
            // The id is present but neither an integer nor a string, so it
            // cannot be echoed: the request is invalid and answered with id null.
            return self::error(null, -32600, 'Invalid Request');
        }
        $params = $message['params'] ?? [];
        if (!is_array($params) || ($params !== [] && array_is_list($params))) {
            return self::error($id, -32602, 'Params must be an object.');
        }

        try {
            $profile = $this->negotiator->select($message);
        } catch (UnsupportedProtocolVersionException $unsupported) {
            return self::error($id, $unsupported->getCode(), $unsupported->getMessage(), $unsupported->data());
        }
        $this->profile = $profile;
        $this->noteProtocol($message, $profile);

        try {
            $response = $this->dispatchMethod($method, $id, $params, $profile, $pollCancellation);
        } finally {
            // Whatever method answered it, the request is done: a cancel that
            // named it must not outlive it and withdraw a later request that
            // reuses the id.
            unset($this->cancelledRequests[self::cancelKey($id)]);
        }
        // Envelope rules are the one thing that varies per revision, so they are
        // applied once here rather than at every success() call site.
        if ($response !== null && isset($response['result']) && is_array($response['result'])) {
            $response['result'] = $profile->decorate($response['result'], $method);
        }

        return $response;
    }

    /**
     * A JSON-RPC error frame, optionally carrying structured data such as the supported revisions.
     *
     * Public so a transport's own framing failures use the identical shape.
     *
     * @param array<string, mixed>|null $data @return array<string, mixed>
     */
    public static function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }

        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /**
     * Route a validated request to its method handler.
     *
     * @param array<string, mixed> $params
     * @param (Closure(int|string): bool)|null $pollCancellation
     * @return array<string, mixed>|null
     */
    private function dispatchMethod(string $method, int|string $id, array $params, ProtocolProfile $profile, ?Closure $pollCancellation): ?array
    {
        if ($method === 'server/discover') {
            return $this->success($id, [
                'protocolVersions' => ProtocolNegotiator::supported(),
                'capabilities' => $this->capabilities(true),
                'serverInfo' => self::serverInfo(),
                'instructions' => self::INSTRUCTIONS,
            ]);
        }
        if ($method === 'initialize') {
            $requested = $params['protocolVersion'] ?? null;
            if (!is_string($requested)) {
                return self::error($id, -32602, 'protocolVersion must be a string.');
            }
            return $this->success($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => $this->capabilities(false),
                'serverInfo' => self::serverInfo(),
                'instructions' => self::INSTRUCTIONS,
            ]);
        }
        if ($method === 'ping') {
            return $this->success($id, (object) []);
        }
        if ($profile->requiresHandshake() && !$this->initialized) {
            // Distinct from the resource-not-found code so a client can tell
            // "not initialized yet" from "no such resource". Unreachable for
            // revisions that removed the handshake.
            return self::error($id, -32003, 'Server has not received notifications/initialized.');
        }
        if ($method === 'tools/list') {
            return $this->success($id, ['tools' => $this->tools->definitions()]);
        }
        if ($method === 'tools/call') {
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                return self::error($id, -32602, 'Tool name and object arguments are required.');
            }
            try {
                $cancellation = new CancellationToken(fn(): bool => $this->cancellationRequested($id, $pollCancellation));
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
                $response = self::error($id, -32602, $invalid->getMessage());
            } catch (ScanCancelledException $cancelled) {
                if (isset($this->cancelledRequests[self::cancelKey($id)])) {
                    // The client asked to cancel this request and is no longer
                    // waiting; send nothing back (handle() drops the entry).
                    return null;
                }
                $response = $this->toolError($id, 'KNOSSOS_SCAN_CANCELLED', $cancelled->getMessage());
            } catch (Throwable $error) {
                $response = $this->toolError($id, ToolErrorMapper::code($error), ToolErrorMapper::publicMessage($error));
            }
            return $response;
        }

        if ($this->resources !== null && $method === 'resources/list') {
            $cursor = $params['cursor'] ?? null;
            if ($cursor !== null && !is_string($cursor)) {
                return self::error($id, -32602, 'Invalid cursor.');
            }
            try {
                return $this->success($id, $this->resources->list($cursor));
            } catch (\InvalidArgumentException) {
                return self::error($id, -32602, 'Invalid cursor.');
            }
        }
        if ($this->resources !== null && $method === 'resources/read') {
            $uri = $params['uri'] ?? null;
            if (!is_string($uri)) {
                return self::error($id, -32602, 'uri must be a string.');
            }
            $result = $this->resources->read($uri);
            return $result === null
                ? self::error($id, $profile->resourceNotFoundCode(), 'Resource not found: ' . $uri)
                : $this->success($id, $result);
        }

        if ($this->prompts !== null && $method === 'prompts/list') {
            return $this->success($id, ['prompts' => $this->prompts->list()]);
        }
        if ($this->prompts !== null && $method === 'prompts/get') {
            $name = $params['name'] ?? null;
            $arguments = $params['arguments'] ?? [];
            if (!is_string($name) || !is_array($arguments)) {
                return self::error($id, -32602, 'Prompt name and object arguments are required.');
            }
            $result = $this->prompts->get($name, array_filter($arguments, 'is_string'));
            return $result === null
                ? self::error($id, -32602, 'Unknown prompt: ' . $name)
                : $this->success($id, $result);
        }

        return self::error($id, -32601, 'Method not found');
    }

    /**
     * Whether the running request $id has been cancelled.
     *
     * A cancel already handed to handle() answers at once. Otherwise the
     * transport's poll, when it has one, looks for a cancel that arrived while
     * the tool was running; one it finds is recorded here, so the tools/call
     * handler knows to send nothing back.
     *
     * @param (Closure(int|string): bool)|null $pollCancellation
     */
    private function cancellationRequested(int|string $id, ?Closure $pollCancellation): bool
    {
        $key = self::cancelKey($id);
        if (isset($this->cancelledRequests[$key])) {
            return true;
        }
        if ($pollCancellation === null || !$pollCancellation($id)) {
            return false;
        }
        $this->cancelledRequests[$key] = $id;

        return true;
    }

    /**
     * A JSON-RPC success frame.
     *
     * @return array<string, mixed>
     */
    private function success(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * Server capabilities, shared by `initialize` and `server/discover`.
     *
     * `extensions` is declared empty for `server/discover`: Knossos implements
     * no optional extension yet, but the field's presence is how a client learns
     * that — its absence would be indistinguishable from a server too old to
     * report any. It is withheld from `initialize`, whose revision predates the
     * field, so handshake-era responses stay byte-identical.
     *
     * @return array<string, mixed>
     */
    private function capabilities(bool $withExtensions): array
    {
        return [
            'tools' => ['listChanged' => false],
            ...($this->resources === null ? [] : ['resources' => ['subscribe' => false, 'listChanged' => false]]),
            ...($this->prompts === null ? [] : ['prompts' => ['listChanged' => false]]),
            ...($withExtensions ? ['extensions' => (object) []] : []),
        ];
    }

    /**
     * This server's identity, shared by the handshake and server/discover.
     *
     * @return array<string, string>
     */
    private static function serverInfo(): array
    {
        return [
            'name' => 'knossos', 'title' => 'Knossos Architecture Intelligence',
            'version' => Application::VERSION,
            'description' => 'Evidence-backed architecture graph for PHP and TypeScript projects.',
        ];
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

    /**
     * Record the negotiated revision once per connection, on the lifecycle log.
     *
     * Which revision a host actually speaks was previously invisible: the wrapper
     * logs start, signals, and exit, but nothing about the handshake, so answering
     * "has the client moved to 2026-07-28 yet?" meant grepping the host's own
     * binary for its protocol constant. That is the question that decides when the
     * legacy profile can be dropped, so it belongs in the log.
     *
     * The stdio transport points this at stderr because tools/mcp-serve already
     * appends the server's stderr to .knossos/mcp-serve.log; stdout carries
     * protocol traffic and must stay clean. Logged on change rather than on
     * `initialize`, since the 2026-07-28 revision replaced the handshake with
     * server/discover and a modern client may never send an initialize at all.
     *
     * @param array<string, mixed> $message
     */
    private function noteProtocol(array $message, ProtocolProfile $profile): void
    {
        if ($this->notes === null || $profile->version() === $this->notedProtocol) {
            return;
        }
        $this->notedProtocol = $profile->version();
        $client = $message['params']['clientInfo'] ?? null;
        // Two declaration sites, because the revisions disagree about where a
        // version goes: 2026-07-28 puts it in `params._meta`, which is what the
        // negotiator reads, while a 2025-11-25 client declares it as
        // `initialize`'s protocolVersion. Reading only the first would log
        // `requested=none` for every legacy client -- exactly the population this
        // line exists to count.
        $declared = $message['params']['protocolVersion'] ?? null;
        $requested = ProtocolNegotiator::requestedVersion($message)
            ?? (is_string($declared) ? $declared : null);
        fwrite($this->notes, sprintf(
            "%s protocol requested=%s selected=%s client=%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            self::logSafe($requested ?? 'none'),
            $profile->version(),
            is_array($client)
                ? self::logSafe((string) ($client['name'] ?? 'unknown')) . '/' . self::logSafe((string) ($client['version'] ?? '?'))
                : 'unknown',
        ));
    }

    /**
     * Reduce a client-supplied string to something that cannot forge a log line.
     *
     * Everything in this line except the timestamp and the selected revision comes
     * from the peer, and the log is line-oriented, so an unfiltered name could
     * inject whole entries — including a plausible-looking one claiming a revision
     * the client never requested. Kept to printable non-space characters and a
     * short cap, which is all a version string or client identifier needs.
     */
    private static function logSafe(string $value): string
    {
        $safe = preg_replace('/[^\x21-\x7E]/', '_', $value) ?? '';

        return $safe === '' ? 'unknown' : substr($safe, 0, 64);
    }

    /**
     * The key a request id is pending cancellation under: its type and its
     * value (serialize(): "i:7;", "s:1:\"7\";"), so "7" and 7 stay apart and no
     * key is numeric (PHP would store a numeric string key as an int and renumber it
     * on array_shift).
     */
    private static function cancelKey(int|string $id): string
    {
        return serialize($id);
    }
}
