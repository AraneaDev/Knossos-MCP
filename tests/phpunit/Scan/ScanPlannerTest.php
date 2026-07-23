<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use InvalidArgumentException;
use Knossos\Configuration\ProjectConfiguration;
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
            updated_at TEXT NOT NULL
        )');
        return $pdo;
    }

    private function makePreparation(string $rootRealpath = '/tmp/foo'): ScanPreparation
    {
        return new ScanPreparation(
            configuration: new ProjectConfiguration(),
            discovery: new DiscoveryResult(
                rootRealpath: $rootRealpath,
                files: [],
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
