<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\RequestReads;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What the real Python worker reports a request read, file by file, checked
 * with the core's own rules: every read is confirmed by `input_hashes`, and
 * every entry of `input_hashes` is named by some read.
 */
#[Group('python-scanner')]
final class PythonWorkerReadsTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-python-worker-reads-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        $this->root = (string) realpath($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * `app.py` computes the package's declarations and `cli.py` takes them
     * from the memo; `zz.py` takes them after the package's own scan filled
     * the memo. Each names the package, and the package's own contribution
     * names what it re-exports, which the request reports as unattributed.
     */
    public function testEachFileNamesWhatItReadAndTheRequestNamesTheRest(): void
    {
        $this->writeTree([
            'pkg/__init__.py' => "from .impl import Engine\nfrom .star import *\nfrom .later import Later\n",
            'pkg/impl.py' => "class Engine:\n    def run(self):\n        return 1\n",
            'pkg/star.py' => "def helper():\n    return 1\n",
            'pkg/sub/__init__.py' => '',
            'pkg/sub/user.py' => "def use():\n    return 1\n",
            'app.py' => "from pkg import Engine\n",
            'cli.py' => "from pkg import Engine\n",
            'zz.py' => "from pkg import Engine\nfrom pkg.sub import user\nfrom vendor.lib import Thing\nfrom src.tools import tool\nfrom config import x\n",
            'vendor/lib/__init__.py' => "from .core import Thing\n",
            'vendor/lib/core.py' => "class Thing:\n    pass\n",
            'src/tools.py' => "from .impl2 import tool\n",
            'src/impl2.py' => "def tool():\n    return 1\n",
            'config' => "#!/bin/sh\necho hi\n",
            'broken.py' => "def (\n",
            'notes.txt' => "x\n",
        ]);
        $sources = ['app.py', 'broken.py', 'cli.py', 'pkg/__init__.py', 'pkg/impl.py', 'pkg/star.py', 'pkg/sub/__init__.py', 'pkg/sub/user.py', 'src/impl2.py', 'src/tools.py', 'zz.py'];
        $requested = ['app.py', 'broken.py', 'cli.py', 'notes.txt', 'pkg/__init__.py', 'zz.py'];

        $client = $this->pythonWorkerClient();
        try {
            $manifest = $client->initialize();
            $contributions = iterator_to_array($client->scan(['root' => $this->root, 'files' => $requested, 'source_files' => $sources]), false);
            $result = $client->lastScanResult();
        } finally {
            $client->shutdown();
        }
        $byPath = [];
        foreach ($contributions as $contribution) {
            self::assertInstanceOf(ScanContribution::class, $contribution);
            $byPath[substr($contribution->ownerKey, strlen('knossos.python:file:'))] = $contribution;
        }

        // The core's own checks: nothing read goes unconfirmed or unnamed.
        $reads = RequestReads::forRequest($result, $manifest, $result['input_hashes'], $contributions, $requested);
        self::assertCount(6, $reads['owners']);

        foreach (['app.py', 'cli.py', 'zz.py'] as $importer) {
            self::assertArrayHasKey('pkg/__init__.py', $byPath[$importer]->reads ?? [], $importer);
            self::assertArrayNotHasKey('pkg/star.py', $byPath[$importer]->reads ?? [], $importer);
        }
        self::assertArrayHasKey('pkg/star.py', $byPath['pkg/__init__.py']->reads ?? []);
        $package = $byPath['pkg/__init__.py']->reads ?? [];
        self::assertArrayHasKey('pkg/later.py', $package);
        self::assertNull($package['pkg/later.py']);
        self::assertArrayHasKey('pkg/star.py', $result['unattributed_reads']);
        $zz = $byPath['zz.py']->reads ?? [];
        // Found without being read, by a module discovery left out, under
        // another spelling of its own id, and ruled out by its shebang.
        foreach (['pkg/sub/user.py', 'vendor/lib/core.py', 'src/tools.py', 'config'] as $path) {
            self::assertIsString($zz[$path] ?? null, $path);
        }
        // `src/tools.py` read as `src.tools` is its own module `tools`: its own
        // contribution names what it re-exports.
        self::assertArrayNotHasKey('src/impl2.py', $zz);
        self::assertArrayHasKey('src/impl2.py', $result['unattributed_reads']);
        // `src/` is the only top-level directory that is a source root.
        assertSame(['src/__init__.py' => null], $result['reads']);

        // Facts are labelled with the source roots; a file without facts is not.
        $environment = $result['environments']['python'];
        assertSame(['python', $environment], [$byPath['app.py']->program, $byPath['app.py']->environment]);
        assertSame([null, []], [$byPath['broken.py']->program, $byPath['broken.py']->reads]);
        assertSame([null, []], [$byPath['notes.txt']->program, $byPath['notes.txt']->reads]);
    }

    /**
     * A package that star imports a thousand modules with long names makes
     * the unattributed reads outgrow a part, so they travel in
     * `scan/input_hashes` notifications beside an empty `input_hashes`.
     */
    public function testUnattributedReadsLargerThanAPartTravelInParts(): void
    {
        $name = str_repeat('long_module_name_', 10);
        $tree = ['app.py' => "from pkg import Value0\n"];
        $exports = '';
        for ($module = 0; $module < 1_000; ++$module) {
            $tree[sprintf('pkg/%s_%04d.py', $name, $module)] = sprintf("class Value%d:\n    pass\n", $module);
            $exports .= sprintf("from .%s_%04d import *\n", $name, $module);
        }
        $tree['pkg/__init__.py'] = $exports;
        $this->writeTree($tree);
        $params = ['root' => $this->root, 'files' => ['app.py'], 'source_files' => ['app.py', 'pkg/__init__.py']];

        $frames = $this->runPythonWorkerProtocol([
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'scan', 'params' => $params], JSON_THROW_ON_ERROR),
            json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'shutdown', 'params' => []], JSON_THROW_ON_ERROR),
        ]);

        $unattributed = [];
        $parts = 0;
        foreach ($frames as $frame) {
            if (($frame['method'] ?? null) === 'scan/input_hashes' && isset($frame['params']['unattributed_reads'])) {
                assertSame([], $frame['params']['input_hashes']);
                $unattributed += $frame['params']['unattributed_reads'];
                ++$parts;
            } elseif (($frame['id'] ?? null) === 1) {
                $unattributed += $frame['result']['unattributed_reads'];
            }
        }
        self::assertGreaterThanOrEqual(1, $parts);
        // Each module's file and the package marker its name was probed for first.
        self::assertCount(2_000, $unattributed);
    }

    /** @param array<string, string> $tree */
    private function writeTree(array $tree): void
    {
        foreach ($tree as $relative => $contents) {
            $path = $this->root . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }
    }
}
