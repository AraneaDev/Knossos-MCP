<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanInputHashes;
use Knossos\Scan\UndiscoveredInputVerifier;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PythonScannerTest extends KnossosTestCase
{
    #[Group('python-scanner')]
    public function testPythonWorkerExtractsStaticArchitectureWithoutImports(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python';
        $client = $this->pythonWorkerClient();
        $manifest = $client->initialize();
        assertSame('knossos.python', $manifest->id);
        assertSame(['python'], $manifest->languages);
        // A cancel capability is deliberately absent: handle() returns at once
        // and the process is blocked inside scan(), so a cancel frame is not
        // read until the scan it names has already finished.
        assertSame(['partial_ast', 'content_hash', 'input_hashes'], $manifest->capabilities);

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

    /**
     * A script's body is run by a shell or `python -m`, never referenced from
     * the codebase, so its module has no inbound edge however heavily the
     * script is used. Only the PHP scanner marked its modules executable, so
     * `architecture_health` had nothing to exclude on and reported this
     * repository's own Python worker — launched on every scan — as probably
     * dead code.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerMarksScriptModulesExecutable(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/python';
        $contributions = iterator_to_array($this->pythonWorkerClient()->scan([
            'root' => $root,
            'files' => ['shop/cli.py', 'shop/guarded.py', 'shop/service.py', 'shop/__init__.py'],
        ]));
        $executable = [];
        $packageInit = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $executable[$node->canonicalName] = $node->attributes['executable'] ?? false;
                    $packageInit[$node->canonicalName] = $node->attributes['package_init'] ?? false;
                }
            }
        }

        // A package's own module runs whenever anything inside it is imported.
        assertSame(true, $packageInit['shop']);
        assertSame(false, $packageInit['shop.service']);

        // A shebang and a `__main__` guard each say the file is run directly.
        assertSame(true, $executable['shop.cli']);
        assertSame(true, $executable['shop.guarded']);
        // A library module nothing imports is exactly what dead-code analysis
        // exists to surface, so it must stay reportable.
        assertSame(false, $executable['shop.service']);
    }

    /**
     * `python3 app/main.py` puts `app/` first on `sys.path`, so the script's
     * `from monitor import Probe` names its sibling `app/monitor.py` even when
     * `app/` is a package. Resolution only tried the source roots, so the
     * import stayed external and every module the script loads was reported as
     * unreferenced. A library module gets no such fallback, and a sibling
     * named after a standard-library module never captures its import.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerResolvesAScriptsSiblingImportsFromItsOwnDirectory(): void
    {
        $root = sys_get_temp_dir() . '/knossos-py-script-dir-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/main.py' => implode("\n", [
                'import json',
                'import helpers',
                'from monitor import Probe',
                '',
                'def main():',
                '    return Probe().run(), helpers.VALUE, json.dumps({})',
                '',
                'if __name__ == "__main__":',
                '    main()',
                '',
            ]),
            'app/monitor.py' => "class Probe:\n    def run(self):\n        return 1\n",
            'app/helpers.py' => "VALUE = 1\n",
            'app/json.py' => "def dumps(value):\n    return ''\n",
            'app/library.py' => "from monitor import Probe\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['app/main.py', 'app/library.py'],
            ]));
            $client->shutdown();
        } finally {
            foreach (array_keys($files) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $edges = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $edges[] = [$edge->kind, $edge->sourceReference, $edge->targetReference];
            }
        }

        assertArrayContains(['imports', 'py:module:app.main', 'py:module:app.monitor'], $edges);
        assertArrayContains(['imports', 'py:module:app.main', 'py:module:app.helpers'], $edges);
        assertArrayContains(['calls', 'py:function:app.main.main', 'py:class:app.monitor.Probe'], $edges);
        // The standard library wins over a sibling that shadows it.
        assertArrayContains(['imports', 'py:module:app.main', 'py:module:json'], $edges);
        // A module imported by others has no directory of its own on sys.path.
        assertArrayContains(['imports', 'py:module:app.library', 'py:module:monitor'], $edges);
    }

    /**
     * A bound method handed over as a value, `timer.timeout.connect(self.tick)`,
     * is how every Qt slot and most callbacks are wired, and a `@property` is
     * only ever read. Neither is a call, so both carried no inbound edge and
     * were reported as unreferenced while running on every event.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReferencesBoundMethodsReadAsValues(): void
    {
        $root = sys_get_temp_dir() . '/knossos-py-bound-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        file_put_contents($root . '/widget.py', implode("\n", [
            'class Widget:',
            '    def __init__(self, timer):',
            '        self.data = 1',
            '        timer.connect(self.tick)',
            '        handlers = {"size": self.size}',
            '        print(self.data, handlers)',
            '        self.helper()',
            '',
            '    def tick(self):',
            '        return self.data',
            '',
            '    @property',
            '    def size(self):',
            '        return 1',
            '',
            '    def helper(self):',
            '        return self.size',
            '',
        ]));

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['widget.py']]));
            $client->shutdown();
        } finally {
            @unlink($root . '/widget.py');
            @rmdir($root);
        }

        $edges = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $edges[] = [$edge->kind, $edge->sourceReference, $edge->targetReference];
            }
        }

        assertArrayContains(['references', 'py:method:widget.Widget::__init__', 'py:method:widget.Widget::tick'], $edges);
        assertArrayContains(['references', 'py:method:widget.Widget::__init__', 'py:method:widget.Widget::size'], $edges);
        assertArrayContains(['references', 'py:method:widget.Widget::helper', 'py:method:widget.Widget::size'], $edges);
        assertArrayContains(['calls', 'py:method:widget.Widget::__init__', 'py:method:widget.Widget::helper'], $edges);
        // A data attribute is not a declaration, and a call is not also a reference.
        $targets = array_map(fn(array $edge): string => $edge[2], $edges);
        assertSame(false, in_array('py:method:widget.Widget::data', $targets, true));
        assertSame(false, in_array(['references', 'py:method:widget.Widget::__init__', 'py:method:widget.Widget::helper'], $edges, true));
    }

    /**
     * `from .tools import cors` imports the submodule `tools/cors.py` through
     * its package. The name was looked up among the package's own declarations,
     * found nothing, and every `cors.make_tool()` went unresolved, so whole
     * tool modules read as unreferenced.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerResolvesASubmoduleImportedFromItsPackage(): void
    {
        $root = sys_get_temp_dir() . '/knossos-py-submodule-' . bin2hex(random_bytes(6));
        mkdir($root . '/app/tools', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/tools/__init__.py' => "VERSION = 1\n",
            'app/tools/cors.py' => "def make_tool():\n    return 1\n",
            'app/registry.py' => "from .tools import cors, VERSION\n\ndef build():\n    return cors.make_tool(), VERSION\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app/registry.py']]));
            $client->shutdown();
        } finally {
            foreach (array_reverse(array_keys($files)) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app/tools');
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $edges = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $edges[] = [$edge->kind, $edge->sourceReference, $edge->targetReference];
            }
        }

        assertArrayContains(['imports', 'py:module:app.registry', 'py:module:app.tools.cors'], $edges);
        assertArrayContains(['calls', 'py:function:app.registry.build', 'py:function:app.tools.cors.make_tool'], $edges);
    }

    /**
     * A function handed over by name, returned from a factory
     * (`def make_tool(): def handler(): ...; return handler`) or listed in a
     * registry, is never called where it is named. Only calls were edges, so
     * every tool handler built this way read as dead.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReferencesFunctionsUsedAsValues(): void
    {
        $root = sys_get_temp_dir() . '/knossos-py-values-' . bin2hex(random_bytes(6));
        mkdir($root, 0o755, true);
        file_put_contents($root . '/tools.py', implode("\n", [
            'def make_tool():',
            '    def handler():',
            '        return 1',
            '    return handler',
            '',
            'def ping():',
            '    return 2',
            '',
            'class Probe:',
            '    pass',
            '',
            'LIMIT = 3',
            'REGISTRY = [ping, Probe, LIMIT]',
            '',
            'def run():',
            '    return make_tool()()',
            '',
        ]));

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['tools.py']]));
            $client->shutdown();
        } finally {
            @unlink($root . '/tools.py');
            @rmdir($root);
        }

        $references = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'references') {
                    $references[] = [$edge->sourceReference, $edge->targetReference];
                }
            }
        }

        assertArrayContains(['py:function:tools.make_tool', 'py:function:tools.make_tool.<locals>.handler'], $references);
        assertArrayContains(['py:module:tools', 'py:function:tools.ping'], $references);
        assertArrayContains(['py:module:tools', 'py:class:tools.Probe'], $references);
        // A call is a call, not also a reference, and a constant is no declaration.
        $targets = array_map(fn(array $edge): string => $edge[1], $references);
        assertSame(false, in_array('py:function:tools.make_tool', $targets, true));
        assertSame([], array_values(array_filter($targets, fn(string $t): bool => str_contains($t, 'LIMIT'))));
    }

    /**
     * A service module creates one instance at import time
     * (`user_repo = UserRepository()`) and the rest of the codebase imports
     * that instance. Its type was known only inside the declaring module, so
     * every method called through the imported instance read as unreferenced.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerTypesAnImportedModuleLevelInstance(): void
    {
        $root = sys_get_temp_dir() . '/knossos-py-singleton-' . bin2hex(random_bytes(6));
        mkdir($root . '/app/clients', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/repo.py' => "class Repo:\n    def count(self):\n        return 1\n\nrepo: Repo = Repo()\n",
            'app/clients/__init__.py' => "from .client import DockerClient\n\ndocker_client = DockerClient()\n",
            'app/clients/client.py' => "class DockerClient:\n    def ls(self):\n        return []\n",
            'app/service.py' => implode("\n", [
                'from .repo import repo',
                'from app.clients import docker_client',
                '',
                'def run():',
                '    return repo.count(), docker_client.ls()',
                '',
                'def shadowed(repo):',
                '    return repo.count()',
                '',
                'def called():',
                '    return repo()',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app/service.py']]));
            $client->shutdown();
        } finally {
            foreach (array_reverse(array_keys($files)) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app/clients');
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $calls = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'calls') {
                    $calls[] = [$edge->sourceReference, $edge->targetReference];
                }
            }
        }

        assertArrayContains(['py:function:app.service.run', 'py:method:app.repo.Repo::count'], $calls);
        assertArrayContains(['py:function:app.service.run', 'py:method:app.clients.client.DockerClient::ls'], $calls);
        // A parameter named like the instance is a different value.
        assertSame(false, in_array(['py:function:app.service.shadowed', 'py:method:app.repo.Repo::count'], $calls, true));
        // Calling the instance itself names no declaration.
        assertSame([], array_values(array_filter($calls, fn(array $c): bool => str_starts_with($c[1], 'py:instance:'))));
        // The shadowing parameter's call has no type to resolve through, so
        // the module records the member name for dead-code confidence.
        $untyped = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $untyped = $node->attributes['unresolved_member_calls'] ?? null;
                }
            }
        }
        assertSame(['count'], $untyped);
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
        // A file over the byte cap is well-formed, so it costs only itself: the
        // request succeeds and the file arrives as a diagnostic-only contribution.
        $contributions = iterator_to_array($client->scan(
            ['root' => $root, 'files' => ['shop/service.py'], 'limits' => ['max_file_bytes' => 1]],
        ));
        assertSame(1, count($contributions));
        assertSame([], $contributions[0]->nodes);
        assertSame('PY_UNSCANNABLE_FILE', $contributions[0]->diagnostics[0]->code);
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
                json_encode(['id' => 4, 'method' => 'scan', 'params' => ['root' => '', 'files' => []]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 5, 'method' => 'scan', 'params' => ['root' => $root . '/notes.txt', 'files' => []]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 6, 'method' => 'scan', 'params' => ['root' => $root, 'files' => 'edge.py']], JSON_THROW_ON_ERROR),
                json_encode(['id' => 7, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['bad\\path.py']]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 8, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['notes.txt']]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 9, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['edge.py'], 'limits' => ['max_files' => 0]]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 10, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['edge.py', 'shop/service.py']]], JSON_THROW_ON_ERROR),
                json_encode(['id' => 11, 'method' => 'shutdown', 'params' => (object) []], JSON_THROW_ON_ERROR),
            ];
            $responses = $this->runPythonWorkerProtocol($messages);
            $errors = array_values(array_filter($responses, fn(array $frame): bool => isset($frame['error'])));
            // Nine, not ten: `notes.txt` (id 8) names a real file of a kind this
            // worker does not scan, so it is reported against that file rather
            // than failing the request. Malformed paths — `bad\path.py` (id 7) —
            // are still request-level errors.
            assertSame(9, count($errors));
            assertSame(-32602, $errors[0]['error']['code']);
            assertSame('bye', array_values(array_filter(
                $responses,
                fn(array $frame): bool => ($frame['id'] ?? null) === 11,
            ))[0]['result']['status']);
            $contributions = array_values(array_filter(
                $responses,
                fn(array $frame): bool => ($frame['method'] ?? null) === 'scan/contribution',
            ));
            // Three: the two scanned files, plus the diagnostic-only contribution
            // that reports why `notes.txt` was skipped.
            assertSame(3, count($contributions));
            $skipped = array_values(array_filter(
                $contributions,
                fn(array $frame): bool => $frame['params']['owner_key'] === 'knossos.python:file:notes.txt',
            ))[0];
            assertSame('PY_UNSCANNABLE_FILE', $skipped['params']['diagnostics'][0]['code']);
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
    public function testPythonWorkerResolvesReceiversThroughWhatTheyHold(): void
    {
        // Receiver resolution is only reachable through a real parse, so it is
        // exercised end to end here rather than against the collector: every
        // way a name comes to hold a class -- annotation, construction,
        // assignment from another name -- and the reassignment that takes it
        // away again.
        $root = sys_get_temp_dir() . '/knossos-python-receiver-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0o700, true)) {
            throw new RuntimeException('Unable to create Python receiver fixture.');
        }
        // Each receiver calls a different member: identical relationships are
        // deduplicated, so a shared member would hide every failure but one.
        file_put_contents($root . '/mod.py', <<<'PYTHON'
class Helper:
    def injected_call(self) -> None: ...

    def built_call(self) -> None: ...

    def typed_call(self) -> None: ...

    def stored_call(self) -> None: ...

    def copied_call(self) -> None: ...

    def annotated_call(self) -> None: ...

    def param_call(self) -> None: ...

    def dropped_call(self) -> None: ...


def orphan(seed: Helper) -> None:
    # No enclosing class, so there is no attribute map to record into.
    self.orphan = seed


class Owner:
    def __init__(self, injected: Helper) -> None:
        self.injected = injected
        self.built = Helper()
        self.typed: Helper = Helper()

    def run(self, passed: Helper, source) -> None:
        local = Helper()
        copied = local
        annotated: Helper = Helper()
        paired = also = Helper()
        self.stored = local
        self.injected.injected_call()
        self.built.built_call()
        self.typed.typed_call()
        self.stored.stored_call()
        copied.copied_call()
        annotated.annotated_call()
        passed.param_call()
        dropped = passed
        dropped = source.anything()
        dropped.dropped_call()
        # Last, so the calls above still see what the attribute held: an
        # attribute reassigned to something untracked is given up too.
        self.built = source.anything()
PYTHON);

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['mod.py']]));
            $client->shutdown();

            assertSame(1, count($contributions));
            assertSame([], $contributions[0]->diagnostics);
            $edges = array_map(
                fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
                $contributions[0]->edges,
            );
            foreach ([
                'injected_call',  // an annotated parameter stored on the instance
                'built_call',     // an attribute constructed in place
                'typed_call',     // an annotated attribute
                'stored_call',    // an attribute assigned from a tracked local
                'copied_call',    // a local assigned from another local
                'annotated_call', // an annotated local
                'param_call',     // an annotated parameter, called directly
            ] as $member) {
                assertArrayContains(
                    ['calls', 'py:method:mod.Owner::run', 'py:method:mod.Helper::' . $member],
                    $edges,
                );
            }
            // `dropped` held the parameter's class until it was reassigned to
            // something untracked. Neither the local nor the annotation may
            // answer for it afterwards.
            assertSame([], array_values(array_filter(
                $edges,
                fn(array $edge): bool => $edge[0] === 'calls' && $edge[2] === 'py:method:mod.Helper::dropped_call',
            )));
            // The invented-member guard: `self.a.b()` must never be read as a
            // member named `a.b`, which no file declares.
            assertSame([], array_values(array_filter(
                $edges,
                fn(array $edge): bool => str_contains($edge[2], '::') && str_contains(explode('::', $edge[2], 2)[1], '.'),
            )));
        } finally {
            @unlink($root . '/mod.py');
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
            assertSame(7, $first->data['parsed_files']);
            assertSame('7', (string) $pdo->query("SELECT COUNT(*) FROM files WHERE language = 'python'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'application.service'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'python:knossos-python-fixture'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM diagnostics WHERE code = 'PY_SYNTAX_ERROR'")->fetchColumn());
            assertSame('7', (string) $pdo->query("SELECT COUNT(*) FROM contribution_cache WHERE scanner_id = 'knossos.python'")->fetchColumn());

            $second = $service->scan($root, 'Python Fixture');
            assertSame(0, $second->data['parsed_files']);
            assertSame(7, $second->data['unchanged_files']);
        } finally {
            unset($service, $pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    /**
     * A top-level relative import is unrunnable Python but legal to parse. It
     * must cost its own edge and produce a diagnostic, never a reference the
     * graph cannot name.
     */
    #[Group('python-scanner')]
    public function testTopLevelRelativeImportReportsADiagnosticInsteadOfAnEmptyModule(): void
    {
        $root = sys_get_temp_dir() . '/knossos-pyrelative-' . bin2hex(random_bytes(8));
        mkdir($root, 0o700, true);
        file_put_contents($root . '/toplevel.py', "from . import helper\n");
        try {
            $contributions = iterator_to_array($this->pythonWorkerClient()->scan([
                'root' => $root,
                'files' => ['toplevel.py'],
            ]));

            assertSame(1, count($contributions));
            $targets = array_map(fn(EdgeFact $edge): string => $edge->targetReference, $contributions[0]->edges);
            assertSame(false, in_array('py:module:', $targets, true));
            $codes = array_map(fn(Diagnostic $diagnostic): string => $diagnostic->code, $contributions[0]->diagnostics);
            assertArrayContains('PY_UNRESOLVED_RELATIVE_IMPORT', $codes);
        } finally {
            @unlink($root . '/toplevel.py');
            @rmdir($root);
        }
    }

    #[Group('python-scanner')]
    public function testPythonWorkerReportsTheHashOfTheRawBytesItParsed(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/pkg', 0o777, true);
        $files = [
            'pkg/bom.py' => "\xEF\xBB\xBFclass Bom:\n    pass\n",
            'pkg/crlf.py' => "class Crlf:\r\n    pass\r\n",
            'pkg/broken.py' => "class :\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        $client = $this->pythonWorkerClient();
        try {
            assertSame(true, in_array('content_hash', $client->initialize()->capabilities, true));
            $byOwner = [];
            foreach ($client->scan(['root' => $root, 'files' => array_keys($files)]) as $contribution) {
                $byOwner[$contribution->ownerKey] = $contribution->contentHash;
            }

            foreach ($files as $relative => $bytes) {
                assertSame(hash('sha256', $bytes), $byOwner['knossos.python:file:' . $relative] ?? null, $relative);
            }
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * The module index resolves `pkg/a.py`'s imports by reading `pkg/b.py` and
     * `pkg/broken.py`, neither of which was requested, so those reads have to be
     * reported beside the requested file's own: a file changed and restored
     * while the index read it would otherwise leave facts resolved against
     * bytes no recorded hash describes. A module that fails to parse was still
     * read, so its hash is reported too.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReportsEveryModuleFileTheIndexRead(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/pkg', 0o777, true);
        $files = [
            'pkg/a.py' => "from pkg.b import Thing\nfrom pkg.broken import Gone\n\n\nclass Local(Thing):\n    pass\n",
            'pkg/b.py' => "\xEF\xBB\xBFclass Thing:\n    pass\n",
            'pkg/broken.py' => "class :\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        $client = $this->pythonWorkerClient();
        try {
            assertSame(true, in_array('input_hashes', $client->initialize()->capabilities, true));
            $edges = [];
            foreach ($client->scan(['root' => $root, 'files' => ['pkg/a.py']]) as $contribution) {
                foreach ($contribution->edges as $edge) {
                    $edges[] = $edge->kind . ' ' . $edge->targetReference;
                }
            }
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            // The read really fed resolution: the base class resolved to b.py's declaration.
            assertArrayContains('extends py:class:pkg.b.Thing', $edges);
            $expected = array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files);
            assertSame(true, is_array($inputHashes));
            self::assertInputHashesInclude($expected, $inputHashes);
            self::assertInputHashesVerify($root, $inputHashes, $client->initialize());
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * `pkg/a.py` sorts first, so the index reads `pkg/b.py` for a's imports
     * before b's own scan reads it again. Both reads land on one key with the
     * same hash, which the map keeps. An empty request still carries the field,
     * as `{}`.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReportsAModuleReadBothForAnImporterAndItsOwnScan(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/pkg', 0o777, true);
        $files = [
            'pkg/a.py' => "from pkg.b import Thing\n\n\nclass Local(Thing):\n    pass\n",
            'pkg/b.py' => "class Thing:\r\n    pass\r\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        $client = $this->pythonWorkerClient();
        try {
            $byOwner = [];
            foreach ($client->scan(['root' => $root, 'files' => array_keys($files)]) as $contribution) {
                $byOwner[$contribution->ownerKey] = $contribution->contentHash;
            }
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;
            iterator_to_array($client->scan(['root' => $root, 'files' => []]));
            $empty = $client->lastScanResult();

            $expected = array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files);
            self::assertInputHashesInclude($expected, $inputHashes);
            self::assertInputHashesVerify($root, $inputHashes, $client->initialize());
            assertSame($expected['pkg/b.py'], $byOwner['knossos.python:file:pkg/b.py'] ?? null);
            assertSame(true, array_key_exists('input_hashes', $empty));
            assertSame([], $empty['input_hashes']);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * Two more ways one path is read twice in a request, both with identical
     * bytes, so the entry keeps the hash. `pkg/b.py` sorts first and fails to
     * parse, so it seeds nothing and `pkg/c.py`'s import reads it again
     * (own read, then index read). `app.py` names `src/pkg/b.py` under two
     * module ids, `pkg.b` and `src.pkg.b`, and the index reads it for each.
     * Only reads that disagree turn an entry into null.
     */
    #[Group('python-scanner')]
    public function testIdenticalRepeatReadsOfOnePathKeepTheirHash(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        // Two separate trees: `pkg/b.py` at the first tree's root would shadow `pkg.b`.
        mkdir($root . '/one/pkg', 0o777, true);
        mkdir($root . '/two/src/pkg', 0o777, true);
        $files = [
            'one/pkg/b.py' => "class :\n",
            'one/pkg/c.py' => "from pkg.b import Thing\n\n\nclass Local(Thing):\n    pass\n",
            'two/src/pkg/b.py' => "class Thing:\n    pass\n",
            'two/app.py' => "from pkg.b import Thing\nfrom src.pkg.b import Thing as T2\n\n\nclass One(Thing):\n    pass\n\n\nclass Two(T2):\n    pass\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        $client = $this->pythonWorkerClient();
        try {
            iterator_to_array($client->scan(['root' => $root . '/one', 'files' => ['pkg/b.py', 'pkg/c.py']]));
            $ownThenIndex = $client->lastScanResult()['input_hashes'] ?? null;
            $edges = [];
            foreach ($client->scan(['root' => $root . '/two', 'files' => ['app.py']]) as $contribution) {
                foreach ($contribution->edges as $edge) {
                    $edges[] = $edge->kind . ' ' . $edge->sourceReference . ' ' . $edge->targetReference;
                }
            }
            $indexThenIndex = $client->lastScanResult()['input_hashes'] ?? null;

            $hash = static fn(string $relative): string => hash('sha256', $files[$relative]);
            self::assertInputHashesInclude(['pkg/b.py' => $hash('one/pkg/b.py'), 'pkg/c.py' => $hash('one/pkg/c.py')], $ownThenIndex);
            self::assertInputHashesVerify($root . '/one', $ownThenIndex, $client->initialize());
            // Both ids really resolved through src/pkg/b.py, so both reads happened.
            assertArrayContains('extends py:class:app.One py:class:pkg.b.Thing', $edges);
            assertArrayContains('extends py:class:app.Two py:class:src.pkg.b.Thing', $edges);
            self::assertInputHashesInclude(['app.py' => $hash('two/app.py'), 'src/pkg/b.py' => $hash('two/src/pkg/b.py')], $indexThenIndex);
            self::assertInputHashesVerify($root . '/two', $indexThenIndex, $client->initialize());
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * An extensionless script refused on its shebang is reported by the hash
     * of the whole file, judged again on those bytes, so a stable tree passes
     * verification while a script swapped around the probe would not. One
     * over the byte cap has no whole-file hash and is null; one refused by its
     * name read nothing and is not reported.
     */
    #[Group('python-scanner')]
    public function testAShebangRefusalReportsTheHashItsVerdictRestedOn(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/bin', 0o777, true);
        $node = "#!/usr/bin/env node\nconsole.log(1)\n";
        file_put_contents($root . '/bin/tool', $node);
        file_put_contents($root . '/bin/large', "#!/bin/sh\n" . str_repeat('#', 100) . "\n");
        file_put_contents($root . '/notes.txt', "text\n");
        $client = $this->pythonWorkerClient();
        try {
            $manifest = $client->initialize();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['bin/large', 'bin/tool', 'notes.txt'],
                'limits' => ['max_file_bytes' => 64],
            ]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            assertSame(3, count($contributions));
            foreach ($contributions as $contribution) {
                assertSame([], $contribution->nodes);
            }
            assertSame(['bin/large' => null, 'bin/tool' => hash('sha256', $node)], $inputHashes);
            self::assertInputHashesVerify($root, ['bin/tool' => $inputHashes['bin/tool']], $manifest, 64);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * Discovery never follows a symlink, so the only path it tracks for a
     * module reached through a linked directory is the target's. The index
     * read has to be keyed there; spelled through the link alone, it would
     * name a path discovery never hashed, checked only by the re-read at
     * commit and never against the hash discovery recorded for the target. The
     * link and the path through it are keyed too, as null here (the probe that
     * accepted the module read no bytes), which that re-read accepts for a
     * path reached through a link.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerKeysAModuleReadThroughASymlinkByItsTarget(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/real', 0o777, true);
        $files = [
            'app.py' => "from linked.b import Thing\n\n\nclass Local(Thing):\n    pass\n",
            'real/b.py' => "class Thing:\n    pass\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        symlink($root . '/real', $root . '/linked');
        $client = $this->pythonWorkerClient();
        try {
            iterator_to_array($client->scan(['root' => $root, 'files' => ['app.py']]));
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            self::assertInputHashesInclude(
                array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files) + ['linked' => null, 'linked/b.py' => null],
                $inputHashes,
            );
            self::assertInputHashesVerify($root, $inputHashes, $client->initialize());
        } finally {
            $client->shutdown();
            @unlink($root . '/linked');
            $this->removeTempTree($root);
        }
    }

    /**
     * A path below `node_modules` is keyed like any other: discovery never
     * hashes one, but the core verifies every undiscovered key when the scan
     * commits, so a link the index walked through there is reported rather
     * than dropped, and the whole map still passes that re-read.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerKeysALinkBelowNodeModulesItReadThrough(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/real', 0o777, true);
        mkdir($root . '/node_modules', 0o777, true);
        $files = [
            'app.py' => "from node_modules.linked.b import Thing\n\n\nclass Local(Thing):\n    pass\n",
            'real/b.py' => "class Thing:\n    pass\n",
        ];
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        symlink('../real', $root . '/node_modules/linked');
        $client = $this->pythonWorkerClient();
        try {
            iterator_to_array($client->scan(['root' => $root, 'files' => ['app.py']]));
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            self::assertInputHashesInclude(
                array_map(static fn(string $bytes): string => hash('sha256', $bytes), $files) + ['node_modules/linked' => null, 'node_modules/linked/b.py' => null],
                $inputHashes,
            );
            self::assertInputHashesVerify($root, $inputHashes, $client->initialize());
            $undiscovered = ScanInputHashes::verify(['input_hashes' => $inputHashes], $client->initialize(), self::discoveredByPath($root));
            (new UndiscoveredInputVerifier())->verify((string) realpath($root), $undiscovered, 2_000_000);
        } finally {
            $client->shutdown();
            @unlink($root . '/node_modules/linked');
            $this->removeTempTree($root);
        }
    }

    /**
     * A FIFO where the index expects a module is not a module: opening it for
     * reading blocks until a writer appears, which would stall the worker until
     * its request timeout and degrade the language on a tree that is not even
     * changing. Discovery skips a FIFO, so the index resolves as if the
     * package were absent: to the same-named module beside it, whose class the
     * import then names. The FIFO is reported as a failed read. The client's
     * request timeout bounds a regression to a failure, not a hang.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerTreatsAFifoWhereAModuleIsExpectedAsAbsentWithoutBlocking(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('FIFOs are unavailable here.');
        }
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/generated/models', 0o777, true);
        $app = "from generated.models import Model\n\n\nclass App(Model):\n    pass\n";
        $module = "class Model:\n    pass\n";
        file_put_contents($root . '/app.py', $app);
        file_put_contents($root . '/generated/models.py', $module);
        assertSame(true, posix_mkfifo($root . '/generated/models/__init__.py', 0o600));
        $client = $this->pythonWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app.py']]));
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            self::assertInputHashesInclude(
                ['app.py' => hash('sha256', $app), 'generated/models.py' => hash('sha256', $module), 'generated/models/__init__.py' => null],
                $inputHashes,
            );
            self::assertInputHashesVerify($root, $inputHashes, $client->initialize());
            $undiscovered = ScanInputHashes::verify(['input_hashes' => $inputHashes], $client->initialize(), self::discoveredByPath($root));
            (new UndiscoveredInputVerifier())->verify((string) realpath($root), $undiscovered, 2_000_000);
            $codes = [];
            $bases = [];
            foreach ($contributions as $contribution) {
                foreach ($contribution->diagnostics as $diagnostic) {
                    $codes[] = $diagnostic->code;
                }
                foreach ($contribution->edges as $edge) {
                    if ($edge->kind === 'extends') {
                        $bases[] = $edge->targetReference;
                    }
                }
            }
            assertSame([], $codes);
            assertSame(['py:class:generated.models.Model'], $bases);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * A deeply nested unary expression parses fine (`ast.parse` has its own
     * guard against runaway nesting), but the visitor's recursive descent
     * through `PythonAstFactCollector.collect()` exhausts Python's own
     * recursion limit, which is the real, worker-triggered route to
     * PY_INTERNAL_ERROR — the diagnostic still has to carry the hash of the
     * bytes that were genuinely read and parsed.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReportsTheHashOnAnInternalErrorDuringCollection(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o777, true);
        $bytes = 'x = ' . str_repeat('-', 4000) . "1\n";
        file_put_contents($root . '/deep.py', $bytes);
        $client = $this->pythonWorkerClient();
        try {
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['deep.py']]));
            $contribution = $contributions[0];
            assertSame([], $contribution->nodes);
            assertSame([], $contribution->edges);
            assertSame('PY_INTERNAL_ERROR', $contribution->diagnostics[0]->code);
            assertSame(hash('sha256', $bytes), $contribution->contentHash);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * A scanned file seeds the module index with its own declarations, so its
     * local references resolve against the bytes it hashed. The loser of a
     * `mod.py`/`mod/__init__.py` collision must not seed the shared id: the
     * package owns it, and an importer's targets would otherwise depend on
     * whether the module file happened to share its batch.
     */
    #[Group('python-scanner')]
    public function testACollidingModuleFileDoesNotSeedTheIdThePackageOwns(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/mod', 0o777, true);
        file_put_contents($root . '/mod.py', "class OnlyInModule:\n    pass\n");
        file_put_contents($root . '/mod/__init__.py', '');
        file_put_contents($root . '/user.py', "from mod import OnlyInModule\n\n\nclass Local(OnlyInModule):\n    pass\n");
        try {
            $client = $this->pythonWorkerClient();
            $targets = function (array $files) use ($client, $root): array {
                foreach ($client->scan(['root' => $root, 'files' => $files]) as $contribution) {
                    if ($contribution->ownerKey === 'knossos.python:file:user.py') {
                        return array_map(
                            fn(EdgeFact $edge): string => $edge->kind . ' ' . $edge->targetReference,
                            array_values(array_filter(
                                $contribution->edges,
                                fn(EdgeFact $edge): bool => $edge->sourceReference === 'py:class:user.Local',
                            )),
                        );
                    }
                }

                return [];
            };
            try {
                $alone = $targets(['user.py']);
                $together = $targets(['mod.py', 'user.py']);
            } finally {
                $client->shutdown();
            }

            assertSame(['extends py:external_symbol:mod.OnlyInModule'], $alone);
            assertSame($alone, $together);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A module the index refuses to read, here for exceeding the byte cap, is
     * left out of resolution, so the importer's facts are computed without it.
     * The worker says so by reporting that path as a failed read.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReportsAModuleItRefusedAsNull(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/pkg', 0o777, true);
        $importer = "from pkg.big import Thing\n";
        file_put_contents($root . '/pkg/a.py', $importer);
        file_put_contents($root . '/pkg/big.py', "class Thing:\n    pass\n" . str_repeat('#', 200) . "\n");
        $client = $this->pythonWorkerClient();
        try {
            $client->initialize();
            iterator_to_array($client->scan(['root' => $root, 'files' => ['pkg/a.py'], 'limits' => ['max_file_bytes' => 100]]));
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            self::assertInputHashesInclude(['pkg/a.py' => hash('sha256', $importer), 'pkg/big.py' => null], $inputHashes);
            // Discovery does not report the over-cap file either, so the null is ignored.
            self::assertInputHashesVerify($root, $inputHashes, $client->initialize(), 100);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * A requested file the filesystem would not let the worker read as the
     * file discovery hashed (over the byte cap, gone, a directory, or a link
     * leaving the root) is reported as `null`: its contribution carries no
     * facts, so without the null a discovered file would lose its facts from a
     * graph reported fresh. A path refused by policy stays unreported.
     */
    #[Group('python-scanner')]
    public function testPythonWorkerReportsARequestedFileWhoseReadFailsAsNull(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $outside = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0o777, true);
        mkdir($root . '/app/dir.py', 0o777, true);
        mkdir($outside, 0o777, true);
        file_put_contents($root . '/app/__init__.py', '');
        file_put_contents($root . '/app/big.py', "VALUE = 1\n" . str_repeat('#', 200) . "\n");
        file_put_contents($root . '/app/notes.txt', "text\n");
        file_put_contents($outside . '/out.py', "VALUE = 2\n");
        symlink($outside . '/out.py', $root . '/app/out.py');
        $client = $this->pythonWorkerClient();
        try {
            $client->initialize();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['app/big.py', 'app/dir.py', 'app/gone.py', 'app/notes.txt', 'app/out.py'],
                'limits' => ['max_file_bytes' => 100],
            ]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;

            self::assertInputHashesInclude(['app/big.py' => null, 'app/dir.py' => null, 'app/gone.py' => null, 'app/out.py' => null], $inputHashes);
            assertSame(5, count($contributions));
            foreach ($contributions as $contribution) {
                assertSame('PY_UNSCANNABLE_FILE', $contribution->diagnostics[0]->code);
            }
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
            $this->removeTempTree($outside);
        }
    }

    /**
     * Stable link layouts through the real worker process: a link whose target
     * climbs with `..` after another link, an absolute link inside the root, one
     * with a doubled leading slash, a chain of 41 links (one past the kernel's
     * limit), a dangling link, and a linked directory. Each is keyed by the file
     * the kernel opens and by the links it passed, and the whole map passes the
     * core's check against real discovery, so none of it can fail a scan of a
     * tree that is not changing.
     */
    #[Group('python-scanner')]
    public function testStableLinkLayoutsAreKeyedByTheKernelWalkAndPassVerification(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        foreach (['pkg', 'deep/dir', 'lib', 'real', 'chain'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        $files = [
            'pkg/__init__.py' => '',
            'deep/c.py' => "class Deep:\n    pass\n",
            // What a textual collapse of pkg/lnk.py's `..` would name instead.
            'pkg/c.py' => "class Decoy:\n    pass\n",
            'lib/b.py' => "class Lib:\n    pass\n",
            'lib/s.py' => "class Slash:\n    pass\n",
            'real/c.py' => "class Real:\n    pass\n",
            'chain/real.py' => "class Chained:\n    pass\n",
            'deep/dir/keep.py' => '',
        ];
        $files['app.py'] = implode("\n", [
            'from pkg.lnk import Deep',
            'from pkg.abs import Lib',
            'from pkg.slash import Slash',
            'from chain.l0 import Chained',
            'from pkg.dangling import Gone',
            'from linked.c import Real',
            '',
            '',
            'class App(Deep, Lib, Slash, Real):',
            '    pass',
            '',
        ]);
        foreach ($files as $relative => $bytes) {
            file_put_contents($root . '/' . $relative, $bytes);
        }
        symlink('../deep/dir', $root . '/pkg/d');
        symlink('d/../c.py', $root . '/pkg/lnk.py');
        symlink($root . '/lib/b.py', $root . '/pkg/abs.py');
        symlink('/' . $root . '/lib/s.py', $root . '/pkg/slash.py');
        for ($link = 0; $link < 41; ++$link) {
            symlink($link === 40 ? 'real.py' : sprintf('l%d.py', $link + 1), sprintf('%s/chain/l%d.py', $root, $link));
        }
        symlink('gone.py', $root . '/pkg/dangling.py');
        symlink('real', $root . '/linked');
        $client = $this->pythonWorkerClient();
        try {
            $manifest = $client->initialize();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => array_keys($files)]), false);
            $inputHashes = $client->lastScanResult()['input_hashes'] ?? null;
            $edges = [];
            foreach ($contributions as $contribution) {
                assertSame([], array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, array_filter(
                    $contribution->diagnostics,
                    static fn(Diagnostic $diagnostic): bool => $diagnostic->severity === 'error',
                )));
                foreach ($contribution->edges as $edge) {
                    $edges[] = $edge->kind . ' ' . $edge->targetReference;
                }
            }

            assertSame(count($files), count($contributions));
            assertArrayContains('extends py:class:pkg.lnk.Deep', $edges);
            assertArrayContains('extends py:class:pkg.abs.Lib', $edges);
            assertArrayContains('extends py:class:pkg.slash.Slash', $edges);
            assertArrayContains('extends py:class:linked.c.Real', $edges);
            self::assertInputHashesInclude([
                'deep/c.py' => hash('sha256', $files['deep/c.py']),
                'pkg/lnk.py' => null,
                'pkg/d' => null,
                'lib/b.py' => hash('sha256', $files['lib/b.py']),
                'pkg/abs.py' => null,
                'lib/s.py' => hash('sha256', $files['lib/s.py']),
                'pkg/slash.py' => null,
                'chain/l39.py' => null,
                'pkg/dangling.py' => null,
                'pkg/gone.py' => null,
                'real/c.py' => hash('sha256', $files['real/c.py']),
                'linked' => null,
                'linked/c.py' => null,
            ], $inputHashes);
            self::assertInputHashesVerify($root, $inputHashes, $manifest);
        } finally {
            $client->shutdown();
            $this->removeTempTree($root);
        }
    }

    /**
     * Every expected entry is in the map with its value. Probes add null entries
     * for candidates that are absent and for links they passed, none of which
     * discovery reports; assertInputHashesVerify() checks exactly that.
     *
     * @param array<string, string|null> $expected
     * @param mixed $inputHashes
     */
    private static function assertInputHashesInclude(array $expected, mixed $inputHashes): void
    {
        assertSame(true, is_array($inputHashes));
        $actual = [];
        foreach (array_keys($expected) as $path) {
            $actual[$path] = array_key_exists($path, $inputHashes) ? $inputHashes[$path] : 'absent';
        }
        assertSame($expected, $actual);
    }

    /**
     * The map passes the core's own check against what discovery reports for
     * the tree as it stands: a stable tree never fails a scan.
     *
     * @param mixed $inputHashes
     */
    private static function assertInputHashesVerify(string $root, mixed $inputHashes, ScannerManifest $manifest, int $maxFileBytes = 2_000_000): void
    {
        $byPath = self::discoveredByPath($root, $maxFileBytes);
        assertSame(true, $byPath !== []);
        ScanInputHashes::verify(['input_hashes' => $inputHashes], $manifest, $byPath);
    }

    /**
     * What discovery reports for the tree as it stands, by relative path.
     *
     * @return array<string, \Knossos\Discovery\DiscoveredFile>
     */
    private static function discoveredByPath(string $root, int $maxFileBytes = 2_000_000): array
    {
        $byPath = [];
        foreach ((new ProjectDiscoverer(new DiscoveryConfig([$root], maxFileBytes: $maxFileBytes)))->discover($root)->files as $file) {
            $byPath[$file->relativePath] = $file;
        }

        return $byPath;
    }

    public function testAFunctionAPackageReExportsIsCalledThroughThePackage(): void
    {
        // `from app import config; config.staging_dir()`: the package
        // re-exports what its submodules declare, and a caller reaches it
        // through the package.
        $root = self::repositoryRoot() . '/tests/Fixtures/python-reexport';
        $contributions = iterator_to_array($this->pythonWorkerClient()->scan([
            'root' => $root,
            'files' => ['app/__init__.py', 'app/config/__init__.py', 'app/config/limits.py', 'app/config/paths.py', 'app/service.py'],
        ]), false);
        $calls = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'calls') {
                    $calls[] = $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
        }

        self::assertContains('py:function:app.service.run -> py:function:app.config.paths.staging_dir', $calls);
        self::assertContains('py:function:app.service.run -> py:function:app.config.limits.request_window', $calls);
    }

    #[Group('python-scanner')]
    public function testAMethodCalledOnAFreshInstanceResolvesThroughItsClass(): void
    {
        // `Repo().count()` calls a method on the instance just built, so the
        // class it names types the receiver. A call on another call's result
        // has no type to go on and counts as a call on an untyped receiver.
        $root = sys_get_temp_dir() . '/knossos-py-fresh-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/repo.py' => "class Repo:\n    def count(self):\n        return 1\n",
            'app/service.py' => implode("\n", [
                'from .repo import Repo',
                '',
                'def run():',
                '    return Repo().count()',
                '',
                'def chained(make):',
                '    return make().render()',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app/service.py']]));
            $client->shutdown();
        } finally {
            foreach (array_reverse(array_keys($files)) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $calls = [];
        $untyped = null;
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'calls') {
                    $calls[] = $edge->sourceReference . ' -> ' . $edge->targetReference;
                }
            }
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'module') {
                    $untyped = $node->attributes['unresolved_member_calls'] ?? null;
                }
            }
        }

        self::assertContains('py:function:app.service.run -> py:method:app.repo.Repo::count', $calls);
        self::assertSame(['render'], $untyped);
    }

    #[Group('python-scanner')]
    public function testAFunctionAnObjectRegistersThroughADecoratorIsRuntimeInvoked(): void
    {
        // `@tq.register("x")` hands the function to an object that calls it
        // later, so nothing in the code calls it by name. A decorator taken
        // from a module, `@functools.cache`, wraps the function and registers
        // it nowhere.
        $root = sys_get_temp_dir() . '/knossos-py-registered-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/handlers.py' => implode("\n", [
                'import functools',
                '',
                'bus = object()',
                '',
                'def register(tq):',
                '    @tq.register("scan")',
                '    def _handle(payload):',
                '        return payload',
                '',
                '@bus.on',
                'def on_event(event):',
                '    return event',
                '',
                '@functools.cache',
                'def cached():',
                '    return 1',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app/handlers.py']]));
            $client->shutdown();
        } finally {
            foreach (array_reverse(array_keys($files)) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $invoked = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                if ($node->kind === 'function') {
                    $invoked[$node->canonicalName] = $node->attributes['runtime_invoked'] ?? false;
                }
            }
        }

        self::assertSame([
            'app.handlers.cached' => false,
            'app.handlers.on_event' => true,
            'app.handlers.register' => false,
            'app.handlers.register.<locals>._handle' => true,
        ], (static function (array $map): array {
            ksort($map);

            return $map;
        })($invoked));
    }

    #[Group('python-scanner')]
    public function testAMethodReadOffATypedReceiverAndAClassDeclaredUnderAGuardAreReferenced(): void
    {
        // `{"cache": client.prune_cache}` hands a bound method to a dispatch
        // table, and a class declared under `if TYPE_CHECKING:` is still a
        // module-level name an annotation refers to.
        $root = sys_get_temp_dir() . '/knossos-py-bound-' . bin2hex(random_bytes(6));
        mkdir($root . '/app', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/client.py' => "class Client:\n    def prune_cache(self):\n        return 1\n\nclient = Client()\n",
            'app/service.py' => implode("\n", [
                'from typing import TYPE_CHECKING, Protocol',
                '',
                'if TYPE_CHECKING:',
                '    class QueueLike(Protocol):',
                '        def depth(self) -> int: ...',
                '',
                'def prune(mode, queue: QueueLike | None):',
                '    from app.client import client as _c',
                '    dispatch = {"cache": _c.prune_cache, "size": _c.size}',
                '    return dispatch[mode]()',
                '',
            ]),
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app/service.py']]));
            $client->shutdown();
        } finally {
            foreach (array_reverse(array_keys($files)) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $references = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if ($edge->kind === 'references') {
                    $references[$edge->targetReference] = ($edge->attributes['speculative'] ?? false) === true;
                }
            }
        }
        ksort($references);

        // The read is kept only if the class declares the member, which the
        // reconciler decides, so both reads are speculative.
        self::assertSame([
            'py:class:app.service.QueueLike' => false,
            'py:method:app.client.Client::prune_cache' => true,
            'py:method:app.client.Client::size' => true,
        ], $references);
    }

    #[Group('python-scanner')]
    public function testAModuleNoImportCanNameIsLoadedByPathAndItsPublicNamesAreItsInterface(): void
    {
        // `029_seed.py` cannot be the target of an import statement, so a
        // runner loads it by path and calls what it exposes. Its private
        // helpers are still judged by their callers.
        $root = sys_get_temp_dir() . '/knossos-py-by-path-' . bin2hex(random_bytes(6));
        mkdir($root . '/app/migrations', 0o755, true);
        $files = [
            'app/__init__.py' => '',
            'app/migrations/__init__.py' => '',
            'app/migrations/029_seed.py' => "def _rows():\n    return []\n\ndef apply(conn):\n    return _rows()\n",
            'app/migrations/helpers.py' => "def apply(conn):\n    return conn\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $client = $this->pythonWorkerClient();
            $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => ['app/migrations/029_seed.py', 'app/migrations/helpers.py']]), false);
            $client->shutdown();
        } finally {
            foreach (array_reverse(array_keys($files)) as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/app/migrations');
            @rmdir($root . '/app');
            @rmdir($root);
        }

        $invoked = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->nodes as $node) {
                $invoked[$node->kind . ' ' . $node->canonicalName] = $node->attributes['runtime_invoked'] ?? false;
            }
        }
        ksort($invoked);

        self::assertSame([
            'function app.migrations.029_seed._rows' => false,
            'function app.migrations.029_seed.apply' => true,
            'function app.migrations.helpers.apply' => false,
            'module app.migrations.029_seed' => true,
            'module app.migrations.helpers' => false,
        ], $invoked);
    }
}
