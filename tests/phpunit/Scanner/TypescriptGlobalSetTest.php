<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * An edit can change which global declaration files a program holds without
 * any of those files changing: an import or a triple-slash reference brings a
 * global library in or takes it out, and a package.json changes whether a file
 * is a script. Files that read none of the edited ones still see the change.
 * After each, the incremental graph equals a full scan of the same bytes,
 * through the real worker.
 */
#[Group('typescript-scanner')]
final class TypescriptGlobalSetTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-global-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $files the tree before the edit
     * @param array<string, ?string> $edits what the edit writes, or null to delete
     */
    #[DataProvider('cases')]
    public function testAnEditThatChangesTheProgramsGlobalsMatchesAFullScan(string $tsconfig, array $files, array $edits, string $reader): void
    {
        $this->write('tsconfig.json', $tsconfig);
        foreach ($files as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $before = $this->facts($pdo, $reader);
        foreach ($edits as $relative => $contents) {
            if ($contents === null) {
                unlink($this->root . '/' . $relative);
            } else {
                $this->write($relative, $contents);
            }
        }
        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);
        assertSame('incremental', $incremental->data['mode']);
        // The edit does change the reader's facts, so the case tests something.
        self::assertNotSame($before, $this->facts($full, $reader));
        assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    /** @return iterable<string, array{0: string, 1: array<string, string>, 2: array<string, ?string>, 3: string}> */
    public static function cases(): iterable
    {
        $bundler = '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["src"]}';
        $zone = [
            'node_modules/zone/package.json' => '{"name": "zone", "types": "index.d.ts"}',
            'node_modules/zone/index.d.ts' => "declare function zfun(): void;\n",
            'src/d.ts' => "export function d(): void { zfun(); }\n",
        ];
        yield 'import brings a global library into the program' => [
            $bundler,
            $zone + ['src/a.ts' => "export const a = 1;\n"],
            ['src/a.ts' => "import 'zone';\nexport const a = 1;\n"],
            'src/d.ts',
        ];
        yield 'last import of a global library removed' => [
            $bundler,
            $zone + ['src/a.ts' => "import 'zone';\nexport const a = 1;\n"],
            ['src/a.ts' => "export const a = 1;\n"],
            'src/d.ts',
        ];
        yield 'reference types brings a global library' => [
            $bundler,
            [
                'node_modules/@types/zz/package.json' => '{"name": "@types/zz", "types": "index.d.ts"}',
                'node_modules/@types/zz/index.d.ts' => "declare function zfun(): void;\n",
                'src/d.ts' => "export function d(): void { zfun(); }\n",
                'src/a.ts' => "export const a = 1;\n",
            ],
            ['src/a.ts' => "/// <reference types=\"zz\" />\nexport const a = 1;\n"],
            'src/d.ts',
        ];
        yield 'import-brought global library edited' => [
            $bundler,
            $zone + ['src/a.ts' => "import 'zone';\nexport const a = 1;\n", 'src/e.ts' => "export function e(): void { yfun(); }\n"],
            ['node_modules/zone/index.d.ts' => "declare function zfun(): void;\ndeclare function yfun(): void;\n"],
            'src/e.ts',
        ];
        yield 'import-brought UMD global edited' => [
            $bundler,
            ['node_modules/u/package.json' => '{"name": "u", "types": "index.d.ts"}', 'node_modules/u/index.d.ts' => "export declare function one(): void;\nexport as namespace U;\n", 'src/a.ts' => "import 'u';\nexport const a = 1;\n", 'src/e.ts' => "export function e(): void { U.two(); }\n"],
            ['node_modules/u/index.d.ts' => "export declare function one(): void;\nexport declare function two(): void;\nexport as namespace U;\n"],
            'src/e.ts',
        ];
        yield 'setup import brings a matcher augmentation' => [
            $bundler,
            [
                'node_modules/dep/package.json' => '{"name": "dep", "types": "index.d.ts"}',
                'node_modules/dep/index.d.ts' => "export declare const base: number;\n",
                'node_modules/aug/package.json' => '{"name": "aug", "types": "index.d.ts"}',
                'node_modules/aug/index.d.ts' => "export {};\ndeclare module 'dep' { export function extra(): void; }\n",
                'src/b.ts' => "import { extra } from 'dep';\nexport function run(): void { extra(); }\n",
                'src/setup.ts' => "export const s = 1;\n",
            ],
            ['src/setup.ts' => "import 'aug';\nexport const s = 1;\n"],
            'src/b.ts',
        ];
        $node16 = '{"compilerOptions": {"strict": true, "module": "node16", "moduleResolution": "node16", "moduleDetection": "auto"}, "include": ["src"]}';
        yield 'package.json type change makes an unedited file a script' => [
            $node16,
            [
                'package.json' => '{"name": "root"}',
                'src/lib/package.json' => '{"type": "module"}',
                'src/lib/g.ts' => "declare function hello(): void;\nconst z = 1;\n",
                'src/a.ts' => "export function run(): void { hello(); }\n",
            ],
            ['src/lib/package.json' => '{}'],
            'src/a.ts',
        ];
        yield 'package.json type change makes an unedited script a module' => [
            $node16,
            [
                'package.json' => '{"name": "root"}',
                'src/lib/package.json' => '{}',
                'src/lib/g.ts' => "declare function hello(): void;\nconst z = 1;\n",
                'src/a.ts' => "export function run(): void { hello(); }\n",
            ],
            ['src/lib/package.json' => '{"type": "module"}'],
            'src/a.ts',
        ];
        yield 'global script deleted' => [
            $bundler,
            ['src/g.ts' => "declare function hello(): void;\n", 'src/a.ts' => "export function run(): void { hello(); }\n"],
            ['src/g.ts' => null],
            'src/a.ts',
        ];
        yield 'declare global removed' => [
            $bundler,
            ['src/m.ts' => "export const z = 1;\ndeclare global { function hello(): void }\n", 'src/a.ts' => "export function run(): void { hello(); }\n"],
            ['src/m.ts' => "export const z = 1;\n"],
            'src/a.ts',
        ];
        yield 'becomes global while reader is edited too' => [
            $bundler,
            ['src/m.ts' => "export const z = 1;\n", 'src/a.ts' => "export function run(): void { hello(); }\n", 'src/e.ts' => "export function e(): void { hello(); }\n"],
            ['src/m.ts' => "export const z = 1;\ndeclare global { function hello(): void }\n", 'src/a.ts' => "export function run(): void { hello(); hello(); }\n"],
            'src/e.ts',
        ];
    }

    private function scan(PDO $pdo): \Knossos\Query\ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    /** The edges and diagnostics one file owns, to show an edit changed them. */
    private function facts(PDO $pdo, string $relative): string
    {
        $owner = 'knossos.typescript:file:' . $relative;
        $edges = $pdo->prepare('SELECT e.kind, t.canonical_name FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.owner_key = ? ORDER BY 1, 2');
        $edges->execute([$owner]);
        $diagnostics = $pdo->prepare('SELECT d.code, d.message FROM diagnostics d JOIN projects p ON p.active_scan_id = d.scan_id WHERE d.owner_key = ? ORDER BY 1, 2');
        $diagnostics->execute([$owner]);

        return json_encode([$edges->fetchAll(PDO::FETCH_NUM), $diagnostics->fetchAll(PDO::FETCH_NUM)], JSON_THROW_ON_ERROR);
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
