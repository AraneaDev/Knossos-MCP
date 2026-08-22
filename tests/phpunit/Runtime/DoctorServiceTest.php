<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\DoctorService;
use Knossos\Runtime\RuntimeVersionRequirement;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

#[Group('doctor-service')]
final class DoctorServiceTest extends KnossosTestCase
{
    private PDO $pdo;
    private string $installationRoot;

    protected function setUp(): void
    {
        $this->pdo = SqliteConnection::open(':memory:');
        $this->installationRoot = sys_get_temp_dir() . '/knossos-doctor-' . uniqid('', true);
        mkdir($this->installationRoot, 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->installationRoot)) {
            foreach (glob($this->installationRoot . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->installationRoot);
        }
    }

    // ----- shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(DoctorService::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorIsPublicTakesPdoAndTwoStrings(): void
    {
        $constructor = (new \ReflectionClass(DoctorService::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPublic());
        $params = $constructor->getParameters();
        assertSame(3, count($params));
        assertSame(PDO::class, (string) $params[0]->getType());
        assertSame('string', (string) $params[1]->getType());
        assertSame('string', (string) $params[2]->getType());
    }

    public function testRunIsPublicInstanceMethod(): void
    {
        $method = (new \ReflectionClass(DoctorService::class))->getMethod('run');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    // ----- structure -----

    public function testRunResultHasOkBooleanAndChecksList(): void
    {
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $result = $service->run();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('checks', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsArray($result['checks']);
    }

    public function testRunChecksEachEntryHasNameStatusDetail(): void
    {
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');
        $result = $service->run();

        // Each check has the expected shape and a closed set of status values.
        foreach ($result['checks'] as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('detail', $check);
            $this->assertIsString($check['name']);
            $this->assertIsString($check['status']);
            $this->assertIsString($check['detail']);
            // Status is exactly 'ok', 'error', or 'skipped' (no other values).
            // 'skipped' reports an optional worker (Rust) whose binary is not
            // installed — visible in doctor output without counting as a fault.
            $this->assertContains($check['status'], ['ok', 'error', 'skipped']);
        }

        // The 14 checks fired when databasePath is ':memory:' (data.writable
        // is conditional and excluded in this mode) — verifies both shape
        // AND the exact set of named checks in a single run() call.
        $names = array_column($result['checks'], 'name');
        $expected = [
            'php.version',
            'php.extension.json', 'php.extension.pdo', 'php.extension.pdo_sqlite',
            'node.version', 'git.version', 'python.version',
            'sqlite.integrity', 'sqlite.foreign_keys', 'sqlite.migrations',
            'worker.php', 'worker.typescript', 'worker.python', 'worker.rust',
        ];
        foreach ($expected as $name) {
            $this->assertContains($name, $names, "missing check: {$name}");
        }
        assertSame(14, count($names));
    }

    public function testRunSkipsDataWritableWhenDatabasePathIsInMemory(): void
    {
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $names = array_column($service->run()['checks'], 'name');

        $this->assertNotContains('data.writable', $names);
    }

    public function testRunIncludesDataWritableWhenDatabasePathIsFileBacked(): void
    {
        $writableDir = sys_get_temp_dir();
        $service = new DoctorService($this->pdo, $this->installationRoot, $writableDir . '/knossos-test.sqlite');

        $names = array_column($service->run()['checks'], 'name');

        $this->assertContains('data.writable', $names);
    }

    // ----- PHP version + extension checks (always-OK on test env) -----

    public function testRunPhpVersionCheckIsOkWithVersionIdBelow80500(): void
    {
        // Test runner is PHP 8.3.x (PHP_VERSION_ID < 80500), so the
        // closure returns PHP_VERSION rather than throwing.
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'php.version');

        assertSame('ok', $check['status']);
        assertSame(PHP_VERSION, $check['detail']);
    }

    public function testRunPhpExtensionJsonCheckIsOkWhenExtensionLoaded(): void
    {
        assertSame(true, extension_loaded('json'), 'json extension is required for the rest of the suite');

        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'php.extension.json');

        assertSame('ok', $check['status']);
        assertSame('loaded', $check['detail']);
    }

    public function testRunPhpExtensionPdoCheckIsOkWhenExtensionLoaded(): void
    {
        assertSame(true, extension_loaded('pdo'), 'pdo extension is required for the rest of the suite');

        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'php.extension.pdo');

        assertSame('ok', $check['status']);
        assertSame('loaded', $check['detail']);
    }

    public function testRunPhpExtensionPdoSqliteCheckIsOkWhenExtensionLoaded(): void
    {
        assertSame(true, extension_loaded('pdo_sqlite'), 'pdo_sqlite extension is required for the rest of the suite');

        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'php.extension.pdo_sqlite');

        assertSame('ok', $check['status']);
        assertSame('loaded', $check['detail']);
    }

    // ----- sqlite.* checks -----

    public function testRunSqliteIntegrityCheckIsOkOnFreshDatabase(): void
    {
        // PRAGMA quick_check on an empty in-memory database returns 'ok'.
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'sqlite.integrity');

        assertSame('ok', $check['status']);
        assertSame('ok', $check['detail']);
    }

    public function testRunSqliteForeignKeysCheckIsOkWhenEnabled(): void
    {
        // SqliteConnection::open() enables PRAGMA foreign_keys = ON.
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'sqlite.foreign_keys');

        assertSame('ok', $check['status']);
        assertSame('enabled', $check['detail']);
    }

    public function testRunSqliteMigrationsCheckFailsWithoutSchemaMigrationsTable(): void
    {
        // Fresh DB has no schema_migrations table; query() throws PDOException;
        // check() catches Throwable → 'error' status.
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'sqlite.migrations');

        assertSame('error', $check['status']);
        $this->assertStringContainsString('schema_migrations', $check['detail']);
    }

    public function testRunSqliteMigrationsCheckFailsWhenFewerThanSixMigrationsApplied(): void
    {
        // Set up the table with only 3 rows — below the source's threshold.
        $this->pdo->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY)');
        for ($i = 1; $i <= 3; $i++) {
            $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute(['v' => "m{$i}"]);
        }

        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'sqlite.migrations');

        assertSame('error', $check['status']);
        $this->assertStringContainsString('Only 3 migrations', $check['detail']);
    }

    public function testRunSqliteMigrationsCheckPassesWhenSixMigrationsApplied(): void
    {
        $this->pdo->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY)');
        for ($i = 1; $i <= 6; $i++) {
            $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute(['v' => "m{$i}"]);
        }

        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $check = $this->findCheck($service->run(), 'sqlite.migrations');

        assertSame('ok', $check['status']);
        assertSame('6 applied', $check['detail']);
    }

    // ----- data.writable (conditional) -----

    public function testRunDataWritablePassesForWritableTempDir(): void
    {
        // sys_get_temp_dir() is always writable.
        $service = new DoctorService($this->pdo, $this->installationRoot, sys_get_temp_dir() . '/knossos-test.sqlite');

        $check = $this->findCheck($service->run(), 'data.writable');

        assertSame('ok', $check['status']);
        assertSame(sys_get_temp_dir(), $check['detail']);
    }

    public function testRunDataWritableFailsForNonWritableDir(): void
    {
        // The data.writable guard is the only DoctorService branch that
        // cannot be exercised in a root-privileged test environment:
        // is_writable() returns true for /root under uid 0 regardless of
        // any chmod we apply. Skip cleanly when running as root or when
        // /root happens to be writable, so the rest of the suite stays
        // GREEN. The branch is documented here so future maintainers
        // don't think it's untested — it's untested on root, tested
        // elsewhere by code that runs as a non-root user.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root; is_writable("/root") is always true.');
        }
        if (is_writable('/root')) {
            $this->markTestSkipped('/root is writable in this environment.');
        }

        $service = new DoctorService($this->pdo, $this->installationRoot, '/root/knossos-test.sqlite');

        $check = $this->findCheck($service->run(), 'data.writable');

        assertSame('error', $check['status']);
        $this->assertStringContainsString('not writable', $check['detail']);
    }

    // ----- ok-field calculation -----

    public function testRunOkIsFalseWhenAnyCheckIsError(): void
    {
        // Use a non-writable dirname to force data.writable='error'. Because
        // sqlite.migrations also fails for the fresh :memory: DB, this test's
        // assertions hold regardless of which error path fires first. Skip
        // under root because is_writable('/root') is true in that environment
        // — sqlite.migrations still fails though, so the ok=false assertion
        // would still hold; the guard keeps the data.writable assertion honest.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root; data.writable error path not engineerable.');
        }
        if (is_writable('/root')) {
            $this->markTestSkipped('/root is writable in this environment.');
        }

        $service = new DoctorService($this->pdo, $this->installationRoot, '/root/knossos-test.sqlite');

        $result = $service->run();

        assertSame(false, $result['ok']);
        $errorChecks = array_filter($result['checks'], static fn(array $check): bool => $check['status'] === 'error');
        $this->assertNotEmpty($errorChecks);
    }

    public function testRunOkIsBoolean(): void
    {
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $result = $service->run();

        $this->assertIsBool($result['ok']);
        // On test env, php.* / sqlite.integrity / sqlite.foreign_keys are 'ok';
        // sqlite.migrations is 'error'; node/git/python/worker checks may be either.
        // ok reflects "any error anywhere".
        $anyError = count(array_filter($result['checks'], static fn(array $check): bool => $check['status'] === 'error')) > 0;
        assertSame(!$anyError, $result['ok']);
    }

    // ----- detail format -----

    public function testRunTrimsLeadingAndTrailingWhitespaceFromOkDetails(): void
    {
        // php.version returns PHP_VERSION (no leading/trailing whitespace), so
        // trim() is a no-op there. Use sqlite.integrity which returns 'ok' trimmed.
        // The point of this test is verifying that check() calls trim() on the detail
        // — for any 'ok' check, detail should not have leading/trailing whitespace.
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        foreach ($service->run()['checks'] as $check) {
            if ($check['status'] === 'ok') {
                assertSame(trim($check['detail']), $check['detail'], "detail for {$check['name']} should be trimmed");
            }
        }
    }

    // ----- error detail carries exception message -----

    public function testRunCheckErrorDetailIsExceptionMessage(): void
    {
        // sqlite.migrations fails (no schema_migrations table) → error detail comes
        // from the inner PDOException message. We verify the detail is non-empty
        // and that error checks exist in this configuration.
        $service = new DoctorService($this->pdo, $this->installationRoot, ':memory:');

        $result = $service->run();
        $errorChecks = array_filter($result['checks'], static fn(array $check): bool => $check['status'] === 'error');

        $this->assertNotEmpty($errorChecks);
        foreach ($errorChecks as $check) {
            $this->assertNotEmpty($check['detail']);
        }
    }

    // ----- runtime version floors -----

    public function testInstalledRuntimesAreAcceptedWheneverTheyMeetTheDocumentedFloor(): void
    {
        // The floors are minimums, not ranges: a runtime newer than the one this
        // release was built against still has to pass, or `doctor` reports a
        // working installation as broken.
        $floors = [
            'node.version' => [trim((string) shell_exec('node --version 2>/dev/null')), '/^v(\d+)\./', '22', 'Node'],
            'python.version' => [trim((string) shell_exec('python3 --version 2>/dev/null')), '/^Python (\d+\.\d+)\./', '3.11', 'Python'],
            'php.version' => [PHP_VERSION, '/^(\d+\.\d+)\./', '8.3', 'PHP'],
        ];
        $result = (new DoctorService($this->pdo, $this->installationRoot, ':memory:'))->run();

        foreach ($floors as $name => [$reported, $pattern, $minimum, $runtime]) {
            if ($reported === '') {
                continue;
            }
            $check = $this->findCheck($result, $name);
            assertSame((new RuntimeVersionRequirement($runtime, $pattern, $minimum))->verify($reported), $check['detail'], $name);
            assertSame('ok', $check['status'], $name);
        }
    }

    // ----- optional worker (Rust) -----

    public function testAnAbsentOptionalWorkerIsSkippedRatherThanFailed(): void
    {
        // setUp() creates an empty temporary installation root, so no worker binary
        // exists under it. The Rust worker cannot run, and that must not make an
        // otherwise healthy installation unhealthy.
        $report = (new DoctorService($this->pdo, $this->installationRoot, ':memory:'))->run();
        $rust = null;
        foreach ($report['checks'] as $check) {
            if ($check['name'] === 'worker.rust') {
                $rust = $check;
            }
        }

        self::assertNotNull($rust, 'doctor must report the Rust worker.');
        self::assertSame('skipped', $rust['status']);
        self::assertStringContainsString('workers/rust/bin/knossos-rust-worker', $rust['detail']);
    }

    public function testDoctorProbesOneWorkerPerPackagedDescriptor(): void
    {
        $report = (new DoctorService($this->pdo, $this->installationRoot, ':memory:'))->run();
        $names = array_values(array_filter(
            array_column($report['checks'], 'name'),
            static fn(string $name): bool => str_starts_with($name, 'worker.'),
        ));

        self::assertSame(['worker.php', 'worker.typescript', 'worker.python', 'worker.rust'], $names);
    }

    public function testRunOkIsUnaffectedByASkippedCheck(): void
    {
        // Recomputing ok from $result['checks'] with the same formula run()
        // uses is tautological: it passes for any production formula,
        // including a broken one that folds 'skipped' into the error count.
        // The only way to pin the real invariant is a fixture where every
        // check genuinely passes except worker.rust, and a literal
        // assertTrue($result['ok']) — so a regression that starts treating
        // 'skipped' as unhealthy actually flips the assertion.
        // Same "command -v" probe as Processes::locateGit(), inlined here
        // because only this one test needs node/python3 rather than git.
        if (trim((string) @shell_exec('command -v node 2>/dev/null')) === '') {
            self::markTestSkipped('node is not on PATH.');
        }
        if (trim((string) @shell_exec('command -v python3 2>/dev/null')) === '') {
            self::markTestSkipped('python3 is not on PATH.');
        }
        $rustBinary = self::repositoryRoot() . '/workers/rust/bin/knossos-rust-worker';
        if (is_file($rustBinary)) {
            self::markTestSkipped('A Rust worker binary is present; worker.rust cannot be observed as skipped.');
        }

        // The repository root's own packaged php/typescript/python worker
        // binaries genuinely exist, so those three checks report 'ok' rather
        // than 'error'. schema_migrations is seeded the same way
        // testRunSqliteMigrationsCheckPassesWhenSixMigrationsApplied does, so
        // sqlite.migrations passes too; sqlite.integrity and
        // sqlite.foreign_keys already pass on a fresh in-memory database.
        $this->pdo->exec('CREATE TABLE schema_migrations (version TEXT PRIMARY KEY)');
        for ($i = 1; $i <= 6; $i++) {
            $this->pdo->prepare('INSERT INTO schema_migrations (version) VALUES (:v)')->execute(['v' => "m{$i}"]);
        }

        $service = new DoctorService($this->pdo, self::repositoryRoot(), ':memory:');

        $result = $service->run();

        $errors = array_filter($result['checks'], static fn(array $check): bool => $check['status'] === 'error');
        assertSame([], array_column($errors, 'name'), 'no check should report error in this fixture');

        $rust = $this->findCheck($result, 'worker.rust');
        assertSame('skipped', $rust['status']);

        self::assertTrue($result['ok'], 'a report with only ok and skipped checks must be healthy');
    }

    // ----- helpers -----

    /**
     * @param array{ok: bool, checks: list<array{name: string, status: string, detail: string}>} $result
     */
    private function findCheck(array $result, string $name): array
    {
        foreach ($result['checks'] as $check) {
            if ($check['name'] === $name) {
                return $check;
            }
        }
        $this->fail("check '{$name}' not found in result");
    }
}
