<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A nested npm package with no tsconfig of its own (a load-test or e2e
 * package beside the app) is its own world: its own TypeScript and its own
 * installed types. Read as if it stood at the project root, its globals were
 * missing and its TypeScript's defaults were the root's.
 */
#[Group('typescript-scanner')]
final class NestedPackageFallbackTest extends KnossosTestCase
{
    public function testANestedPackageIsReadWithItsOwnTypesAndTypeScript(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-nested-package-' . bin2hex(random_bytes(6));
        $files = [
            'package.json' => '{"name":"app","private":true}',
            'tsconfig.json' => '{"compilerOptions":{"strict":true},"include":["src"]}',
            'src/app.ts' => "export const app = 1;\n",
            'tools/package.json' => '{"name":"tools","private":true,"devDependencies":{"typescript":"^5.4.0"}}',
            'tools/node_modules/@types/toolenv/package.json' => '{"name":"@types/toolenv","types":"index.d.ts"}',
            'tools/node_modules/@types/toolenv/index.d.ts' => "declare const TOOL_GLOBAL: string;\n",
            // Its TypeScript (5.x) does not check side-effect imports; 6.x does.
            'tools/build.ts' => "import \"./style.css\";\n\nexport const value: string = TOOL_GLOBAL;\n",
        ];
        foreach ($files as $relative => $contents) {
            if (!is_dir(dirname($root . '/' . $relative))) {
                mkdir(dirname($root . '/' . $relative), 0o777, true);
            }
            file_put_contents($root . '/' . $relative, $contents);
        }

        try {
            $pdo = $this->freshTestDatabase();
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'full')->projectId;
            $statement = $pdo->prepare('SELECT d.code FROM diagnostics d JOIN files f ON f.id = d.file_id WHERE d.project_id = ? AND f.relative_path = ?');
            $statement->execute([$projectId, 'tools/build.ts']);
            $codes = $statement->fetchAll(\PDO::FETCH_COLUMN);
        } finally {
            $this->removeTempTree($root);
        }

        self::assertSame([], $codes);
    }
}
