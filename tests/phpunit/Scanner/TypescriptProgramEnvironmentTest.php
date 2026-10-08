<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
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
