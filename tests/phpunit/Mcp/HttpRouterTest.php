<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * Boots the real `bin/http-router.php` behind PHP's built-in web server and
 * drives it over a socket, so the router's own env parsing (KNOSSOS_ALLOWED_ROOTS,
 * bearer token, allowed hosts/origins) and its wiring into HttpEndpoint are
 * exercised end-to-end rather than only through the in-process HttpEndpoint unit
 * test. Asserts the three security-relevant outcomes: 401 (missing/bad token),
 * 403 (bad origin), and 200 (a valid initialize).
 */
final class HttpRouterTest extends KnossosTestCase
{
    #[Group('http')]
    public function testHttpRouterEnforcesAuthOriginAndServesInitialize(): void
    {
        $port = self::reserveEphemeralPort();
        $dataDir = self::makeTempDir('knossos-router-data-');
        $logFile = tempnam(sys_get_temp_dir(), 'knossos-router-log-');
        if ($logFile === false) {
            throw new RuntimeException('Unable to allocate router log file.');
        }
        $root = self::repositoryRoot();
        $allowedRoot = $root . '/tests/Fixtures/mixed';

        $command = [PHP_BINARY];
        // When the coverage harness is active, run the server under the same pcov
        // prepend the PHPUnit process uses so bin/http-router.php contributes to
        // the merged coverage report instead of showing as an unexecuted file.
        $coverageDir = getenv('KNOSSOS_PHP_COVERAGE_DIR');
        if (is_string($coverageDir) && $coverageDir !== '') {
            $command[] = '-d';
            $command[] = 'pcov.directory=' . $root;
            $command[] = '-d';
            $command[] = 'auto_prepend_file=' . $root . '/tools/pcov-prepend.php';
        }
        $command[] = '-S';
        $command[] = '127.0.0.1:' . $port;
        $command[] = $root . '/bin/http-router.php';

        $env = getenv();
        $env['KNOSSOS_ALLOWED_ROOTS'] = $allowedRoot;
        $env['KNOSSOS_HTTP_BEARER_TOKEN'] = 'router-secret';
        $env['KNOSSOS_HTTP_ALLOWED_HOSTS'] = '127.0.0.1:' . $port;
        $env['KNOSSOS_HTTP_ALLOWED_ORIGINS'] = 'http://127.0.0.1:' . $port;
        $env['KNOSSOS_DATA_DIR'] = $dataDir;

        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']];
        $process = proc_open($command, $descriptors, $pipes, $root, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch http-router server.');
        }

        try {
            self::waitForServer($port);

            $validInit = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
            ]], JSON_THROW_ON_ERROR);
            $baseHeaders = [
                'Host' => '127.0.0.1:' . $port,
                'Origin' => 'http://127.0.0.1:' . $port,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json, text/event-stream',
                'MCP-Protocol-Version' => '2025-11-25',
            ];

            // No Authorization header -> the router requires a bearer token.
            $noToken = self::request($port, 'POST', '/mcp', $baseHeaders, $validInit);
            assertSame(401, $noToken['status']);

            // Present but wrong bearer token -> still 401.
            $badToken = self::request($port, 'POST', '/mcp', $baseHeaders + ['Authorization' => 'Bearer wrong'], $validInit);
            assertSame(401, $badToken['status']);

            // Valid token but a disallowed Origin -> 403 (origin is checked
            // before auth, so a valid token does not rescue a bad origin).
            $badOriginHeaders = $baseHeaders;
            $badOriginHeaders['Origin'] = 'https://evil.test';
            $badOriginHeaders['Authorization'] = 'Bearer router-secret';
            $badOrigin = self::request($port, 'POST', '/mcp', $badOriginHeaders, $validInit);
            assertSame(403, $badOrigin['status']);

            // A path other than /mcp is a 404 from the router itself.
            $notFound = self::request($port, 'GET', '/', ['Host' => '127.0.0.1:' . $port], '');
            assertSame(404, $notFound['status']);

            // Everything valid -> the initialize handshake succeeds. Kept last so
            // that, under the coverage harness, the built-in server's per-request
            // pcov shutdown handler records the full success path for the router.
            $ok = self::request($port, 'POST', '/mcp', $baseHeaders + ['Authorization' => 'Bearer router-secret'], $validInit);
            assertSame(200, $ok['status']);
            assertContains('protocolVersion', $ok['body']);
        } finally {
            proc_terminate($process, defined('SIGTERM') ? SIGTERM : 15);
            // Give the server a moment to exit and flush any coverage shutdown
            // handler before reaping it.
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                usleep(10_000);
            }
            proc_close($process);
            @unlink($logFile);
            self::removeTree($dataDir);
        }
    }

    private static function reserveEphemeralPort(): int
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            throw new RuntimeException(sprintf('Unable to reserve an ephemeral port: %s (%d).', $errstr, $errno));
        }
        $name = stream_socket_get_name($server, false);
        fclose($server);
        if ($name === false || ($colon = strrpos($name, ':')) === false) {
            throw new RuntimeException('Unable to determine the reserved port.');
        }

        return (int) substr($name, $colon + 1);
    }

    private static function makeTempDir(string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);
        if ($base === false) {
            throw new RuntimeException('Unable to allocate a temporary directory.');
        }
        unlink($base);
        if (!mkdir($base, 0700) && !is_dir($base)) {
            throw new RuntimeException('Unable to create a temporary directory.');
        }

        return $base;
    }

    private static function waitForServer(int $port): void
    {
        // A bare TCP connect succeeds as soon as the socket is bound -- before the
        // built-in server's accept loop is serving requests -- so poll with a real
        // HTTP request and wait until a well-formed status line comes back.
        $deadline = microtime(true) + 15.0;
        while (microtime(true) < $deadline) {
            try {
                self::request($port, 'GET', '/', ['Host' => '127.0.0.1:' . $port], '');

                return;
            } catch (RuntimeException) {
                usleep(50_000);
            }
        }
        throw new RuntimeException('http-router server did not become ready in time.');
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     */
    private static function request(int $port, string $method, string $path, array $headers, string $body): array
    {
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 5.0);
        if (!is_resource($connection)) {
            throw new RuntimeException(sprintf('Unable to connect to http-router: %s (%d).', $errstr, $errno));
        }
        stream_set_timeout($connection, 10);
        $headers['Content-Length'] = (string) strlen($body);
        // HTTP/1.0 with an explicit close keeps the response un-chunked and lets a
        // read-to-EOF recover the whole body without keep-alive bookkeeping.
        $request = $method . ' ' . $path . " HTTP/1.0\r\n";
        foreach ($headers as $name => $value) {
            $request .= $name . ': ' . $value . "\r\n";
        }
        $request .= "\r\n" . $body;
        fwrite($connection, $request);

        $response = '';
        while (!feof($connection)) {
            $chunk = fread($connection, 8192);
            if ($chunk === false) {
                break;
            }
            $response .= $chunk;
        }
        fclose($connection);

        if (!preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $response, $matches)) {
            throw new RuntimeException('Malformed HTTP response from http-router: ' . substr($response, 0, 200));
        }
        $separator = strpos($response, "\r\n\r\n");

        return [
            'status' => (int) $matches[1],
            'body' => $separator === false ? '' : substr($response, $separator + 4),
        ];
    }

    private static function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path)) {
                self::removeTree($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($directory);
    }
}
