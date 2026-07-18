<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'compliant';
$pidFile = $argv[2] ?? null;
$cancelled = [];

while (($line = fgets(STDIN)) !== false) {
    $request = json_decode($line, true);
    if (!is_array($request)) {
        continue;
    }

    $method = $request['method'] ?? '';
    $id = $request['id'] ?? null;

    if ($method === 'cancel') {
        $requestId = $request['params']['request_id'] ?? null;
        if (is_string($requestId)) {
            $cancelled[] = $requestId;
        }
        continue;
    }

    if ($method === 'initialize') {
        if ($mode === 'crash') {
            fwrite(STDERR, "intentional crash\n");
            exit(7);
        }
        if ($mode === 'slow') {
            usleep(250_000);
        }
        if ($mode === 'malformed') {
            fwrite(STDOUT, "this is not json\n");
            fflush(STDOUT);
            continue;
        }
        if ($mode === 'unexpected_id') {
            respond(999, manifest('1.0'));
            continue;
        }

        respond($id, manifest($mode === 'mismatch' ? '999.0' : '1.0'));
        continue;
    }

    if ($method === 'discover') {
        respond($id, [
            'units' => [],
            'cancelled' => $cancelled,
            'root' => $request['params']['root'] ?? null,
        ]);
        continue;
    }

    if ($method === 'scan') {
        if (($request['params']['files'] ?? null) === []) {
            respond($id, ['count' => 0]);
            continue;
        }
        if ($mode === 'slow_scan') {
            usleep(500_000);
        }
        if ($mode === 'valid_over_five_seconds') {
            usleep(5_100_000);
        }
        if ($mode === 'child_scan') {
            $childPipes = [];
            $child = proc_open([PHP_BINARY, '-r', 'sleep(30);'], [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'w'],
                2 => ['file', '/dev/null', 'w'],
            ], $childPipes);
            if (is_resource($child) && is_string($pidFile)) {
                $childStatus = proc_get_status($child);
                file_put_contents($pidFile, (string) $childStatus['pid']);
            }
            usleep(5_000_000);
        }
        if ($mode === 'invalid_contribution') {
            notifyContribution(['owner_key' => '', 'nodes' => [], 'edges' => [], 'diagnostics' => []]);
            respond($id, ['count' => 1]);
            continue;
        }
        if ($mode === 'stderr_flood') {
            fwrite(STDERR, str_repeat('x', 2048));
            fflush(STDERR);
        }
        if ($mode === 'output_flood') {
            for ($index = 0; $index < 20; ++$index) {
                notifyContribution(contribution('worker:file:src/File' . $index . '.ts'));
            }
            respond($id, ['count' => 20]);
            continue;
        }

        fwrite(STDERR, "fake worker scan log\n");
        fflush(STDERR);
        notifyContribution(contribution('worker:file:src/Checkout.ts'));
        respond($id, ['count' => 1]);
        continue;
    }

    if ($method === 'shutdown') {
        respond($id, ['status' => 'bye']);
        exit(0);
    }

    writeMessage([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => -32601, 'message' => 'Unknown method'],
    ]);
}

/** @return array<string, mixed> */
function manifest(string $protocol): array
{
    return [
        'id' => 'knossos.fake',
        'version' => '0.1.0',
        'protocol_version' => $protocol,
        'output_schema_version' => '1.0',
        'languages' => ['typescript'],
        'file_extensions' => ['ts'],
        'capabilities' => ['discover', 'cancel'],
    ];
}

/** @return array<string, mixed> */
function contribution(string $owner): array
{
    return [
        'owner_key' => $owner,
        'nodes' => [[
            'local_id' => 'class:Checkout',
            'kind' => 'class',
            'canonical_name' => 'src/Checkout.Checkout',
            'display_name' => 'Checkout',
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => ['path' => 'src/Checkout.ts', 'start_line' => 1, 'end_line' => 3],
            'attributes' => (object) [],
        ]],
        'edges' => [],
        'diagnostics' => [],
    ];
}

/** @param array<string, mixed> $contribution */
function notifyContribution(array $contribution): void
{
    writeMessage([
        'jsonrpc' => '2.0',
        'method' => 'scan/contribution',
        'params' => $contribution,
    ]);
}

/** @param array<string, mixed> $result */
function respond(mixed $id, array $result): void
{
    writeMessage(['jsonrpc' => '2.0', 'id' => $id, 'result' => (object) $result]);
}

/** @param array<string, mixed> $message */
function writeMessage(array $message): void
{
    fwrite(STDOUT, json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n");
    fflush(STDOUT);
}
