<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Support;

use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use PDO;

trait Fixtures
{
    public function typescriptFixtureFiles(): array
    {
        return [
            'packages/shared/src/contracts.ts',
            'packages/app/src/service.ts',
            'packages/app/src/index.ts',
            'packages/app/src/view.tsx',
            'packages/app/src/legacy.cjs',
            'packages/app/src/noexecute.cjs',
            'packages/app/src/invalid.ts',
        ];
    }

    /** @return list<string> */
    public function pythonFixtureFiles(): array
    {
        return [
            'shop/__init__.py',
            'shop/api.py',
            'shop/bad.py',
            'shop/cli.py',
            'shop/contracts.pyi',
            'shop/guarded.py',
            'shop/service.py',
        ];
    }

    /** @return array{0: PDO, 1: GraphReconciler, 2: FullScanRequest} */
    public function reconciliationFixture(): array
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
        $phpEvidence = new Evidence('src/CheckoutService.php', 7, 9);
        $typescriptEvidence = new Evidence('frontend/src/index.ts', 1, 3);

        $php = new ScanContribution(
            'knossos.php:file:src/CheckoutService.php',
            [new NodeFact(
                'php:class:Fixture\\CheckoutService',
                'class',
                'Fixture\\CheckoutService',
                'CheckoutService',
                Origin::Ast,
                Confidence::Certain,
                $phpEvidence,
            )],
            [new EdgeFact(
                'references',
                'php:class:Fixture\\CheckoutService',
                'php:class:Vendor\\Missing',
                Origin::Ast,
                Confidence::Certain,
                $phpEvidence,
            )],
            [new Diagnostic('warning', 'PHP_DYNAMIC_REFERENCE', 'A dynamic reference was skipped.', $phpEvidence)],
        );
        $typescript = new ScanContribution(
            'knossos.typescript:file:frontend/src/index.ts',
            [new NodeFact(
                'ts:class:frontend/src/index.ts#CheckoutService',
                'class',
                'frontend/src/index.ts#CheckoutService',
                'CheckoutService',
                Origin::Ast,
                Confidence::Certain,
                $typescriptEvidence,
            )],
            [new EdgeFact(
                'depends_on',
                'ts:class:frontend/src/index.ts#CheckoutService',
                'php:class:Fixture\\CheckoutService',
                Origin::Derived,
                Confidence::Probable,
                $typescriptEvidence,
            )],
        );
        $scanners = [
            new ScannerManifest('knossos.php', '0.1.0', '1.0', '1.0', ['php'], ['php'], []),
            new ScannerManifest(
                'knossos.typescript',
                '0.1.0',
                '1.0',
                '1.0',
                ['typescript', 'javascript'],
                ['ts', 'js'],
                [],
            ),
        ];

        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $reconciler = new GraphReconciler(new SqliteGraphRepository($pdo));
        $request = new FullScanRequest('mixed-fixture', 'Mixed Fixture', $discovery, $scanners, [$php, $typescript]);

        return [$pdo, $reconciler, $request];
    }

    /**
     * @return array{0: PDO, 1: SqliteGraphRepository, 2: array<string, string>}
     */
    public function storeFixture(?string $migrationDirectory = null): array
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, $migrationDirectory ?? self::repositoryRoot() . '/migrations'))->migrate();
        $repository = new SqliteGraphRepository($pdo);

        $project = StableId::project('fixture-shop');
        $scan = StableId::scan($project, 'scan-1');
        $file = StableId::file($project, 'src/Checkout.php');
        $checkout = StableId::symbol($project, 'php', 'class', 'App\\Checkout');
        $invoice = StableId::symbol($project, 'php', 'class', 'App\\InvoiceService');
        $edge = StableId::edge($project, 'calls', $checkout, $invoice, 'src/Checkout.php:12');

        $repository->saveProject($project, 'Fixture Shop', '/workspace/fixture-shop');
        $repository->createScan($scan, $project, 'full', hash('sha256', 'scanner-set'));
        $repository->saveFile(
            $file,
            $project,
            'src/Checkout.php',
            hash('sha256', 'fixture source'),
            100,
            1,
            'php',
            '0.1.0',
            $scan,
        );
        $repository->saveNode(
            $checkout,
            $project,
            'php',
            'class',
            'App\\Checkout',
            'Checkout',
            null,
            $file,
            3,
            18,
            'ast',
            'certain',
            [],
            'php:file:src/Checkout.php',
            $scan,
        );
        $repository->saveNode(
            $invoice,
            $project,
            'php',
            'class',
            'App\\InvoiceService',
            'InvoiceService',
            null,
            $file,
            21,
            35,
            'ast',
            'certain',
            [],
            'php:file:src/InvoiceService.php',
            $scan,
        );
        $repository->saveEdge(
            $edge,
            $project,
            'calls',
            $checkout,
            $invoice,
            $file,
            12,
            12,
            'ast',
            'certain',
            [],
            'php:file:src/Checkout.php',
            $scan,
        );

        return [$pdo, $repository, compact('project', 'scan', 'file', 'checkout', 'invoice', 'edge')];
    }

    public function graphSignature(PDO $pdo): string
    {
        $queries = [
            'nodes' => 'SELECT n.id, n.kind, n.canonical_name, n.display_name, f.relative_path, n.start_line, n.end_line, n.origin, n.confidence, n.attributes_json, n.owner_key FROM nodes n LEFT JOIN files f ON f.id = n.file_id ORDER BY n.id',
            'edges' => 'SELECT e.id, e.kind, s.canonical_name source_name, t.canonical_name target_name, f.relative_path, e.start_line, e.end_line, e.origin, e.confidence, e.attributes_json, e.owner_key FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id LEFT JOIN files f ON f.id = e.file_id ORDER BY e.id',
            'classifications' => 'SELECT c.id, n.canonical_name, c.role, c.origin, c.confidence, c.rule_id, c.attributes_json FROM classifications c JOIN nodes n ON n.id = c.node_id ORDER BY c.id',
            'boundaries' => 'SELECT id, name, matcher_json, source FROM boundaries ORDER BY id',
            'memberships' => 'SELECT b.name, n.canonical_name FROM boundary_memberships bm JOIN boundaries b ON b.id = bm.boundary_id JOIN nodes n ON n.id = bm.node_id ORDER BY b.name, n.canonical_name',
            'diagnostics' => 'SELECT severity, code, message, start_line, end_line, owner_key FROM diagnostics ORDER BY id',
        ];
        $graph = [];
        foreach ($queries as $name => $sql) {
            $graph[$name] = $pdo->query($sql)->fetchAll();
        }
        return json_encode($graph, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A project whose graph is large enough that a row-by-row bound is observable.
     *
     * Seeds `$count + 1` chained nodes and `$count` `depends_on` edges on top of
     * storeFixture(), so a traversal that streams its rows can be told apart
     * from one that materialises them: the two produce the same envelope, and
     * only the number of rows actually read separates them.
     *
     * @return array{0: PDO, 1: string} the connection and the project id
     */
    public function seedGraphWithEdges(int $count): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $pdo->beginTransaction();
        $previous = null;
        for ($index = 0; $index <= $count; ++$index) {
            $name = sprintf('App\\Chain%05d', $index);
            $node = StableId::symbol($ids['project'], 'php', 'class', $name);
            $repository->saveNode(
                $node,
                $ids['project'],
                'php',
                'class',
                $name,
                sprintf('Chain%05d', $index),
                null,
                $ids['file'],
                $index + 1,
                $index + 2,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
            if ($previous !== null) {
                $repository->saveEdge(
                    StableId::edge($ids['project'], 'depends_on', $previous, $node, (string) $index),
                    $ids['project'],
                    'depends_on',
                    $previous,
                    $node,
                    $ids['file'],
                    $index + 1,
                    $index + 1,
                    'ast',
                    'certain',
                    [],
                    'php:file:src/Checkout.php',
                    $ids['scan'],
                );
            }
            $previous = $node;
        }
        $pdo->commit();
        $repository->completeScan($ids['project'], $ids['scan']);

        return [$pdo, $ids['project']];
    }

    public function freshTestDatabase(): PDO
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        return $pdo;
    }

    /**
     * Builds a real temp-directory project for StalenessProbe tests that need
     * to mutate the filesystem (delete, add, or rewind an mtime) independently
     * of the `files` row that was scanned, rather than running the actual
     * scanners the way scanTempFixture() does.
     *
     * Writes each path under a fresh `knossos-stale-` temp root (the prefix
     * removeTempTree() requires), inserts a `projects` row pointing at it, one
     * `files` row per path stamped with that file's current on-disk mtime, and
     * a completed `scans` row. Sets $this->pdo and $this->projectId on the
     * calling test so its assertions can construct a StalenessProbe directly
     * against the same database; the caller must declare both as `protected`
     * properties (protected, not private, because this method is compiled
     * into KnossosTestCase by the trait, one level up from the declaring
     * test class).
     *
     * @param list<string> $relativePaths
     * @return string the temp root, for the caller to mutate and to pass to removeTempTree()
     */
    public function seedProjectWithFiles(array $relativePaths): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        foreach ($relativePaths as $relativePath) {
            $absolute = $root . '/' . $relativePath;
            $directory = dirname($absolute);
            if (!is_dir($directory)) {
                mkdir($directory, 0o777, true);
            }
            file_put_contents($absolute, "<?php\n");
        }

        $this->pdo = $this->freshTestDatabase();
        $this->projectId = StableId::project('stale-probe-' . bin2hex(random_bytes(6)));
        $scanId = StableId::scan($this->projectId, 'scan-1');
        $repository = new SqliteGraphRepository($this->pdo);
        $repository->saveProject($this->projectId, 'Stale Probe Fixture', $root);
        $repository->createScan($scanId, $this->projectId, 'full', hash('sha256', 'stale-probe'));
        foreach ($relativePaths as $relativePath) {
            $absolute = $root . '/' . $relativePath;
            $repository->saveFile(
                StableId::file($this->projectId, $relativePath),
                $this->projectId,
                $relativePath,
                hash('sha256', (string) file_get_contents($absolute)),
                (int) filesize($absolute),
                (int) filemtime($absolute),
                'php',
                '0.1.0',
                $scanId,
            );
        }
        $repository->completeScan($this->projectId, $scanId);
        // Backdate finished_at a few seconds into the past: completeScan()
        // stamps it with second resolution, and a test that seeds a project
        // then immediately mutates the tree can otherwise land in the same
        // wall-clock second, making a strictly-later directory mtime
        // indistinguishable from "no change" for addedSince().
        self::backdateScanFinishedAt($this->pdo, $scanId);

        return $root;
    }

    /** @return array{0: PDO, 1: string, 2: string} [pdo, projectId, absoluteRoot] */
    public function scanTempFixture(string $fixture): array
    {
        $src = self::repositoryRoot() . '/tests/Fixtures/' . $fixture;
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        // Recursively copy the fixture so mtimes can be mutated safely.
        $this->copyTree($src, $root);
        $pdo = $this->freshTestDatabase();
        $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        // See TempTrees::backdateDirectories(): without this, a caller that
        // probes staleness right after scanning (with no mutation at all) can
        // intermittently see 'stale', because the directories copyTree() just
        // created can read as being at or after the scan's own finished_at.
        $this->backdateDirectories($root, 10);
        return [$pdo, $result->projectId, $root];
    }

    /** Backdates a scan's finished_at by 5 seconds; used by seedProjectWithFiles() to give a later mutation headroom against StalenessProbe::addedSince()'s directory-mtime comparison. */
    private static function backdateScanFinishedAt(PDO $pdo, string $scanId): void
    {
        $pdo->prepare('UPDATE scans SET finished_at = :finished WHERE id = :id')
            ->execute(['finished' => gmdate('Y-m-d\TH:i:s\Z', time() - 5), 'id' => $scanId]);
    }

    /** @return array{0: ToolService, 1: string, 2: string, 3: PDO} [tools, projectId, absoluteRoot, pdo] */
    public function buildToolServiceWithScan(string $fixture): array
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture($fixture);
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(
                new \Knossos\Query\StalenessProbe($pdo),
                new \Knossos\Mcp\NextStepPlanner(),
            ),
        );
        return [$tools, $projectId, $root, $pdo];
    }

    /**
     * Builds a ToolService wired the same way as McpTest's dispatch tests, but
     * scans tests/Fixtures/mixed directly via the scan_project tool call
     * (rather than the ProjectScanService::scan() shortcut used by
     * buildToolServiceWithScan()) so callers exercise the real dispatch path
     * end to end. Shared by envelope/shape regression tests (see
     * EnvelopeBudgetTest) that need a scanned project_id without duplicating
     * this wiring per test class.
     *
     * @return array{0: ToolService, 1: string} [tools, projectId]
     */
    public function toolServiceWithScannedFixture(): array
    {
        $pdo = $this->freshTestDatabase();
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [$root]),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $scanned = $tools->call('scan_project', ['path' => $root]);

        return [$tools, $scanned->projectId];
    }

    /**
     * Two-scan diff fixture for snapshot_diff budget/ordering tests. Builds on
     * storeFixture()'s single-scan project, then layers a second "diff-next"
     * scan with deliberately lopsided fact counts:
     *
     *  - 8 added components (structural, first in $tableMap either way -- not
     *    itself proof of the reorder)
     *  - 20 added boundaries (structural, moved ahead of relationships/roles
     *    by the Task 6 reorder)
     *  - 10 added relationships/edges (non-structural, used to come before
     *    boundaries in $tableMap)
     *  - 5 added diagnostics (non-structural, last in $tableMap either way)
     *
     * Components(8) + boundaries(20) = 28, already past the default 25-change
     * budget, so boundaries only partially fits and relationships/diagnostics
     * are fully starved. Under the OLD table order (relationships before
     * boundaries), relationships would have consumed that remaining budget
     * instead of boundaries -- so a test asserting boundaries got the budget
     * and relationships didn't is sensitive to the actual reorder, not just
     * to components already being first.
     *
     * Wires a ToolService over the same PDO so callers can exercise
     * snapshot_diff through the MCP dispatch path (ToolService::call()) rather
     * than the query service directly.
     *
     * @return array{0: ToolService, 1: string, 2: string} [tools, projectId, fromSnapshotId]
     */
    public function twoSnapshotFixture(): array
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $repository->completeScan($ids['project'], $ids['scan']);
        $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
        $next = StableId::scan($ids['project'], 'diff-next');
        $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-next'));

        for ($i = 1; $i <= 8; $i++) {
            $node = StableId::symbol($ids['project'], 'php', 'class', "App\\Extra{$i}");
            $repository->saveNode(
                $node,
                $ids['project'],
                'php',
                'class',
                "App\\Extra{$i}",
                "Extra{$i}",
                null,
                $ids['file'],
                40 + $i,
                45 + $i,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $next,
            );
        }

        for ($i = 1; $i <= 20; $i++) {
            $boundary = StableId::boundary($ids['project'], "Boundary{$i}", 'explicit');
            $repository->saveBoundary($boundary, $ids['project'], "Boundary{$i}", ['path_prefix' => "src/Boundary{$i}"], 'explicit', $next);
        }

        for ($i = 1; $i <= 10; $i++) {
            $edge = StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], "extra-edge-{$i}");
            $repository->saveEdge(
                $edge,
                $ids['project'],
                'calls',
                $ids['checkout'],
                $ids['invoice'],
                $ids['file'],
                50 + $i,
                50 + $i,
                'ast',
                'certain',
                [],
                "php:file:src/Checkout.php#extra-{$i}",
                $next,
            );
        }

        for ($i = 1; $i <= 5; $i++) {
            $repository->saveDiagnostic(
                hash('sha256', $ids['project'] . ':extra-diagnostic:' . $i),
                $ids['project'],
                $next,
                $ids['file'],
                'warning',
                'EXTRA_DIAGNOSTIC',
                "Extra diagnostic {$i}.",
                1,
                1,
                'php:file:src/Checkout.php',
            );
        }

        $repository->completeScan($ids['project'], $next);

        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), []),
            new ArchitectureQueryService($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );

        return [$tools, $ids['project'], $ids['scan']];
    }
}
