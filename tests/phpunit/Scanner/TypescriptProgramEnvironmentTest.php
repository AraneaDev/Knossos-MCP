<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * An edit that leaves every program's global declarations as they were
 * rescans only what read the edited file, in a project of one program and in
 * one of several, and an unchanged tree rescans nothing.
 */
#[Group('typescript-scanner')]
final class TypescriptProgramEnvironmentTest extends KnossosTestCase
{
    /** A cache row an incremental scan reused keeps this stamp; a rescanned one is rewritten. */
    private const UNTOUCHED = 'untouched';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-env-' . bin2hex(random_bytes(6));
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

    public function testAnOrdinaryEditInOneOfTwoProgramsRescansOnlyThatFile(): void
    {
        $this->write('tsconfig.json', '{"files": []}');
        $config = '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["."]}';
        foreach (['p1', 'p2'] as $package) {
            $this->write("packages/$package/tsconfig.json", $config);
            $this->write("packages/$package/leaf.ts", "export class Leaf {}\n");
            for ($i = 0; $i < 8; ++$i) {
                $this->write("packages/$package/f$i.ts", "export const f$i = 1;\n");
            }
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        foreach ([1 => 'p1', 2 => 'p2', 3 => 'p1'] as $n => $package) {
            $this->stamp($pdo);
            $this->write("packages/$package/leaf.ts", "export class Leaf {}\nexport const v$n = $n;\n");
            $result = $this->scan($pdo);

            assertSame(1, $result->data['parsed_files'], "edit $n");
            assertSame(["packages/$package/leaf.ts"], $this->rescanned($pdo), "edit $n");
        }
        $this->assertMatchesAFullScan($pdo);
    }

    public function testAnOrdinaryEditInOneProgramRescansOnlyItsReaders(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["src"]}');
        $this->write('node_modules/zone/package.json', '{"name": "zone", "types": "index.d.ts"}');
        $this->write('node_modules/zone/index.d.ts', "declare function zfun(): void;\n");
        $this->write('src/globals.d.ts', "declare function gfun(): void;\n");
        $this->write('src/z.ts', "import 'zone';\nexport const z = 1;\n");
        $this->write('src/leaf.ts', "export class Leaf {}\n");
        $this->write('src/mid.ts', "import { Leaf } from './leaf';\nexport class Mid extends Leaf {}\n");
        for ($i = 0; $i < 6; ++$i) {
            $this->write("src/f$i.ts", "export function f$i(): void { gfun(); zfun(); }\n");
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        foreach ([1, 2] as $n) {
            $this->stamp($pdo);
            $this->write('src/leaf.ts', "export class Leaf {}\nexport const v$n = $n;\n");
            $this->scan($pdo);

            assertSame(['src/leaf.ts', 'src/mid.ts'], $this->rescanned($pdo), "edit $n");
        }
        $this->stamp($pdo);
        $this->write('src/leaf.ts', "import { f1 } from './f1';\nexport class Leaf {}\nexport const g = f1;\n");
        $this->scan($pdo);
        assertSame(['src/leaf.ts', 'src/mid.ts'], $this->rescanned($pdo), 'a local import added');
        $this->assertMatchesAFullScan($pdo);
    }

    #[DataProvider('globalImportEdits')]
    public function testAGlobalImportEditedInAFileTwoConfigsIncludeReachesBothPrograms(string $before, string $after): void
    {
        $config = '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": [".", "../shared"]}';
        $this->write('tsconfig.json', '{"files": []}');
        $this->write('packages/p1/tsconfig.json', $config);
        $this->write('packages/p2/tsconfig.json', $config);
        $this->write('node_modules/zone/package.json', '{"name": "zone", "types": "index.d.ts"}');
        $this->write('node_modules/zone/index.d.ts', "declare function zfun(): void;\n");
        $this->write('packages/shared/x.ts', $before);
        $this->write('packages/p1/a.ts', "export const a = 1;\n");
        $this->write('packages/p2/d.ts', "export function d(): void { zfun(); }\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $facts = $this->facts($pdo, 'packages/p2/d.ts');

        $this->stamp($pdo);
        $this->write('packages/shared/x.ts', $after);
        $this->scan($pdo);

        self::assertContains('packages/p2/d.ts', $this->rescanned($pdo));
        assertNotSame($facts, $this->facts($pdo, 'packages/p2/d.ts'));
        $this->assertMatchesAFullScan($pdo);
    }

    /** @return iterable<string, array{string, string}> */
    public static function globalImportEdits(): iterable
    {
        yield 'the import is added' => ["export const x = 1;\n", "import 'zone';\nexport const x = 1;\n"];
        yield 'the import is removed' => ["import 'zone';\nexport const x = 1;\n", "export const x = 1;\n"];
    }

    /**
     * @param array<string, string> $files
     */
    #[DataProvider('filesNoConfigIncludes')]
    public function testAnOrdinaryEditOfAFileNoConfigIncludesRescansOnlyThatFile(array $files): void
    {
        foreach ($files as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        foreach ([2, 3] as $n) {
            $this->stamp($pdo);
            $this->write('test/b.test.ts', "export const b = $n;\n");
            $result = $this->scan($pdo);

            assertSame(1, $result->data['parsed_files'], "edit $n");
            assertSame(['test/b.test.ts'], $this->rescanned($pdo), "edit $n");
        }
        $this->assertMatchesAFullScan($pdo);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function filesNoConfigIncludes(): iterable
    {
        $config = '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["src"]}';
        yield 'a neighbour declares a global' => [[
            'tsconfig.json' => $config,
            'src/m.ts' => "export const m = 1;\n",
            'test/setup.ts' => "export {};\ndeclare global { function hello(): void }\n",
            'test/a.test.ts' => "hello();\nexport {};\n",
            'test/b.test.ts' => "export const b = 1;\n",
        ]];
        yield 'a neighbour imports a package that augments another' => [[
            'tsconfig.json' => $config,
            'node_modules/dep/package.json' => '{"name": "dep", "types": "index.d.ts"}',
            'node_modules/dep/index.d.ts' => "export declare const base: number;\n",
            'node_modules/aug/package.json' => '{"name": "aug", "types": "index.d.ts"}',
            'node_modules/aug/index.d.ts' => "export {};\ndeclare module 'dep' { export function extra(): void; }\n",
            'src/m.ts' => "export const m = 1;\n",
            'test/setup.ts' => "import 'aug';\n",
            'test/a.test.ts' => "import { extra } from 'dep';\nextra();\n",
            'test/b.test.ts' => "export const b = 1;\n",
            'test/c.test.ts' => "export const c = 1;\n",
        ]];
        yield 'no config at all, a neighbour declares a global' => [[
            'package.json' => '{"name": "root"}',
            'src/types.ts' => "export {};\ndeclare global { function hello(): void }\n",
            'src/a.ts' => "export function run(): void { hello(); }\n",
            'test/b.test.ts' => "export const b = 1;\n",
            'src/c.ts' => "export const c = 1;\n",
        ]];
    }

    public function testAnEditedFileNoConfigIncludesStillSeesTheGlobalsItsNeighboursDeclare(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src"]}');
        $this->write('src/m.ts', "export const m = 1;\n");
        $this->write('test/setup.ts', "export {};\ndeclare global { function hello(): void }\n");
        $this->write('test/a.test.ts', "hello();\nexport {};\n");
        $this->write('test/b.test.ts', "export const b = 1;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $this->stamp($pdo);
        $this->write('test/a.test.ts', "hello();\nhello();\nexport {};\n");
        $result = $this->scan($pdo);

        assertSame(1, $result->data['parsed_files']);
        assertSame(['test/a.test.ts'], $this->rescanned($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testASequenceOfLeafEditsRescansOnlyEachEditedFileAndItsReaders(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["src"]}');
        $this->write('src/leaf.ts', "export class Leaf {}\n");
        $this->write('src/mid.ts', "import { Leaf } from './leaf';\nexport class Mid extends Leaf {}\n");
        for ($i = 0; $i < 5; ++$i) {
            $this->write("src/f$i.ts", "export function f$i(): void {}\n");
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $steps = [
            ['src/leaf.ts', "import { f1 } from './f1';\nexport class Leaf {}\nexport const g = f1;\n", ['src/leaf.ts', 'src/mid.ts']],
            ['src/leaf.ts', "export class Leaf {}\n", ['src/leaf.ts', 'src/mid.ts']],
            ['src/f3.ts', "export function f3(): void {}\nexport const q = 1;\n", ['src/f3.ts']],
            ['src/f2.ts', "export function f2(): void {}\nexport const q = 1;\n", ['src/f2.ts']],
            ['src/f1.ts', "export function f1(): void {}\nexport const q = 1;\n", ['src/f1.ts']],
            ['src/mid.ts', "import { Leaf } from './leaf';\nexport class Mid extends Leaf { m = 1; }\n", ['src/mid.ts']],
        ];
        foreach ($steps as $n => [$relative, $contents, $expected]) {
            $this->stamp($pdo);
            $this->write($relative, $contents);
            $result = $this->scan($pdo);

            assertSame(count($expected), $result->data['parsed_files'], "step $n");
            assertSame($expected, $this->rescanned($pdo), "step $n");
        }
        $this->assertMatchesAFullScan($pdo);
    }

    public function testAnUnchangedTreeRescansNothingAfterAnEdit(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src"]}');
        $this->write('src/globals.d.ts', "declare function gfun(): void;\n");
        $this->write('src/a.ts', "export function a(): void { gfun(); }\n");
        $this->write('src/b.ts', "export const b = 1;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->write('src/b.ts', "export const b = 2;\n");
        $this->scan($pdo);

        $this->stamp($pdo);
        $again = $this->scan($pdo);

        assertSame(0, $again->data['parsed_files']);
        assertSame([], $this->rescanned($pdo));
    }

    private function scan(PDO $pdo): ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    private function stamp(PDO $pdo): void
    {
        $pdo->prepare('UPDATE contribution_cache SET updated_at = ?')->execute([self::UNTOUCHED]);
    }

    /** @return list<string> the files whose contribution the last scan rewrote */
    private function rescanned(PDO $pdo): array
    {
        $statement = $pdo->prepare("SELECT file_path FROM contribution_cache WHERE scanner_id = 'knossos.typescript' AND updated_at <> ? ORDER BY file_path");
        $statement->execute([self::UNTOUCHED]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** The edges and diagnostics one file's contribution owns, as one comparable string. */
    private function facts(PDO $pdo, string $relative): string
    {
        $owner = 'knossos.typescript:file:' . $relative;
        $edges = $pdo->prepare('SELECT e.kind, t.canonical_name FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.owner_key = ? ORDER BY 1, 2');
        $edges->execute([$owner]);
        $diagnostics = $pdo->prepare('SELECT d.code, d.message FROM diagnostics d JOIN projects p ON p.active_scan_id = d.scan_id WHERE d.owner_key = ? ORDER BY 1, 2');
        $diagnostics->execute([$owner]);

        return json_encode([$edges->fetchAll(PDO::FETCH_NUM), $diagnostics->fetchAll(PDO::FETCH_NUM)], JSON_THROW_ON_ERROR);
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
