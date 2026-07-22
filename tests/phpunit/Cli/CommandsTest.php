<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\BundleCommand;
use Knossos\Cli\Command\MaintenanceCommand;
use Knossos\Cli\Command\MetaCommand;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Cli\Command\ScanCommand;
use Knossos\Cli\Command\ServeCommand;
use Knossos\Cli\Command\WatchCommand;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\StableId;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the 7 *Command.php implementations in
 * src/Cli/Command/. Strategy from the Batch 10b thinker:
 *
 *   A. Real classes + real :memory: context. The
 *      `CliCommandContext` constructed below uses the
 *      `RuntimeFactory::repositoryRoot()` + `:memory:` path that
 *      ALREADY succeeds for batches 1-9.
 *   C. Throw paths only for commands that (i) hit unmigrated
 *      tables (scan / query success arms) or (ii) block on
 *      STDIN/STDERR streams (serve / watch).
 *
 * Whatever is deferred in this batch stays covered indirectly through
 * the bin/knossos end-to-end script (documented in § 8 of the
 * close-out doc as "out of unit-test scope").
 *
 * Conventions match batches 1-10: bare global helpers from
 * `tests/phpunit/Support/Assertions.php` (assertSame / assertNotSame /
 * assertContains / assertArrayContains / assertThrows / captureThrows /
 * canonicalJsonValue); class-level `#[Group('cli-commands')]`. NO
 * `#[CoversClass]`. NO `assertTrue` (NOT in Support/Assertions.php).
 */
#[Group('cli-commands')]
final class CommandsTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    private function newContext(): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
    }

    // ===== MetaCommand ====================================================

    public function testMetaCommandSupportsVersionAndHelpVariants(): void
    {
        // M1 / MetaCommand::supports() match-arm: ['version', '--version',
        // 'help', '--help', '-h'] in_array.
        $cmd = new MetaCommand(new CliHelpRenderer(), '1.2.3');
        assertSame(true, $cmd->supports('version'));
        assertSame(true, $cmd->supports('--version'));
        assertSame(true, $cmd->supports('help'));
        assertSame(true, $cmd->supports('--help'));
        assertSame(true, $cmd->supports('-h'));
        assertSame(false, $cmd->supports('scan'));
    }

    public function testMetaCommandVersionReturnsZero(): void
    {
        // M2 / MetaCommand::run() success: 'version' arm fwrite the
        // version tuple to `output()` (uncapturable fwrite — batch 10
        // pattern). 'help' arm calls help->render() (smoke test). Both
        // return 0.
        $cmd = new MetaCommand(new CliHelpRenderer(), '1.2.3');
        assertSame(0, $cmd->run('version', [], [], $this->newContext()));
        assertSame(0, $cmd->run('help', [], [], $this->newContext()));
    }

    // ===== ScanCommand ====================================================

    public function testScanCommandSupportsOnlyScan(): void
    {
        // M3 / ScanCommand::supports() match-arm: `$command === 'scan'`.
        $cmd = new ScanCommand();
        assertSame(true, $cmd->supports('scan'));
        assertSame(false, $cmd->supports('version'));
        assertSame(false, $cmd->supports('maintain-database'));
    }

    public function testScanCommandMissingPathThrows(): void
    {
        // M4 / ScanCommand::run() throw: no positional[0] -> Usage throw.
        // Avoid exercising the happy path because ProjectScanService::scan
        // queries unmigrated tables.
        assertThrows(
            fn() => (new ScanCommand())->run('scan', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    // ===== WatchCommand ===================================================

    public function testWatchCommandSupportsOnlyWatch(): void
    {
        // M5 / WatchCommand::supports() match-arm: `$command === 'watch'`.
        $cmd = new WatchCommand();
        assertSame(true, $cmd->supports('watch'));
        assertSame(false, $cmd->supports('scan'));
    }

    public function testWatchCommandMissingPathThrows(): void
    {
        // M6 / WatchCommand::run() throw: no positional[0] -> Usage throw.
        // Avoid exercising the happy path because WatchService::run enters
        // a poll/debounce loop that does not terminate in unit-test scope.
        assertThrows(
            fn() => (new WatchCommand())->run('watch', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    // ===== BundleCommand ==================================================

    public function testBundleCommandSupportsExportAndImport(): void
    {
        // M7 / BundleCommand::supports() match-arm: ['export-bundle',
        // 'import-bundle'].
        $cmd = new BundleCommand();
        assertSame(true, $cmd->supports('export-bundle'));
        assertSame(true, $cmd->supports('import-bundle'));
        assertSame(false, $cmd->supports('scan'));
    }

    public function testBundleCommandExportRequiresOutputOption(): void
    {
        // M8 / BundleCommand::export() throw: positional[0] present, but
        // --output=FILE missing -> second throw.
        assertThrows(
            fn() => (new BundleCommand())->run(
                'export-bundle',
                ['proj-1'],
                [],
                $this->newContext(),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testBundleCommandImportRequiresFilePositional(): void
    {
        // M9 / BundleCommand::import() throw: positional[0] missing ->
        // Usage throw.
        assertThrows(
            fn() => (new BundleCommand())->run(
                'import-bundle',
                [],
                [],
                $this->newContext(),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testBundleCommandExportWithPopulatedDatabase(): void
    {
        // M9b / BundleCommand::export() happy path against a pre-populated
        // database. Exercises the export dispatch (Ternary), positional[0]
        // guard pass (IncrementInteger/Coalesce/Throw_ mutants), --output
        // guard pass (Coalesce mutant), GraphBundleService::export, and
        // fwrite + close + unlink-on-failure patterns.
        //
        // expectOutputRegex captures echo-based output from output(),
        // killing ArrayItemRemoval (M5), MethodCallRemoval (M6), and
        // text-formatting mutants. --json makes the output a JSON object
        // containing project_id so the regex kills M5 (ArrayItemRemoval).
        // Also verifies the written bundle is valid gzip+JSON.
        $this->expectOutputRegex('/"project_id":"[^"]*"/');
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        $outputPath = sys_get_temp_dir() . '/knossos-bundle-' . bin2hex(random_bytes(6)) . '.gxt';
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new BundleCommand();
            assertSame(0, $cmd->run('export-bundle', [$projectId], ['output' => [$outputPath], 'json' => [true]], $context));
            assertSame(true, is_file($outputPath), 'Bundle file was created');
            assertSame(true, filesize($outputPath) > 0, 'Bundle file is non-empty');

            // Decode and verify the bundle content.
            $raw = file_get_contents($outputPath);
            assertSame(true, is_string($raw) && $raw !== '', 'Bundle content is readable');
            $decoded = json_decode(gzdecode($raw), true);
            assertSame(true, is_array($decoded), 'Bundle is valid gzip+JSON');
            assertSame(true, isset($decoded['manifest']), 'Bundle has manifest');
            assertSame(true, isset($decoded['payload']), 'Bundle has payload');
            assertSame(true, isset($decoded['payload']['nodes']), 'Bundle has nodes');
            assertSame(true, count($decoded['payload']['nodes']) >= 1, 'Bundle contains at least one node');
        } finally {
            @unlink($outputPath);
            $cleanup();
        }
    }

    public function testBundleCommandImportExportedBundle(): void
    {
        // M9c / BundleCommand::import() happy path: export a bundle, then
        // import it back into a fresh :memory: database. Exercises the
        // import dispatch (Ternary), positional[0] guard pass
        // (Coalesce/IncrementInteger mutants), CliInputLoader::bundle(),
        // and GraphBundleService::import.
        //
        // expectOutputRegex captures echo-based output from output(),
        // killing Concat/ConcatOperandRemoval/MethodCallRemoval
        // mutants (M7-M12) on the output() call.
        $this->expectOutputRegex('/Imported \\d+ portable graph facts\.\nProject: bundle:/');
        [$dbPath, $exportProjectId, $dbCleanup] = $this->populatedTestDatabase();
        $bundlePath = sys_get_temp_dir() . '/knossos-bundle-' . bin2hex(random_bytes(6)) . '.gxt';
        $importDbPath = null;
        try {
            // Step 1: Export a bundle from the populated database.
            $exportContext = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            assertSame(0, (new BundleCommand())->run('export-bundle', [$exportProjectId], ['output' => [$bundlePath]], $exportContext));

            // Step 2: Import the bundle into a fresh file-based database.
            $importDbPath = sys_get_temp_dir() . '/knossos-import-' . bin2hex(random_bytes(6)) . '.sqlite';
            $importPdo = SqliteConnection::open($importDbPath);
            (new MigrationRunner($importPdo, self::repositoryRoot() . '/migrations'))->migrate();
            unset($importPdo);
            $importContext = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $importDbPath,
            );
            assertSame(0, (new BundleCommand())->run('import-bundle', [$bundlePath], [], $importContext));

            // Verify the imported project exists in the destination database.
            $verifyPdo = SqliteConnection::open($importDbPath);
            $stmt = $verifyPdo->query('SELECT count(*) FROM projects');
            $projectCount = $stmt !== false ? $stmt->fetchColumn() : 0;
            unset($verifyPdo);
            assertSame(true, $projectCount >= 1, 'Imported database should have at least one project');
        } finally {
            if ($importDbPath !== null) {
                @unlink($importDbPath);
            }
            @unlink($bundlePath);
            $dbCleanup();
        }
    }

    // ===== MaintenanceCommand =============================================

    public function testMaintenanceCommandSupportsFourCommands(): void
    {
        // M10 / MaintenanceCommand::supports() match-arm: ['doctor',
        // 'remove-project', 'cleanup-stale-scans', 'maintain-database']
        // in_array.
        $cmd = new MaintenanceCommand();
        assertSame(true, $cmd->supports('doctor'));
        assertSame(true, $cmd->supports('remove-project'));
        assertSame(true, $cmd->supports('cleanup-stale-scans'));
        assertSame(true, $cmd->supports('maintain-database'));
        assertSame(false, $cmd->supports('scan'));
    }

    public function testMaintenanceCommandMissingPositionalsThrow(): void
    {
        // M11 / MaintenanceCommand::run() throws for remove-project /
        // cleanup-stale-scans / maintain-database when positional[0]
        // missing.
        $cmd = new MaintenanceCommand();
        assertThrows(
            fn() => $cmd->run('remove-project', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => $cmd->run('cleanup-stale-scans', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
        assertThrows(
            fn() => $cmd->run('maintain-database', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    // ===== QueryCommand ===================================================

    public function testQueryCommandSupports21Commands(): void
    {
        // M12 / QueryCommand::supports() match-arm: COMMANDS const
        // contains 21 entries.
        $cmd = new QueryCommand();
        $supported = [
            'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
            'find-component', 'inspect-component', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
            'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
            'changed-files-impact', 'architecture-context', 'export-diagram', 'list-boundaries', 'search-architecture',
        ];
        assertSame(21, count($supported), 'Invariant: 21 names in QueryCommand COMMANDS');
        foreach ($supported as $name) {
            assertSame(true, $cmd->supports($name), $name . ' should be supported');
        }
        assertSame(false, $cmd->supports('scan'));
        assertSame(false, $cmd->supports('unknown-command'));
    }

    public function testQueryCommandSnapshotDiffThrows(): void
    {
        // M13 / QueryCommand::run() -- snapshotDiff() throw: missing <project-id>
        // -> Usage throw.
        assertThrows(
            fn() => (new QueryCommand())->run('snapshot-diff', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandFindComponentThrows(): void
    {
        // M14 / QueryCommand::run() -- findComponent() throw: missing <project-id>
        // AND/OR missing <name> -> Usage throw (first positional guard fires first).
        assertThrows(
            fn() => (new QueryCommand())->run('find-component', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandQualityGateThrows(): void
    {
        // M15 / QueryCommand::run() -- qualityGate() throw: missing <project-id>
        // -> Usage throw.
        assertThrows(
            fn() => (new QueryCommand())->run('quality-gate', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandExplainFlowRequiresFromAndTo(): void
    {
        // M16 / QueryCommand::run() -- explainFlow() throw: positional[0]
        // present but positional[1] (<from>) missing -> second throw.
        assertThrows(
            fn() => (new QueryCommand())->run(
                'explain-flow',
                ['proj-1'],
                [],
                $this->newContext(),
            ),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandListSnapshotsThrowsWithoutProject(): void
    {
        // M17 / QueryCommand::run() -- listSnapshots() throw: missing <project-id>.
        assertThrows(
            fn() => (new QueryCommand())->run('list-snapshots', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandArchitectureTrendsThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('architecture-trends', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandInspectComponentThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('inspect-component', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandArchitectureSummaryThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('architecture-summary', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandFileMetricsThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('file-metrics', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandDependencyCyclesThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('dependency-cycles', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandArchitectureHealthThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('architecture-health', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandCheckArchitectureThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('check-architecture', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandSuggestLocationThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('suggest-location', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandChangeImpactThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('change-impact', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandChangedFilesImpactThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('changed-files-impact', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandArchitectureContextThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('architecture-context', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandExportDiagramThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('export-diagram', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandListBoundariesThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('list-boundaries', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandSearchArchitectureThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('search-architecture', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandImpactAnalysisThrowsWithoutProject(): void
    {
        assertThrows(
            fn() => (new QueryCommand())->run('impact-analysis', [], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandFindComponentRequiresName(): void
    {
        // Mxx / QueryCommand::run() -- findComponent() second guard:
        // positional[0] (project-id) present but positional[1] (name) missing.
        assertThrows(
            fn() => (new QueryCommand())->run('find-component', ['proj-1'], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    // ===== ServeCommand ===================================================

    public function testServeCommandSupportsOnlyServe(): void
    {
        // M17 / ServeCommand::supports() match-arm: `$command === 'serve'`.
        $cmd = new ServeCommand();
        assertSame(true, $cmd->supports('serve'));
        assertSame(false, $cmd->supports('scan'));
    }

    public function testServeCommandThrowsWithoutAllowedRoots(): void
    {
        // M18 / ServeCommand::run() throw: no --allow-root options AND
        // KNOSSOS_ALLOWED_ROOTS env unset/empty -> final Usage throw.
        // putenv() to ensure the env var is cleared for this run (the
        // test inherits the user's shell env, which may carry
        // KNOSSOS_ALLOWED_ROOTS).
        $previous = getenv('KNOSSOS_ALLOWED_ROOTS');
        putenv('KNOSSOS_ALLOWED_ROOTS');
        try {
            assertThrows(
                fn() => (new ServeCommand())->run('serve', [], [], $this->newContext()),
                InvalidArgumentException::class,
            );
        } finally {
            if ($previous === false) {
                putenv('KNOSSOS_ALLOWED_ROOTS');
            } else {
                putenv('KNOSSOS_ALLOWED_ROOTS=' . $previous);
            }
        }
    }

    // ===== MaintenanceCommand: doctor =====================================

    public function testMaintenanceCommandDoctorRunsAgainstMemoryDb(): void
    {
        // M19 / MaintenanceCommand::run() -- doctor() arm. DoctorService::run()
        // wraps each check in try/catch, so the method completes even with
        // :memory: (where schema_migrations does not exist). We verify the
        // method returns without throwing and produces exit code 1 (because
        // some checks will fail on a fresh :memory: db).
        $cmd = new MaintenanceCommand();
        assertSame(1, $cmd->run('doctor', [], [], $this->newContext()));
    }

    public function testMaintenanceCommandRemoveProjectAgainstMemoryDb(): void
    {
        // M45 / MaintenanceCommand::run() -- removeProject() arm. Uses a
        // pre-populated database with a project; --execute flag removes it.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new MaintenanceCommand();
            assertSame(0, $cmd->run('remove-project', [$projectId], ['execute' => [true]], $context));
        } finally {
            $cleanup();
        }
    }

    public function testMaintenanceCommandMaintainDatabaseIntegrity(): void
    {
        // M46 / MaintenanceCommand::run() -- maintain() arm with 'integrity'
        // action. Exercises the match dispatch and maintain() method body.
        [$dbPath, , $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new MaintenanceCommand();
            assertSame(0, $cmd->run('maintain-database', ['integrity'], ['execute' => [true]], $context));
        } finally {
            $cleanup();
        }
    }

    public function testMaintenanceCommandCleanupStaleScansExecutes(): void
    {
        // M47 / MaintenanceCommand::run() -- cleanup() arm with --execute.
        // Uses a populated database and runs cleanup with a project ID.
        // Kills IncrementInteger (0→1) on $positionals[0]/[1] and
        // Coalesce mutants in the cleanup() method.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new MaintenanceCommand();
            assertSame(0, $cmd->run('cleanup-stale-scans', [$projectId], ['older-than-hours' => ['1'], 'execute' => [true]], $context));
        } finally {
            $cleanup();
        }
    }

    // ===== BundleCommand: import with missing file ========================

    public function testBundleCommandImportThrowsOnMissingFile(): void
    {
        // M20 / BundleCommand::import() -- positional[0] present but file
        // does not exist -> CliInputLoader::bundle() throws
        // InvalidArgumentException before reaching GraphBundleService.
        assertThrows(
            fn() => (new BundleCommand())->run(
                'import-bundle',
                ['/nonexistent-' . bin2hex(random_bytes(8)) . '.gxt'],
                [],
                $this->newContext(),
            ),
            InvalidArgumentException::class,
        );
    }

    // ===== Integration: QueryCommand with populated database ==============

    /**
     * Create a temp SQLite file, populate it with the mixed fixture via
     * GraphReconciler, and return [dbPath, projectId, cleanup].
     *
     * @return array{0: string, 1: string, 2: callable} [dbPath, projectId, cleanup]
     */
    private function populatedTestDatabase(): array
    {
        $dbPath = sys_get_temp_dir() . '/knossos-commands-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = SqliteConnection::open($dbPath);
        try {
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            [$fixturePdo, , $request] = $this->reconciliationFixture();
            unset($fixturePdo);
            $reconciler = new GraphReconciler(new SqliteGraphRepository($pdo));
            $result = $reconciler->reconcile($request);
            unset($pdo);
        } catch (\Throwable $error) {
            unset($pdo);
            @unlink($dbPath);
            throw $error;
        }

        return [$dbPath, $result->projectId, static function () use ($dbPath): void {
            @unlink($dbPath);
        }];
    }

    public function testQueryCommandListProjectsWithPopulatedDatabase(): void
    {
        // M21 / QueryCommand::run() -- listProjects() happy path against
        // a pre-populated SQLite file. Exercises the full dispatch through
        // ArchitectureQueryService::listProjects(), including the text
        // formatting loop in QueryCommand.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('list-projects', [], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandArchitectureSummaryWithPopulatedDatabase(): void
    {
        // M22 / QueryCommand::run() -- architectureSummary() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('architecture-summary', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandListSnapshotsWithPopulatedDatabase(): void
    {
        // M23 / QueryCommand::run() -- listSnapshots() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('list-snapshots', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandFileMetricsWithPopulatedDatabase(): void
    {
        // M24 / QueryCommand::run() -- fileMetrics() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('file-metrics', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandBoundariesWithPopulatedDatabase(): void
    {
        // M25 / QueryCommand::run() -- listBoundaries() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('list-boundaries', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandFindComponentWithPopulatedDatabase(): void
    {
        // M26 / QueryCommand::run() -- findComponent() happy path.
        // The mixed fixture contains nodes matching "CheckoutService".
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('find-component', [$projectId, 'CheckoutService'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandExportDiagramWithPopulatedDatabase(): void
    {
        // M27 / QueryCommand::run() -- exportDiagram() happy path with 3 nodes.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('export-diagram', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandSearchArchitectureWithPopulatedDatabase(): void
    {
        // M28 / QueryCommand::run() -- searchArchitecture() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('search-architecture', [$projectId, 'Checkout'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandInspectComponentWithPopulatedDatabase(): void
    {
        // M29 / QueryCommand::run() -- inspectComponent() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('inspect-component', [$projectId, 'Checkout'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandDependencyCyclesWithPopulatedDatabase(): void
    {
        // M30 / QueryCommand::run() -- dependencyCycles() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('dependency-cycles', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandArchitectureHealthWithPopulatedDatabase(): void
    {
        // M31 / QueryCommand::run() -- architectureHealth() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('architecture-health', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandArchitectureTrendsWithPopulatedDatabase(): void
    {
        // M32 / QueryCommand::run() -- architectureTrends() happy path.
        // The fixture has only 1 snapshot, so trends will be empty.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('architecture-trends', [$projectId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandSuggestLocationWithPopulatedDatabase(): void
    {
        // M33 / QueryCommand::run() -- suggestLocation() happy path.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('suggest-location', [$projectId, 'payment processing'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandSnapshotDiffRequiresTwoPositionals(): void
    {
        // M34 / QueryCommand::run() -- snapshotDiff() guard: positional[0]
        // present but positional[1] (from-snapshot) missing -> Usage throw.
        // (The fixture only has 1 snapshot so a happy-path diff is impossible.)
        assertThrows(
            fn() => (new QueryCommand())->run('snapshot-diff', ['proj-1'], [], $this->newContext()),
            InvalidArgumentException::class,
        );
    }

    public function testQueryCommandExplainFlowWithPopulatedDatabase(): void
    {
        // M36 / QueryCommand::run() -- explainFlow() happy path.
        // The fixture has nodes connected via edges — trace flow between them.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('explain-flow', [$projectId, 'Fixture\\CheckoutService', 'Fixture\\Vendor\\Missing'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandImpactAnalysisWithPopulatedDatabase(): void
    {
        // M37 / QueryCommand::run() -- impactAnalysis() happy path.
        [$actualDbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $actualDbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('impact-analysis', [$projectId, 'Fixture\\CheckoutService'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandChangeImpactWithPopulatedDatabase(): void
    {
        // M39 / QueryCommand::run() -- changeImpact() happy path.
        // Uses ProcessGitHistoryProvider — requires the project root to be a git repo.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('change-impact', [$projectId, 'Fixture\\CheckoutService'], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandArchitectureContextWithPopulatedDatabase(): void
    {
        // M41 / QueryCommand::run() -- architectureContext() happy path.
        // Provide --task=TEXT since empty task with no files is rejected.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('architecture-context', [$projectId], ['task' => ['understand payment flow']], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandCheckArchitectureRequiresExistingBoundary(): void
    {
        // M38 / QueryCommand::run() -- checkArchitecture() guard: the fixture
        // has 0 boundaries so any policy from_boundary throws. This test
        // passes a valid policy file to exercise the policy-parsing code path
        // (CliInputLoader::policies) before the boundary-resolution check.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        $policiesFile = sys_get_temp_dir() . '/knossos-policies-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($policiesFile, json_encode([
            ['id' => 'pol1', 'from_boundary' => 'nonexistent-boundary', 'allow_targets' => ['@unassigned']],
        ]));
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertThrows(
                fn() => $cmd->run('check-architecture', [$projectId], ['policies' => [$policiesFile]], $context),
                InvalidArgumentException::class,
            );
        } finally {
            @unlink($policiesFile);
            $cleanup();
        }
    }

    public function testQueryCommandChangedFilesImpactWithPopulatedDatabase(): void
    {
        // M40 / QueryCommand::run() -- changedFilesImpact() happy path.
        // Uses ProcessGitWorkingTreeProvider — requires --working-tree flag.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('changed-files-impact', [$projectId], ['working-tree' => [true]], $context));
        } finally {
            $cleanup();
        }
    }

    // ===== Multi-snapshot fixture with boundaries =========================

    /**
     * Build on populatedTestDatabase() by:
     * 1. Archiving the active scan into scan_snapshots (retaining its 3-node,
     *    2-edge state).
     * 2. Adding an explicit boundary + membership to the data tables.
     * 3. Creating and completing a 2nd scan (making it the new active scan).
     *
     * After this, the db has 2 snapshots: an archived scan (without boundary)
     * and an active scan (with boundary), enabling snapshot-diff and
     * quality-gate on differing snapshots, plus check-architecture with a
     * real boundary.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: callable}
     *         [dbPath, projectId, boundaryId, archivedScanId, cleanup]
     */
    private function richPopulatedTestDatabase(): array
    {
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();

        $pdo = SqliteConnection::open($dbPath);
        try {
            // Fetch a node ID from the existing data to attach the boundary.
            $nodeStmt = $pdo->prepare('SELECT id FROM nodes WHERE project_id = :project LIMIT 1');
            $nodeStmt->execute(['project' => $projectId]);
            $nodeId = $nodeStmt->fetchColumn();
            if (!is_string($nodeId)) {
                throw new \RuntimeException('No node found in populated database.');
            }

            $repository = new SqliteGraphRepository($pdo);
            $configHash = hash('sha256', '{}');

            // Step 1: Archive the current (1st) snapshot so snapshotDiff
            // has a retained snapshot to read.
            $repository->archiveActiveSnapshot($projectId, $configHash, 5);

            // Step 2: Create a 2nd scan BEFORE saving boundaries/memberships
            // (which FK-reference scans via last_scan_id).
            $scan2Id = StableId::scan($projectId, bin2hex(random_bytes(16)));
            $repository->createScan($scan2Id, $projectId, 'full', hash('sha256', 'scanner-set-v2'));

            // Step 3: Add an explicit boundary + membership to the data
            // tables (the archived snapshot does NOT have this boundary).
            $boundaryId = StableId::boundary($projectId, 'Checkout', 'explicit');
            $repository->saveBoundary(
                $boundaryId,
                $projectId,
                'Checkout',
                ['path_prefix' => 'src/Checkout'],
                'explicit',
                $scan2Id,
            );
            $repository->saveBoundaryMembership($boundaryId, $projectId, $nodeId, $scan2Id);

            // Step 4: Complete the 2nd scan so it becomes active.
            $repository->completeScan($projectId, $scan2Id);

            unset($pdo);

            // Find the archived scan ID from the snapshots table.
            $snapshotPdo = SqliteConnection::open($dbPath);
            $archStmt = $snapshotPdo->prepare('SELECT scan_id FROM scan_snapshots WHERE project_id = :project LIMIT 1');
            $archStmt->execute(['project' => $projectId]);
            $archivedScanId = $archStmt->fetchColumn();
            unset($snapshotPdo);

            if (!is_string($archivedScanId)) {
                throw new \RuntimeException('No snapshot found after archiving.');
            }

            $combinedCleanup = static function () use ($cleanup): void {
                $cleanup();
            };

            return [$dbPath, $projectId, $boundaryId, $archivedScanId, $combinedCleanup];
        } catch (\Throwable $error) {
            unset($pdo);
            $cleanup();
            throw $error;
        }
    }

    public function testQueryCommandSnapshotDiffWithRichDatabase(): void
    {
        // M42 / QueryCommand::run() -- snapshotDiff() happy path with 2
        // different snapshots (archived scan1 ≠ active scan2).
        [$dbPath, $projectId, , $archivedScanId, $cleanup] = $this->richPopulatedTestDatabase();
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('snapshot-diff', [$projectId, $archivedScanId], [], $context));
        } finally {
            $cleanup();
        }
    }

    public function testQueryCommandQualityGateWithRichDatabase(): void
    {
        // M43 / QueryCommand::run() -- qualityGate() happy path.
        // Uses the archived scan as baseline (which differs from active).
        [$dbPath, $projectId, , $archivedScanId, $cleanup] = $this->richPopulatedTestDatabase();
        $budgetsFile = sys_get_temp_dir() . '/knossos-budgets-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($budgetsFile, json_encode([
            'error_diagnostics' => 100,
            'warning_diagnostics' => 100,
        ]));
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('quality-gate', [$projectId, $archivedScanId], ['budgets' => [$budgetsFile]], $context));
        } finally {
            @unlink($budgetsFile);
            $cleanup();
        }
    }

    public function testQueryCommandCheckArchitectureWithRichDatabase(): void
    {
        // M44 / QueryCommand::run() -- checkArchitecture() happy path.
        // The rich fixture has an explicit 'Checkout' boundary. The policy
        // references this boundary.
        [$dbPath, $projectId, $boundaryId, , $cleanup] = $this->richPopulatedTestDatabase();
        $policiesFile = sys_get_temp_dir() . '/knossos-policies-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($policiesFile, json_encode([
            ['id' => 'pol1', 'from_boundary' => $boundaryId, 'allow_targets' => ['@unassigned']],
        ]));
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertSame(0, $cmd->run('check-architecture', [$projectId], ['policies' => [$policiesFile]], $context));
        } finally {
            @unlink($policiesFile);
            $cleanup();
        }
    }

    // ===== ToolService / ArchitecturePolicyQueryService validation guards ===

    public function testCheckArchitectureRejectsEmptyPolicies(): void
    {
        // ArchitecturePolicyQueryService::checkArchitecture() guard:
        // policies must be a non-empty list with at most 50 declarations.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        $policiesFile = sys_get_temp_dir() . '/knossos-policies-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($policiesFile, json_encode([]));
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertThrows(
                fn() => $cmd->run('check-architecture', [$projectId], ['policies' => [$policiesFile]], $context),
                InvalidArgumentException::class,
            );
        } finally {
            @unlink($policiesFile);
            $cleanup();
        }
    }

    public function testCheckArchitectureRejectsPolicyNonUniqueIds(): void
    {
        // ArchitecturePolicyQueryService::checkArchitecture() guard:
        // policy ids must be unique.
        [$dbPath, $projectId, $cleanup] = $this->populatedTestDatabase();
        $policiesFile = sys_get_temp_dir() . '/knossos-policies-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($policiesFile, json_encode([
            ['id' => 'dup', 'from_boundary' => '@unassigned', 'allow_targets' => ['@unassigned']],
            ['id' => 'dup', 'from_boundary' => '@unassigned', 'allow_targets' => ['@unassigned']],
        ]));
        try {
            $context = new CliCommandContext(
                new CliOptionParser(),
                new CliInputLoader(),
                new RuntimeFactory(self::repositoryRoot()),
                $dbPath,
            );
            $cmd = new QueryCommand();
            assertThrows(
                fn() => $cmd->run('check-architecture', [$projectId], ['policies' => [$policiesFile]], $context),
                InvalidArgumentException::class,
            );
        } finally {
            @unlink($policiesFile);
            $cleanup();
        }
    }
}
