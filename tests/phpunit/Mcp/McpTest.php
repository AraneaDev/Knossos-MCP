<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;

final class McpTest extends KnossosTestCase
{
    #[Group('mcp')]
    public function testToolServiceDispatchesEveryArchitectureQueryAndRejectsMalformedArguments(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
        $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
        $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
        $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $calls = [
            ['find_component', ['project_id' => $ids['project'], 'name' => 'Checkout']],
            ['list_snapshots', ['project_id' => $ids['project']]],
            ['architecture_summary', ['project_id' => $ids['project']]],
            ['explain_flow', ['project_id' => $ids['project'], 'from' => 'Checkout', 'to' => 'Invoice']],
            ['impact_analysis', ['project_id' => $ids['project'], 'symbol' => 'Invoice']],
            ['dependency_cycles', ['project_id' => $ids['project']]],
            ['architecture_health', ['project_id' => $ids['project']]],
            ['check_architecture', [
                'project_id' => $ids['project'],
                'policies' => [[
                    'id' => 'backend-billing', 'from_boundary' => $backend, 'allow_targets' => [$billing],
                ]],
            ]],
            ['suggest_location', ['project_id' => $ids['project'], 'feature_description' => 'checkout billing']],
            ['change_impact', ['project_id' => $ids['project'], 'symbol' => 'Invoice']],
            ['changed_files_impact', ['project_id' => $ids['project'], 'files' => ['src/Checkout.php']]],
            ['architecture_context', ['project_id' => $ids['project'], 'task_description' => 'checkout billing']],
            ['export_diagram', ['project_id' => $ids['project']]],
            ['list_boundaries', ['project_id' => $ids['project']]],
            ['search_architecture', ['project_id' => $ids['project'], 'query' => 'Checkout']],
            ['cleanup_stale_scans', ['project_id' => $ids['project']]],
            ['remove_project', ['project_id' => $ids['project']]],
        ];
        assertSame('catalog', $tools->call('list_projects', [])->projectId);
        assertSame($ids['project'], $tools->call('inspect_component', [
            'project_id' => $ids['project'], 'component' => $ids['checkout'],
        ])->projectId);
        assertSame('database', $tools->call('maintain_database', ['action' => 'integrity'])->projectId);
        foreach ($calls as [$name, $arguments]) {
            assertSame($ids['project'], $tools->call($name, $arguments)->projectId);
        }
        assertThrows(fn() => $tools->call('missing_tool', []), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('find_component', ['project_id' => $ids['project']]), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('architecture_summary', ['project_id' => $ids['project'], 'extra' => true]), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('architecture_summary', ['project_id' => $ids['project'], 'limit' => 'many']), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('explain_flow', ['project_id' => $ids['project'], 'from' => 'Checkout', 'to' => 'Invoice', 'edge_kinds' => [1]]), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('check_architecture', ['project_id' => $ids['project'], 'policies' => 'invalid']), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('list_projects', ['include_roots' => 'yes']), InvalidArgumentException::class);
    }

    #[Group('mcp')]
    public function testPollCancellationDropsOversizedUnterminatedFrames(): void
    {
        [$pdo] = $this->storeFixture();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $input = fopen('php://temp', 'w+');
        if (!is_resource($input)) {
            throw new RuntimeException('Unable to allocate cancellation polling stream.');
        }
        // A frame that exceeds the line cap with no terminator cannot be a valid
        // message; polling must drop it instead of buffering it unboundedly.
        fwrite($input, str_repeat('x', 4096));
        rewind($input);
        $server = new StdioServer($tools, maxLineBytes: 64);
        (new ReflectionProperty($server, 'input'))->setValue($server, $input);
        $poll = new ReflectionMethod($server, 'pollCancellation');
        assertSame(false, $poll->invoke($server, 'r1'));
        assertSame('', (new ReflectionProperty($server, 'inputBuffer'))->getValue($server));
        assertSame(0, count((new ReflectionProperty($server, 'pendingLines'))->getValue($server)));
        fclose($input);
    }

    #[Group('mcp')]
    public function testTransportMapsInputErrorsToInvalidParamsAndGatesCommonOptions(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        // Common options are honoured only for tools whose schema declares them.
        $gated = captureThrows(
            fn() => $tools->call('remove_project', ['project_id' => $ids['project'], 'refresh_if_stale' => true]),
            \Knossos\Mcp\ToolInputException::class,
        );
        assertContains('refresh_if_stale', $gated->getMessage());
        assertThrows(fn() => $tools->call('nope', []), \Knossos\Mcp\ToolInputException::class);

        // quality_gate accepts an empty budgets object ({} -> [] in PHP): it clears
        // the structural guard and fails later with a semantic message instead.
        $emptyBudgets = captureThrows(
            fn() => $tools->call('quality_gate', ['project_id' => $ids['project'], 'baseline_snapshot' => 'active', 'budgets' => []]),
            \InvalidArgumentException::class,
        );
        assertSame(false, str_contains($emptyBudgets->getMessage(), 'must be an object'));

        // Schema hygiene: scan_project drops misleading defaults; changed_files_impact
        // documents the base_ref/working_tree coupling.
        $definitions = [];
        foreach ($tools->definitions() as $definition) {
            $definitions[$definition['name']] = $definition;
        }
        $scanProps = $definitions['scan_project']['inputSchema']['properties'];
        assertSame(false, array_key_exists('default', $scanProps['worker_timeout_ms']));
        assertSame(false, array_key_exists('default', $scanProps['snapshot_retention']));
        assertContains('knossos.json', $scanProps['worker_timeout_ms']['description']);
        assertContains('working_tree', $definitions['changed_files_impact']['description']);
        assertContains('working_tree', $definitions['changed_files_impact']['inputSchema']['properties']['base_ref']['description']);

        // Transport surfaces pre-dispatch validation as JSON-RPC -32602, while a
        // tool that runs and fails stays an isError result.
        $server = new StdioServer($tools);
        $server->handle(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]]);
        $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
        assertSame(-32602, $server->handle([
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'nope', 'arguments' => []],
        ])['error']['code']);
        assertSame(-32602, $server->handle([
            'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
            'params' => ['name' => 'architecture_summary', 'arguments' => ['project_id' => $ids['project'], 'bogus' => 1]],
        ])['error']['code']);
        $runtimeFailure = $server->handle([
            'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call',
            'params' => ['name' => 'architecture_summary', 'arguments' => ['project_id' => 'project_' . str_repeat('0', 64)]],
        ]);
        assertSame(true, $runtimeFailure['result']['isError']);
        assertSame('KNOSSOS_INVALID_ARGUMENT', $runtimeFailure['result']['structuredContent']['error']['code']);
    }

    #[Group('mcp')]
    public function testAnnotateComponentAndListAnnotationsDispatch(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        $preview = $tools->call('annotate_component', [
            'project_id' => $ids['project'], 'component' => 'App\\Checkout', 'kind' => 'note', 'value' => 'core flow',
        ]);
        assertSame(false, $preview->data['executed']);
        assertSame([], $tools->call('list_annotations', ['project_id' => $ids['project']])->data['annotations']);

        $written = $tools->call('annotate_component', [
            'project_id' => $ids['project'], 'component' => 'App\\Checkout', 'kind' => 'note', 'value' => 'core flow', 'execute' => true,
        ]);
        assertSame(true, $written->data['executed']);
        assertSame('upsert', $written->data['action']);

        $list = $tools->call('list_annotations', ['project_id' => $ids['project']]);
        assertSame(1, count($list->data['annotations']));
        assertSame('App\\Checkout', $list->data['annotations'][0]['canonical_name']);
        assertSame('note', $list->data['annotations'][0]['kind']);
        assertSame('core flow', $list->data['annotations'][0]['value']);

        assertThrows(fn() => $tools->call('annotate_component', ['project_id' => $ids['project'], 'component' => 'App\\Checkout', 'kind' => 'bogus_kind']), InvalidArgumentException::class);
        assertThrows(fn() => $tools->call('list_annotations', ['project_id' => $ids['project'], 'extra' => true]), InvalidArgumentException::class);
    }

    #[Group('mcp')]
    public function testStdioRunLoopContainsMalformedFramesNotificationsAndResponseCaps(): void
    {
        [$pdo] = $this->storeFixture();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $input = fopen('php://temp', 'w+');
        $output = fopen('php://temp', 'w+');
        $errors = fopen('php://temp', 'w+');
        if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
            throw new RuntimeException('Unable to allocate stdio test streams.');
        }
        fwrite($input, "not-json\n");
        fwrite($input, "[]\n");
        fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]], JSON_THROW_ON_ERROR) . "\n");
        fwrite($input, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR) . "\n");
        fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], JSON_THROW_ON_ERROR) . "\n");
        fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'unknown'], JSON_THROW_ON_ERROR) . "\n");
        rewind($input);
        assertSame(0, (new StdioServer($tools, maxResponseBytes: 100))->run($input, $output, $errors));
        rewind($output);
        $responses = (string) stream_get_contents($output);
        assertContains('Parse error', $responses);
        assertContains('Response exceeds the configured byte limit', $responses);
        assertContains('Method not found', $responses);
        rewind($errors);
        assertContains('Syntax error', (string) stream_get_contents($errors));
        fclose($input);
        fclose($output);
        fclose($errors);

        $server = new StdioServer($tools);
        assertSame(-32600, $server->handle(['id' => 10])['error']['code']);
        assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'initialize'])['error']['code']);
        assertSame('2.0', $server->handle(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'ping'])['jsonrpc']);
        assertSame(-32003, $server->handle(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/list'])['error']['code']);
        assertSame(null, $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
        assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call', 'params' => []])['error']['code']);
        $toolError = $server->handle([
            'jsonrpc' => '2.0', 'id' => 15, 'method' => 'tools/call',
            'params' => ['name' => 'architecture_summary', 'arguments' => ['project_id' => 'missing']],
        ]);
        assertSame(true, $toolError['result']['isError']);
        assertSame('KNOSSOS_INVALID_ARGUMENT', $toolError['result']['structuredContent']['error']['code']);
        assertSame(null, $server->handle([
            'jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 'scan-1'],
        ]));
        // The request whose id was cancelled is answered with no response at all:
        // the client already withdrew, so nothing is sent back and the entry is pruned.
        $cancelled = $server->handle([
            'jsonrpc' => '2.0', 'id' => 'scan-1', 'method' => 'tools/call',
            'params' => ['name' => 'scan_project', 'arguments' => ['path' => self::repositoryRoot() . '/tests/Fixtures/mixed']],
        ]);
        assertSame(null, $cancelled);
        $cancelledProperty = new ReflectionProperty($server, 'cancelledRequests');
        assertSame(false, array_key_exists('scan-1', $cancelledProperty->getValue($server)));
        $scanned = $server->handle([
            'jsonrpc' => '2.0', 'id' => 16, 'method' => 'tools/call',
            'params' => ['name' => 'scan_project', 'arguments' => ['path' => self::repositoryRoot() . '/tests/Fixtures/mixed']],
        ]);
        assertSame(false, $scanned['result']['isError']);
        assertContains('project_', $scanned['result']['structuredContent']['project_id']);

        $oversizedInput = fopen('php://temp', 'w+');
        $oversizedOutput = fopen('php://temp', 'w+');
        $oversizedErrors = fopen('php://temp', 'w+');
        fwrite($oversizedInput, str_repeat('x', 20) . "\n");
        rewind($oversizedInput);
        assertSame(0, (new StdioServer($tools, maxLineBytes: 10))->run($oversizedInput, $oversizedOutput, $oversizedErrors));
        rewind($oversizedOutput);
        assertContains('Invalid or oversized', (string) stream_get_contents($oversizedOutput));
        fclose($oversizedInput);
        fclose($oversizedOutput);
        fclose($oversizedErrors);

        $pollInput = fopen('php://temp', 'w+');
        if (!is_resource($pollInput)) {
            throw new RuntimeException('Unable to allocate cancellation polling stream.');
        }
        fwrite($pollInput, "not-json\n");
        fwrite($pollInput, json_encode(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n");
        fwrite($pollInput, json_encode([
            'jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 'polled-scan'],
        ], JSON_THROW_ON_ERROR) . "\n");
        rewind($pollInput);
        $polledServer = new StdioServer($tools);
        $inputProperty = new ReflectionProperty($polledServer, 'input');
        $inputProperty->setValue($polledServer, $pollInput);
        $pollMethod = new ReflectionMethod($polledServer, 'pollCancellation');
        assertSame(true, $pollMethod->invoke($polledServer, 'polled-scan'));
        $pendingProperty = new ReflectionProperty($polledServer, 'pendingLines');
        assertSame(2, count($pendingProperty->getValue($polledServer)));
        fclose($pollInput);
    }

    #[Group('mcp')]
    public function testIdleConnectionEmitsKeepalivePingsAndIgnoresTheirResponses(): void
    {
        [$pdo] = $this->storeFixture();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        // A keepalive ping expects a reply. The client's reply is a JSON-RPC
        // response (id plus result or error, no method); it must be ignored,
        // never answered with an Invalid Request error that would desync the
        // stream and make the host tear the connection down.
        $server = new StdioServer($tools);
        assertSame(null, $server->handle(['jsonrpc' => '2.0', 'id' => 'knossos-keepalive-1', 'result' => []]));
        assertSame(null, $server->handle([
            'jsonrpc' => '2.0', 'id' => 'knossos-keepalive-2', 'error' => ['code' => -32601, 'message' => 'pong'],
        ]));
        // A message that is neither a request (no method) nor a response
        // (no result or error) is still a malformed request.
        assertSame(-32600, $server->handle(['jsonrpc' => '2.0', 'id' => 7])['error']['code']);

        // The ping frame itself is a well-formed JSON-RPC request with a unique,
        // non-null id on every send so the client can correlate its replies.
        $sendKeepalive = new ReflectionMethod($server, 'sendKeepalive');
        $keepaliveOutput = fopen('php://temp', 'w+');
        if (!is_resource($keepaliveOutput)) {
            throw new RuntimeException('Unable to allocate keepalive output stream.');
        }
        $sendKeepalive->invoke($server, $keepaliveOutput);
        $sendKeepalive->invoke($server, $keepaliveOutput);
        rewind($keepaliveOutput);
        $frames = array_values(array_filter(explode("\n", (string) stream_get_contents($keepaliveOutput))));
        assertSame(2, count($frames));
        $first = json_decode($frames[0], true, 512, JSON_THROW_ON_ERROR);
        $second = json_decode($frames[1], true, 512, JSON_THROW_ON_ERROR);
        assertSame('ping', $first['method']);
        assertSame('2.0', $first['jsonrpc']);
        assertSame('knossos-keepalive-1', $first['id']);
        assertSame('knossos-keepalive-2', $second['id']);
        fclose($keepaliveOutput);

        // Drive the real read loop: the first wait reports an idle timeout (0),
        // so a ping is emitted; the next wait reports readable, and the empty
        // input then reaches EOF so run() returns cleanly.
        $waits = 0;
        $waiter = static function ($input) use (&$waits): int {
            $waits++;
            return $waits === 1 ? 0 : 1;
        };
        $loopInput = fopen('php://temp', 'w+');
        $loopOutput = fopen('php://temp', 'w+');
        $loopErrors = fopen('php://temp', 'w+');
        if (!is_resource($loopInput) || !is_resource($loopOutput) || !is_resource($loopErrors)) {
            throw new RuntimeException('Unable to allocate keepalive loop streams.');
        }
        $loopServer = new StdioServer($tools, readinessWaiter: $waiter);
        assertSame(0, $loopServer->run($loopInput, $loopOutput, $loopErrors));
        rewind($loopOutput);
        $loopPayload = (string) stream_get_contents($loopOutput);
        assertContains('"method":"ping"', $loopPayload);
        assertContains('knossos-keepalive-1', $loopPayload);
        fclose($loopInput);
        fclose($loopOutput);
        fclose($loopErrors);
    }
}
