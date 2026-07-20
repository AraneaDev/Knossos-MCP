<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PythonScannerTest extends KnossosTestCase
{
    #[Group('python-scanner')]
    public function testPythonWorkerDiscoversPackagesAndExtractsStaticArchitectureWithoutImports(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python';
        $client = $this->pythonWorkerClient();
        $manifest = $client->initialize();
        assertSame('knossos.python', $manifest->id);
        assertSame(['python'], $manifest->languages);
        assertSame([
            'pyproject.toml',
        ], $client->discover(['root' => $root])['config_files']);

        $contributions = iterator_to_array($client->scan([
            'root' => $root,
            'files' => $this->pythonFixtureFiles(),
        ]));
        $byOwner = [];
        foreach ($contributions as $contribution) {
            $byOwner[$contribution->ownerKey] = $contribution;
        }

        $service = $byOwner['knossos.python:file:shop/service.py'];
        $names = array_map(fn(NodeFact $node): string => $node->canonicalName, $service->nodes);
        foreach (['shop.service', 'shop.service.Gateway', 'shop.service.Gateway::charge', 'shop.service.CheckoutService', 'shop.service.CheckoutService::checkout', 'shop.service.CheckoutService::validate'] as $name) {
            assertArrayContains($name, $names);
        }
        $checkout = array_values(array_filter($service->nodes, fn(NodeFact $node): bool => $node->canonicalName === 'shop.service.CheckoutService'))[0];
        assertSame(['registered'], $checkout->attributes['decorators']);
        $async = array_values(array_filter($service->nodes, fn(NodeFact $node): bool => $node->canonicalName === 'shop.service.CheckoutService::checkout'))[0];
        assertSame(true, $async->attributes['async']);
        $edges = array_map(fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $service->edges);
        assertArrayContains(['extends', 'py:class:shop.service.CheckoutService', 'py:class:shop.service.Gateway'], $edges);
        assertArrayContains(['calls', 'py:method:shop.service.CheckoutService::checkout', 'py:method:shop.service.CheckoutService::validate'], $edges);

        $api = $byOwner['knossos.python:file:shop/api.py'];
        assertArrayContains('shop.api.checkout_endpoint', array_map(fn(NodeFact $node): string => $node->canonicalName, $api->nodes));
        assertSame(['router.get'], array_values(array_filter($api->nodes, fn(NodeFact $node): bool => $node->kind === 'function'))[0]->attributes['decorators']);
        assertArrayContains(['calls', 'py:function:shop.api.checkout_endpoint', 'py:class:shop.service.CheckoutService'], array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $api->edges,
        ));

        $package = $byOwner['knossos.python:file:shop/__init__.py'];
        assertSame(1, count(array_filter($package->nodes, fn(NodeFact $node): bool => $node->kind === 'package' && $node->canonicalName === 'shop')));
        assertSame('PY_SYNTAX_ERROR', $byOwner['knossos.python:file:shop/bad.py']->diagnostics[0]->code);
        assertSame(true, array_values(array_filter($byOwner['knossos.python:file:shop/contracts.pyi']->nodes, fn(NodeFact $node): bool => $node->kind === 'module'))[0]->attributes['stub']);
        $client->shutdown();
    }

    #[Group('python-scanner')]
    public function testPythonWorkerIsDeterministicBoundedAndPathSafe(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python';
        $client = $this->pythonWorkerClient();
        $request = ['root' => $root, 'files' => ['shop/service.py', 'shop/api.py']];
        $first = iterator_to_array($client->scan($request));
        $second = iterator_to_array($client->scan($request));
        assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));
        $error = captureThrows(
            fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../pyproject.toml']])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
        $error = captureThrows(
            fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['shop/service.py'], 'limits' => ['max_file_bytes' => 1]])),
            WorkerException::class,
        );
        assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
    }

    #[Group('python-scanner')]
    public function testPythonWorkerGivesNestedFunctionsLexicalIdentitiesAndCallTargets(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python-nested';
        $client = $this->pythonWorkerClient();
        $first = iterator_to_array($client->scan(['root' => $root, 'files' => ['nested.py']]));
        $second = iterator_to_array($client->scan(['root' => $root, 'files' => ['nested.py']]));
        $client->shutdown();
        assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));

        $contribution = $first[0];
        $byCanonical = [];
        foreach ($contribution->nodes as $node) {
            $byCanonical[$node->canonicalName] = $node;
        }
        $firstHelper = 'nested.first.<locals>.helper';
        $secondHelper = 'nested.second.<locals>.helper';
        $deeper = 'nested.second.<locals>.helper.<locals>.deeper';
        foreach (['nested.first', 'nested.second', $firstHelper, $secondHelper, $deeper] as $canonical) {
            assertSame('nested.py', $byCanonical[$canonical]->evidence->relativePath);
        }
        assertSame(false, $byCanonical[$firstHelper]->attributes['async']);
        assertSame(true, $byCanonical[$secondHelper]->attributes['async']);
        assertSame(true, $byCanonical[$deeper]->attributes['async']);

        $edges = array_map(
            fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $contribution->edges,
        );
        assertArrayContains(['contains', 'py:function:nested.first', 'py:function:' . $firstHelper], $edges);
        assertArrayContains(['contains', 'py:function:nested.second', 'py:function:' . $secondHelper], $edges);
        assertArrayContains(['contains', 'py:function:' . $secondHelper, 'py:function:' . $deeper], $edges);
        assertArrayContains(['calls', 'py:function:nested.first', 'py:function:' . $firstHelper], $edges);
        assertArrayContains(['calls', 'py:function:' . $firstHelper, 'py:function:' . $firstHelper], $edges);
        assertArrayContains(['calls', 'py:function:nested.second', 'py:function:' . $secondHelper], $edges);
        assertArrayContains(['calls', 'py:function:' . $secondHelper, 'py:function:' . $deeper], $edges);
        assertArrayContains(['calls', 'py:function:' . $deeper, 'py:function:' . $secondHelper], $edges);
    }

    #[Group('python-scanner')]
    public function testPythonWorkerContainsProtocolAndEdgeCaseSyntaxPaths(): void
    {
        $root = sys_get_temp_dir() . '/knossos-python-edge-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/shop', 0o700, true)) {
            throw new RuntimeException('Unable to create Python edge-case fixture.');
        }
        file_put_contents($root . '/shop/__init__.py', "\n");
        file_put_contents($root . '/shop/service.py', "class Gateway:\n    pass\n");
        file_put_contents($root . '/edge.py', <<<'PYTHON'
    import json as codec
    import shop.service as service
    from somewhere import *

    @unknown.decorator()
    class Derived(service.Gateway):
        def invoke(self) -> None:
            (lambda: None)()
            self.missing()
    PYTHON);
        file_put_contents($root . '/notes.txt', "not Python\n");
        symlink($root . '/edge.py', $root . '/outside.py');

        try {
            $messages = [
                'not-json',
                json_encode([], JSON_THROW_ON_ERROR),
                json_encode(['id' => 1, 'params' => (object) []], JSON_THROW_ON_ERROR),
                json_encode(['id' => 2, 'method' => 'cancel', 'params' => (object) []], JSON_THROW_ON_ERROR),
                json_encode(['id' => 3, 'method' => 'unknown', 'params' => (object) []], JSON_THROW_ON_ERROR),
                json_encode(['id' => 4, 'method' => 'discover', 'params' => ['root' => '']], JSON_THROW_ON_ERROR),
                json_encode(['id' => 5, 'method' => 'discover', 'params' => ['root' => $root . '/notes.txt']], JSON_THROW_ON_ERROR),
                json_encode(['id' => 6, 'method' => 'scan', 'params' => ['root' => $root, 'files' => 'edge.py']], JSON_THROW_ON_ERROR),
                json_encode(['id' => 7, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['bad\\path.py']]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 8, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['notes.txt']]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 9, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['edge.py'], 'limits' => ['max_files' => 0]]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 10, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['edge.py', 'shop/service.py']]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 11, 'method' => 'shutdown', 'params' => (object) []], JSON_THROW_ON_ERROR),
            ];
            $responses = $this->runPythonWorkerProtocol($messages);
            $errors = array_values(array_filter($responses, fn(array $frame): bool => isset($frame['error'])));
            assertSame(10, count($errors));
            assertSame(-32602, $errors[0]['error']['code']);
            assertSame('bye', array_values(array_filter(
                $responses,
                fn(array $frame): bool => ($frame['id'] ?? null) === 11,
            ))[0]['result']['status']);
            $contributions = array_values(array_filter(
                $responses,
                fn(array $frame): bool => ($frame['method'] ?? null) === 'scan/contribution',
            ));
            assertSame(2, count($contributions));
            $edgeContribution = array_values(array_filter(
                $contributions,
                fn(array $frame): bool => $frame['params']['owner_key'] === 'knossos.python:file:edge.py',
            ))[0];
            assertArrayContains('extends', array_column($edgeContribution['params']['edges'], 'kind'));
        } finally {
            foreach (['outside.py', 'edge.py', 'notes.txt', 'shop/__init__.py', 'shop/service.py'] as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/shop');
            @rmdir($root);
        }
    }

    #[Group('python-scanner')]
    public function testPythonProjectScanPersistsClassificationsBoundariesDiagnosticsAndCache(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python';
        $database = tempnam(sys_get_temp_dir(), 'knossos-python-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate Python database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $first = $service->scan($root, 'Python Fixture');
            assertSame(5, $first->data['parsed_files']);
            assertSame('5', (string) $pdo->query("SELECT COUNT(*) FROM files WHERE language = 'python'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'application.service'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'python:knossos-python-fixture'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM diagnostics WHERE code = 'PY_SYNTAX_ERROR'")->fetchColumn());
            assertSame('5', (string) $pdo->query("SELECT COUNT(*) FROM contribution_cache WHERE scanner_id = 'knossos.python'")->fetchColumn());

            $second = $service->scan($root, 'Python Fixture');
            assertSame(0, $second->data['parsed_files']);
            assertSame(5, $second->data['unchanged_files']);
        } finally {
            unset($service, $pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }
}
