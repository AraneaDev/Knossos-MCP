<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every Python module id is the one its importers use, no two files share
 * one, and a framework mount names a router node that exists.
 *
 * Each case scans a small tree through the real worker and checks the node a
 * file declares, the edge an importer emits, and that the phantom the old
 * naming produced is gone.
 */
#[Group('python-scanner')]
final class PythonModuleIdentityTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-python-identity-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * `app/` has no `__init__.py` and is no source root: it is a namespace
     * package, so `app/models/user.py` is `app.models.user`, the name both
     * import forms use.
     */
    public function testAModuleBelowANamespaceDirectoryIsNamedAsItsImportersNameIt(): void
    {
        $this->write('app/models/user.py', "def make():\n    return 1\n");
        $this->write('main.py', implode("\n", [
            'import app.models.user',
            'from app.models.user import make',
            '',
            '',
            'def run():',
            '    return make() + app.models.user.make()',
            '',
        ]));

        $facts = $this->scanned();

        self::assertContains('py:function:app.models.user.make', $this->nodeIds($facts, 'app/models/user.py'));
        self::assertNotContains('py:function:models.user.make', $this->nodeIds($facts, 'app/models/user.py'));
        $edges = $this->edges($facts, 'main.py');
        self::assertContains(['calls', 'py:function:main.run', 'py:function:app.models.user.make'], $edges);
        self::assertContains(['imports', 'py:module:main', 'py:module:app.models.user'], $edges);
        // `import app.models.user` binds `app`, so the dotted call walks down
        // to the submodule instead of naming `app.models.user.models.user.make`.
        self::assertSame([], array_values(array_filter(
            $edges,
            static fn(array $edge): bool => str_contains($edge[2], 'models.user.models'),
        )));
    }

    /**
     * `src/` without a marker is a src layout, so `src/shop/cart.py` is
     * `shop.cart`; an import that spells the file's path from the bare root
     * names the module by the file it finds.
     */
    public function testASrcLayoutIsASourceRootAndAnImportNamesTheFileItFinds(): void
    {
        $this->write('src/shop/__init__.py', '');
        $this->write('src/shop/cart.py', "class Cart:\n    pass\n");
        $this->write('scripts/report.py', "def report():\n    return 1\n");
        $this->write('tests/test_cart.py', implode("\n", [
            'from shop.cart import Cart',
            'from src.shop.cart import Cart as Same',
            '',
            '',
            'def test_cart():',
            '    return Cart(), Same()',
            '',
        ]));

        $facts = $this->scanned();

        self::assertContains('py:class:shop.cart.Cart', $this->nodeIds($facts, 'src/shop/cart.py'));
        self::assertContains('py:function:scripts.report.report', $this->nodeIds($facts, 'scripts/report.py'));
        self::assertNotContains('py:function:report.report', $this->nodeIds($facts, 'scripts/report.py'));
        $edges = $this->edges($facts, 'tests/test_cart.py');
        self::assertContains(['calls', 'py:function:tests.test_cart.test_cart', 'py:class:shop.cart.Cart'], $edges);
        self::assertContains(['imports', 'py:module:tests.test_cart', 'py:module:shop.cart'], $edges);
        self::assertNotContains(['imports', 'py:module:tests.test_cart', 'py:module:src.shop.cart'], $edges);
        self::assertSame([], array_values(array_filter($edges, static fn(array $edge): bool => str_contains($edge[2], 'src.shop'))));

        // A `src/` that is a package is no src layout: its modules keep the `src.` prefix.
        $this->write('src/__init__.py', '');
        $facts = $this->scanned();
        self::assertContains('py:class:src.shop.cart.Cart', $this->nodeIds($facts, 'src/shop/cart.py'));
        self::assertContains(['calls', 'py:function:tests.test_cart.test_cart', 'py:class:src.shop.cart.Cart'], $this->edges($facts, 'tests/test_cart.py'));
    }

    /** @return array<string, array{0: string}> */
    public static function declaredRoots(): array
    {
        return [
            'setuptools find where' => ["[tool.setuptools.packages.find]\nwhere = [\"code\"]\n"],
            'setuptools package-dir' => ["[tool.setuptools.package-dir]\n\"\" = \"code\"\n"],
            'poetry packages from' => ["[tool.poetry]\nname = \"x\"\npackages = [{ include = \"pkgx\", from = \"code\" }]\n"],
            'hatch wheel packages' => ["[tool.hatch.build.targets.wheel]\npackages = [\"code/pkgx\"]\n"],
            'pdm package-dir' => ["[tool.pdm.build]\npackage-dir = \"code\"\n"],
        ];
    }

    /** A directory the pyproject names as where its packages live is a source root. */
    #[DataProvider('declaredRoots')]
    public function testADirectoryThePyprojectDeclaresIsASourceRoot(string $pyproject): void
    {
        $this->write('pyproject.toml', $pyproject);
        $this->write('code/pkgx/__init__.py', '');
        $this->write('code/pkgx/mod.py', "def f():\n    return 1\n");
        $this->write('use.py', "from pkgx.mod import f\n\n\ndef u():\n    return f()\n");

        $facts = $this->scanned();

        self::assertContains('py:function:pkgx.mod.f', $this->nodeIds($facts, 'code/pkgx/mod.py'));
        self::assertContains(['calls', 'py:function:use.u', 'py:function:pkgx.mod.f'], $this->edges($facts, 'use.py'));
    }

    /** A pyproject that does not parse, or names a path outside the project, declares no source root. */
    public function testAnUnusablePyprojectDeclaresNoSourceRoot(): void
    {
        $this->write('pyproject.toml', "[tool.setuptools.packages.find]\nwhere = [\"../outside\", \"/abs\", 3]\n");
        $this->write('code/mod.py', "def f():\n    return 1\n");

        self::assertContains('py:function:code.mod.f', $this->nodeIds($this->scanned(), 'code/mod.py'));

        $this->write('pyproject.toml', "[tool.setuptools.packages.find\nwhere = \"code\"\n");
        self::assertContains('py:function:code.mod.f', $this->nodeIds($this->scanned(), 'code/mod.py'));

        // Over the byte cap, or left out by discovery, it is not read at all.
        $this->write('pyproject.toml', "[tool.setuptools.packages.find]\nwhere = [\"code\"]\n");
        self::assertContains('py:function:code.mod.f', $this->nodeIds($this->scanned(['limits' => ['max_file_bytes' => 30]]), 'code/mod.py'));
        $exclusions = ['segments' => [], 'prefixes' => [], 'sequences' => [], 'suffixes' => [], 'path_prefixes' => ['pyproject.toml'], 'patterns' => []];
        self::assertContains('py:function:code.mod.f', $this->nodeIds($this->scanned(['exclusions' => $exclusions]), 'code/mod.py'));
        self::assertContains('py:function:mod.f', $this->nodeIds($this->scanned(), 'code/mod.py'));
    }

    /**
     * pytest puts a test module's own directory first on `sys.path` when that
     * directory is no package, and so does a script run from it: a bare
     * import names the sibling file. A module inside a package gets no such
     * fallback.
     */
    public function testABareImportInADirectoryThatIsNoPackageNamesItsSibling(): void
    {
        $this->write('tests/helpers.py', "def build():\n    return 1\n");
        $this->write('tests/test_build.py', "from helpers import build\n\n\ndef test_build():\n    return build()\n");
        $this->write('tools/fmt.py', "def fmt():\n    return 1\n");
        $this->write('tools/run.py', "import fmt\n\n\ndef run():\n    return fmt.fmt()\n");
        $this->write('pkg/__init__.py', '');
        $this->write('pkg/helpers.py', "def build():\n    return 2\n");
        $this->write('pkg/lib.py', "from helpers import build\n");

        $facts = $this->scanned();

        self::assertContains(['calls', 'py:function:tests.test_build.test_build', 'py:function:tests.helpers.build'], $this->edges($facts, 'tests/test_build.py'));
        self::assertContains(['calls', 'py:function:tools.run.run', 'py:function:tools.fmt.fmt'], $this->edges($facts, 'tools/run.py'));
        self::assertContains(['imports', 'py:module:pkg.lib', 'py:module:helpers'], $this->edges($facts, 'pkg/lib.py'));
    }

    /**
     * Sibling directories, a root and a `tests/` conftest, and a module beside
     * its stub each named two files alike, and the reconciler kept one.
     */
    public function testFilesInSiblingDirectoriesAndAStubBesideItsModuleKeepApartIds(): void
    {
        $this->writeCollidingTree();

        $facts = $this->scanned();

        self::assertContains('py:module:scripts.utils', $this->nodeIds($facts, 'scripts/utils.py'));
        self::assertContains('py:module:tools.utils', $this->nodeIds($facts, 'tools/utils.py'));
        self::assertContains('py:module:conftest', $this->nodeIds($facts, 'conftest.py'));
        self::assertContains('py:module:tests.conftest', $this->nodeIds($facts, 'tests/conftest.py'));
        self::assertContains('py:function:pkg.mod.f', $this->nodeIds($facts, 'pkg/mod.py'));
        $stub = $this->nodeIds($facts, 'pkg/mod.pyi');
        self::assertContains('py:function:pkg.mod.<pkg/mod.pyi>.f', $stub);
        self::assertNotContains('py:function:pkg.mod.f', $stub);
        // A stub describes the module beside it, so none of its symbols is a dead-code question.
        foreach ($facts['pkg/mod.pyi']->nodes as $node) {
            self::assertTrue($node->attributes['declaration_file'] ?? false, $node->localId);
        }
        // A stub beside its own module is the intended layout, not a collision.
        self::assertSame([], $this->diagnosticCodes($facts, 'pkg/mod.pyi'));
        // A stub with no module beside it is the module.
        self::assertContains('py:function:pkg.ext.g', $this->nodeIds($facts, 'pkg/ext.pyi'));
    }

    /**
     * `utils.py` at the bare root and `src/utils.py` both map to `utils`; an
     * import finds the bare root's, so the other file is named by its path,
     * resolves its own names to itself, and says why.
     */
    public function testAFileWhoseModuleIdAnotherFileOwnsIsNamedByItsPath(): void
    {
        $this->writeCollidingTree();

        $facts = $this->scanned();

        self::assertContains('py:function:utils.top', $this->nodeIds($facts, 'utils.py'));
        $loser = $this->nodeIds($facts, 'src/utils.py');
        self::assertContains('py:module:utils.<src/utils.py>', $loser);
        self::assertContains('py:function:utils.<src/utils.py>.inner', $loser);
        self::assertNotContains('py:function:utils.inner', $loser);
        self::assertContains(
            ['calls', 'py:function:utils.<src/utils.py>.outer', 'py:function:utils.<src/utils.py>.inner'],
            $this->edges($facts, 'src/utils.py'),
        );
        self::assertSame(['PY_MODULE_ID_COLLISION'], $this->diagnosticCodes($facts, 'src/utils.py'));
        self::assertStringContainsString('utils.py', $this->diagnostics($facts, 'src/utils.py')[0]->message);
        // An import of the file through its bare-root path names it as it names itself.
        $viapath = $this->edges($facts, 'viapath.py');
        self::assertContains(['calls', 'py:function:viapath.v', 'py:function:utils.<src/utils.py>.inner'], $viapath);
        self::assertContains(['calls', 'py:function:viapath.v', 'py:function:utils.<src/utils.py>.outer'], $viapath);
        self::assertContains(['imports', 'py:module:viapath', 'py:module:utils.<src/utils.py>'], $viapath);
        // Scanned before `src/utils.py`, a plain import of it alone still
        // reads its declarations, so the class it re-exports is reached.
        self::assertContains(['calls', 'py:function:aplain.p', 'py:class:gadgets.Gadget'], $this->edges($facts, 'aplain.py'));

        // A module file beside a package of the same name: the package owns the id.
        self::assertContains('py:function:pair.p', $this->nodeIds($facts, 'pair/__init__.py'));
        self::assertContains('py:function:pair.<pair.py>.q', $this->nodeIds($facts, 'pair.py'));
        // A package named by its path is still displayed by its own name.
        $package = array_values(array_filter($facts['src/pkg/__init__.py']->nodes, static fn(NodeFact $node): bool => $node->kind === 'package'))[0];
        self::assertSame(['py:package:pkg.<src/pkg/__init__.py>', 'pkg'], [$package->localId, $package->displayName]);
        self::assertSame(['PY_MODULE_ID_COLLISION'], $this->diagnosticCodes($facts, 'pair.py'));
        self::assertSame(['PY_MODULE_ID_COLLISION'], $this->diagnosticCodes($facts, 'pair/__init__.py'));
    }

    /** Through the whole scan, every file keeps its own declarations and none is reported re-declared. */
    public function testNoPythonDeclarationIsDroppedAsADuplicate(): void
    {
        $this->writeCollidingTree();
        $pdo = $this->freshTestDatabase();

        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        $duplicates = $pdo->query("SELECT message FROM diagnostics WHERE code = 'reconciler.duplicate_symbol_evidence'")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([], $duplicates);
        $paths = $pdo->query("SELECT n.canonical_name, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.kind = 'function' AND n.language = 'py'")->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame('conftest.py', $paths['conftest.fx'] ?? null);
        self::assertSame('tests/conftest.py', $paths['tests.conftest.fy'] ?? null);
        self::assertSame('pkg/mod.py', $paths['pkg.mod.f'] ?? null);
        self::assertSame('pkg/mod.pyi', $paths['pkg.mod.<pkg/mod.pyi>.f'] ?? null);
        self::assertSame('src/utils.py', $paths['utils.<src/utils.py>.inner'] ?? null);
    }

    /**
     * `include_router` and `register_blueprint` name a router; the router is
     * now a node wherever it is assigned, so the mount reaches it, and a
     * blueprint's `add_url_rule` keeps the blueprint's prefix.
     */
    public function testAMountedRouterIsANodeAndAUrlRuleKeepsItsBlueprintPrefix(): void
    {
        $this->write('api/__init__.py', '');
        $this->write('api/users.py', implode("\n", [
            'from fastapi import APIRouter',
            '',
            'router = APIRouter(prefix="/users")',
            '',
            '',
            '@router.get("/")',
            'def list_users():',
            '    return []',
            '',
        ]));
        $this->write('main.py', implode("\n", [
            'import fastapi',
            'from fastapi import APIRouter, FastAPI',
            'from flask import Blueprint, Flask',
            'from api import users',
            'from api.users import router as users_router',
            '',
            'app = FastAPI()',
            'local = fastapi.APIRouter(prefix="/local")',
            'app.include_router(local, prefix="/v1")',
            'app.include_router(users_router)',
            'app.include_router(users.router)',
            'app.include_router(build_router())',
            '',
            '',
            'def register(target: FastAPI, extra: APIRouter):',
            '    target.include_router(extra)',
            '',
            '',
            'web = Flask(__name__)',
            'bp = Blueprint("bp", __name__, url_prefix="/bp")',
            '',
            '',
            'def view():',
            '    return ""',
            '',
            '',
            'bp.add_url_rule("/x", view_func=view)',
            'web.add_url_rule("/y", view_func=view)',
            'web.register_blueprint(bp)',
            '',
        ]));

        $facts = $this->scanned();

        $main = $this->nodeIds($facts, 'main.py');
        self::assertContains('py:router:main.local', $main);
        self::assertContains('py:router:main.bp', $main);
        self::assertContains('py:router:api.users.router', $this->nodeIds($facts, 'api/users.py'));
        $mounts = array_values(array_filter($facts['main.py']->edges, static fn(EdgeFact $edge): bool => $edge->kind === 'mounts'));
        $targets = [];
        foreach ($mounts as $edge) {
            $targets[$edge->targetReference] = $edge->attributes;
        }
        self::assertSame(['prefix' => '/v1'], $targets['py:router:main.local'] ?? null);
        self::assertSame(['prefix' => ''], $targets['py:router:api.users.router'] ?? null);
        self::assertSame(['prefix' => ''], $targets['py:router:main.bp'] ?? null);
        // A router handed in as a parameter was built elsewhere: the mount is a guess the graph keeps only if it resolves.
        self::assertSame(['prefix' => '', 'speculative' => true], $targets['py:router:main.extra'] ?? null);
        self::assertNotContains('py:router:main.users_router', array_keys($targets));
        self::assertNotContains('py:router:main.users.router', array_keys($targets));

        self::assertContains('py:route:GET /bp/x => main.view', $main);
        self::assertNotContains('py:route:GET /x => main.view', $main);
        self::assertContains('py:route:GET /y => main.view', $main);
    }

    /**
     * A tree whose files used to share module ids: sibling directories, a
     * root and a `tests/` conftest, a module and its stub, a module at the
     * bare root and one in `src/`, and a module beside a same-named package.
     */
    private function writeCollidingTree(): void
    {
        $this->write('scripts/utils.py', "def a():\n    return 1\n");
        $this->write('tools/utils.py', "def b():\n    return 2\n");
        $this->write('conftest.py', "def fx():\n    return 1\n");
        $this->write('tests/conftest.py', "def fy():\n    return 2\n");
        $this->write('pkg/__init__.py', '');
        $this->write('pkg/mod.py', "def f():\n    return 1\n");
        $this->write('pkg/mod.pyi', "def f() -> int: ...\n");
        $this->write('pkg/ext.pyi', "def g() -> int: ...\n");
        $this->write('utils.py', "def top():\n    return 1\n");
        $this->write('src/gadgets.py', "class Gadget:\n    pass\n");
        $this->write('src/utils.py', "from gadgets import Gadget\n\n\ndef inner():\n    return 1\n\n\ndef outer():\n    return inner()\n");
        $this->write('viapath.py', "from src.utils import inner\nimport src.utils as su\n\n\ndef v():\n    return inner(), su.outer(), su.sub.thing()\n");
        $this->write('aplain.py', "import src.utils as su\n\n\ndef p():\n    return su.Gadget()\n");
        $this->write('src/pkg/__init__.py', '');
        $this->write('pair.py', "def q():\n    return 1\n");
        $this->write('pair/__init__.py', "def p():\n    return 1\n");
    }

    /**
     * @param array<string, mixed> $params what the request carries besides the root and the files
     * @return array<string, ScanContribution> each scanned file's contribution, by path
     */
    private function scanned(array $params = []): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($this->root) + 1);
            if (str_ends_with($relative, '.py') || str_ends_with($relative, '.pyi')) {
                $files[] = $relative;
            }
        }
        sort($files);
        $client = $this->pythonWorkerClient();
        try {
            $byPath = [];
            foreach ($client->scan(['root' => $this->root, 'files' => $files, 'source_files' => $files] + $params) as $contribution) {
                $byPath[substr($contribution->ownerKey, strlen('knossos.python:file:'))] = $contribution;
            }
        } finally {
            $client->shutdown();
        }

        return $byPath;
    }

    /**
     * @param array<string, ScanContribution> $facts
     * @return list<string>
     */
    private function nodeIds(array $facts, string $path): array
    {
        return array_map(static fn(NodeFact $node): string => $node->localId, $facts[$path]->nodes);
    }

    /**
     * @param array<string, ScanContribution> $facts
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function edges(array $facts, string $path): array
    {
        return array_map(
            static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
            $facts[$path]->edges,
        );
    }

    /**
     * @param array<string, ScanContribution> $facts
     * @return list<Diagnostic>
     */
    private function diagnostics(array $facts, string $path): array
    {
        return $facts[$path]->diagnostics;
    }

    /**
     * @param array<string, ScanContribution> $facts
     * @return list<string>
     */
    private function diagnosticCodes(array $facts, string $path): array
    {
        return array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $facts[$path]->diagnostics);
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
