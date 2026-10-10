<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * The worker checks each program whole before collecting facts, reports
 * what names no file (an option error, a global type the checker cannot find
 * when it starts) once per program, on the program's carrier, and leaves out
 * a global type the checker only looks for while checking one file. After an
 * edit the incremental graph equals a full scan of the same bytes, through
 * the real worker.
 */
#[Group('typescript-scanner')]
final class TypescriptFileDiagnosticsTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-file-diagnostics-' . bin2hex(random_bytes(6));
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
     * A generator needs the global `IterableIterator`, which an ES5 library
     * lacks. The checker finds that out only when it checks the generator,
     * so whether it was reported followed which files were checked: on the
     * program's carrier after a full scan, and nowhere after an incremental
     * scan that rebuilt only the edited file.
     */
    public function testAnEditThatMakesTheCheckerMissAGlobalTypeMatchesAFullScan(): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"lib": ["es5"], "target": "es2015"}, "include": ["src"]}');
        $this->write('src/a.ts', "export const a = 1;\n");
        $this->write('src/b.ts', "export const b = 2;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->write('src/b.ts', "export function* b(): Generator<number> { yield 2; }\n");

        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        self::assertSame('incremental', $incremental->data['mode']);
        self::assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    public function testAnEditInOneOfSeveralProgramsMatchesAFullScan(): void
    {
        foreach (['p1', 'p2', 'p3'] as $package) {
            // `baseUrl` is deprecated: an option error, which names no file.
            $this->write("packages/{$package}/tsconfig.json", '{"compilerOptions": {"strict": true, "baseUrl": "."}, "include": ["src"]}');
            $this->write("packages/{$package}/src/a.ts", "export const a: number = 1;\n");
            $this->write("packages/{$package}/src/b.ts", "import { a } from './a';\nexport const b: string = a;\n");
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        self::assertSame(['TS2322'], $this->codes($pdo, 'packages/p2/src/b.ts'));
        $this->write('packages/p2/src/b.ts', "import { a } from './a';\nexport const b: number = a;\nexport const c: boolean = b;\n");

        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        self::assertSame('incremental', $incremental->data['mode']);
        self::assertSame($this->graphSignature($full), $this->graphSignature($pdo));
        self::assertSame(['TS2322'], $this->codes($pdo, 'packages/p2/src/b.ts'));
        // Once per program, on its carrier, its first root file.
        foreach (['p1', 'p2', 'p3'] as $package) {
            self::assertSame(['TS5101'], $this->codes($pdo, "packages/{$package}/src/a.ts"), $package);
        }
    }

    /**
     * The checker's budgets (instantiation depth, union size) count across
     * every file it checked before, so checking one file alone could run
     * out where the whole program did not. Every request checks the whole
     * program in program order before facts are collected, so a budget
     * that runs out does so in every scan alike, and its diagnostic stays.
     *
     * @param array<string, string> $files the tree before `src/b.ts` is touched
     * @param list<array{0: string, 1: string}> $expected each budget diagnostic's owner and code
     */
    #[DataProvider('budgets')]
    public function testACheckerBudgetRunsOutAlikeInAnIncrementalAndAFullScan(array $files, array $expected): void
    {
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true, "lib": ["es2020"]}, "include": ["src"]}');
        foreach ($files as $relative => $contents) {
            $this->write($relative, $contents);
        }
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->write('src/b.ts', $files['src/b.ts'] . "// touched\n");

        $incremental = $this->scan($pdo);
        $full = $this->freshTestDatabase();
        $this->scan($full);

        self::assertSame('incremental', $incremental->data['mode']);
        $budget = "SELECT d.owner_key, d.code FROM diagnostics d JOIN projects p ON p.active_scan_id = d.scan_id WHERE d.code IN ('TS2589', 'TS2590', 'TS2321', 'TS7056') ORDER BY 1, 2";
        self::assertSame($expected, $full->query($budget)->fetchAll(PDO::FETCH_NUM));
        self::assertSame($expected, $pdo->query($budget)->fetchAll(PDO::FETCH_NUM));
        self::assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    /** @return iterable<string, array{0: array<string, string>, 1: list<array{0: string, 1: string}>}> */
    public static function budgets(): iterable
    {
        yield 'instantiation depth' => [[
            'src/deep.ts' => "export type Deep<N extends number, A extends unknown[] = []> = A['length'] extends N ? A : [...Deep<N, [...A, 0]>];\n",
            'src/a.ts' => "import type { Deep } from './deep';\nexport type Pre = Deep<60, [" . implode(',', array_fill(0, 30, '0')) . "]>;\nexport const a: Pre = [] as never;\n",
            'src/b.ts' => "import type { Deep } from './deep';\nexport const b: Deep<100> = [] as never;\n",
        ], [['knossos.typescript:file:src/b.ts', 'TS2589']]];
        yield 'union size' => [[
            'src/deep.ts' => "type Digit = 0|1|2|3|4|5|6|7|8|9;\nexport type Big = `\${Digit}\${Digit}\${Digit}\${Digit}\${Digit}`;\nexport type Wrap<T> = T extends string ? { k: T } : never;\n",
            'src/a.ts' => "import type { Big, Wrap } from './deep';\nexport type Pre = Wrap<Big>;\n",
            'src/b.ts' => "import type { Big, Wrap } from './deep';\nexport const b: Wrap<Big> = null as never;\n",
        ], [['knossos.typescript:file:src/deep.ts', 'TS2590']]];
    }

    /** @return list<string> the codes of the diagnostics one file owns */
    private function codes(PDO $pdo, string $relative): array
    {
        $codes = $pdo->prepare('SELECT d.code FROM diagnostics d JOIN projects p ON p.active_scan_id = d.scan_id WHERE d.owner_key = ? ORDER BY 1');
        $codes->execute(['knossos.typescript:file:' . $relative]);

        return $codes->fetchAll(PDO::FETCH_COLUMN);
    }

    private function scan(PDO $pdo): \Knossos\Result\ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
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
