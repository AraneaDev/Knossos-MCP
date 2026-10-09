<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * A scan, through the real TypeScript and Python workers, of a tree with a
 * source directory named `build` below a directory that holds no manifest, and
 * build output below a manifest root.
 *
 * Discovery and the workers have to agree on which paths exist. The workers
 * were sent `build`, `dist`, `site` and `coverage` as names to exclude at any
 * depth, so once discovery kept `src/build/x.ts` the TypeScript worker refused
 * to read it (an unresolved import and an unscannable file) and the Python
 * worker resolved a call into `pkg/build` as external.
 */
#[Group('scan')]
final class AnchoredBuildOutputScanTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-anchored-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testWorkersReadSourceNamedLikeBuildOutputAndSkipTheRealOutput(): void
    {
        $this->writeTree([
            'package.json' => '{"name":"web"}',
            'src/a.ts' => "import { x } from './build/x';\nexport const all = [x];\n",
            'src/build/x.ts' => "export const x = 1;\n",
            'packages/a/package.json' => '{"name":"a"}',
            'packages/a/dist/d.ts' => "export const d = 1;\n",
            'pkg/__init__.py' => '',
            'pkg/build/__init__.py' => '',
            'pkg/build/helper.py' => "def h():\n    return 1\n",
            'pkg/use.py' => "from pkg.build.helper import h\n\n\ndef u():\n    return h()\n",
        ]);
        $pdo = $this->freshTestDatabase();

        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        $files = $pdo->query('SELECT relative_path FROM files ORDER BY relative_path')->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('src/build/x.ts', $files);
        self::assertNotContains('packages/a/dist/d.ts', $files);
        $codes = $pdo->query('SELECT code FROM diagnostics')->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotContains('TS2307', $codes);
        self::assertNotContains('TS_UNSCANNABLE_FILE', $codes);
        self::assertContains('DISCOVERY_BUILD_OUTPUT_SKIPPED', $codes);
        self::assertContains(['imports', 'src/a.ts', 'src/build/x.ts'], $this->edgesBetweenFiles($pdo));
        $calls = $pdo->query(
            "SELECT t.kind FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'calls' AND t.canonical_name = 'pkg.build.helper.h'",
        )->fetchAll(PDO::FETCH_COLUMN);
        assertSame(['function'], $calls);
    }

    /** @return list<array{0: string, 1: string, 2: string}> edge kind, source file and target file */
    private function edgesBetweenFiles(PDO $pdo): array
    {
        $rows = $pdo->query(
            'SELECT e.kind, sf.relative_path AS source, tf.relative_path AS target FROM edges e'
            . ' JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id'
            . ' JOIN files sf ON sf.id = s.file_id JOIN files tf ON tf.id = t.file_id',
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn(array $row): array => [(string) $row['kind'], (string) $row['source'], (string) $row['target']], $rows);
    }

    /** @param array<string, string> $files contents keyed by project-relative path */
    private function writeTree(array $files): void
    {
        foreach ($files as $path => $contents) {
            $absolute = $this->root . '/' . $path;
            if (!is_dir(dirname($absolute))) {
                mkdir(dirname($absolute), 0o777, true);
            }
            file_put_contents($absolute, $contents);
        }
    }
}
