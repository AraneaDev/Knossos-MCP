<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use InvalidArgumentException;
use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPlanner;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Store\StableId;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-planner')]
final class ScanPlannerTest extends TestCase
{
    private function createSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE projects (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            root_realpath TEXT NOT NULL,
            config_json TEXT NOT NULL DEFAULT \'{}\',
            active_scan_id TEXT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )');
        $pdo->exec('CREATE TABLE contribution_cache (
            project_id TEXT NOT NULL,
            owner_key TEXT NOT NULL,
            file_path TEXT NOT NULL,
            content_hash TEXT NOT NULL,
            scanner_id TEXT NOT NULL,
            scanner_version TEXT NOT NULL,
            configuration_hash TEXT NOT NULL,
            payload_json TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            read_attribution INTEGER NOT NULL DEFAULT 0,
            read_group TEXT
        )');
        $pdo->exec('CREATE TABLE contribution_reads (project_id TEXT NOT NULL, owner_key TEXT NOT NULL, read_path TEXT NOT NULL, read_hash TEXT)');
        $pdo->exec('CREATE TABLE contribution_read_groups (project_id TEXT NOT NULL, group_id TEXT NOT NULL, read_path TEXT NOT NULL, read_hash TEXT)');
        $pdo->exec('CREATE TABLE files (project_id TEXT NOT NULL, relative_path TEXT NOT NULL, content_hash TEXT NOT NULL)');
        $pdo->exec('CREATE TABLE scans (
            id TEXT PRIMARY KEY,
            project_id TEXT NOT NULL,
            unit_inputs_json TEXT NULL
        )');
        return $pdo;
    }

    /** @param list<DiscoveredFile> $files */
    private function makePreparation(string $rootRealpath = '/tmp/foo', array $files = []): ScanPreparation
    {
        return new ScanPreparation(
            configuration: new ProjectConfiguration(),
            discovery: new DiscoveryResult(
                rootRealpath: $rootRealpath,
                files: $files,
                units: [],
                diagnostics: [],
                inputHash: '',
                configurationHash: '',
            ),
            maxFiles: 0,
            maxFileBytes: 0,
            explicitBoundaries: [],
            requestedMode: 'auto',
            snapshotRetention: 0,
            executionPolicy: new WorkerExecutionPolicy(),
            laravel: false,
            symfony: false,
            configurationHashes: ['php' => '', 'typescript' => '', 'python' => ''],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
    }

    public function testPrepareDetectsPythonAndRustFrameworksFromManifests(): void
    {
        $root = sys_get_temp_dir() . '/knossos-planner-frameworks-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            file_put_contents($root . '/pyproject.toml', <<<'TOML'
[project]
name = "demo"
dependencies = ["fastapi", "requests"]
TOML);
            file_put_contents($root . '/Cargo.toml', <<<'TOML'
[package]
name = "demo"
version = "0.1.0"

[dependencies]
axum = "0.7"
serde = "1"
TOML);

            $planner = new ScanPlanner($this->createSchema(), [$root]);
            $preparation = $planner->prepare($root, null, null, null, 'auto', 0, null);

            assertSame(['fastapi'], $preparation->pythonFrameworks);
            assertSame(['axum'], $preparation->rustFrameworks);
        } finally {
            @unlink($root . '/pyproject.toml');
            @unlink($root . '/Cargo.toml');
            @rmdir($root);
        }
    }

    public function testPrepareDetectsPythonFrameworksFromRequirementsTxt(): void
    {
        $root = sys_get_temp_dir() . '/knossos-planner-req-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            file_put_contents($root . '/requirements.txt', "flask==3.0\nrequests==2.31\n");
            $planner = new ScanPlanner($this->createSchema(), [$root]);
            $preparation = $planner->prepare($root, null, null, null, 'auto', 0, null);
            assertSame(['flask'], $preparation->pythonFrameworks);
        } finally {
            @unlink($root . '/requirements.txt');
            @rmdir($root);
        }
    }

    public function testPrepareAcceptsConfiguredFrameworkHintsWithoutManifestDeps(): void
    {
        $root = sys_get_temp_dir() . '/knossos-planner-hints-' . bin2hex(random_bytes(6));
        mkdir($root, 0700, true);
        try {
            file_put_contents($root . '/knossos.json', json_encode([
                'version' => 1,
                'frameworks' => ['flask', 'rocket'],
            ], JSON_THROW_ON_ERROR));

            $planner = new ScanPlanner($this->createSchema(), [$root]);
            $preparation = $planner->prepare($root, null, null, null, 'auto', 0, null);

            assertSame(['flask'], $preparation->pythonFrameworks);
            assertSame(['rocket'], $preparation->rustFrameworks);
        } finally {
            @unlink($root . '/knossos.json');
            @rmdir($root);
        }
    }

    public function testFinalizeReturnsFullModeWhenNoExistingProject(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);

        $plan = $planner->finalize($this->makePreparation('/tmp/empty-project'));

        assertSame('full', $plan->effectiveMode);
    }

    public function testFinalizeReturnsFullModeWhenRequestedModeIsFull(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/existing-project';
        $projectId = StableId::project('root:' . $root);

        $pdo->prepare('INSERT INTO projects(id, name, root_realpath, config_json, active_scan_id, created_at, updated_at) VALUES (:id, :name, :root, :config, :scan, :created, :updated)')
            ->execute([
                'id' => $projectId,
                'name' => 'existing-project',
                'root' => $root,
                'config' => '{}',
                'scan' => 'scan-abc',
                'created' => '2026-07-21T00:00:00Z',
                'updated' => '2026-07-21T00:00:00Z',
            ]);

        $preparation = $this->makePreparation($root);
        $planRequestingFull = new ScanPreparation(
            configuration: $preparation->configuration,
            discovery: $preparation->discovery,
            maxFiles: $preparation->maxFiles,
            maxFileBytes: $preparation->maxFileBytes,
            explicitBoundaries: $preparation->explicitBoundaries,
            requestedMode: 'full',
            snapshotRetention: $preparation->snapshotRetention,
            executionPolicy: $preparation->executionPolicy,
            laravel: $preparation->laravel,
            symfony: $preparation->symfony,
            configurationHashes: $preparation->configurationHashes,
            configurationMilliseconds: $preparation->configurationMilliseconds,
            discoveryMilliseconds: $preparation->discoveryMilliseconds,
            planningMilliseconds: $preparation->planningMilliseconds,
        );

        $plan = $planner->finalize($planRequestingFull);

        assertSame('full', $plan->effectiveMode);
    }

    public function testFinalizeReturnsIncrementalModeWhenExistingProjectHasActiveScan(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/incremental-project';
        $projectId = StableId::project('root:' . $root);

        $pdo->prepare('INSERT INTO projects(id, name, root_realpath, config_json, active_scan_id, created_at, updated_at) VALUES (:id, :name, :root, :config, :scan, :created, :updated)')
            ->execute([
                'id' => $projectId,
                'name' => 'incremental-project',
                'root' => $root,
                'config' => '{}',
                'scan' => 'scan-xyz',
                'created' => '2026-07-21T00:00:00Z',
                'updated' => '2026-07-21T00:00:00Z',
            ]);

        $plan = $planner->finalize($this->makePreparation($root));

        assertSame('incremental', $plan->effectiveMode);
    }

    public function testFinalizeReturnsFullModeWhenExistingProjectHasNoActiveScan(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/no-active-scan';
        $projectId = StableId::project('root:' . $root);

        $pdo->prepare('INSERT INTO projects(id, name, root_realpath, config_json, active_scan_id, created_at, updated_at) VALUES (:id, :name, :root, :config, :scan, :created, :updated)')
            ->execute([
                'id' => $projectId,
                'name' => 'no-active-scan',
                'root' => $root,
                'config' => '{}',
                'scan' => null,
                'created' => '2026-07-21T00:00:00Z',
                'updated' => '2026-07-21T00:00:00Z',
            ]);

        $plan = $planner->finalize($this->makePreparation($root));

        assertSame('full', $plan->effectiveMode);
    }

    public function testFinalizeComputesDeletedFilesFromCacheDiff(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/deleted-project';
        $projectId = StableId::project('root:' . $root);

        $pdo->prepare('INSERT INTO projects(id, name, root_realpath, config_json, active_scan_id, created_at, updated_at) VALUES (:id, :name, :root, :config, :scan, :created, :updated)')
            ->execute([
                'id' => $projectId,
                'name' => 'deleted-project',
                'root' => $root,
                'config' => '{}',
                'scan' => 'scan-existing',
                'created' => '2026-07-21T00:00:00Z',
                'updated' => '2026-07-21T00:00:00Z',
            ]);

        $pdo->prepare('INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at) VALUES (:project, :owner, :path, :hash, :scanner, :version, :config, :payload, :updated)')
            ->execute([
                'project' => $projectId,
                'owner' => 'php:file:old-deleted-file.php',
                'path' => 'old-deleted-file.php',
                'hash' => 'abc123',
                'scanner' => 'php-scanner',
                'version' => '1.0',
                'config' => '',
                'payload' => '{}',
                'updated' => '2026-07-21T00:00:00Z',
            ]);

        // Current files list is empty — so the cached file is "deleted"
        $plan = $planner->finalize($this->makePreparation($root));

        assertSame(1, $plan->deletedFiles);
    }

    /**
     * An incremental plan names the cached owners a change reached, here a
     * deleted file and the file that read it; a full plan rebuilds everything
     * and loads no read sets at all.
     */
    public function testFinalizeNamesTheOwnersAChangeReachedOnlyForAnIncrementalScan(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/reads-project';
        $projectId = StableId::project('root:' . $root);
        $pdo->prepare("INSERT INTO projects(id, name, root_realpath, active_scan_id, created_at, updated_at) VALUES (?, 'reads', ?, 'scan-existing', 'now', 'now')")
            ->execute([$projectId, $root]);
        $insert = $pdo->prepare("INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at, read_attribution) VALUES (?, ?, ?, 'abc', 'php-scanner', '1.0', '', '{}', 'now', 1)");
        $insert->execute([$projectId, 'php-scanner:file:gone.php', 'gone.php']);
        $insert->execute([$projectId, 'php-scanner:file:reader.php', 'reader.php']);
        $pdo->prepare("INSERT INTO contribution_reads(project_id, owner_key, read_path, read_hash) VALUES (?, 'php-scanner:file:reader.php', 'gone.php', ?)")
            ->execute([$projectId, hash('sha256', 'gone')]);

        $incremental = $planner->finalize($this->makePreparation($root));

        $invalidated = $incremental->invalidatedOwners;
        ksort($invalidated, SORT_STRING);
        assertSame(['php-scanner:file:gone.php' => true, 'php-scanner:file:reader.php' => true], $invalidated);
        self::assertNotNull($incremental->cachedReads);
        self::assertArrayNotHasKey('payload_json', $incremental->cacheByScannerPath["php-scanner\0reader.php"]);

        $pdo->exec("UPDATE projects SET active_scan_id = NULL");
        $full = $planner->finalize($this->makePreparation($root));

        assertSame([], $full->invalidatedOwners);
        self::assertNull($full->cachedReads);
    }

    /**
     * A TypeScript file with no cache row is an added file, and the TypeScript
     * worker does not say which file read what, so every cached TypeScript
     * file is rebuilt even though none of their own bytes changed.
     */
    public function testAnAddedFileRebuildsEveryRowOfAnUnattributedScanner(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/added-project';
        $projectId = StableId::project('root:' . $root);
        $pdo->prepare("INSERT INTO projects(id, name, root_realpath, active_scan_id, created_at, updated_at) VALUES (?, 'added', ?, 'scan-existing', 'now', 'now')")
            ->execute([$projectId, $root]);
        $insert = $pdo->prepare("INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at, read_attribution) VALUES (?, ?, ?, ?, 'knossos.typescript', '1.0', '', '{}', 'now', 0)");
        $files = [];
        foreach (['a.ts', 'b.ts', 'new.ts'] as $path) {
            $files[] = new DiscoveredFile($path, $root . '/' . $path, 'typescript', 10, 0, hash('sha256', $path));
            if ($path !== 'new.ts') {
                $insert->execute([$projectId, 'knossos.typescript:file:' . $path, $path, hash('sha256', $path)]);
            }
        }

        $unchanged = $planner->finalize($this->makePreparation($root, array_slice($files, 0, 2)));
        $added = $planner->finalize($this->makePreparation($root, $files));

        assertSame([], $unchanged->invalidatedOwners);
        $invalidated = $added->invalidatedOwners;
        ksort($invalidated, SORT_STRING);
        assertSame(['knossos.typescript:file:a.ts' => true, 'knossos.typescript:file:b.ts' => true], $invalidated);
    }

    /**
     * The TypeScript worker attributes its reads, but a file added to it may
     * declare globals any file uses, so an added file still rebuilds every
     * cached TypeScript file, while an edit rebuilds only what read it.
     */
    public function testAnAddedFileRebuildsEveryRowOfAScannerWhoseAddedFilesAffectAll(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/attributed-project';
        $projectId = StableId::project('root:' . $root);
        $pdo->prepare("INSERT INTO projects(id, name, root_realpath, active_scan_id, created_at, updated_at) VALUES (?, 'attributed', ?, 'scan-existing', 'now', 'now')")
            ->execute([$projectId, $root]);
        $insert = $pdo->prepare("INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at, read_attribution) VALUES (?, ?, ?, ?, 'knossos.typescript', '1.0', '', '{}', 'now', 1)");
        $files = [];
        foreach (['a.ts', 'b.ts', 'globals.ts'] as $path) {
            $files[] = new DiscoveredFile($path, $root . '/' . $path, 'typescript', 10, 0, hash('sha256', $path));
            if ($path !== 'globals.ts') {
                $insert->execute([$projectId, 'knossos.typescript:file:' . $path, $path, hash('sha256', $path)]);
            }
        }
        $edited = $files;
        $edited[0] = new DiscoveredFile('a.ts', $root . '/a.ts', 'typescript', 10, 0, hash('sha256', 'edited'));

        $invalidated = $planner->finalize($this->makePreparation($root, $files))->invalidatedOwners;
        ksort($invalidated, SORT_STRING);

        assertSame(['knossos.typescript:file:a.ts' => true, 'knossos.typescript:file:b.ts' => true], $invalidated);
        assertSame(['knossos.typescript:file:a.ts' => true], $planner->finalize($this->makePreparation($root, array_slice($edited, 0, 2)))->invalidatedOwners);
    }

    /**
     * A file the active scan recorded with the same bytes but could not cache
     * is not an added file, so it rebuilds nothing beyond itself. Recorded
     * with other bytes, it is a change the scanner cannot attribute.
     */
    public function testAFileWithoutACacheRowThatTheActiveScanRecordedIsNotAdded(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);
        $root = '/tmp/uncached-project';
        $projectId = StableId::project('root:' . $root);
        $pdo->prepare("INSERT INTO projects(id, name, root_realpath, active_scan_id, created_at, updated_at) VALUES (?, 'uncached', ?, 'scan-existing', 'now', 'now')")
            ->execute([$projectId, $root]);
        $insert = $pdo->prepare("INSERT INTO contribution_cache(project_id, owner_key, file_path, content_hash, scanner_id, scanner_version, configuration_hash, payload_json, updated_at, read_attribution) VALUES (?, ?, ?, ?, 'knossos.typescript', '1.0', '', '{}', 'now', 0)");
        $recorded = $pdo->prepare('INSERT INTO files(project_id, relative_path, content_hash) VALUES (?, ?, ?)');
        $files = [];
        foreach (['a.ts', 'uncached.ts'] as $path) {
            $files[] = new DiscoveredFile($path, $root . '/' . $path, 'typescript', 10, 0, hash('sha256', $path));
            $recorded->execute([$projectId, $path, hash('sha256', $path)]);
        }
        $insert->execute([$projectId, 'knossos.typescript:file:a.ts', 'a.ts', hash('sha256', 'a.ts')]);

        assertSame([], $planner->finalize($this->makePreparation($root, $files))->invalidatedOwners);

        $pdo->prepare("UPDATE files SET content_hash = ? WHERE relative_path = 'uncached.ts'")->execute([hash('sha256', 'before')]);

        assertSame(['knossos.typescript:file:a.ts' => true], $planner->finalize($this->makePreparation($root, $files))->invalidatedOwners);
    }

    public function testFinalizeReturnsEmptyCacheByDefault(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, ['/tmp']);

        $plan = $planner->finalize($this->makePreparation('/tmp/no-cache-project'));

        assertSame([], $plan->cacheByScannerPath);
        assertSame(0, $plan->deletedFiles);
        assertSame(true, $plan instanceof ScanPlan);
    }

    public function testPrepareRejectsInvalidMode(): void
    {
        $pdo = $this->createSchema();
        $dir = sys_get_temp_dir() . '/knossos-planner-mode-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $planner = new ScanPlanner($pdo, [sys_get_temp_dir()]);

            $error = captureThrows(
                static fn() => $planner->prepare($dir, null, null, null, 'bogus-mode', null, null),
                InvalidArgumentException::class,
            );

            assertSame('Scan mode must be auto, full, or incremental.', $error->getMessage());
        } finally {
            rmdir($dir);
        }
    }

    /**
     * `Cargo.toml` is a recorded unit (kind `cargo`) and must feed the Rust
     * configuration hash the same way `composer.json` feeds PHP's and
     * `package.json` feeds TypeScript's — editing it should invalidate a
     * cached Rust contribution.
     */
    public function testPrepareCargoManifestChangesTheRustConfigurationHash(): void
    {
        $pdo = $this->createSchema();
        $dir = sys_get_temp_dir() . '/knossos-planner-cargo-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $planner = new ScanPlanner($pdo, [sys_get_temp_dir()]);
            $withoutCargo = $planner->prepare($dir, null, null, null, null, null, null);

            file_put_contents($dir . '/Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
            $withCargo = $planner->prepare($dir, null, null, null, null, null, null);

            $this->assertNotSame(
                $withoutCargo->configurationHashes['rust'],
                $withCargo->configurationHashes['rust'],
            );
        } finally {
            @unlink($dir . '/Cargo.toml');
            rmdir($dir);
        }
    }

    /**
     * A Cargo dependency renamed with `package` states the real crate there
     * and uses the table key as a local alias. Framework detection matches the
     * real name, so recording only the alias left a crate that genuinely uses
     * actix scanned with its enrichment switched off.
     */
    public function testPrepareDetectsAFrameworkBehindARenamedCargoDependency(): void
    {
        $pdo = $this->createSchema();
        $dir = sys_get_temp_dir() . '/knossos-planner-rename-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            file_put_contents($dir . '/Cargo.toml', <<<'TOML'
[package]
name = "demo"
version = "0.1.0"
edition = "2021"

[dependencies]
web = { package = "actix-web", version = "4" }
TOML);
            $preparation = (new ScanPlanner($pdo, [sys_get_temp_dir()]))->prepare($dir, null, null, null, null, null, null);

            assertSame(['actix'], $preparation->rustFrameworks);
        } finally {
            @unlink($dir . '/Cargo.toml');
            rmdir($dir);
        }
    }

    /**
     * Python framework gating reads `requirements.txt` as well as
     * `pyproject.toml`, so the Python configuration hash has to cover both.
     * Hashing only `pyproject.toml` let an incremental scan reuse Python
     * contributions produced while framework enrichment was still switched
     * off, leaving route facts and classifications permanently stale.
     */
    public function testPrepareRequirementsFileChangesThePythonConfigurationHash(): void
    {
        $pdo = $this->createSchema();
        $dir = sys_get_temp_dir() . '/knossos-planner-requirements-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $planner = new ScanPlanner($pdo, [sys_get_temp_dir()]);
            $before = $planner->prepare($dir, null, null, null, null, null, null);
            assertSame([], $before->pythonFrameworks);

            file_put_contents($dir . '/requirements.txt', "fastapi==0.136.1
");
            $after = $planner->prepare($dir, null, null, null, null, null, null);

            assertSame(['fastapi'], $after->pythonFrameworks);
            $this->assertNotSame(
                $before->configurationHashes['python'],
                $after->configurationHashes['python'],
            );
        } finally {
            @unlink($dir . '/requirements.txt');
            rmdir($dir);
        }
    }

    /**
     * The configuration hash is of the project's units alone. The worker's own
     * files are keyed separately, so no hand-kept version string is in it.
     */
    public function testConfigurationHashIsOfTheMatchingUnitsOnly(): void
    {
        $pdo = $this->createSchema();
        $planner = new ScanPlanner($pdo, [sys_get_temp_dir()]);
        $method = new \ReflectionMethod(ScanPlanner::class, 'configurationHash');
        $unit = static fn(string $kind, string $path, string $hash): object => (object) ['kind' => $kind, 'configPath' => $path, 'contentHash' => $hash];
        $composer = $unit('composer', 'composer.json', 'aa');
        $other = $unit('node', 'package.json', 'bb');

        $hash = $method->invoke($planner, [$other, $composer], ['composer']);

        assertSame(hash('sha256', 'composer:composer.json=aa'), $hash);
        assertNotSame($hash, $method->invoke($planner, [$unit('composer', 'composer.json', 'cc')], ['composer']));
        assertSame($hash, $method->invoke($planner, [$composer, $other], ['composer']));
    }

    public function testPrepareRejectsSnapshotRetentionOutOfRange(): void
    {
        $pdo = $this->createSchema();
        $dir = sys_get_temp_dir() . '/knossos-planner-retention-' . bin2hex(random_bytes(6));
        mkdir($dir);
        try {
            $planner = new ScanPlanner($pdo, [sys_get_temp_dir()]);

            $error = captureThrows(
                static fn() => $planner->prepare($dir, null, null, null, null, 21, null),
                InvalidArgumentException::class,
            );

            assertSame('snapshot_retention must be between 0 and 20.', $error->getMessage());
        } finally {
            rmdir($dir);
        }
    }
}
