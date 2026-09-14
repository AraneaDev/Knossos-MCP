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
 * reports neither a symlink nor a file over the byte cap, and the core ignores
 * a null for a path it did not discover.
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
}
