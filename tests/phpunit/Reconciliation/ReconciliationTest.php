<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use InvalidArgumentException;
use Knossos\Bundle\GraphBundleService;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Reconciliation\ReconciliationException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class ReconciliationTest extends KnossosTestCase
{
    #[Group('reconciliation')]
    public function testReconcilerAssemblesMixedScannerFactsAndResolvesExactReferences(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $result = $reconciler->reconcile($request);

        assertSame(3, $result->files);
        assertSame(3, $result->nodes);
        assertSame(2, $result->edges);
        assertSame(1, $result->unresolvedNodes);
        assertSame(1, $result->diagnostics);

        $repository = new SqliteGraphRepository($pdo);
        assertSame(2, count($repository->findNodesByName($result->projectId, 'CheckoutService')));

        $crossLanguage = $pdo->query(
            'SELECT e.kind, source.attributes_json AS source_attributes, target.attributes_json AS target_attributes ' .
            'FROM edges e JOIN nodes source ON source.id = e.source_id JOIN nodes target ON target.id = e.target_id ' .
            "WHERE e.kind = 'depends_on'",
        )->fetch();
        assertContains('knossos.typescript', $crossLanguage['source_attributes']);
        assertContains('knossos.php', $crossLanguage['target_attributes']);

        $external = $pdo->query("SELECT * FROM nodes WHERE kind = 'external_class'")->fetch();
        assertSame('Vendor\\Missing', $external['canonical_name']);
        assertContains('"unresolved":true', $external['attributes_json']);
        assertSame('2', (string) $pdo->query(
            "SELECT COUNT(*) FROM nodes WHERE origin <> 'derived' AND file_id IS NOT NULL AND attributes_json LIKE '%scanner%'",
        )->fetchColumn());
    }

    #[Group('reconciliation')]
    public function testReconciliationSnapshotsAndBundlesPreserveRepeatedEdgeOccurrences(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
        $path = 'src/CheckoutService.php';
        $owner = 'knossos.php:file:' . $path;
        $nodeEvidence = new Evidence($path, 1, 1);
        $nodes = [
            new NodeFact('php:method:Fixture\\CheckoutService::run', 'method', 'Fixture\\CheckoutService::run', 'run', Origin::Ast, Confidence::Certain, $nodeEvidence),
            new NodeFact('php:method:Fixture\\CheckoutService::load', 'method', 'Fixture\\CheckoutService::load', 'load', Origin::Ast, Confidence::Certain, $nodeEvidence),
            new NodeFact('php:class:Fixture\\Order', 'class', 'Fixture\\Order', 'Order', Origin::Ast, Confidence::Certain, $nodeEvidence),
            new NodeFact('php:module:Fixture\\Contracts', 'module', 'Fixture\\Contracts', 'Contracts', Origin::Ast, Confidence::Certain, $nodeEvidence),
        ];
        $edges = [];
        foreach ([
            ['calls', 'php:method:Fixture\\CheckoutService::load', 10],
            ['calls', 'php:method:Fixture\\CheckoutService::load', 11],
            ['constructs', 'php:class:Fixture\\Order', 12],
            ['constructs', 'php:class:Fixture\\Order', 13],
            ['imports', 'php:module:Fixture\\Contracts', 14],
            ['imports', 'php:module:Fixture\\Contracts', 15],
        ] as [$kind, $target, $line]) {
            $edges[] = new EdgeFact(
                $kind,
                'php:method:Fixture\\CheckoutService::run',
                $target,
                Origin::Ast,
                Confidence::Certain,
                new Evidence($path, $line, $line),
            );
        }
        $contribution = new ScanContribution($owner, $nodes, $edges);
        $scanner = new ScannerManifest('knossos.php', '0.1.0', '1.0', '1.0', ['php'], ['php'], []);
        $request = new FullScanRequest(
            'repeated-edge-occurrences',
            'Repeated edge occurrences',
            $discovery,
            [$scanner],
            [$contribution],
        );

        $pdo = SqliteConnection::open(':memory:');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $reconciler = new GraphReconciler(new SqliteGraphRepository($pdo));
        $first = $reconciler->reconcile($request);
        assertSame(6, $first->edges);
        assertSame(6, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        assertSame(6, (int) $pdo->query('SELECT COUNT(DISTINCT id) FROM edges')->fetchColumn());
        foreach (['calls', 'constructs', 'imports'] as $kind) {
            assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = '" . $kind . "'")->fetchColumn());
        }

        $second = $reconciler->reconcile($request);
        $snapshotJson = (string) $pdo->query(
            "SELECT payload_json FROM scan_snapshots WHERE scan_id = '" . $first->scanId . "'",
        )->fetchColumn();
        $snapshot = json_decode($snapshotJson, true, 512, JSON_THROW_ON_ERROR);
        assertSame(6, count($snapshot['facts']['edges']));

        $bundles = new GraphBundleService($pdo);
        $bundle = $bundles->export($second->projectId);
        $imported = $bundles->import($bundle, 'Repeated edge import');
        $importedEdges = $pdo->prepare('SELECT COUNT(*) FROM edges WHERE project_id = :project');
        $importedEdges->execute(['project' => $imported->projectId]);
        assertSame(6, (int) $importedEdges->fetchColumn());
    }

    #[Group('reconciliation')]
    public function testFullReconciliationIsStableAndActivatesSnapshotsAtomically(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $first = $reconciler->reconcile($request);
        $firstNodes = $pdo->query('SELECT id, kind, canonical_name FROM nodes ORDER BY id')->fetchAll();
        $firstEdges = $pdo->query('SELECT id, kind, source_id, target_id FROM edges ORDER BY id')->fetchAll();

        $second = $reconciler->reconcile($request);
        assertNotSame($first->scanId, $second->scanId);
        assertSame($firstNodes, $pdo->query('SELECT id, kind, canonical_name FROM nodes ORDER BY id')->fetchAll());
        assertSame($firstEdges, $pdo->query('SELECT id, kind, source_id, target_id FROM edges ORDER BY id')->fetchAll());
        assertSame($second->scanId, (string) $pdo->query(
            "SELECT active_scan_id FROM projects WHERE id = '" . $second->projectId . "'",
        )->fetchColumn());
        $snapshot = $pdo->query("SELECT * FROM scan_snapshots WHERE project_id = '" . $second->projectId . "'")->fetch();
        assertSame($first->scanId, $snapshot['scan_id']);
        assertSame(1, (int) $snapshot['complete']);
        assertSame(true, (int) $snapshot['fact_count'] > 0);
        $payload = json_decode($snapshot['payload_json'], true, 512, JSON_THROW_ON_ERROR);
        assertSame(1, $payload['schema']);
        assertSame($firstNodes, array_map(fn(array $node): array => [
            'id' => $node['id'], 'kind' => $node['kind'], 'canonical_name' => $node['canonical_name'],
        ], $payload['facts']['nodes']));

        $retainedRequest = new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            $request->scanners,
            $request->contributions,
            ['snapshot_retention' => 1],
            $request->classifications,
            $request->boundaries,
            $request->mode,
            $request->contributionCache,
        );
        $third = $reconciler->reconcile($retainedRequest);
        assertSame($second->scanId, (string) $pdo->query('SELECT scan_id FROM scan_snapshots')->fetchColumn());
        assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn());
        $listed = (new ArchitectureQueryService($pdo))->listSnapshots($third->projectId);
        assertSame([$third->scanId, $second->scanId], array_column($listed->data['snapshots'], 'scan_id'));
        assertSame([true, false], array_column($listed->data['snapshots'], 'active'));
        assertThrows(fn() => (new ArchitectureQueryService($pdo))->listSnapshots($third->projectId, offset: -1), InvalidArgumentException::class);
    }

    #[Group('reconciliation')]
    public function testReconciliationFailuresPreserveTheActiveGraph(): void
    {
        [$pdo, $reconciler, $request] = $this->reconciliationFixture();
        $active = $reconciler->reconcile($request);
        $nodeIds = $pdo->query('SELECT id FROM nodes ORDER BY id')->fetchAll();

        $badContribution = new ScanContribution(
            'knossos.php:file:src/CheckoutService.php',
            $request->contributions[0]->nodes,
            [new EdgeFact(
                'calls',
                'php:method:Fixture\\DoesNotExist::run',
                'php:class:Vendor\\Missing',
                Origin::Ast,
                Confidence::Certain,
                new Evidence('src/CheckoutService.php', 1, 1),
            )],
        );
        $badRequest = new FullScanRequest(
            $request->projectIdentity,
            $request->projectName,
            $request->discovery,
            $request->scanners,
            [$badContribution, $request->contributions[1]],
        );

        assertThrows(fn() => $reconciler->reconcile($badRequest), ReconciliationException::class);
        assertSame($nodeIds, $pdo->query('SELECT id FROM nodes ORDER BY id')->fetchAll());
        assertSame($active->scanId, (string) $pdo->query(
            "SELECT active_scan_id FROM projects WHERE id = '" . $active->projectId . "'",
        )->fetchColumn());
    }
}
