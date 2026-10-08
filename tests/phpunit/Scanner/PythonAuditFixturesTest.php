<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The audit's Python identity and routing fixtures through the whole scan:
 * each module, function and router is a node on its own file, every edge
 * reaches one, and ids do not depend on how the files were batched.
 */
#[Group('python-scanner')]
final class PythonAuditFixturesTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-python-audit-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }
    /** A module below a namespace directory is named as its importers name it; `os.path.join` keeps its name. */
    /** A module below a namespace directory is named as its importers name it;  keeps its name. */
    public function testH10AuditFixtureThroughTheService(): void
    {
        $this->write('app/models/user.py', "def make():\n    return 1\n");
        $this->write('main.py', "from app.models.user import make\nimport app.models.user\nimport os.path\n\n\ndef run():\n    os.path.join('a', 'b')\n    return make(), app.models.user.make()\n");
        $pdo = $this->scan();

        $nodes = $this->nodes($pdo);
        $byName = array_column($nodes, null, 'canonical_name');
        self::assertSame('app/models/user.py', $byName['app.models.user.make']['relative_path'] ?? null, json_encode($nodes));
        self::assertSame('function', $byName['app.models.user.make']['kind']);
        self::assertArrayNotHasKey('models.user.make', $byName);
        $phantoms = array_filter($nodes, static fn(array $n): bool => str_contains($n['canonical_name'], 'models.user') && $n['kind'] !== 'function' && $n['kind'] !== 'module' && $n['kind'] !== 'package');
        self::assertSame([], array_values($phantoms), 'no phantom externals under models.user');
        self::assertSame([], array_values(array_filter($nodes, static fn(array $n): bool => str_contains($n['canonical_name'], 'path.path'))), 'os.path.join is not os.path.path.join');

        $edges = $this->edges($pdo);
        $calls = array_values(array_filter($edges, static fn(array $e): bool => $e['kind'] === 'calls' && $e['source'] === 'main.run'));
        $targets = array_column($calls, 'target');
        sort($targets);
        self::assertContains('app.models.user.make', $targets, json_encode($calls));
        foreach ($calls as $call) {
            if ($call['target'] === 'app.models.user.make') {
                self::assertSame('function', $call['target_kind']);
                self::assertSame('app/models/user.py', $call['target_file']);
                self::assertSame('certain', $call['confidence']);
            }
        }
        $imports = array_values(array_filter($edges, static fn(array $e): bool => $e['kind'] === 'imports' && $e['source'] === 'main'));
        $importTargets = array_unique(array_column($imports, 'target'));
        sort($importTargets);
        self::assertContains('app.models.user', $importTargets, json_encode($imports));
        self::assertNotContains('models.user', $importTargets);
    }

    /** No two files share a module id, and only the file that loses an id reports it. */
    public function testH11AuditFixtureThroughTheService(): void
    {
        $this->write('scripts/utils.py', "def a():\n    return 1\n");
        $this->write('tools/utils.py', "def b():\n    return 2\n");
        $this->write('conftest.py', "def fx():\n    return 1\n");
        $this->write('tests/conftest.py', "def fy():\n    return 2\n");
        $this->write('pkg/__init__.py', '');
        $this->write('pkg/mod.py', "def f():\n    return 1\n");
        $this->write('pkg/mod.pyi', "def f() -> int: ...\n");
        $this->write('utils.py', "def top():\n    return 1\n");
        $this->write('src/utils.py', "def inner():\n    return 1\n\n\ndef outer():\n    return inner()\n");
        $this->write('use.py', "from utils import top\nfrom src.utils import inner\n\n\ndef u():\n    return top(), inner()\n");
        $pdo = $this->scan();

        $nodes = $this->nodes($pdo);
        $modules = [];
        foreach ($nodes as $n) {
            if ($n['kind'] === 'module') {
                $modules[$n['canonical_name']] = $n['relative_path'];
            }
        }
        ksort($modules);
        self::assertSame([
            'conftest' => 'conftest.py',
            'pkg' => 'pkg/__init__.py',
            'pkg.mod' => 'pkg/mod.py',
            'pkg.mod.<pkg/mod.pyi>' => 'pkg/mod.pyi',
            'scripts.utils' => 'scripts/utils.py',
            'tests.conftest' => 'tests/conftest.py',
            'tools.utils' => 'tools/utils.py',
            'use' => 'use.py',
            'utils' => 'utils.py',
            'utils.<src/utils.py>' => 'src/utils.py',
        ], $modules);
        $functions = [];
        foreach ($nodes as $n) {
            if ($n['kind'] === 'function') {
                $functions[$n['canonical_name']] = $n['relative_path'];
            }
        }
        self::assertSame('pkg/mod.pyi', $functions['pkg.mod.<pkg/mod.pyi>.f'] ?? null);
        self::assertSame('pkg/mod.py', $functions['pkg.mod.f'] ?? null);
        self::assertSame('src/utils.py', $functions['utils.<src/utils.py>.inner'] ?? null);
        $stub = array_values(array_filter($nodes, static fn(array $n): bool => $n['relative_path'] === 'pkg/mod.pyi'));
        foreach ($stub as $n) {
            self::assertTrue(json_decode($n['attributes_json'], true)['declaration_file'] ?? false, $n['canonical_name']);
        }

        $diagnostics = $this->diagnostics($pdo);
        self::assertSame([], array_values(array_filter($diagnostics, static fn(array $d): bool => $d['code'] === 'reconciler.duplicate_symbol_evidence')));
        $collisions = array_values(array_filter($diagnostics, static fn(array $d): bool => $d['code'] === 'PY_MODULE_ID_COLLISION'));
        self::assertCount(1, $collisions, json_encode($collisions));
        self::assertSame('src/utils.py', $collisions[0]['relative_path']);
        self::assertStringContainsString("'utils.py'", $collisions[0]['message']);
        self::assertStringContainsString('utils.<src/utils.py>', $collisions[0]['message']);

        $edges = $this->edges($pdo);
        $calls = array_values(array_filter($edges, static fn(array $e): bool => $e['kind'] === 'calls'));
        $pairs = array_map(static fn(array $e): string => $e['source'] . ' -> ' . $e['target'] . ' [' . $e['target_kind'] . ']', $calls);
        sort($pairs);
        self::assertSame([
            'use.u -> utils.<src/utils.py>.inner [function]',
            'use.u -> utils.top [function]',
            'utils.<src/utils.py>.outer -> utils.<src/utils.py>.inner [function]',
        ], $pairs);
    }

    /** Every mount reaches a router node, and a blueprint's url rule keeps its prefix. */
    public function testL28AuditFixtureThroughTheService(): void
    {
        $this->write('api/__init__.py', '');
        $this->write('api/users.py', "from fastapi import APIRouter\n\nrouter = APIRouter(prefix=\"/users\")\n\n\n@router.get(\"/\")\ndef list_users():\n    return []\n");
        $this->write('main.py', "from fastapi import FastAPI\nfrom api.users import router as users_router\n\napp = FastAPI()\napp.include_router(users_router, prefix=\"/v1\")\n\n\ndef setup(r):\n    app.include_router(r)\n");
        $this->write('alt.py', "from fastapi import FastAPI\nimport api.users as users\n\napp = FastAPI()\napp.include_router(users.router)\n");
        $this->write('web.py', "from flask import Flask, Blueprint\n\napp = Flask(__name__)\nbp = Blueprint(\"bp\", __name__, url_prefix=\"/bp\")\n\n\ndef view():\n    return \"x\"\n\n\nbp.add_url_rule(\"/x\", view_func=view)\napp.register_blueprint(bp)\n");
        $pdo = $this->scan();

        $nodes = $this->nodes($pdo);
        $routers = [];
        foreach ($nodes as $n) {
            if ($n['kind'] === 'router') {
                $routers[$n['canonical_name']] = $n['relative_path'];
            }
        }
        ksort($routers);
        self::assertSame(['api.users.router' => 'api/users.py', 'web.bp' => 'web.py'], $routers);
        $names = array_column($nodes, 'canonical_name');
        self::assertNotContains('main.users_router', $names);
        self::assertNotContains('main.users', $names);
        self::assertNotContains('main.r', $names);
        self::assertNotContains('GET /x => web.view', $names);
        self::assertContains('GET /bp/x => web.view', $names);
        self::assertContains('GET /users => api.users.list_users', $names, json_encode($names));

        $mounts = array_values(array_filter($this->edges($pdo), static fn(array $e): bool => $e['kind'] === 'mounts'));
        $pairs = array_map(static fn(array $e): string => $e['source'] . ' -> ' . $e['target'] . ' [' . $e['target_kind'] . '] ' . $e['attributes_json'], $mounts);
        sort($pairs);
        self::assertSame([
            'alt -> api.users.router [router] {"prefix":""}',
            'main -> api.users.router [router] {"prefix":"/v1"}',
            'web -> web.bp [router] {"prefix":""}',
        ], $pairs);
        self::assertNotContains('alt.users', $names);
    }

    /** The same tree, scanned in one request ascending, one descending, and one file per request, gives one answer. */
    public function testIdsDoNotDependOnRequestOrder(): void
    {
        $this->write('utils.py', "def top():\n    return 1\n");
        $this->write('src/gadgets.py', "class Gadget:\n    pass\n");
        $this->write('src/utils.py', "from gadgets import Gadget\n\n\ndef inner():\n    return Gadget()\n\n\ndef outer():\n    return inner()\n");
        $this->write('viapath.py', "from src.utils import inner\nimport src.utils as su\n\n\ndef v():\n    return inner(), su.outer()\n");
        $this->write('aplain.py', "import src.utils as su\nimport utils\n\n\ndef p():\n    return su.Gadget(), utils.top()\n");
        $this->write('pkg/__init__.py', '');
        $this->write('pkg/mod.py', "def f():\n    return 1\n");
        $this->write('pkg/mod/__init__.py', "def g():\n    return 1\n");
        $this->write('pkg/mod.pyi', "def f() -> int: ...\n");
        $files = ['aplain.py', 'pkg/__init__.py', 'pkg/mod.py', 'pkg/mod.pyi', 'pkg/mod/__init__.py', 'src/gadgets.py', 'src/utils.py', 'utils.py', 'viapath.py'];

        $ascending = $this->signature([$files], $files);
        $descending = $this->signature([array_reverse($files)], $files);
        $single = $this->signature(array_map(static fn(string $f): array => [$f], $files), $files);
        $singleReversed = $this->signature(array_map(static fn(string $f): array => [$f], array_reverse($files)), $files);

        self::assertSame($ascending, $descending);
        self::assertSame($ascending, $single);
        self::assertSame($ascending, $singleReversed);
        self::assertStringContainsString('py:module:utils.<src/utils.py>', $ascending);
        self::assertStringContainsString('py:module:pkg.mod.<pkg/mod.py>', $ascending);
        self::assertStringContainsString('py:class:gadgets.Gadget', $ascending);
        self::assertStringNotContainsString('py:class:src.gadgets.Gadget', $ascending);
        self::assertStringNotContainsString('py:external_symbol:src.utils', $ascending);
    }

    /** @param list<list<string>> $requests @param list<string> $sourceFiles */
    private function signature(array $requests, array $sourceFiles): string
    {
        $client = $this->pythonWorkerClient();
        $out = [];
        try {
            foreach ($requests as $files) {
                foreach ($client->scan(['root' => $this->root, 'files' => $files, 'source_files' => $sourceFiles]) as $contribution) {
                    $path = substr($contribution->ownerKey, strlen('knossos.python:file:'));
                    $nodes = array_map(static fn(NodeFact $n): string => $n->localId, $contribution->nodes);
                    $edges = array_map(static fn(EdgeFact $e): string => $e->kind . ' ' . $e->sourceReference . ' -> ' . $e->targetReference . ' ' . json_encode($e->attributes), $contribution->edges);
                    sort($nodes);
                    sort($edges);
                    $out[$path] = ['nodes' => $nodes, 'edges' => $edges];
                }
            }
        } finally {
            $client->shutdown();
        }
        ksort($out);

        return json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function scan(): PDO
    {
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        return $pdo;
    }

    /** @return list<array<string, mixed>> */
    private function nodes(PDO $pdo): array
    {
        return $pdo->query("SELECT n.kind, n.canonical_name, n.attributes_json, n.confidence, f.relative_path FROM nodes n LEFT JOIN files f ON f.id = n.file_id WHERE n.language = 'py' ORDER BY n.canonical_name")->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function edges(PDO $pdo): array
    {
        return $pdo->query('SELECT e.kind, s.canonical_name source, t.canonical_name target, t.kind target_kind, tf.relative_path target_file, e.confidence, e.attributes_json FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id LEFT JOIN files tf ON tf.id = t.file_id ORDER BY e.id')->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string, mixed>> */
    private function diagnostics(PDO $pdo): array
    {
        return $pdo->query('SELECT d.code, d.message, f.relative_path FROM diagnostics d JOIN projects p ON p.id = d.project_id AND p.active_scan_id = d.scan_id LEFT JOIN files f ON f.id = d.file_id ORDER BY d.code, f.relative_path')->fetchAll(PDO::FETCH_ASSOC);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }
}
