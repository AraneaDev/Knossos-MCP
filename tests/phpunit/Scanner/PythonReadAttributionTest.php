<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The Python worker says which files each contribution read, so an
 * incremental scan rescans only the files a change reaches, through the real
 * worker, and still ends with the graph a full scan of the same bytes gives.
 *
 * Each case changes the tree in one way a Python import can depend on and
 * compares the incremental graph with a full scan of the changed tree.
 */
#[Group('python-scanner')]
final class PythonReadAttributionTest extends KnossosTestCase
{
    /** A cache row an incremental scan reused keeps this stamp; a rescanned one is rewritten. */
    private const UNTOUCHED = 'untouched';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-python-reads-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * `pkg/__init__.py` re-exports `Engine` from `pkg/impl.py`. Two importers
     * use it: the first one scanned computes the package's declarations and
     * the second takes them from the worker's memo, and both must still name
     * the package. The star importer reads the package for its own
     * declarations; `other.py` reads none of it.
     */
    public function testEditingAModuleRescansItsReadersAndNothingElse(): void
    {
        $this->writePackage();
        $pdo = $this->scannedAndStamped();

        $this->write('pkg/impl.py', "class Engine:\n    def start(self):\n        return 1\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(['app.py', 'cli.py', 'pkg/__init__.py', 'pkg/impl.py', 'pkg/sub/user.py', 'stars.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEditingAStarImportedModuleRescansThePackageAndItsReaders(): void
    {
        $this->writePackage();
        $pdo = $this->scannedAndStamped();

        $this->write('pkg/star.py', "def helper2():\n    return 1\n");
        $this->scan($pdo);

        assertSame(['app.py', 'cli.py', 'pkg/__init__.py', 'pkg/star.py', 'stars.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A file rescanned on its own, in a request that scanned nothing it
     * imports, still names what it read: a later edit of the module reaches it.
     */
    public function testAFileRescannedAloneStillReachesItsModulesLater(): void
    {
        $this->writePackage();
        $pdo = $this->scannedAndStamped();
        $this->write('cli.py', "from pkg import Engine\n\n\ndef cli():\n    return Engine().run() + 1\n");
        $this->scan($pdo);
        assertSame(['cli.py'], $this->rescannedFiles($pdo));
        $this->stampCacheRows($pdo);

        $this->write('pkg/impl.py', "class Engine:\n    def start(self):\n        return 1\n");
        $this->scan($pdo);

        assertSame(['app.py', 'cli.py', 'pkg/__init__.py', 'pkg/impl.py', 'pkg/sub/user.py', 'stars.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** The importer probed `pkg/extra.py` and the package probed `pkg/later.py`; each was absent. */
    public function testCreatingAModuleAnImportProbedRescansTheImporter(): void
    {
        $this->writePackage();
        $this->write('late.py', "from pkg.extra import Extra\n\n\ndef f():\n    return Extra()\n");
        $pdo = $this->scannedAndStamped();

        $this->write('pkg/extra.py', "class Extra:\n    pass\n");
        $this->scan($pdo);
        assertSame(['late.py', 'pkg/extra.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('pkg/later.py', "class Later:\n    pass\n");
        $this->scan($pdo);
        assertSame(['app.py', 'cli.py', 'pkg/__init__.py', 'pkg/later.py', 'stars.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** `from pkg.sub import user` found the submodule without reading it; deleting it changes the importer. */
    public function testDeletingAModuleRescansItsReaders(): void
    {
        $this->writePackage();
        $this->write('mods.py', "from pkg.sub import user\n\n\ndef m():\n    return user.use()\n");
        $pdo = $this->scannedAndStamped();

        unlink($this->root . '/pkg/sub/user.py');
        $this->scan($pdo);
        assertSame(['mods.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        unlink($this->root . '/pkg/star.py');
        $this->scan($pdo);
        assertSame(['app.py', 'cli.py', 'pkg/__init__.py', 'stars.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testRenamingAModuleRescansTheImportersOfBothNames(): void
    {
        $this->writePackage();
        $this->write('util.py', "def tool():\n    return 3\n");
        $this->write('uses_util.py', "from util import tool\n\n\ndef u():\n    return tool()\n");
        $this->write('uses_tools.py', "from tools import tool\n\n\ndef t():\n    return tool()\n");
        $pdo = $this->scannedAndStamped();

        rename($this->root . '/util.py', $this->root . '/tools.py');
        $this->scan($pdo);

        assertSame(['tools.py', 'uses_tools.py', 'uses_util.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * `lib/` and `src/` are both source roots, searched in that order. A
     * module added to the later root changes nothing; one added at the bare
     * root, searched first, shadows the import, and takes the module id over
     * from both files that had it, which probed the bare root for it too.
     */
    public function testAModuleAddedToASourceRootRescansOnlyTheImportsItShadows(): void
    {
        $this->writeSourceRoots();
        $pdo = $this->scannedAndStamped();

        $this->write('src/shared.py', "def thing():\n    return 2\n");
        $this->scan($pdo);
        assertSame(['src/shared.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('shared.py', "class thing:\n    pass\n");
        $this->scan($pdo);
        assertSame(['lib/shared.py', 'shared.py', 'src/run.py', 'src/shared.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A new top-level directory is a new source root, searched before `lib/`,
     * at a path no import could have probed: the source roots changed, so
     * every Python file is rebuilt.
     */
    public function testANewSourceRootRebuildsEveryPythonFile(): void
    {
        $this->writeSourceRoots();
        $pdo = $this->scannedAndStamped();

        $this->write('aaa/shared.py', "class thing:\n    pass\n");
        $this->scan($pdo);

        assertSame(['aaa/shared.py', 'lib/shared.py', 'other.py', 'src/run.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** `lib/__init__.py` turns the `lib/` source root into a package, renaming every module below it. */
    public function testAddingATopLevelPackageMarkerRebuildsEveryPythonFile(): void
    {
        $this->writeSourceRoots();
        $pdo = $this->scannedAndStamped();

        $this->write('lib/__init__.py', '');
        $this->scan($pdo);

        assertSame(['lib/__init__.py', 'lib/shared.py', 'other.py', 'src/run.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** Nothing imports `core`, and deleting its marker still renames `core.base` to `base`. */
    public function testDeletingATopLevelPackageMarkerRebuildsEveryPythonFile(): void
    {
        $this->writeSourceRoots();
        $this->write('core/__init__.py', '');
        $this->write('core/base.py', "class Base:\n    pass\n");
        $pdo = $this->scannedAndStamped();

        unlink($this->root . '/core/__init__.py');
        $this->scan($pdo);

        assertSame(['core/base.py', 'lib/shared.py', 'other.py', 'src/run.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** A package marker below the top level is an ordinary probe: only what probed it is rebuilt. */
    public function testANestedPackageThatShadowsAModuleRescansTheModuleAndItsImporter(): void
    {
        $this->writeSourceRoots();
        $this->write('pkg2/__init__.py', '');
        $this->write('pkg2/mod.py', "def a():\n    return 1\n");
        $this->write('modmain.py', "from pkg2.mod import a\n\n\ndef x():\n    return a()\n");
        $pdo = $this->scannedAndStamped();

        $this->write('pkg2/mod/__init__.py', "class a:\n    pass\n");
        $this->scan($pdo);

        assertSame(['modmain.py', 'pkg2/mod.py', 'pkg2/mod/__init__.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * `ns/` has no marker, so it is a source root and `import ns.tools` reads
     * `ns/tools.py` under a name its own scan does not give it; what that
     * module re-exports is still followed.
     */
    public function testANamespacePackageModuleReachesItsImporter(): void
    {
        $this->writeSourceRoots();
        $this->write('ns/tools.py', "from .impl2 import tool\n");
        $this->write('ns/impl2.py', "def tool():\n    return 1\n");
        $this->write('nsuse.py', "from ns.tools import tool\n\n\ndef n():\n    return tool\n");
        $pdo = $this->scannedAndStamped();

        $this->write('ns/impl2.py', "class tool:\n    pass\n");
        $this->scan($pdo);

        assertSame(['ns/impl2.py', 'ns/tools.py', 'nsuse.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * Discovery leaves `vendor/` out, so no contribution of its own names
     * what `vendor/lib/__init__.py` re-exports: the importer names it.
     */
    public function testAReexportInAnUndiscoveredPackageReachesItsImporter(): void
    {
        $this->writeSourceRoots();
        $this->write('vendor/lib/__init__.py', "from .core import Thing\n");
        $this->write('vendor/lib/core.py', "class Thing:\n    pass\n");
        $this->write('venduse.py', "from vendor.lib import Thing\n\n\ndef v():\n    return Thing\n");
        $pdo = $this->scannedAndStamped();

        $this->write('vendor/lib/core.py', "def Thing():\n    return 1\n");
        $this->scan($pdo);

        assertSame(['venduse.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** A script resolves a bare import beside itself until a source root holds that module. */
    public function testAModuleShadowingAScriptsSiblingRescansTheScript(): void
    {
        $this->writeSourceRoots();
        $this->write('apps/__init__.py', '');
        $this->write('apps/helper.py', "def h():\n    return 1\n");
        $this->write('apps/run.py', "import helper\n\n\ndef go():\n    return helper.h()\n\n\nif __name__ == \"__main__\":\n    go()\n");
        $pdo = $this->scannedAndStamped();

        $this->write('helper.py', "def h():\n    return 2\n");
        $this->scan($pdo);

        assertSame(['apps/run.py', 'helper.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * `m.py` re-exports `Thing` from `n.py` and declares so much that its own
     * answer outgrows a frame, so it is left out and its row names nothing it
     * read. `app.py` names only `m.py`, relying on that row: a change to `n.py`
     * must still reach it.
     */
    public function testALeftOutModuleIsRebuiltWithItsReadersOnAnyPythonChange(): void
    {
        $functions = '';
        for ($index = 0; $index < 12_000; ++$index) {
            $functions .= sprintf("\n\ndef f%05d():\n    pass\n", $index);
        }
        $this->write('n.py', "class Thing:\n    pass\n");
        $this->write('m.py', "from n import Thing\n" . $functions);
        $this->write('app.py', "from m import Thing\n\n\ndef a():\n    return Thing\n");
        $this->write('other.py', "def unrelated():\n    return 2\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM diagnostics WHERE message LIKE 'Left out of the graph%m.py%'")->fetchColumn());
        $this->stampCacheRows($pdo);

        $this->write('n.py', "def Thing():\n    return 1\n");
        $this->scan($pdo);

        assertSame(['app.py', 'm.py', 'n.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEditingTheRequirementsRebuildsEveryPythonFile(): void
    {
        $this->writeSourceRoots();
        $this->write('api.py', "from fastapi import FastAPI\n\napp = FastAPI()\n\n\n@app.get(\"/x\")\ndef x():\n    return 1\n");
        $this->write('requirements.txt', "requests\n");
        $pdo = $this->scannedAndStamped();

        $this->write('requirements.txt', "requests\nfastapi\n");
        $this->scan($pdo);

        assertSame(['api.py', 'lib/shared.py', 'other.py', 'src/run.py'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEveryContributionIsStoredAsAttributed(): void
    {
        $this->writePackage();
        $pdo = $this->freshTestDatabase();

        $this->scan($pdo);

        assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM contribution_cache WHERE scanner_id = 'knossos.python' AND read_attribution = 0")->fetchColumn());
        foreach (['app.py', 'cli.py'] as $importer) {
            $reads = $this->storedReads($pdo, $importer);
            self::assertContains('pkg/__init__.py', $reads, $importer);
            self::assertNotContains('other.py', $reads, $importer);
        }
        assertSame(['other/__init__.py'], $this->storedReads($pdo, 'other.py'));
    }

    /**
     * A package, its re-exports and their importers.
     *
     * `app.py` is scanned before the package's own file and `pkg/sub/user.py`
     * after it, so one reads the package's declarations computed for an
     * importer and the other those computed from the file's own scan.
     */
    private function writePackage(): void
    {
        $this->write('pkg/__init__.py', "from .impl import Engine\nfrom .star import *\nfrom .later import Later\n");
        $this->write('pkg/impl.py', "class Engine:\n    def run(self):\n        return 1\n");
        $this->write('pkg/star.py', "def helper():\n    return 1\n");
        $this->write('pkg/sub/__init__.py', '');
        $this->write('pkg/sub/user.py', "from ..impl import Engine\n\n\ndef use():\n    return Engine().run()\n");
        $this->write('app.py', "from pkg import Engine\n\n\ndef main():\n    return Engine().run()\n");
        $this->write('cli.py', "from pkg import Engine\n\n\ndef cli():\n    return Engine().run()\n");
        $this->write('stars.py', "from pkg import *\n\n\ndef go():\n    return helper()\n");
        $this->write('other.py', "def unrelated():\n    return 2\n");
    }

    /** Two source roots, `lib/` and `src/`, and a file that imports from the first. */
    private function writeSourceRoots(): void
    {
        $this->write('lib/shared.py', "def thing():\n    return 1\n");
        $this->write('src/run.py', "from shared import thing\n\n\ndef r():\n    return thing()\n");
        $this->write('other.py', "def unrelated():\n    return 2\n");
    }

    private function scannedAndStamped(): PDO
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        return $pdo;
    }

    private function scan(PDO $pdo): ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    private function stampCacheRows(PDO $pdo): void
    {
        $pdo->prepare('UPDATE contribution_cache SET updated_at = ?')->execute([self::UNTOUCHED]);
    }

    /** @return list<string> the Python files whose contribution the last scan rewrote */
    private function rescannedFiles(PDO $pdo): array
    {
        $statement = $pdo->prepare("SELECT file_path FROM contribution_cache WHERE scanner_id = 'knossos.python' AND updated_at <> ? ORDER BY file_path");
        $statement->execute([self::UNTOUCHED]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> the paths one file's stored contribution read */
    private function storedReads(PDO $pdo, string $file): array
    {
        $statement = $pdo->prepare('SELECT read_path FROM contribution_reads WHERE owner_key = ? ORDER BY read_path');
        $statement->execute(['knossos.python:file:' . $file]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertMatchesAFullScan(PDO $incremental): void
    {
        $full = $this->freshTestDatabase();
        $this->scan($full);
        assertSame($this->graphSignature($full), $this->graphSignature($incremental));
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
