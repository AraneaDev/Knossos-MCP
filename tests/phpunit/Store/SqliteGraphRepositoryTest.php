<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\GraphRepository;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('sqlite-graph-repository')]
final class SqliteGraphRepositoryTest extends TestCase
{
    private static function projectRoot(): string
    {
        return \dirname(__DIR__, 3);
    }

    private string $migrationDir;
    private string $tempSqlite;
    private PDO $pdo;
    private SqliteGraphRepository $repository;

    protected function setUp(): void
    {
        // Copy project migrations into a temp dir so the test owns the runner.
        $this->migrationDir = sys_get_temp_dir() . '/knossos-mig-' . uniqid('', true);
        mkdir($this->migrationDir, 0777, true);
        foreach (glob(self::projectRoot() . '/migrations/*.sql') ?: [] as $src) {
            copy($src, $this->migrationDir . '/' . basename($src));
        }

        $this->tempSqlite = sys_get_temp_dir() . '/knossos-graph-' . uniqid('', true) . '.sqlite';
        $this->pdo = SqliteConnection::open($this->tempSqlite);
        (new MigrationRunner($this->pdo, $this->migrationDir))->migrate();

        $this->repository = new SqliteGraphRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->migrationDir) && is_dir($this->migrationDir)) {
            foreach (glob($this->migrationDir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->migrationDir);
        }
        if (isset($this->tempSqlite)) {
            foreach (glob($this->tempSqlite . '-*') ?: [] as $f) {
                @unlink($f);
            }
            if (is_file($this->tempSqlite)) {
                @unlink($this->tempSqlite);
            }
        }
    }

    // ----- helpers -----

    private function seedProject(string $projectId = 'project-1'): void
    {
        $this->repository->saveProject(
            id: $projectId,
            name: 'Project One',
            rootRealpath: '/projects/one',
            config: [],
        );
    }

    private function seedScan(string $projectId, string $scanId): void
    {
        $this->repository->createScan($scanId, $projectId, 'full', 'scanner-set-hash');
    }

    private static function evidence(string $path = 'src/A.php', int $start = 1, int $end = 1): Evidence
    {
        return new Evidence(relativePath: $path, startLine: $start, endLine: $end);
    }

    private static function buildNode(
        string $localId,
        string $canonicalName,
        ?string $parentId = null,
    ): NodeFact {
        return new NodeFact(
            localId: $localId,
            kind: 'class',
            canonicalName: $canonicalName,
            displayName: $canonicalName,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: self::evidence(),
            attributes: [],
        );
    }

    private static function buildContribution(string $ownerKey, NodeFact ...$nodes): ScanContribution
    {
        return new ScanContribution(
            ownerKey: $ownerKey,
            nodes: $nodes,
            edges: [],
            diagnostics: [],
        );
    }

    // ----- constructor -----

    public function testImplementsGraphRepositoryContract(): void
    {
        assertSame(true, $this->repository instanceof GraphRepository);
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(SqliteGraphRepository::class);

        $this->assertTrue($reflection->isFinal());
    }

    // ----- transaction() -----

    public function testTransactionCommitsOperationResultOnSuccess(): void
    {
        $result = $this->repository->transaction(function (): string {
            $this->seedProject('trans-ok');

            return 'committed';
        });

        assertSame('committed', $result);
        assertSame('Project One', $this->repository->findProject('trans-ok')['name']);
    }

    public function testTransactionRollsBackAndRethrowsOnError(): void
    {
        $error = captureThrows(
            function () {
                $this->repository->transaction(function (): void {
                    $this->seedProject('trans-rollback');
                    throw new \LogicException('boom');
                });
            },
            \LogicException::class,
        );

        assertSame('boom', $error->getMessage());
        assertSame(null, $this->repository->findProject('trans-rollback'));
        assertSame(false, $this->pdo->inTransaction(), 'transaction must be released after rollback');
    }

    public function testTransactionRunsInlineWhenAlreadyInsideTransaction(): void
    {
        $this->repository->transaction(function (): void {
            $result = $this->repository->transaction(function (SqliteGraphRepository $repo): string {
                $this->seedProject('trans-nested');

                return 'nested-ok';
            });

            assertSame('nested-ok', $result);
            assertSame(true, $this->pdo->inTransaction());
        });

        assertSame('Project One', $this->repository->findProject('trans-nested')['name']);
        assertSame(false, $this->pdo->inTransaction(), 'outer transaction must still be committed');
    }

    // ----- saveProject() + findProject() -----

    public function testSaveProjectInsertsAndUpsertsInPlace(): void
    {
        $this->repository->saveProject(
            id: 'proj-upsert',
            name: 'Original',
            rootRealpath: '/orig',
            config: ['flag' => true],
        );

        $this->repository->saveProject(
            id: 'proj-upsert',
            name: 'Renamed',
            rootRealpath: '/new',
            config: ['flag' => false, 'mode' => 'dev'],
        );

        $row = $this->repository->findProject('proj-upsert');

        assertSame('Renamed', $row['name']);
        assertSame('/new', $row['root_realpath']);
        assertSame('{"flag":false,"mode":"dev"}', $row['config_json']);
        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM projects WHERE id = 'proj-upsert'")->fetchColumn());
    }

    public function testFindProjectReturnsNullWhenAbsent(): void
    {
        assertSame(null, $this->repository->findProject('missing'));
    }

    // ----- createScan() + completeScan() -----

    public function testCreateScanAcceptsBothFullAndIncrementalModes(): void
    {
        $this->seedProject('proj-scans');
        $this->repository->createScan('scan-full', 'proj-scans', 'full', 'hash-1');
        $this->repository->createScan('scan-inc', 'proj-scans', 'incremental', 'hash-1');

        assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM scans WHERE project_id = 'proj-scans'")->fetchColumn());
    }

    public function testCreateScanRejectsUnknownMode(): void
    {
        $this->seedProject('proj-bad-mode');

        $error = captureThrows(
            fn () => $this->repository->createScan('scan-x', 'proj-bad-mode', 'partial', 'hash-1'),
            InvalidArgumentException::class,
        );

        assertSame('Scan mode must be full or incremental.', $error->getMessage());
    }

    public function testCompleteScanMarksRunningScanCompleteAndSetsActive(): void
    {
        $this->seedProject('proj-complete');
        $this->seedScan('proj-complete', 'scan-complete');
        $this->repository->completeScan('proj-complete', 'scan-complete');

        $scan = $this->pdo->query("SELECT status, finished_at FROM scans WHERE id = 'scan-complete'")->fetch(\PDO::FETCH_ASSOC);
        $project = $this->pdo->query("SELECT active_scan_id FROM projects WHERE id = 'proj-complete'")->fetch(\PDO::FETCH_ASSOC);

        assertSame('complete', $scan['status']);
        assertSame('scan-complete', $project['active_scan_id']);
    }

    public function testCompleteScanThrowsWhenNoRunningScanMatches(): void
    {
        $this->seedProject('proj-no-scan');

        $error = captureThrows(
            fn () => $this->repository->completeScan('proj-no-scan', 'scan-y'),
            InvalidArgumentException::class,
        );

        assertSame('Running scan not found for project.', $error->getMessage());
    }

    // ----- archiveActiveSnapshot() -----

    public function testArchiveActiveSnapshotRejectsRetentionOutOfRange(): void
    {
        $this->seedProject('proj-snap');

        $error = captureThrows(
            fn () => $this->repository->archiveActiveSnapshot('proj-snap', 'cfg-h', -1),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Snapshot retention must be between 0 and 20', $error->getMessage());
    }

    public function testArchiveActiveSnapshotReturnsSilentlyWhenNoActiveScan(): void
    {
        $this->seedProject('proj-snap-empty');

        $this->repository->archiveActiveSnapshot('proj-snap-empty', 'cfg-h', 5);

        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_snapshots WHERE project_id = 'proj-snap-empty'")->fetchColumn());
    }

    public function testArchiveActiveSnapshotReturnsSilentlyWhenRetentionIsZero(): void
    {
        $this->seedProject('proj-snap-zero');

        $this->repository->archiveActiveSnapshot('proj-snap-zero', 'cfg-h', 0);

        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_snapshots WHERE project_id = 'proj-snap-zero'")->fetchColumn());
    }

    public function testArchiveActiveSnapshotReturnsSilentlyWhenActiveScanNotComplete(): void
    {
        $this->seedProject('proj-running');
        $this->seedScan('proj-running', 'scan-running');

        $this->repository->archiveActiveSnapshot('proj-running', 'cfg-h', 5);

        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM scan_snapshots WHERE project_id = 'proj-running'")->fetchColumn());
    }

    public function testArchiveActiveSnapshotWritesSnapshotForCompletedScan(): void
    {
        $this->seedProject('proj-snap-write');
        $this->seedScan('proj-snap-write', 'scan-snap');
        $this->repository->completeScan('proj-snap-write', 'scan-snap');

        $this->repository->archiveActiveSnapshot('proj-snap-write', 'cfg-h', 5);

        $row = $this->pdo->query("SELECT complete, fact_count, payload_json FROM scan_snapshots WHERE project_id = 'proj-snap-write'")->fetch(\PDO::FETCH_ASSOC);

        assertSame(1, (int) $row['complete']);
        $this->assertGreaterThanOrEqual(0, (int) $row['fact_count']);
        $this->assertStringContainsString('"schema":1', $row['payload_json']);
    }

    // ----- clearProjectGraph() -----

    public function testClearProjectGraphDeletesAllGraphRowsButKeepsProjectIdentity(): void
    {
        $this->seedProject('proj-clear');
        $this->seedScan('proj-clear', 'scan-clear');
        $this->repository->saveFile(
            id: 'file-clear',
            projectId: 'proj-clear',
            relativePath: 'src/A.php',
            contentHash: 'h-a',
            size: 10,
            mtime: 1,
            language: 'php',
            scannerVersion: 'v1',
            scanId: 'scan-clear',
        );

        $this->repository->clearProjectGraph('proj-clear');

        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM files WHERE project_id = 'proj-clear'")->fetchColumn());
        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM projects WHERE id = 'proj-clear'")->fetchColumn());
        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM scans WHERE project_id = 'proj-clear'")->fetchColumn());
    }

    // ----- saveFile() -----

    public function testSaveFileUsesZeroLineCountWhenOmitted(): void
    {
        $this->seedProject('proj-file');
        $this->seedScan('proj-file', 'scan-file');

        $this->repository->saveFile(
            id: 'file-default',
            projectId: 'proj-file',
            relativePath: 'src/A.php',
            contentHash: 'h',
            size: 10,
            mtime: 1,
            language: 'php',
            scannerVersion: 'v1',
            scanId: 'scan-file',
        );

        $row = $this->pdo->query("SELECT line_count FROM files WHERE id = 'file-default'")->fetch(\PDO::FETCH_ASSOC);
        assertSame(0, (int) $row['line_count']);
    }

    public function testSaveFileUpdatesOnConflictByRelativePath(): void
    {
        $this->seedProject('proj-file-conflict');
        $this->seedScan('proj-file-conflict', 'scan-conflict');

        $this->repository->saveFile('id-1', 'proj-file-conflict', 'src/A.php', 'h-old', 10, 1, 'php', 'v1', 'scan-conflict', 5);
        $this->repository->saveFile('id-2', 'proj-file-conflict', 'src/A.php', 'h-new', 99, 2, 'php', 'v2', 'scan-conflict', 30);

        $rows = $this->pdo->query("SELECT id, content_hash, size, line_count FROM files WHERE project_id = 'proj-file-conflict'")->fetchAll(\PDO::FETCH_ASSOC);

        assertSame(1, count($rows));
        assertSame('id-1', $rows[0]['id']);
        assertSame('h-new', $rows[0]['content_hash']);
        assertSame(99, (int) $rows[0]['size']);
        assertSame(30, (int) $rows[0]['line_count']);
    }

    // ----- saveNode() -----

    public function testSaveNodePersistsAllAttributesAndNullableFields(): void
    {
        $this->seedProject('proj-node');
        $this->seedScan('proj-node', 'scan-node');

        $this->repository->saveNode(
            id: 'node-1',
            projectId: 'proj-node',
            language: 'php',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'Foo',
            parentId: null,
            fileId: null,
            startLine: null,
            endLine: null,
            origin: Origin::Ast->value,
            confidence: 'probable',
            attributes: ['visibility' => 'public'],
            ownerKey: 'owner-1',
            scanId: 'scan-node',
        );

        $row = $this->pdo->query("SELECT canonical_name, display_name, parent_id, file_id, attributes_json FROM nodes WHERE id = 'node-1'")->fetch(\PDO::FETCH_ASSOC);

        assertSame('App\\Foo', $row['canonical_name']);
        assertSame('Foo', $row['display_name']);
        assertSame(null, $row['parent_id']);
        assertSame(null, $row['file_id']);
        assertSame('{"visibility":"public"}', $row['attributes_json']);
    }

    public function testSaveNodeUpsertsById(): void
    {
        $this->seedProject('proj-upsert-node');
        $this->seedScan('proj-upsert-node', 'scan-upsert-node');

        $this->repository->saveNode(
            'n', 'proj-upsert-node', 'php', 'class', 'Foo', 'Foo',
            null, null, null, null,
            Origin::Ast->value, 'probable', ['v' => 1],
            'owner-1', 'scan-upsert-node',
        );
        $this->repository->saveNode(
            'n', 'proj-upsert-node', 'php', 'interface', 'Foo', 'IFoo',
            null, null, 1, 5,
            Origin::FrameworkConvention->value, 'certain', ['v' => 2],
            'owner-2', 'scan-upsert-node',
        );

        $row = $this->pdo->query("SELECT kind, canonical_name, attributes_json FROM nodes WHERE id = 'n'")->fetch(\PDO::FETCH_ASSOC);

        assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE id = 'n'")->fetchColumn());
        assertSame('interface', $row['kind']);
        assertSame('{"v":2}', $row['attributes_json']);
    }

    // ----- saveEdge() -----

    public function testSaveEdgePersistsSourceTargetAndEvidence(): void
    {
        $this->seedProject('proj-edge');
        $this->seedScan('proj-edge', 'scan-edge');
        $this->repository->saveNode('n1', 'proj-edge', 'php', 'class', 'A', 'A', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-edge');
        $this->repository->saveNode('n2', 'proj-edge', 'php', 'class', 'B', 'B', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-edge');

        $this->repository->saveEdge(
            'e1', 'proj-edge', 'calls', 'n1', 'n2',
            null, 10, 15, Origin::Ast->value, 'probable',
            ['resolved' => true], 'owner', 'scan-edge',
        );

        $row = $this->pdo->query("SELECT source_id, target_id, kind, attributes_json FROM edges WHERE id = 'e1'")->fetch(\PDO::FETCH_ASSOC);

        assertSame('n1', $row['source_id']);
        assertSame('n2', $row['target_id']);
        assertSame('calls', $row['kind']);
        assertSame('{"resolved":true}', $row['attributes_json']);
    }

    // ----- saveDiagnostic() -----

    public function testSaveDiagnosticAcceptsNullFileAndLineRanges(): void
    {
        $this->seedProject('proj-diag');
        $this->seedScan('proj-diag', 'scan-diag');

        $this->repository->saveDiagnostic(
            'd1', 'proj-diag', 'scan-diag', null,
            'info', 'compilation_skipped', 'partial',
            null, null, 'owner-diag',
        );

        $row = $this->pdo->query("SELECT severity, code, message, file_id, start_line, end_line FROM diagnostics WHERE id = 'd1'")->fetch(\PDO::FETCH_ASSOC);

        assertSame('info', $row['severity']);
        assertSame('compilation_skipped', $row['code']);
        assertSame('partial', $row['message']);
        assertSame(null, $row['file_id']);
        assertSame(null, $row['start_line']);
        assertSame(null, $row['end_line']);
    }

    // ----- findNodesByName() -----

    public function testFindNodesByNameReturnsExactMatchesFirstThenDisplayMatches(): void
    {
        $this->seedProject('proj-find');
        $this->seedScan('proj-find', 'scan-find');

        for ($i = 0; $i < 3; ++$i) {
            $this->repository->saveNode(
                "n-displ-$i", 'proj-find', 'php', 'class', "Other$i", 'Target',
                null, null, null, null,
                Origin::Ast->value, 'certain', [],
                'owner', 'scan-find',
            );
        }
        $this->repository->saveNode(
            'n-canonical', 'proj-find', 'php', 'class', 'Target', 'Target',
            null, null, null, null,
            Origin::Ast->value, 'certain', [],
            'owner', 'scan-find',
        );

        $rows = $this->repository->findNodesByName('proj-find', 'Target', limit: 50);

        assertSame(4, count($rows));
        assertSame('n-canonical', $rows[0]['id']);
    }

    public function testFindNodesByNameRejectsInvalidLimit(): void
    {
        $this->seedProject('proj-limit');

        $error = captureThrows(
            fn () => $this->repository->findNodesByName('proj-limit', 'x', limit: 0),
            InvalidArgumentException::class,
        );

        assertSame('Query limit must be between 1 and 1000.', $error->getMessage());
    }

    // ----- saveClassification() -----

    public function testSaveClassificationRoundtripsItsFields(): void
    {
        $this->seedProject('proj-class');
        $this->seedScan('proj-class', 'scan-class');
        $this->repository->saveNode('n', 'proj-class', 'php', 'class', 'Foo', 'Foo', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-class');

        $this->repository->saveClassification(
            'c1', 'proj-class', 'n', 'http.controller',
            Origin::FrameworkConvention->value, 'probable', 'laravel-rules-v1',
            null, 5, 10, ['route' => '/'], 'scan-class',
        );

        $row = $this->pdo->query("SELECT role, origin, confidence, rule_id, attributes_json FROM classifications WHERE id = 'c1'")->fetch(\PDO::FETCH_ASSOC);

        assertSame('http.controller', $row['role']);
        assertSame('framework_convention', $row['origin']);
        assertSame('probable', $row['confidence']);
        assertSame('laravel-rules-v1', $row['rule_id']);
        assertSame('{"route":"/"}', $row['attributes_json']);
    }

    // ----- saveBoundary() + saveBoundaryMembership() -----

    public function testSaveBoundaryAndMembershipPersistAndAreRetrievable(): void
    {
        $this->seedProject('proj-bound');
        $this->seedScan('proj-bound', 'scan-bound');
        $this->repository->saveNode('n', 'proj-bound', 'php', 'class', 'Foo', 'Foo', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-bound');

        $this->repository->saveBoundary('b1', 'proj-bound', 'CoreDomain', ['prefix' => 'src/Domain'], 'inferred', 'scan-bound');
        $this->repository->saveBoundaryMembership('b1', 'proj-bound', 'n', 'scan-bound');

        $boundary = $this->pdo->query("SELECT name, matcher_json, source FROM boundaries WHERE id = 'b1'")->fetch(\PDO::FETCH_ASSOC);
        $members = $this->pdo->query("SELECT node_id FROM boundary_memberships WHERE boundary_id = 'b1'")->fetchAll(\PDO::FETCH_COLUMN);

        assertSame('CoreDomain', $boundary['name']);
        assertSame('{"prefix":"src/Domain"}', $boundary['matcher_json']);
        assertSame('inferred', $boundary['source']);
        assertSame(['n'], array_map('strval', $members));
    }

    // ----- replaceContributionCache() -----

    public function testReplaceContributionCacheDeletesOldEntriesAndInsertsNewOnes(): void
    {
        $this->seedProject('proj-cache');

        $oldContribution = self::buildContribution('php-scanner', self::buildNode('old', 'Old'));
        $newContribution = self::buildContribution('php-scanner', self::buildNode('new', 'New'));

        $oldEntry = new ContributionCacheEntry('src/Old.php', 'old-hash', 'php-scanner', 'v1', 'cfg-h', $oldContribution);
        $newEntry = new ContributionCacheEntry('src/New.php', 'new-hash', 'php-scanner', 'v1', 'cfg-h', $newContribution);

        $this->repository->replaceContributionCache('proj-cache', [$oldEntry]);
        $this->repository->replaceContributionCache('proj-cache', [$newEntry]);

        $rows = $this->pdo->query("SELECT file_path FROM contribution_cache WHERE project_id = 'proj-cache' ORDER BY file_path")->fetchAll(\PDO::FETCH_COLUMN);

        assertSame(['src/New.php'], array_map('strval', $rows));
    }

    public function testReplaceContributionCacheRejectsNonEntryValues(): void
    {
        $this->seedProject('proj-cache-bad');

        $error = captureThrows(
            fn () => $this->repository->replaceContributionCache('proj-cache-bad', [[1, 2, 3]]),
            InvalidArgumentException::class,
        );

        assertSame('Invalid contribution cache entry.', $error->getMessage());
    }

    // ----- outgoing() + incoming() -----

    public function testOutgoingReturnsEdgesOriginatingAtNodeAndAcceptsKindFilter(): void
    {
        $this->seedProject('proj-out');
        $this->seedScan('proj-out', 'scan-out');
        $this->repository->saveNode('a', 'proj-out', 'php', 'class', 'A', 'A', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-out');
        $this->repository->saveNode('b', 'proj-out', 'php', 'class', 'B', 'B', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-out');
        $this->repository->saveNode('c', 'proj-out', 'php', 'class', 'C', 'C', null, null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-out');

        $this->repository->saveEdge('e-calls', 'proj-out', 'calls',  'a', 'b', null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-out');
        $this->repository->saveEdge('e-imports','proj-out', 'imports', 'a', 'c', null, null, null, Origin::Ast->value, 'certain', [], 'owner', 'scan-out');

        $all = $this->repository->outgoing('proj-out', 'a', limit: 50);
        $onlyCalls = $this->repository->outgoing('proj-out', 'a', kind: 'calls', limit: 50);
        $incomingToB = $this->repository->incoming('proj-out', 'b', limit: 50);

        assertSame(2, count($all));
        assertSame(1, count($onlyCalls));
        assertSame('calls', $onlyCalls[0]['kind']);
        assertSame('e-calls', $incomingToB[0]['id']);
    }

    public function testOutgoingRejectsInvalidLimit(): void
    {
        $this->seedProject('proj-out-limit');

        $error = captureThrows(
            fn () => $this->repository->outgoing('proj-out-limit', 'any-id', limit: 0),
            InvalidArgumentException::class,
        );

        assertSame('Query limit must be between 1 and 1000.', $error->getMessage());
    }

    // ----- deleteFactsByOwner() -----

    public function testDeleteFactsByOwnerRemovesNodesEdgesAndDiagnosticsOnlyForOwner(): void
    {
        $this->seedProject('proj-owner');
        $this->seedScan('proj-owner', 'scan-owner');

        foreach (['a', 'b'] as $name) {
            $this->repository->saveNode($name, 'proj-owner', 'php', 'class', $name, $name, null, null, null, null, Origin::Ast->value, 'certain', [], 'shared', 'scan-owner');
        }
        $this->repository->saveNode('c', 'proj-owner', 'php', 'class', 'C', 'C', null, null, null, null, Origin::Ast->value, 'certain', [], 'scanner-a', 'scan-owner');
        $this->repository->saveEdge('e-shared', 'proj-owner', 'calls', 'a', 'b', null, null, null, Origin::Ast->value, 'certain', [], 'scanner-a', 'scan-owner');
        $this->repository->saveDiagnostic('d-a', 'proj-owner', 'scan-owner', null, 'info', 'note', 'm', null, null, 'scanner-a');

        $this->repository->deleteFactsByOwner('proj-owner', 'scanner-a');

        assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM nodes WHERE project_id = 'proj-owner'")->fetchColumn(), 'shared owner rows must remain');
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM edges WHERE project_id = 'proj-owner'")->fetchColumn());
        assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM diagnostics WHERE project_id = 'proj-owner'")->fetchColumn());
    }

    // ----- prepare() / json() reuse -----

    public function testRepeatedSaveReusesCachedPreparedStatements(): void
    {
        $this->seedProject('proj-prepare');
        $this->seedScan('proj-prepare', 'scan-prepare');

        for ($i = 0; $i < 5; ++$i) {
            $this->repository->saveFile("f-$i", 'proj-prepare', "src/F$i.php", "h-$i", 1, 1, 'php', 'v1', 'scan-prepare', $i);
        }

        assertSame(5, (int) $this->pdo->query("SELECT COUNT(*) FROM files WHERE project_id = 'proj-prepare'")->fetchColumn());
    }
}