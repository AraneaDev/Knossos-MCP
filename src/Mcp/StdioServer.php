<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use JsonException;
use Throwable;

/**
 * JSON-RPC over stdio: the recommended MCP transport.
 *
 * Stdout carries protocol frames only — diagnostics go to stderr, because one
 * stray write corrupts the stream. This class owns the wire: frames are
 * size-capped, an idle connection is kept warm, and cancellation is polled
 * without blocking on input while a tool runs. What a message means is the
 * {@see McpDispatcher}'s business, shared with the HTTP transport.
 */
final class StdioServer
{
    /** Ceiling on lines parked during cancellation polling, so a flood cannot grow memory without bound. */
    private const MAX_PENDING_LINES = 1024;
    /** Idle seconds before the server pings the client to keep the stdio transport warm. */
    private const KEEPALIVE_INTERVAL_SECONDS = 25.0;
    private int $keepaliveSequence = 0;
    /** @var resource|null */
    private $input = null;
    /** @var list<string> */
    private array $pendingLines = [];
    private string $inputBuffer = '';

    public function __construct(
        private readonly McpDispatcher $dispatcher,
        private readonly int $maxLineBytes = 1_048_576,
        private readonly int $maxResponseBytes = 1_048_576,
        // Seam for tests: report readability without a real timed stream_select.
        // Production leaves this null and polls the input stream directly.
        private readonly ?\Closure $readinessWaiter = null,
    ) {}

    /**
     * The read loop: frame in, response out, until stdin closes.
     *
     * @param resource $input @param resource $output @param resource $errors
     */
    public function run($input, $output, $errors): int
    {
        $this->input = $input;
        $this->dispatcher->logProtocolTo($errors);
        stream_set_read_buffer($input, 0);
        while (($line = $this->nextLine($input, $output)) !== false) {
            if (strlen($line) > $this->maxLineBytes || !str_ends_with($line, "\n")) {
                $this->write($output, McpDispatcher::error(null, -32700, 'Invalid or oversized JSON-RPC frame.'));
                continue;
            }
            // Only a frame that does not decode is a parse error with id null.
            // Once the request decoded, every failure answers under its id:
            // a JSON error while handling or encoding the answer is an internal
            // error of this server, not something the client sent.
            try {
                $message = json_decode(trim($line), true, 512, JSON_THROW_ON_ERROR);
                if (!is_array($message) || array_is_list($message)) {
                    throw new JsonException('JSON-RPC message must be an object.');
                }
            } catch (JsonException $error) {
                fwrite($errors, $error->getMessage() . PHP_EOL);
                $this->write($output, McpDispatcher::error(null, -32700, 'Parse error'));
                continue;
            }
            $this->answer($output, $errors, $message);
        }
        $this->input = null;
        return 0;
    }

    /**
     * Handle one decoded request and write its answer, keeping the request's id on every failure.
     *
     * @param resource $output @param resource $errors @param array<string, mixed> $message
     */
    private function answer($output, $errors, array $message): void
    {
        // The dispatcher answers an id that is neither an integer nor a string with
        // -32600 and id null, so the id echoed here always encodes and this
        // error cannot fail the way the answer it replaces did.
        $id = JsonRpcId::reply($message['id'] ?? null);
        try {
            $response = $this->dispatcher->handle($message, $this->pollCancellation(...));
            if ($response !== null) {
                $this->write($output, $response);
            }
        } catch (Throwable $error) {
            fwrite($errors, $error->getMessage() . PHP_EOL);
            $this->write($output, McpDispatcher::error($id, -32603, 'Internal error'));
        }
    }

    /**
     * Write one frame, replacing it with an error if it exceeds the byte cap.
     *
     * @param resource $output @param array<string, mixed> $message
     */
    private function write($output, array $message): void
    {
        // ResultEnricher::WIRE_FLAGS measures max_chars with these same flags;
        // a test pins the two equal, so change both together.
        $encoded = json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($encoded) > $this->maxResponseBytes) {
            $encoded = json_encode(
                McpDispatcher::error($message['id'] ?? null, -32001, 'Response exceeds the configured byte limit.'),
                JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE,
            );
        }
        fwrite($output, $encoded . "\n");
        fflush($output);
    }

    /**
     * The next complete frame, waking periodically so an idle transport can be kept warm.
     *
     * @param resource $input @param resource $output
     */
    private function nextLine($input, $output): string|false
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
            // Wait for input, but wake on the keepalive interval so an idle
            // connection is pinged instead of sitting silent until the host
            // decides the server is dead and closes the transport.
            //
            // Only revisions that still define `ping` are pinged. A client that
            // has not identified itself yet is: pinging is harmless (an
            // unrecognised request draws -32601, which the dispatcher discards), while
            // staying silent towards a handshake-era client would resurrect the
            // idle-disconnect this keepalive exists to prevent.
            if ($this->awaitReadable($input) === 0) {
                if ($this->dispatcher->profile()?->emitsKeepalive() ?? true) {
                    $this->sendKeepalive($output);
                }
                continue;
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
            // The cap applies to one frame, never to bytes that follow its
            // newline: once the buffer holds a newline, the loop's top returns
            // that line and run() judges its length alone, so a valid frame
            // pipelined with the next one in the same read is still answered.
            if (!str_contains($this->inputBuffer, "\n") && strlen($this->inputBuffer) > $this->maxLineBytes) {
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

    /**
     * Block until $input is readable or the keepalive interval elapses.
     * Returns a positive count when readable, 0 on the idle timeout, or false
     * when the wait is interrupted (e.g. by a signal) or the stream cannot be
     * polled — in which case the caller falls back to a blocking read.
     *
     * @param resource $input
     */
    private function awaitReadable($input): int|false
    {
        if ($this->readinessWaiter !== null) {
            return ($this->readinessWaiter)($input);
        }
        $seconds = (int) self::KEEPALIVE_INTERVAL_SECONDS;
        $microseconds = (int) round((self::KEEPALIVE_INTERVAL_SECONDS - $seconds) * 1_000_000);
        $read = [$input];
        $write = null;
        $except = null;
        return @stream_select($read, $write, $except, $seconds, $microseconds);
    }

    /**
     * Emit a JSON-RPC ping so an idle client keeps the stdio transport open.
     * Each ping carries a fresh, non-null id the client echoes back in a
     * response that the dispatcher then ignores.
     *
     * @param resource $output
     */
    private function sendKeepalive($output): void
    {
        $this->write($output, [
            'jsonrpc' => '2.0',
            'id' => 'knossos-keepalive-' . (++$this->keepaliveSequence),
            'method' => 'ping',
        ]);
    }

    /**
     * Read ahead for a cancellation of $requestId without blocking the running request.
     *
     * Handed to the dispatcher as its cancellation poll. Every other line read
     * on the way is parked for the main loop, so nothing the client sent while
     * the tool ran is lost.
     */
    private function pollCancellation(int|string $requestId): bool
    {
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
                    continue;
                }
                $this->rememberPendingLine($line);
            }
        } finally {
            stream_set_blocking($this->input, true);
        }
        return $cancelled;
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
