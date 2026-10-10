<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\UnitInputSet;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

/**
 * An incremental scan rebuilds every cached contribution a change can reach,
 * through the real workers, so its graph is the one a full scan of the same
 * bytes would produce, and what the scan records it read stays complete when
 * the files that read it were reused rather than rescanned.
 */
#[Group('scan')]
final class IncrementalReadsScanTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-reads-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    public function testAPhpScanStoresNoPerFileReadsAndMarksEveryRowAttributed(): void
    {
        $this->write('composer.json', '{"name": "app/reads", "autoload": {"psr-4": {"App\\\\": "src/"}}}' . "\n");
        $this->write('src/A.php', "<?php\nnamespace App;\nfinal class A extends B {}\n");
        $this->write('src/B.php', "<?php\nnamespace App;\nclass B {}\n");
        $pdo = $this->freshTestDatabase();

        $this->scan($pdo);

        assertSame(['src/A.php', 'src/B.php'], $pdo->query("SELECT file_path FROM contribution_cache WHERE scanner_id = 'knossos.php' AND read_attribution = 1 ORDER BY file_path")->fetchAll(PDO::FETCH_COLUMN));
        assertSame('0', (string) $pdo->query("SELECT COUNT(*) FROM contribution_cache WHERE scanner_id = 'knossos.php' AND read_attribution = 0")->fetchColumn());
        assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM contribution_reads')->fetchColumn());
    }

    /** What a contribution read is stored in its own table, never a second time inside the cached payload. */
    public function testACachedPayloadCarriesNoReads(): void
    {
        $this->write('composer.json', '{"name": "app/reads", "autoload": {"psr-4": {"App\\\\": "src/"}}}' . "\n");
        $this->write('src/A.php', "<?php\nnamespace App;\nfinal class A extends B {}\n");
        $this->write('src/B.php', "<?php\nnamespace App;\nclass B {}\n");
        $pdo = $this->freshTestDatabase();

        $this->scan($pdo);

        $payloads = $pdo->query("SELECT payload_json FROM contribution_cache WHERE scanner_id = 'knossos.php'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(2, $payloads);
        foreach ($payloads as $payload) {
            self::assertArrayNotHasKey('reads', json_decode((string) $payload, true, 512, JSON_THROW_ON_ERROR));
        }
    }

    public function testEditingAnImportedTypescriptFileRescansItsImporterAndMatchesAFullScan(): void
    {
        self::requireNode();
        $this->write('a.ts', "import { B } from './b';\nexport class A extends B {}\n");
        $this->write('b.ts', "export class B {}\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $groups = $pdo->query("SELECT DISTINCT read_group FROM contribution_cache WHERE scanner_id = 'knossos.typescript'")->fetchAll(PDO::FETCH_COLUMN);
        self::assertCount(1, $groups);
        self::assertNotNull($groups[0]);

        $this->write('b.ts', "export class B {}\nexport class C extends B {}\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(2, $incremental->data['parsed_files']);
        $full = $this->freshTestDatabase();
        $this->scan($full);
        assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    public function testADependencyDeclarationReadByAReusedFileStaysInTheRecordedWorkerInputs(): void
    {
        self::requireNode();
        $this->write('package.json', '{"name": "reads", "dependencies": {"dep": "1.0.0"}}' . "\n");
        $this->write('node_modules/dep/package.json', '{"name": "dep", "version": "1.0.0", "types": "index.d.ts"}' . "\n");
        $this->write('node_modules/dep/index.d.ts', "export declare class Dep {}\n");
        $this->write('a.ts', "import { Dep } from 'dep';\nexport class A extends Dep {}\n");
        $this->write('b.ts', "export const b = 1;\n");
        $this->write('src/P.php', "<?php\nnamespace App;\nclass P {}\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        self::assertArrayHasKey('node_modules/dep/index.d.ts', $this->recordedWorkerInputs($pdo));

        $this->write('b.ts', "export const b = 2;\n");
        $this->scan($pdo);
        self::assertArrayHasKey('node_modules/dep/index.d.ts', $this->recordedWorkerInputs($pdo));

        // Only the PHP file changes, so no TypeScript request is sent at all and
        // every TypeScript contribution comes from the cache.
        $this->write('src/P.php', "<?php\nnamespace App;\nclass P { public int \$x = 1; }\n");
        $result = $this->scan($pdo);
        assertSame(1, $result->data['parsed_files']);
        self::assertArrayHasKey('node_modules/dep/index.d.ts', $this->recordedWorkerInputs($pdo));
    }

    /**
     * A file added where an import already pointed changes what the importer
     * resolves to. The importer recorded the path as probed and absent, and a
     * TypeScript file added anywhere may declare globals any file uses, so the
     * worker says added files affect every file and all of them are rebuilt.
     */
    public function testAnAddedTypescriptFileThatAnExistingFileImportsMatchesAFullScan(): void
    {
        self::requireNode();
        $this->write('a.ts', "import { C } from './c';\nexport class A extends C {}\n");
        $this->write('b.ts', "export const b = 1;\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $this->write('c.ts', "export class C {}\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(3, $incremental->data['parsed_files']);
        $full = $this->freshTestDatabase();
        $this->scan($full);
        assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    /**
     * A file the previous scan saw but could not cache is not an added file:
     * only it is rescanned, and once it is cached the next scan changes
     * nothing. Edited while it has no cache row, it counts as added, and an
     * added TypeScript file rebuilds every file of its scanner.
     */
    public function testAFileTheLastScanCouldNotCacheIsRescannedAloneUntilItChanges(): void
    {
        self::requireNode();
        $this->write('a.ts', "import { C } from './c';\nexport class A extends C {}\n");
        $this->write('b.ts', "export const b = 1;\n");
        $this->write('c.ts', "export class C {}\n");
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $forgetC = static fn() => $pdo->exec("DELETE FROM contribution_cache WHERE file_path = 'c.ts'");

        $forgetC();
        $rescanned = $this->scan($pdo);

        assertSame('incremental', $rescanned->data['mode']);
        assertSame(1, $rescanned->data['parsed_files']);
        assertSame('no_change', $this->scan($pdo)->data['fast_path'] ?? null);

        $forgetC();
        $this->write('c.ts', "export class C {}\nexport class D extends C {}\n");
        $edited = $this->scan($pdo);

        assertSame(3, $edited->data['parsed_files']);
        $full = $this->freshTestDatabase();
        $this->scan($full);
        assertSame($this->graphSignature($full), $this->graphSignature($pdo));
    }

    /**
     * A stored miss for a file over the byte cap is what the commit check
     * accepts as unchanged, so it must not invalidate anything either.
     */
    public function testAStoredMissForAnOversizedUndiscoveredFileKeepsTheFastPath(): void
    {
        $this->write('composer.json', '{"name": "app/oversized"}' . "\n");
        $this->write('src/A.php', "<?php\nnamespace App;\nclass A {}\n");
        $this->write('big.bin', str_repeat('x', 4096));
        $pdo = $this->freshTestDatabase();
        $first = $this->scan($pdo, 1000);
        $pdo->prepare("INSERT INTO contribution_reads(project_id, owner_key, read_path, read_hash) VALUES (?, 'knossos.php:file:src/A.php', 'big.bin', NULL)")
            ->execute([$first->projectId]);

        $result = $this->scan($pdo, 1000);

        assertSame('no_change', $result->data['fast_path'] ?? null);
        assertSame(0, $result->data['parsed_files']);
    }

    #[Group('discovery')]
    public function testWorkerInputsBeyondTheOldCapRoundTrip(): void
    {
        $inputs = [];
        for ($index = 0; $index < 6000; ++$index) {
            $inputs[sprintf('node_modules/p/%05d.d.ts', $index)] = hash('sha256', (string) $index);
        }

        $decoded = UnitInputSet::decodeWorkerInputs(UnitInputSet::of([])->encode($inputs));

        self::assertNotNull($decoded);
        self::assertCount(6000, $decoded);
    }

    /**
     * A database written before read sets were stored carries cache rows and a
     * scan record whose worker reads were cut short. After the upgrade, the
     * first scan trusts none of it and rescans every file.
     */
    public function testAnUpgradedDatabaseRescansEveryFileOnce(): void
    {
        $this->write('composer.json', '{"name": "app/upgrade"}' . "\n");
        $this->write('src/A.php', "<?php\nnamespace App;\nclass A {}\n");
        $this->write('src/B.php', "<?php\nnamespace App;\nclass B {}\n");
        $migrations = sys_get_temp_dir() . '/knossos-stale-migrations-' . bin2hex(random_bytes(6));
        mkdir($migrations, 0o700);
        try {
            foreach (glob(self::repositoryRoot() . '/migrations/0*.sql') ?: [] as $migration) {
                if (!str_starts_with(basename($migration), '021_')) {
                    copy($migration, $migrations . '/' . basename($migration));
                }
            }
            $pdo = SqliteConnection::open(':memory:');
            (new MigrationRunner($pdo, $migrations))->migrate();
            $root = (string) realpath($this->root);
            $projectId = StableId::project('root:' . $root);
            $scanId = StableId::scan($projectId, 'legacy');
            $repository = new SqliteGraphRepository($pdo);
            $repository->saveProject($projectId, 'upgrade', $root);
            $legacyInputs = json_encode(['inputs' => [], 'complete' => true, 'worker_inputs' => ['inputs' => [], 'complete' => false]], JSON_THROW_ON_ERROR);
            $repository->createScan($scanId, $projectId, 'full', hash('sha256', 'legacy'), unitInputsJson: $legacyInputs);
            $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute([$scanId, $projectId]);
            $pdo->prepare("INSERT INTO contribution_cache (project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at) VALUES (?, 'knossos.php:file:src/A.php', 'src/A.php', ?, 'knossos.php', 'legacy', 'legacy', '{}', 'now')")
                ->execute([$projectId, hash_file('sha256', $this->root . '/src/A.php')]);

            copy(self::repositoryRoot() . '/migrations/021_contribution_reads.sql', $migrations . '/021_contribution_reads.sql');
            (new MigrationRunner($pdo, $migrations))->migrate();
            $result = $this->scan($pdo);

            assertSame('incremental', $result->data['mode']);
            assertSame(2, $result->data['parsed_files']);
        } finally {
            $this->removeTempTree($migrations);
        }
    }

    private function scan(PDO $pdo, ?int $maxFileBytes = null): \Knossos\Result\ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, maxFileBytes: $maxFileBytes);
    }

    /** @return array<string, string> */
    private function recordedWorkerInputs(PDO $pdo): array
    {
        $json = (string) $pdo->query('SELECT s.unit_inputs_json FROM scans s JOIN projects p ON p.active_scan_id = s.id')->fetchColumn();
        $inputs = UnitInputSet::decodeWorkerInputs($json);
        self::assertNotNull($inputs);

        return $inputs;
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }

    private static function requireNode(): void
    {
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
    }
}
