<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * An edit that changes what a TypeScript file declares for every file, and not
 * only for its importers, reaches files that never read it: a module that
 * starts to augment the global scope or another module, one that becomes a
 * script, one that exports a UMD global, and a global script whose declarations
 * change. After each, the incremental graph equals a full scan of the same
 * bytes, through the real worker.
 */
#[Group('typescript-scanner')]
final class TypescriptGlobalEditTest extends KnossosTestCase
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
     * @param array<string, string> $edits what the edit writes
     */
    #[DataProvider('globalEdits')]
    public function testAnEditThatChangesWhatAFileDeclaresGloballyMatchesAFullScan(array $files, array $edits, string $reader): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["src"]}' . "\n");
        foreach ($files as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $before = $this->facts($pdo, $reader);

        foreach ($edits as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        assertSame('incremental', $incremental->data['mode']);
        // The edit does change the reader's facts, so the case tests something.
        self::assertNotSame($before, $this->facts($full, $reader));
        assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    /** @return iterable<string, array{0: array<string, string>, 1: array<string, string>, 2: string}> */
    public static function globalEdits(): iterable
    {
        $caller = ['src/a.ts' => "export function run(): void { hello(); }\n"];

        yield 'a module starts to declare a global' => [
            $caller + ['src/m.ts' => "export const z = 1;\n"],
            ['src/m.ts' => "export const z = 1;\ndeclare global { function hello(): void }\n"],
            'src/a.ts',
        ];
        yield 'a module becomes a script' => [
            $caller + ['src/m.ts' => "export const z = 1;\n"],
            ['src/m.ts' => "declare function hello(): void;\nconst z = 1;\n"],
            'src/a.ts',
        ];
        yield 'a module starts to augment a package another file imports' => [
            [
                'node_modules/dep/package.json' => '{"name": "dep", "types": "index.d.ts"}',
                'node_modules/dep/index.d.ts' => "export declare const base: number;\n",
                'src/b.ts' => "import { extra } from 'dep';\nexport function run(): void { extra(); }\n",
                'src/m.ts' => "export const z = 1;\n",
            ],
            ['src/m.ts' => "export const z = 1;\ndeclare module 'dep' { export function extra(): void; }\n"],
            'src/b.ts',
        ];
        yield 'a global script changes its declarations' => [
            $caller + [
                'src/g.ts' => "declare function hello(): void;\n",
                'src/d.ts' => "export function later(): void { bye(); }\n",
            ],
            ['src/g.ts' => "declare function hello(): void;\ndeclare function bye(): void;\n"],
            'src/d.ts',
        ];
    }

    /**
     * A type library the config names declares globals every file sees, though
     * only the file that uses one read it: a declaration added there reaches a
     * file that used a name nothing declared.
     */
    public function testAnEditedGlobalTypeLibraryMatchesAFullScan(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true, "types": ["g"]}, "include": ["src"]}' . "\n");
        $this->write('node_modules/@types/g/package.json', '{"name": "@types/g", "types": "index.d.ts"}');
        $this->write('node_modules/@types/g/index.d.ts', "declare function gfun(): void;\n");
        $this->write('src/a.ts', "export function a(): void { gfun(); }\n");
        $this->write('src/d.ts', "export function d(): void { hfun(); }\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $before = $this->facts($pdo, 'src/d.ts');

        $this->write('node_modules/@types/g/index.d.ts', "declare function gfun(): void;\ndeclare function hfun(): void;\n");
        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        assertSame('incremental', $incremental->data['mode']);
        self::assertNotSame($before, $this->facts($full, 'src/d.ts'));
        assertSame($this->graphSignature($full), $this->graphSignature($pdo));
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
