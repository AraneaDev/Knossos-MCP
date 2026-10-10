<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Result\ResultEnvelope;
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

    /**
     * @param list<array<string, string>> $earlier edits made before the one under test, each followed by a scan
     */
    #[DataProvider('editsBeforeThePackageSwitch')]
    public function testAReadMadeForAnotherProgramsFileUnderThisProgramsResolutionStaysWithTheRequest(array $earlier): void
    {
        // shared/b.ts belongs to p1, where `~/c` is p1/lib/c.ts. In p2's
        // program it resolves `~/c` through p2/vendor/c/package.json, a read
        // b.ts's own contribution never names, so the request must keep it.
        $config = static fn(string $paths, string $include): string => '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler", "baseUrl": ".", "paths": {"~/*": ' . $paths . '}}, "include": ' . $include . '}';
        $this->write('tsconfig.json', '{"files": []}');
        $this->write('packages/p1/tsconfig.json', $config('["lib/*"]', '[".", "../shared"]'));
        $this->write('packages/p2/tsconfig.json', $config('["vendor/*"]', '["."]'));
        $this->write('packages/p1/lib/c.ts', "export class C { p1(): void {} }\n");
        $this->write('packages/p2/vendor/c/package.json', '{"name": "c", "types": "one.ts"}');
        $this->write('packages/p2/vendor/c/one.ts', "export class C { one(): void {} }\n");
        $this->write('packages/p2/vendor/c/two.ts', "export class C { two(): void {} }\n");
        $this->write('packages/shared/b.ts', "export { C } from '~/c';\n");
        $this->write('packages/p2/a.ts', "import { C } from '../shared/b';\nexport class A extends C {}\n");
        $this->write('packages/p2/o.ts', "export const o = 1;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        foreach ($earlier as $edit) {
            foreach ($edit as $relative => $contents) {
                $this->write($relative, $contents);
            }
            $this->scan($pdo);
        }
        $facts = $this->facts($pdo, 'packages/p2/a.ts');

        $this->stamp($pdo);
        $this->write('packages/p2/vendor/c/package.json', '{"name": "c", "types": "two.ts"}');
        $this->scan($pdo);

        self::assertContains('packages/p2/a.ts', $this->rescanned($pdo));
        assertNotSame($facts, $this->facts($pdo, 'packages/p2/a.ts'));
        $this->assertMatchesAFullScan($pdo);
    }

    /** @return iterable<string, array{list<array<string, string>>}> */
    public static function editsBeforeThePackageSwitch(): iterable
    {
        yield 'right after the full scan' => [[]];
        yield 'after the reader and a neighbour were rescanned on their own' => [[
            ['packages/p2/a.ts' => "import { C } from '../shared/b';\nexport class A extends C {}\nexport const k = 1;\n"],
            ['packages/p2/o.ts' => "export const o = 2;\n"],
        ]];
    }

    /**
     * @param list<array<string, string>> $earlier edits made before the one under test, each followed by a scan
     */
    #[DataProvider('editsBeforeTheManifestSwitch')]
    public function testAReadMadeInTheFallbackProgramForAFileAConfigsProgramEmitsStaysWithTheRequest(array $earlier): void
    {
        // No config lists shared/x.ts, so p1's program emits it through
        // main.ts's import, where `c` is p1/lib/c.ts. The fallback program
        // of shared/ resolves `c` through vendor/c/package.json instead, a
        // read x.ts's own contribution never names, so the request keeps it.
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true, "baseUrl": ".", "paths": {"c": ["vendor/c"]}}, "files": []}');
        $this->write('packages/p1/tsconfig.json', '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler", "baseUrl": ".", "paths": {"c": ["lib/c.ts"]}}, "include": ["."]}');
        $this->write('packages/p1/lib/c.ts', "export class C { p1(): void {} }\n");
        $this->write('packages/p1/main.ts', "import { C } from '../shared/x';\nexport class M extends C {}\n");
        $this->write('vendor/c/package.json', '{"name": "c", "types": "one.ts"}');
        $this->write('vendor/c/one.ts', "export class C { one(): void {} }\n");
        $this->write('vendor/c/two.ts', "export const C = 1;\n");
        $this->write('packages/shared/x.ts', "export { C } from 'c';\n");
        $this->write('packages/shared/y.ts', "import { C } from './x';\nexport class Y extends C {}\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        foreach ($earlier as $edit) {
            foreach ($edit as $relative => $contents) {
                $this->write($relative, $contents);
            }
            $this->scan($pdo);
        }
        $facts = $this->facts($pdo, 'packages/shared/y.ts');

        $this->stamp($pdo);
        $this->write('vendor/c/package.json', '{"name": "c", "types": "two.ts"}');
        $this->scan($pdo);

        self::assertContains('packages/shared/y.ts', $this->rescanned($pdo));
        assertNotSame($facts, $this->facts($pdo, 'packages/shared/y.ts'));
        $this->assertMatchesAFullScan($pdo);
    }

    /** @return iterable<string, array{list<array<string, string>>}> */
    public static function editsBeforeTheManifestSwitch(): iterable
    {
        yield 'right after the full scan' => [[]];
        yield 'after the reader was rescanned on its own' => [[
            ['packages/shared/y.ts' => "import { C } from './x';\nexport class Y extends C {}\nexport const k = 1;\n"],
        ]];
    }

    /**
     * @param array<string, string> $files
     * @param list<array<string, string>> $steps edits made in turn, each followed by a scan
     */
    #[DataProvider('importerEditsThatMoveAFileNoConfigLists')]
    public function testAnImportersEditThatMovesAFileNoConfigListsBetweenProgramsRescansIt(array $files, array $steps): void
    {
        // No config lists shared/x.ts. With main.ts importing it, p1's program
        // emits it and resolves `c` to p1/lib/c.ts; without the import the
        // fallback program of shared/ emits it under the root config, whose
        // paths send `c` to vendor/c. No read of x.ts records main.ts's
        // import, so only rebuilding every unlisted file follows the move.
        foreach ($files as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        foreach ($steps as $n => $edits) {
            $facts = $this->facts($pdo, 'packages/shared/x.ts');
            $this->stamp($pdo);
            foreach ($edits as $relative => $contents) {
                $this->write($relative, $contents);
            }
            $result = $this->scan($pdo);

            assertSame(['packages/p1/main.ts', 'packages/shared/x.ts', 'packages/shared/y.ts'], $this->rescanned($pdo), "step $n");
            assertSame(3, $result->data['parsed_files'], "step $n");
            assertNotSame($facts, $this->facts($pdo, 'packages/shared/x.ts'), "step $n");
            $this->assertMatchesAFullScan($pdo);
        }
    }

    /** @return iterable<string, array{array<string, string>, list<array<string, string>>}> */
    public static function importerEditsThatMoveAFileNoConfigLists(): iterable
    {
        $withImport = [
            'tsconfig.json' => '{"compilerOptions": {"strict": true, "baseUrl": ".", "paths": {"c": ["vendor/c"]}}, "files": []}',
            'packages/p1/tsconfig.json' => '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler", "baseUrl": ".", "paths": {"c": ["lib/c.ts"]}}, "include": ["."]}',
            'packages/p1/lib/c.ts' => "export class C { p1(): void {} }\n",
            'packages/p1/main.ts' => "import { C } from '../shared/x';\nexport class M extends C {}\n",
            'vendor/c/package.json' => '{"name": "c", "types": "one.ts"}',
            'vendor/c/one.ts' => "export class C { one(): void {} }\n",
            'packages/shared/x.ts' => "export { C } from 'c';\n",
            'packages/shared/y.ts' => "import { C } from './x';\nexport class Y extends C {}\n",
        ];
        $withoutImport = ['packages/p1/main.ts' => "export class M {}\n"] + $withImport;
        yield 'the importer drops the import: the file moves to the fallback program' => [
            $withImport,
            [['packages/p1/main.ts' => "export class M {}\n"]],
        ];
        yield 'the importer adds the import: the file moves to the config program' => [
            $withoutImport,
            [['packages/p1/main.ts' => "import { C } from '../shared/x';\nexport class M extends C {}\n"]],
        ];
        yield 'dropped, then added again' => [
            $withImport,
            [
                ['packages/p1/main.ts' => "export class M {}\n"],
                ['packages/p1/main.ts' => "import { C } from '../shared/x';\nexport class M extends C {}\n"],
            ],
        ];
    }

    /**
     * @param array<string, string> $files
     * @param list<array<string, string>> $steps edits made in turn, each followed by a scan
     * @param list<string> $rescanned what each step rescans
     */
    #[DataProvider('importsBetweenFilesOfTwoFallbackGroups')]
    public function testAFallbackProgramEmitsOnlyTheFilesOfItsOwnGroup(array $files, array $steps, string $unlisted, string $group, array $rescanned): void
    {
        // Two fallback groups: packages/p1, under p1's tsconfig whose paths
        // send `c` to lib/c.ts, and the root, whose paths send `c` to
        // vendor/c. x.ts is in the root group; t.ts in p1's. An import from
        // t.ts reaches x.ts in p1's fallback program, which must still leave
        // x.ts to the root group's program, whichever group is built first.
        foreach ($files as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $facts = $this->facts($pdo, $unlisted);
        assertSame('fallback:' . $group, $this->program($pdo, $unlisted));
        // Resolved under the root config, where `c` is vendor/c, not under
        // p1's, where it would be packages/p1/lib/c.ts.
        assertSame(json_encode([[['re_exports', 'vendor/c/one.ts']], []], JSON_THROW_ON_ERROR), $facts);

        foreach ($steps as $n => $edits) {
            $this->stamp($pdo);
            foreach ($edits as $relative => $contents) {
                $this->write($relative, $contents);
            }
            $result = $this->scan($pdo);

            assertSame($rescanned, $this->rescanned($pdo), "step $n");
            assertSame(count($rescanned), $result->data['parsed_files'], "step $n");
            assertSame($facts, $this->facts($pdo, $unlisted), "step $n");
            assertSame('fallback:' . $group, $this->program($pdo, $unlisted), "step $n");
            $this->assertMatchesAFullScan($pdo);
        }
    }

    /** @return iterable<string, array{array<string, string>, list<array<string, string>>, string, string, list<string>}> */
    public static function importsBetweenFilesOfTwoFallbackGroups(): iterable
    {
        $layout = static fn(string $shared): array => [
            'tsconfig.json' => '{"compilerOptions": {"strict": true, "baseUrl": ".", "paths": {"c": ["vendor/c"]}}, "files": []}',
            'packages/p1/tsconfig.json' => '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler", "baseUrl": ".", "paths": {"c": ["lib/c.ts"]}}, "include": ["src"]}',
            'packages/p1/src/main.ts' => "export class M {}\n",
            'packages/p1/lib/c.ts' => "export class C { p1(): void {} }\n",
            'packages/p1/test/t.ts' => "export const t = 1;\n",
            'vendor/c/package.json' => '{"name": "c", "types": "one.ts"}',
            'vendor/c/one.ts' => "export class C { one(): void {} }\n",
            "packages/$shared/x.ts" => "export { C } from 'c';\n",
            "packages/$shared/y.ts" => "import { C } from './x';\nexport class Y extends C {}\n",
        ];
        $importing = static fn(string $shared): string => "import { C } from '../../$shared/x';\nexport class T extends C {}\n";
        $plain = "export const t = 1;\n";
        $t = 'packages/p1/test/t.ts';
        yield 'the importer in the other group adds the import' => [
            $layout('shared'), [[$t => $importing('shared')]], 'packages/shared/x.ts', '.', [$t],
        ];
        yield 'the importer in the other group drops the import' => [
            [$t => $importing('shared')] + $layout('shared'), [[$t => $plain]], 'packages/shared/x.ts', '.', [$t],
        ];
        yield 'added, then dropped' => [
            $layout('shared'), [[$t => $importing('shared')], [$t => $plain]], 'packages/shared/x.ts', '.', [$t],
        ];
        // The same, with the unlisted file's group sorting before the
        // importer's, so the groups are built in the other order.
        yield 'the unlisted file sorts before its importer' => [
            $layout('a-shared'), [[$t => $importing('a-shared')]], 'packages/a-shared/x.ts', '.', [$t],
        ];
        yield 'the unlisted file sorts after its importer' => [
            $layout('z-shared'), [[$t => $importing('z-shared')]], 'packages/z-shared/x.ts', '.', [$t],
        ];
    }

    public function testAnEditToAListedLeafRescansTheLeafItsReadersAndTheFilesNoConfigLists(): void
    {
        $this->write('tsconfig.json', '{"files": []}');
        $this->write('packages/p1/tsconfig.json', '{"compilerOptions": {"strict": true, "module": "esnext", "moduleResolution": "bundler"}, "include": ["."]}');
        $this->write('packages/p1/leaf.ts', "export class Leaf {}\n");
        $this->write('packages/p1/mid.ts', "import { Leaf } from './leaf';\nexport class Mid extends Leaf {}\n");
        for ($i = 0; $i < 4; ++$i) {
            $this->write("packages/p1/f$i.ts", "export function f$i(): void {}\n");
        }
        $this->write('packages/shared/x.ts', "export const x = 1;\n");
        $this->write('packages/shared/y.ts', "import { x } from './x';\nexport const y = x;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $this->stamp($pdo);
        $this->write('packages/p1/leaf.ts', "export class Leaf {}\nexport const v = 1;\n");
        $result = $this->scan($pdo);

        assertSame(['packages/p1/leaf.ts', 'packages/p1/mid.ts', 'packages/shared/x.ts', 'packages/shared/y.ts'], $this->rescanned($pdo));
        assertSame(4, $result->data['parsed_files']);
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

    /** The program one file's cached contribution says its facts came from. */
    private function program(PDO $pdo, string $relative): string
    {
        $statement = $pdo->prepare('SELECT payload_json FROM contribution_cache WHERE owner_key = ?');
        $statement->execute(['knossos.typescript:file:' . $relative]);
        $payload = json_decode((string) $statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);

        return (string) ($payload['program'] ?? '');
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
