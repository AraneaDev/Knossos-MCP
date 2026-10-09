<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * An import nothing resolves names a package only when its specifier can be
 * an npm package. A bundler alias the compiler was not told about
 * (`@/components`, `~/stores`) is the project's own code, not a dependency,
 * and a Node built-in is one package with or without its `node:` prefix.
 */
#[Group('typescript-scanner')]
final class TypescriptPackageNameTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-package-name-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o777, true);
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testOnlyANameNpmCanPublishBecomesAPackage(): void
    {
        file_put_contents($this->root . '/tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src"]}');
        file_put_contents($this->root . '/src/a.ts', implode("\n", [
            "import Card from '@/components/Card';",
            "import user from '~/stores/user';",
            "import { readFileSync } from 'node:fs';",
            "import { statSync } from 'fs';",
            "import { readFile } from 'node:fs/promises';",
            "import { map } from 'lodash/fp';",
            "import { thing } from '@scope/pkg/sub';",
            'export const all = [Card, user, readFileSync, statSync, readFile, map, thing];',
            '',
        ]));
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        $imports = $pdo->query(
            "SELECT t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'imports' AND s.canonical_name = 'src/a.ts' ORDER BY 1",
        )->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['@scope/pkg', 'fs', 'lodash'], $imports);
        $packages = $pdo->query("SELECT canonical_name FROM nodes WHERE language = 'ts' AND kind = 'package' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['@scope/pkg', 'fs', 'lodash'], $packages);
    }
}
