<?php

declare(strict_types=1);

$mode = $argv[1] ?? 'compliant';
$pidFile = $argv[2] ?? null;
// Batch-size threshold for the per_file_* modes: a scan request asking for more
// files than this is treated as too big to answer.
$threshold = (int) ($argv[3] ?? 0);
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

    if ($method === 'scan') {
        if (($request['params']['files'] ?? null) === []) {
            // Echoed back so a test can observe which ids the host cancelled
            // without a discover round trip, which the protocol no longer has.
            respond($id, ['count' => 0, 'cancelled' => $cancelled]);
            continue;
        }
        if ($mode === 'blocked_scan') {
            // One contribution, then a request that never gets its final
            // response. The contribution matters: it hands control back to the
            // consumer, which is where the host re-checks its cancellation
            // callback and sends the cancel notification. A worker that
            // emitted nothing would instead be cancelled from inside the read
            // loop, which fails the request without notifying anyone.
            // The pid is recorded BEFORE the contribution, not after. The
            // contribution is what hands control back to the host, and the host
            // may cancel and terminate this process the moment it has it — so a
            // pid written afterwards races the kill and is sometimes never
            // written at all, leaving the test reading an empty first line.
            if (is_string($pidFile)) {
                file_put_contents($pidFile, getmypid() . "\n");
            }
            notifyContribution(contribution('worker:file:src/Checkout.ts'));
            // A real worker is blocked in its analyser and never sees the
            // cancel at all; this one keeps reading stdin purely so the test
            // can assert what went over the wire — above all that an int
            // request id arrives as an int, not as a stringified copy.
            while (($incoming = fgets(STDIN)) !== false) {
                $decoded = json_decode($incoming, true);
                if (!is_array($decoded) || ($decoded['method'] ?? '') !== 'cancel' || !is_string($pidFile)) {
                    continue;
                }
                $requestId = $decoded['params']['request_id'] ?? null;
                file_put_contents(
                    $pidFile,
                    json_encode(['request_id' => $requestId, 'type' => get_debug_type($requestId)]) . "\n",
                    FILE_APPEND,
                );
            }
            exit(0);
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
        if (str_starts_with($mode, 'per_file')) {
            $requested = $request['params']['files'] ?? [];
            $batch = recordBatch($pidFile, count($requested));
            // A request the worker considers oversized. `per_file_overflow`
            // floods past the client's output cap, which is the retryable
            // WORKER_OUTPUT_LIMIT; `per_file_exit` dies instead, which is not
            // retryable and must cost the language exactly once.
            if ($mode === 'per_file_exit' && count($requested) > $threshold) {
                exit(3);
            }
            if ($mode === 'per_file_frame_too_large_once'
                && count($requested) > $threshold
                && !file_exists($pidFile . '.framed')) {
                file_put_contents($pidFile . '.framed', '1');
                // A frame that exceeds the client's maxLineBytes without a
                // terminating newline, mimicking a worker that streams a
                // contribution too large for one NDJSON frame.
                fwrite(STDOUT, str_repeat('x', 150_000));
                fflush(STDOUT);
                exit(0);
            }
            if ($mode === 'per_file_oom_once'
                && count($requested) > $threshold
                && !file_exists($pidFile . '.oomed')) {
                file_put_contents($pidFile . '.oomed', '1');
                fwrite(STDERR, "FATAL ERROR: Ineffective mark-compacts near heap limit\nJavaScript heap out of memory\n");
                fflush(STDERR);
                exit(1);
            }
            // Overflows the FIRST oversized request only, so a caller that
            // re-widened its budget afterwards can be told apart from one that
            // stayed pinned at the reduced value.
            $overflowOnce = $mode === 'per_file_overflow_once'
                && count($requested) > $threshold
                && !file_exists($pidFile . '.overflowed');
            if ($overflowOnce) {
                file_put_contents($pidFile . '.overflowed', '1');
            }
            if ($overflowOnce || ($mode === 'per_file_overflow' && count($requested) > $threshold)) {
                // Contributions FIRST, so the caller has already received facts
                // for these files when the flood aborts the request. A retry
                // re-sends the same files, so a caller that kept the partial
                // results would count them twice.
                foreach ($requested as $relativePath) {
                    notifyContribution(fileContribution('knossos.fake:file:' . $relativePath, (string) $relativePath));
                }
                for ($chunk = 0; $chunk < 64; ++$chunk) {
                    // Ignored by the session's decoder, so only its bytes count.
                    writeMessage(['jsonrpc' => '2.0', 'method' => 'scan/progress', 'params' => ['pad' => str_repeat('p', 8192)]]);
                }
                respond($id, ['count' => 0]);
                continue;
            }
            foreach ($requested as $relativePath) {
                notifyContribution(fileContribution('knossos.fake:file:' . $relativePath, (string) $relativePath));
            }
            // Shaped like the real TypeScript worker's reply: every integer is a
            // count of what THIS request did, so a caller must sum them, while
            // `parser` is a name the newest request simply overwrites.
            respond($id, [
                'count' => count($requested),
                'files_scanned' => count($requested),
                'programs' => 1,
                'programs_reused' => $batch === 1 ? 0 : 1,
                'parser' => 'fake-' . $batch,
            ]);
            continue;
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
        'capabilities' => ['partial_ast'],
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

/**
 * One trivial contribution for a requested path, so a caller can see exactly
 * which files each scan request carried.
 *
 * @return array<string, mixed>
 */
function fileContribution(string $owner, string $relativePath): array
{
    return [
        'owner_key' => $owner,
        'nodes' => [[
            'local_id' => 'file:' . $relativePath,
            'kind' => 'class',
            'canonical_name' => $relativePath,
            'display_name' => $relativePath,
            'origin' => 'ast',
            'confidence' => 'certain',
            'evidence' => ['path' => $relativePath, 'start_line' => 1, 'end_line' => 1],
            'attributes' => (object) [],
        ]],
        'edges' => [],
        'diagnostics' => [],
    ];
}

/**
 * Append one scan request's file count to the record file and return this
 * request's 1-based ordinal, so a test can assert how the work was split.
 */
function recordBatch(?string $recordPath, int $files): int
{
    if (!is_string($recordPath) || $recordPath === '') {
        return 1;
    }
    file_put_contents($recordPath, $files . "\n", FILE_APPEND);

    return count(array_filter(explode("\n", (string) file_get_contents($recordPath)), static fn(string $line): bool => $line !== ''));
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
