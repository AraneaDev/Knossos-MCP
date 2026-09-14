<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The TypeScript and Python workers report a file they refuse to read as a
 * failed read, because an importer's facts are then computed without it. That
 * must never fail a scan of a layout that simply holds such files: discovery
 * reports neither a symlink nor a file over the byte cap, and the re-read at
 * commit accepts a null for an undiscovered path that is a link or over the
 * cap.
 */
final class RefusedInputScanTest extends KnossosTestCase
{
    #[Group('scan')]
    public function testAStableLayoutWithRefusedImportsScansFresh(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $outside = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o777, true);
        mkdir($root . '/pkg', 0o777, true);
        mkdir($outside, 0o777, true);
        $padding = str_repeat('/', 400) . "\n";
        file_put_contents($root . '/src/app.ts', "import { Big } from './big';\nimport { Linked } from './linked';\nexport class App extends Big {}\n");
        file_put_contents($root . '/src/big.ts', "export class Big {}\n" . '//' . $padding);
        file_put_contents($outside . '/linked.ts', "export class Linked {}\n");
        symlink($outside . '/linked.ts', $root . '/src/linked.ts');
        file_put_contents($root . '/pkg/app.py', "from pkg.big import Big\nfrom pkg.linked import Linked\n\n\nclass App(Big):\n    pass\n");
        file_put_contents($root . '/pkg/big.py', "class Big:\n    pass\n" . '#' . $padding);
        file_put_contents($outside . '/linked.py', "class Linked:\n    pass\n");
        symlink($outside . '/linked.py', $root . '/pkg/linked.py');
        try {
            $pdo = $this->freshTestDatabase();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, maxFileBytes: 300);

            assertSame('full', $result->data['mode']);
            $paths = $pdo->query('SELECT relative_path FROM files ORDER BY relative_path')->fetchAll(\PDO::FETCH_COLUMN);
            assertSame(['pkg/app.py', 'src/app.ts'], $paths);
            assertSame([], $result->data['degraded_languages']);
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($outside);
        }
    }

    /**
     * In-root links of every kind the workers key a read or probe through: a
     * file link, a directory link, a link whose target climbs with `..` after
     * another link (beside the decoy a textual collapse would name), a
     * node_modules workspace link, and imports of modules that do not exist.
     * Every one of those keys names a link, a path through one, or an absent
     * candidate, none of which discovery reports, so scanning the unchanging
     * tree end to end must stay full and fresh, twice.
     */
    #[Group('scan')]
    public function testAStableLayoutWithInRootLinksScansFresh(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        foreach (['src', 'lib', 'deep/dir', 'packages/lib', 'node_modules', 'pkg', 'real', 'pdeep/dir'] as $directory) {
            mkdir($root . '/' . $directory, 0o777, true);
        }
        $files = [
            'tsconfig.json' => '{"compilerOptions": {"strict": true}, "include": ["src", "lib", "deep", "packages"]}',
            'src/app.ts' => implode("\n", [
                "import { Real } from './alias';",
                "import { C } from './linkdir/c';",
                "import { Up } from './up';",
                "import { lib } from 'lib';",
                "import { Missing } from './missing';",
                'export class App extends Real {}',
                'export const used = [C, Up, lib, Missing];',
                '',
            ]),
            'src/real.ts' => "export class Real {}\n",
            'lib/c.ts' => "export const C = 1;\n",
            // src/up.ts -> d/../shared.ts opens deep/shared.ts; collapsing the
            // `..` as text would name src/shared.ts, a different discovered file.
            'deep/shared.ts' => "export const Up = 1;\n",
            'src/shared.ts' => "export const Up = 2;\n",
            'packages/lib/package.json' => '{"name": "lib", "types": "index.ts"}',
            'packages/lib/index.ts' => "export const lib = 1;\n",
            'pkg/__init__.py' => '',
            'pkg/app.py' => "from pkg.alias import Real\nfrom lnk.c import C\nfrom pkg.up import Up\nfrom pkg.missing import Missing\n\n\nclass App(Real):\n    pass\n",
            'pkg/real.py' => "class Real:\n    pass\n",
            'real/c.py' => "class C:\n    pass\n",
            'pdeep/shared.py' => "class Up:\n    pass\n",
            'pkg/shared.py' => "class Up:\n    decoy = True\n",
        ];
        foreach ($files as $relative => $contents) {
            file_put_contents($root . '/' . $relative, $contents);
        }
        symlink('real.ts', $root . '/src/alias.ts');
        symlink('../lib', $root . '/src/linkdir');
        symlink('../deep/dir', $root . '/src/d');
        symlink('d/../shared.ts', $root . '/src/up.ts');
        symlink('../packages/lib', $root . '/node_modules/lib');
        symlink('real.py', $root . '/pkg/alias.py');
        symlink('real', $root . '/lnk');
        symlink('../pdeep/dir', $root . '/pkg/d');
        symlink('d/../shared.py', $root . '/pkg/up.py');
        try {
            $pdo = $this->freshTestDatabase();
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);

            $first = $service->scan($root);
            $second = $service->scan($root, mode: 'full');

            foreach ([$first, $second] as $result) {
                assertSame('full', $result->data['mode']);
                assertSame([], $result->data['degraded_languages']);
            }
            $paths = $pdo->query('SELECT relative_path FROM files ORDER BY relative_path')->fetchAll(\PDO::FETCH_COLUMN);
            assertSame(['deep/shared.ts', 'lib/c.ts', 'packages/lib/index.ts', 'pdeep/shared.py', 'pkg/__init__.py', 'pkg/app.py', 'pkg/real.py', 'pkg/shared.py', 'real/c.py', 'src/app.ts', 'src/real.ts', 'src/shared.ts'], $paths);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
