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
            'shop/contracts.pyi',
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

    public function freshTestDatabase(): PDO
    {
        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        return $pdo;
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
        return [$pdo, $result->projectId, $root];
    }

    /** @return array{0: ToolService, 1: string, 2: string} [tools, projectId, absoluteRoot] */
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
        return [$tools, $projectId, $root];
    }
}
