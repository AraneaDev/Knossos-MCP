<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * The TypeScript worker says which files each contribution read, so an
 * incremental scan rescans only the files a change reaches, through the real
 * worker, and still ends with the graph a full scan of the same bytes gives.
 */
#[Group('typescript-scanner')]
final class TypescriptReadAttributionTest extends KnossosTestCase
{
    /** A cache row an incremental scan reused keeps this stamp; a rescanned one is rewritten. */
    private const UNTOUCHED = 'untouched';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-ts-reads-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        $this->write('tsconfig.json', '{"compilerOptions": {"strict": true}, "include": ["src"]}' . "\n");
        $this->write('src/a.ts', "import { B } from './b';\nimport { X } from './x';\nexport class A extends B {}\nexport const x: typeof X | undefined = undefined;\n");
        $this->write('src/b.ts', "export { C as B } from './c';\n");
        $this->write('src/c.ts', "export class C {}\n");
        $this->write('src/d.ts', "export class D {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testEditingAFileRescansItsReadersAndNothingElse(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/c.ts', "export class C {}\nexport class E extends C {}\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(3, $incremental->data['parsed_files']);
        assertSame(['src/a.ts', 'src/b.ts', 'src/c.ts'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * The importer read the missing path as absent; the new file is also an
     * added TypeScript file, which may declare globals, so every file is
     * rebuilt, the importer among them.
     */
    public function testCreatingAFileAnImportProbedRescansTheImporter(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/x.ts', "export const X = 1;\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(5, $incremental->data['parsed_files']);
        assertSame(['src/a.ts', 'src/b.ts', 'src/c.ts', 'src/d.ts', 'src/x.ts'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEditingTheConfigRescansEveryFile(): void
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('tsconfig.json', '{"compilerOptions": {"strict": false}, "include": ["src"]}' . "\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(4, $incremental->data['parsed_files']);
        assertSame(['src/a.ts', 'src/b.ts', 'src/c.ts', 'src/d.ts'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A script added beside the project declares a global that a file already
     * used, and no read of that file could have named a script that did not
     * exist anywhere it looked: every TypeScript file is rescanned.
     */
    public function testAddingAGlobalScriptRescansEveryFileAndMatchesAFullScan(): void
    {
        $this->write('src/d.ts', "export class D extends APP_BASE {}\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/globals.ts', "declare class APP_BASE {}\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(['src/a.ts', 'src/b.ts', 'src/c.ts', 'src/d.ts', 'src/globals.ts'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEveryContributionIsStoredAsAttributed(): void
    {
        $pdo = $this->freshTestDatabase();

        $this->scan($pdo);

        assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM contribution_cache WHERE scanner_id = 'knossos.typescript' AND read_attribution = 0")->fetchColumn());
        $reads = $pdo->query("SELECT read_path FROM contribution_reads WHERE owner_key = 'knossos.typescript:file:src/a.ts' ORDER BY read_path")->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('src/b.ts', $reads);
        self::assertContains('src/x.ts', $reads);
        self::assertNotContains('src/d.ts', $reads);
    }

    private function scan(PDO $pdo): ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    private function stampCacheRows(PDO $pdo): void
    {
        $pdo->prepare('UPDATE contribution_cache SET updated_at = ?')->execute([self::UNTOUCHED]);
    }

    /** @return list<string> the files whose contribution the last scan rewrote */
    private function rescannedFiles(PDO $pdo): array
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
