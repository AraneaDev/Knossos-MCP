<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use Knossos\Bundle\GraphBundleService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Redaction replaced files.relative_path only: TS names (path#symbol), owner
 * keys, stable ids, boundary names, matchers and messages still carried every
 * path, and strict mode still leaked canonical names. The replacement it did
 * make was an unsalted hash, which names any path an outsider can guess.
 *
 * The paths are read back from the database rather than listed here, so a new
 * kind of path-bearing column cannot slip past the test.
 */
#[Group('bundle')]
final class BundleRedactionLeakTest extends KnossosTestCase
{
    use WorkerClients;

    private string $root;
    private string $database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-redaction-' . bin2hex(random_bytes(6));
        $files = [
            'knossos.json' => '{"version":1,"boundaries":[{"name":"Payroll Area","path_prefix":"src/secret/"}],'
                . '"policies":[{"id":"payroll-isolation","from_boundary":"Payroll Area","deny_targets":["@unassigned"]}]}',
            'composer.json' => '{"autoload":{"psr-4":{"App\\\\":"src/"}}}',
            'package.json' => '{"name":"fixture"}',
            'src/secret/payroll.ts' => "import { ledger } from './ledger';\nexport function hidden(): number { return ledger(); }\n",
            'src/secret/ledger.ts' => "export function ledger(): number { return 1; }\n",
            'src/secret/dangling.ts' => "import { gone } from './missing';\nexport const kept = gone;\n",
            'pkg/__init__.py' => '',
            'pkg/secret/__init__.py' => '',
            'pkg/secret/ledger.py' => "from pkg.secret import other\n\nclass Ledger:\n    def total(self):\n        return other.amount()\n",
            'pkg/secret/other.py' => "def amount():\n    return 1\n",
            'pkg/secret/broken.py' => "def broken(:\n",
            'src/Secret/Payroll.php' => "<?php\nnamespace App\\Secret;\nfinal class Payroll { public function run(): Ledger { return new Ledger(); } }\n",
            'src/Secret/Ledger.php' => "<?php\nnamespace App\\Secret;\nfinal class Ledger {}\n",
            'src/Secret/Broken.php' => "<?php\nclass {\n",
            'crates/secret/Cargo.toml' => "[package]\nname = \"payroll_crate\"\nversion = \"0.1.0\"\nedition = \"2021\"\n",
            'crates/secret/src/lib.rs' => "mod inner;\npub fn top() { inner::f(); }\n",
            'crates/secret/src/inner.rs' => "pub fn f() {}\n",
        ];
        foreach ($files as $relative => $contents) {
            $path = $this->root . '/' . $relative;
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $contents);
        }
        $database = tempnam(sys_get_temp_dir(), 'knossos-redaction-db-');
        self::assertIsString($database);
        $this->database = $database;
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        foreach ([$this->database, $this->database . '-shm', $this->database . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
        parent::tearDown();
    }

    #[DataProvider('modes')]
    public function testNoDiscoveredPathSurvivesARedactedExport(string $mode): void
    {
        $pdo = SqliteConnection::open($this->database);
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root, 'Redaction Source')->projectId;
        $this->assertTheFixtureExercisesEveryLanguage($pdo, $projectId);

        $bundle = (string) gzdecode((new GraphBundleService($pdo))->export($projectId, $mode));
        // Attributes and matchers are JSON inside JSON: a path written with
        // escaped slashes there is still that path, so it is searched for
        // with one and two levels of escaping removed as well.
        $json = $bundle . "\n" . stripslashes($bundle) . "\n" . stripslashes(stripslashes($bundle));

        $paths = $this->column($pdo, 'SELECT relative_path FROM files WHERE project_id = :project', $projectId);
        self::assertNotSame([], $paths);
        foreach ($paths as $path) {
            self::assertFalse(str_contains($json, $path), 'File path ' . $path);
            self::assertStringNotContainsString(substr(hash('sha256', $path), 0, 24), $json, 'Unsalted hash of ' . $path);
            foreach ($this->ancestors($path) as $directory) {
                self::assertFalse(str_contains($json, $directory), 'Directory ' . $directory);
            }
        }
        $modules = $this->column($pdo, "SELECT canonical_name FROM nodes WHERE project_id = :project AND language = 'py' AND kind = 'module' AND file_id IS NOT NULL", $projectId);
        self::assertContains('pkg.secret.ledger', $modules);
        foreach ($modules as $module) {
            if (str_contains($module, '.')) {
                self::assertFalse(str_contains($json, $module), 'Python module ' . $module);
            }
        }
    }

    /** @return iterable<string, array{0: string}> */
    public static function modes(): iterable
    {
        yield 'paths' => ['paths'];
        yield 'strict' => ['strict'];
    }

    /**
     * A test that scans nothing proves nothing: every language the fixture
     * holds produced nodes, a boundary names a directory, and a diagnostic
     * carries a path in its owner key.
     */
    private function assertTheFixtureExercisesEveryLanguage(PDO $pdo, string $projectId): void
    {
        $languages = $this->column($pdo, 'SELECT DISTINCT language FROM nodes WHERE project_id = :project', $projectId);
        $expected = is_file(self::rustWorkerBinary()) ? ['php', 'py', 'rust', 'ts'] : ['php', 'py', 'ts'];
        self::assertSame([], array_values(array_diff($expected, $languages)), 'Languages scanned: ' . implode(', ', $languages));
        self::assertContains('{"type":"path_prefix","value":"src/secret/"}', $this->column($pdo, 'SELECT matcher_json FROM boundaries WHERE project_id = :project', $projectId));
        self::assertContains('knossos.php:file:src/Secret/Broken.php', $this->column($pdo, 'SELECT owner_key FROM diagnostics WHERE project_id = :project', $projectId));
    }

    /**
     * Every ancestor directory with at least two segments; one segment
     * (`src`, `pkg`) is an ordinary word and occurs as one.
     *
     * @return list<string>
     */
    private function ancestors(string $path): array
    {
        $directories = [];
        $directory = dirname($path);
        while (str_contains($directory, '/')) {
            $directories[] = $directory;
            $directory = dirname($directory);
        }
        return $directories;
    }

    /**
     * The first column of every row a query returns.
     *
     * @return list<string>
     */
    private function column(PDO $pdo, string $sql, string $projectId): array
    {
        $statement = $pdo->prepare($sql);
        $statement->execute(['project' => $projectId]);
        return array_values(array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }
}
