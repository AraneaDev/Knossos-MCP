<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use InvalidArgumentException;
use Knossos\Bundle\GraphBundleDecoder;
use Knossos\Bundle\GraphBundleService;
use Knossos\Query\ResultEnvelope;
use Knossos\Store\SqliteConnection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('graph-bundle-service')]
final class GraphBundleServiceTest extends TestCase
{
    private PDO $pdo;
    private GraphBundleService $service;

    protected function setUp(): void
    {
        $this->pdo = SqliteConnection::open(':memory:');
        $this->buildSchema($this->pdo);
        $this->service = new GraphBundleService($this->pdo);
    }

    // ----- shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(GraphBundleService::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorIsPublicTakesPdo(): void
    {
        $constructor = (new \ReflectionClass(GraphBundleService::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPublic());
        assertSame(1, $constructor->getNumberOfParameters());
    }

    public function testServiceExposesNoPublicMethodsBeyondConstructorAndTwoMethods(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(GraphBundleService::class))->getMethods(\ReflectionMethod::IS_PUBLIC),
        );
        sort($methods);

        assertSame(['__construct', 'export', 'import'], $methods);
    }

    // ----- export(): happy path (no redaction) -----

    public function testExportRoundTripsThroughDecoder(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedFile('f2', 'proj-1', 'src/B.php', 'php');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'none'));

        $this->assertNotEmpty($bundle['manifest']);
        assertSame('none', $bundle['manifest']['redaction']);
        assertSame(2, $bundle['fact_count']);
        // Both files preserved (no redaction).
        assertSame('src/A.php', $bundle['payload']['files'][0]['relative_path']);
        assertSame('src/B.php', $bundle['payload']['files'][1]['relative_path']);
    }

    public function testExportEmitsGzippedBytes(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');

        $bundle = $this->service->export('proj-1', 'none');

        $this->assertNotEmpty($bundle);
        // First two bytes of a gzip stream are 0x1F 0x8B.
        assertSame("\x1f\x8b", substr($bundle, 0, 2));
    }

    public function testExportManifestHasRequiredFields(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1', name: 'Test Project');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'none'));

        assertSame(GraphBundleDecoder::FORMAT, $bundle['manifest']['format']);
        assertSame(GraphBundleDecoder::VERSION, $bundle['manifest']['version']);
        assertSame('none', $bundle['manifest']['redaction']);
        assertSame(0, $bundle['manifest']['fact_count']);
        $this->assertStringStartsWith('sha256:', $bundle['manifest']['checksum']);
        assertSame(strlen(GraphBundleDecoder::encodeCanonical($bundle['payload'])), $bundle['manifest']['uncompressed_bytes']);
    }

    public function testExportIncludesEmptyTablesAsLists(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'none'));

        $expectedTables = ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'memberships', 'diagnostics'];
        foreach ($expectedTables as $table) {
            assertSame([], $bundle['payload'][$table]);
        }
    }

    public function testExportPayloadContainsProjectNameAndScanFields(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1', name: 'My Project', scanner_set_hash: 'abc123', finished_at: '2025-01-15T10:30:00+00:00');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'none'));

        assertSame('My Project', $bundle['payload']['project_name']);
        assertSame('abc123', $bundle['payload']['scan']['scanner_set_hash']);
        assertSame('2025-01-15T10:30:00+00:00', $bundle['payload']['scan']['finished_at']);
    }

    public function testExportUsesFinishedAtAsCreatedAt(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1', finished_at: '2025-06-30T12:00:00+00:00');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'none'));

        assertSame('2025-06-30T12:00:00+00:00', $bundle['manifest']['created_at']);
    }

    // ----- export(): redaction = 'paths' -----

    public function testExportPathsRedactionKeepsNodeOwnerKeyUntouched(): void
    {
        // 'paths' mode redacts file relative_paths; node/edge data stays raw.
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'secret/path.php', 'php');
        $this->seedNode('n1', 'proj-1', ['owner_key' => 'owner-secret']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        // Node owner_key is untouched (node data isn't part of paths-redaction).
        assertSame('owner-secret', $bundle['payload']['nodes'][0]['owner_key']);
        // File path IS redacted — original is gone, replaced with 'redacted/<hash><ext>'.
        $this->assertStringStartsWith('redacted/', $bundle['payload']['files'][0]['relative_path']);
        $this->assertStringNotContainsString('secret/path.php', $bundle['payload']['files'][0]['relative_path']);
    }

    public function testExportPathsRedactionHashesFilePathAndPreservesExtension(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/SecretClass.php', 'php');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        $redacted = $bundle['payload']['files'][0]['relative_path'];
        $this->assertStringStartsWith('redacted/', $redacted);
        $this->assertStringEndsWith('.php', $redacted);
        // The hashed portion is the first 24 sha256 chars of the original path.
        $expectedHash = substr(hash('sha256', 'src/SecretClass.php'), 0, 24);
        assertSame('redacted/' . $expectedHash . '.php', $redacted);
    }

    public function testExportPathsRedactionLowercasesExtension(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'SRC/Cls.PHP', 'php');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        assertSame('.php', substr($bundle['payload']['files'][0]['relative_path'], -4));
    }

    public function testExportPathsRedactionHandlesFileWithoutExtension(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'Makefile', '');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        $redacted = $bundle['payload']['files'][0]['relative_path'];
        $this->assertStringStartsWith('redacted/', $redacted);
        $this->assertStringNotContainsString('.', substr($redacted, strlen('redacted/')));
    }

    // ----- export(): redaction = 'strict' -----

    public function testExportStrictRedactionReplacesAttributesJsonWithEmptyObject(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedNode('n1', 'proj-1', ['attributes_json' => '{"secret":true}']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('{}', $bundle['payload']['nodes'][0]['attributes_json']);
    }

    public function testExportStrictRedactionReplacesOwnerKeyWithRedactedPrefix(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedNode('n1', 'proj-1', ['owner_key' => 'secret-owner-key']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        $redacted = $bundle['payload']['nodes'][0]['owner_key'];
        $this->assertStringStartsWith('redacted:', $redacted);
        $this->assertStringNotContainsString('secret-owner-key', $redacted);
    }

    public function testExportStrictRedactionLeavesNullOwnerKeyAsNull(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedNode('n1', 'proj-1', ['owner_key' => null]);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame(null, $bundle['payload']['nodes'][0]['owner_key']);
    }

    public function testExportStrictRedactionAppliesToNodesEdgesAndClassifications(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedNode('n1', 'proj-1', ['owner_key' => 'secret']);
        $this->seedEdge('e1', 'proj-1', 'n1', 'n1', ['owner_key' => 'secret']);
        // classifications table has no owner_key column; redacted attributes_json is
        // the only redaction-target the source applies for this table.
        $this->seedClassification('c1', 'proj-1', 'n1', 'f1', ['attributes_json' => '{"secret":true}']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        $this->assertStringStartsWith('redacted:', $bundle['payload']['nodes'][0]['owner_key']);
        $this->assertStringStartsWith('redacted:', $bundle['payload']['edges'][0]['owner_key']);
        assertSame('{}', $bundle['payload']['classifications'][0]['attributes_json']);
    }

    public function testExportStrictRedactionRedactsDiagnosticMessage(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedDiagnostic('d1', 'proj-1', 'scan-1', 'f1', 'super-secret message');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('[redacted]', $bundle['payload']['diagnostics'][0]['message']);
        $this->assertStringStartsWith('redacted:', $bundle['payload']['diagnostics'][0]['owner_key']);
    }

    public function testExportStrictRedactionDoesNotTouchBoundaries(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->pdo->prepare('INSERT INTO boundaries (id, project_id, name, matcher_json, source) VALUES (:id, :project, :name, :matcher, :source)')->execute([
            'id' => 'b1',
            'project' => 'proj-1',
            'name' => 'Core',
            'matcher' => '{"type":"path","prefix":"src/Domain"}',
            'source' => 'explicit',
        ]);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        // Boundaries have no owner_key/attributes_json, so strict redaction is a
        // no-op for them. Their shape must be unchanged.
        assertSame('Core', $bundle['payload']['boundaries'][0]['name']);
        assertSame('{"type":"path","prefix":"src/Domain"}', $bundle['payload']['boundaries'][0]['matcher_json']);
        assertSame('explicit', $bundle['payload']['boundaries'][0]['source']);
    }

    // ----- export(): rejection paths -----

    public function testExportRejectsInvalidRedactionMode(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');

        $error = captureThrows(
            fn () => $this->service->export('proj-1', 'wat'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle redaction must be none, paths, or strict', $error->getMessage());
    }

    public function testExportRejectsNonexistentProject(): void
    {
        $error = captureThrows(
            fn () => $this->service->export('nonexistent', 'none'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Project has no active snapshot to export', $error->getMessage());
    }

    public function testExportRejectsProjectWithoutActiveScan(): void
    {
        $this->pdo->prepare('INSERT INTO projects (id, name, active_scan_id) VALUES (:id, :name, :active)')->execute([
            'id' => 'proj-1',
            'name' => 'Test',
            'active' => null,
        ]);

        $error = captureThrows(
            fn () => $this->service->export('proj-1', 'none'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Project has no active snapshot to export', $error->getMessage());
    }

    public function testExportRejectsNonCompleteScan(): void
    {
        $this->pdo->prepare('INSERT INTO projects (id, name, active_scan_id) VALUES (:id, :name, :active)')->execute([
            'id' => 'proj-1',
            'name' => 'Test',
            'active' => 'scan-1',
        ]);
        $this->pdo->prepare('INSERT INTO scans (id, project_id, status, scanner_set_hash, finished_at) VALUES (:id, :project, :status, :hash, :finished)')->execute([
            'id' => 'scan-1',
            'project' => 'proj-1',
            'status' => 'running',
            'hash' => 'h',
            'finished' => '2025-01-01T00:00:00+00:00',
        ]);

        $error = captureThrows(
            fn () => $this->service->export('proj-1', 'none'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Active scan is unavailable or incomplete', $error->getMessage());
    }

    // ----- import(): happy path -----

    public function testImportInsertsProjectAndScanRows(): void
    {
        $bundle = $this->buildValidBundle([
            'files' => [$this->makeFileRow('f1', 'src/A.php')],
            'nodes' => [$this->makeNodeRow('n1')],
        ]);

        $this->service->import($bundle);

        $projectId = 'bundle:' . substr($this->bundleChecksum($bundle), 0, 32);
        $row = $this->pdo->query('SELECT id, name, root_realpath, config_json FROM projects WHERE id = ' . $this->pdo->quote($projectId))->fetch();
        $this->assertNotFalse($row);
        assertSame($projectId, $row['id']);
        $this->assertStringStartsWith('bundle://', $row['root_realpath']);
        $this->assertStringContainsString('"imported":true', $row['config_json']);

        $scanRow = $this->pdo->query('SELECT mode, status FROM scans WHERE id = ' . $this->pdo->quote('bundle-scan:' . substr($this->bundleChecksum($bundle), 0, 32)))->fetch();
        $this->assertNotFalse($scanRow);
        assertSame('full', $scanRow['mode']);
        assertSame('complete', $scanRow['status']);
    }

    public function testImportInsertsFactRowsAcrossTables(): void
    {
        $bundle = $this->buildValidBundle([
            'files' => [$this->makeFileRow('f1', 'src/A.php')],
            'nodes' => [$this->makeNodeRow('n1')],
            'boundaries' => [$this->makeBoundaryRow('b1', 'Core')],
        ]);

        $this->service->import($bundle);

        assertSame(1, $this->countTable('files'));
        assertSame(1, $this->countTable('nodes'));
        assertSame(1, $this->countTable('boundaries'));
    }

    public function testImportReturnsResultEnvelopeWithFactCountAndRedaction(): void
    {
        $bundle = $this->buildValidBundle([
            'files' => [
                $this->makeFileRow('f1', 'src/A.php'),
                $this->makeFileRow('f2', 'src/B.php'),
            ],
            'nodes' => [$this->makeNodeRow('n1')],
        ], redaction: 'paths');

        $result = $this->service->import($bundle);

        $this->assertInstanceOf(ResultEnvelope::class, $result);
        $this->assertStringStartsWith('bundle:', $result->projectId);
        $this->assertStringStartsWith('bundle-scan:', $result->snapshotId);
        assertSame('paths', $result->data['redaction']);
        assertSame(3, $result->data['fact_count']);
        assertSame(false, $result->data['root_imported']);
        $this->assertStringContainsString('Imported 3 portable graph facts', $result->summary);
    }

    public function testImportReimportingSameBundleTwiceThrows(): void
    {
        $bundle = $this->buildValidBundle([
            'files' => [$this->makeFileRow('f1', 'src/A.php')],
        ]);

        $this->service->import($bundle);

        $error = captureThrows(
            fn () => $this->service->import($bundle),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle is already imported', $error->getMessage());
    }

    public function testImportAcceptsExplicitProjectNameOverride(): void
    {
        $bundle = $this->buildValidBundle([
            'files' => [$this->makeFileRow('f1', 'src/A.php')],
        ]);

        $this->service->import($bundle, 'My Custom Name');

        $row = $this->pdo->query('SELECT name FROM projects WHERE id LIKE ' . $this->pdo->quote('bundle:%'))->fetch();
        $this->assertNotFalse($row);
        assertSame('My Custom Name', $row['name']);
    }

    public function testImportFallsBackToPayloadProjectNameWhenNameIsNull(): void
    {
        $bundle = $this->buildValidBundle([
            'files' => [$this->makeFileRow('f1', 'src/A.php')],
        ], project_name: 'Payload Project');

        $this->service->import($bundle);

        $row = $this->pdo->query('SELECT name FROM projects WHERE id LIKE ' . $this->pdo->quote('bundle:%'))->fetch();
        $this->assertNotFalse($row);
        assertSame('Payload Project', $row['name']);
    }

    // ----- import(): transaction rollback on importer failure -----

    public function testImportRollsBackTransactionWhenPortableGraphImporterThrows(): void
    {
        // The bundle passes GraphBundleDecoder validation, but
        // PortableGraphImporter rejects the relative path with '..'.
        // GraphBundleService must rollback the transaction so no rows leak.
        $bundle = $this->buildValidBundle([
            'files' => [$this->makeFileRow('f1', '../etc/passwd')],
        ]);

        $error = captureThrows(
            fn () => $this->service->import($bundle),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('unsafe file path', $error->getMessage());
        assertSame(0, $this->countTable('projects'));
        assertSame(0, $this->countTable('scans'));
        assertSame(0, $this->countTable('files'));
        assertSame(false, $this->pdo->inTransaction());
    }

    public function testImportPropagatesUnderlyingThrowableType(): void
    {
        // Trigger a different importer failure path: invalid boundary 'source'
        // is a value that GraphBundleDecoder accepts but PortableGraphImporter
        // rejects. The service must roll back AND re-throw the original error.
        $bundle = $this->buildValidBundle([
            'boundaries' => [['id' => 'b1', 'name' => 'Core', 'matcher_json' => '{}', 'source' => 'who-knows']],
        ]);

        $error = captureThrows(
            fn () => $this->service->import($bundle),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Boundary source is invalid', $error->getMessage());
        assertSame(0, $this->countTable('boundaries'));
    }

    // ----- schema -----

    private function buildSchema(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE projects (
    id TEXT PRIMARY KEY,
    name TEXT,
    active_scan_id TEXT,
    root_realpath TEXT,
    config_json TEXT,
    created_at TEXT,
    updated_at TEXT
);
CREATE TABLE scans (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    mode TEXT,
    status TEXT,
    scanner_set_hash TEXT,
    started_at TEXT,
    finished_at TEXT
);
CREATE TABLE files (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    relative_path TEXT,
    content_hash TEXT,
    size INTEGER,
    line_count INTEGER,
    mtime INTEGER,
    language TEXT,
    scanner_version TEXT,
    last_scan_id TEXT
);
CREATE TABLE nodes (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    kind TEXT,
    canonical_name TEXT,
    display_name TEXT,
    parent_id TEXT,
    file_id TEXT,
    start_line INTEGER,
    end_line INTEGER,
    origin TEXT,
    confidence TEXT,
    attributes_json TEXT,
    owner_key TEXT,
    last_scan_id TEXT
);
CREATE TABLE edges (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    kind TEXT,
    source_id TEXT,
    target_id TEXT,
    file_id TEXT,
    start_line INTEGER,
    end_line INTEGER,
    origin TEXT,
    confidence TEXT,
    attributes_json TEXT,
    owner_key TEXT,
    last_scan_id TEXT
);
CREATE TABLE classifications (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    node_id TEXT,
    role TEXT,
    origin TEXT,
    confidence TEXT,
    rule_id TEXT,
    file_id TEXT,
    start_line INTEGER,
    end_line INTEGER,
    attributes_json TEXT,
    last_scan_id TEXT
);
CREATE TABLE boundaries (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    name TEXT,
    matcher_json TEXT,
    source TEXT,
    last_scan_id TEXT
);
CREATE TABLE boundary_memberships (
    boundary_id TEXT,
    project_id TEXT,
    node_id TEXT,
    last_scan_id TEXT
);
CREATE TABLE diagnostics (
    id TEXT PRIMARY KEY,
    project_id TEXT,
    scan_id TEXT,
    file_id TEXT,
    severity TEXT,
    code TEXT,
    message TEXT,
    start_line INTEGER,
    end_line INTEGER,
    owner_key TEXT
);
SQL
        );
    }

    // ----- seeding -----

    private function seedProjectAndScan(
        string $projectId,
        string $scanId,
        string $name = 'Test',
        string $scanner_set_hash = 'hash',
        string $finished_at = '2025-01-01T00:00:00+00:00',
    ): void {
        $this->pdo->prepare('INSERT INTO projects (id, name, active_scan_id) VALUES (:id, :name, :active)')->execute([
            'id' => $projectId,
            'name' => $name,
            'active' => $scanId,
        ]);
        $this->pdo->prepare('INSERT INTO scans (id, project_id, status, scanner_set_hash, finished_at) VALUES (:id, :project, :status, :hash, :finished)')->execute([
            'id' => $scanId,
            'project' => $projectId,
            'status' => 'complete',
            'hash' => $scanner_set_hash,
            'finished' => $finished_at,
        ]);
    }

    private function seedFile(string $id, string $projectId, string $relativePath, string $language): void
    {
        $this->pdo->prepare('INSERT INTO files (id, project_id, relative_path, content_hash, size, line_count, language, scanner_version) VALUES (:id, :project, :path, :hash, :size, :lines, :lang, :ver)')->execute([
            'id' => $id,
            'project' => $projectId,
            'path' => $relativePath,
            'hash' => 'h',
            'size' => 0,
            'lines' => 0,
            'lang' => $language,
            'ver' => 'sv',
        ]);
    }

    private function seedNode(string $id, string $projectId, array $extras = []): void
    {
        $defaults = [
            'kind' => 'class',
            'canonical_name' => 'X',
            'display_name' => 'X',
            'parent_id' => null,
            'file_id' => null,
            'start_line' => null,
            'end_line' => null,
            'origin' => 'scanner',
            'confidence' => 'certain',
            'attributes_json' => '{}',
            'owner_key' => null,
        ];
        $values = array_merge($defaults, $extras);
        $this->pdo->prepare('INSERT INTO nodes (id, project_id, kind, canonical_name, display_name, parent_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key) VALUES (:id, :project_id, :kind, :canonical_name, :display_name, :parent_id, :file_id, :start_line, :end_line, :origin, :confidence, :attributes_json, :owner_key)')->execute(array_merge([
            'id' => $id,
            'project_id' => $projectId,
        ], $values));
    }

    private function seedEdge(string $id, string $projectId, string $sourceId, string $targetId, array $extras = []): void
    {
        $defaults = [
            'kind' => 'depends_on',
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'file_id' => null,
            'start_line' => null,
            'end_line' => null,
            'origin' => 'scanner',
            'confidence' => 'certain',
            'attributes_json' => '{}',
            'owner_key' => null,
        ];
        $values = array_merge($defaults, $extras);
        $this->pdo->prepare('INSERT INTO edges (id, project_id, kind, source_id, target_id, file_id, start_line, end_line, origin, confidence, attributes_json, owner_key) VALUES (:id, :project_id, :kind, :source_id, :target_id, :file_id, :start_line, :end_line, :origin, :confidence, :attributes_json, :owner_key)')->execute(array_merge([
            'id' => $id,
            'project_id' => $projectId,
        ], $values));
    }

    private function seedClassification(string $id, string $projectId, string $nodeId, string $fileId, array $extras = []): void
    {
        $defaults = [
            'role' => 'controller',
            'origin' => 'scanner',
            'confidence' => 'certain',
            'rule_id' => 'r',
            'start_line' => null,
            'end_line' => null,
            'attributes_json' => '{}',
        ];
        $values = array_merge($defaults, $extras);
        $this->pdo->prepare('INSERT INTO classifications (id, project_id, node_id, role, origin, confidence, rule_id, file_id, start_line, end_line, attributes_json) VALUES (:id, :project_id, :node_id, :role, :origin, :confidence, :rule_id, :file_id, :start_line, :end_line, :attributes_json)')->execute(array_merge([
            'id' => $id,
            'project_id' => $projectId,
            'node_id' => $nodeId,
            'file_id' => $fileId,
        ], $values));
    }

    private function seedDiagnostic(string $id, string $projectId, string $scanId, string $fileId, string $message): void
    {
        $this->pdo->prepare('INSERT INTO diagnostics (id, project_id, scan_id, file_id, severity, code, message, owner_key) VALUES (:id, :project_id, :scan_id, :file_id, :severity, :code, :message, :owner_key)')->execute([
            'id' => $id,
            'project_id' => $projectId,
            'scan_id' => $scanId,
            'file_id' => $fileId,
            'severity' => 'warning',
            'code' => 'c',
            'message' => $message,
            'owner_key' => null,
        ]);
    }

    // ----- helpers -----

    private function countTable(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function makeFileRow(string $id, string $relativePath): array
    {
        return [
            'id' => $id,
            'relative_path' => $relativePath,
            'content_hash' => 'h',
            'size' => 0,
            'line_count' => 0,
            'language' => 'php',
            'scanner_version' => 'sv',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeNodeRow(string $id, ?string $fileId = null): array
    {
        return [
            'id' => $id,
            'kind' => 'class',
            'canonical_name' => 'X',
            'display_name' => 'X',
            'parent_id' => null,
            'file_id' => $fileId,
            'start_line' => null,
            'end_line' => null,
            'origin' => 'scanner',
            'confidence' => 'certain',
            'attributes_json' => '{"foo":"bar"}',
            // owner_key MUST be a non-null string for nodes — PortableGraphImporter
            // calls text($item['owner_key'] ?? null), which throws on null/empty.
            'owner_key' => 'owner-' . $id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function makeBoundaryRow(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'matcher_json' => '{"type":"path","prefix":"src/Domain"}',
            'source' => 'explicit',
        ];
    }

    /**
     * Build a valid gzipped bundle from a payload table override. The default
     * payload has empty tables; tests can override individual tables.
     *
     * @param array<string, list<array<string, mixed>>> $overrides
     */
    private function buildValidBundle(array $overrides = [], string $redaction = 'none', string $project_name = 'Test Project'): string
    {
        $payload = [
            'project_name' => $project_name,
            'scan' => ['scanner_set_hash' => 'h', 'finished_at' => '2025-01-01T00:00:00+00:00'],
            'files' => [],
            'nodes' => [],
            'edges' => [],
            'classifications' => [],
            'boundaries' => [],
            'memberships' => [],
            'diagnostics' => [],
        ];
        foreach ($overrides as $table => $rows) {
            $payload[$table] = $rows;
        }

        $payloadJson = GraphBundleDecoder::encodeCanonical($payload);
        $manifest = [
            'format' => GraphBundleDecoder::FORMAT,
            'version' => GraphBundleDecoder::VERSION,
            'redaction' => $redaction,
            'checksum' => 'sha256:' . hash('sha256', $payloadJson),
            'uncompressed_bytes' => strlen($payloadJson),
            'fact_count' => $this->sumRows($payload),
            'created_at' => '2025-01-01T00:00:00+00:00',
        ];
        $json = GraphBundleDecoder::encodeCanonical(['manifest' => $manifest, 'payload' => $payload]);
        $compressed = gzencode($json);
        $this->assertNotFalse($compressed);

        return $compressed;
    }

    /**
     * Bare sha256-hex checksum the service will use to derive projectId.
     * Strips 'sha256:' prefix from the bundle's manifest checksum.
     */
    private function bundleChecksum(string $compressed): string
    {
        $json = gzdecode($compressed);
        $this->assertNotFalse($json);
        $bundle = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        $checksum = $bundle['manifest']['checksum'];

        return substr($checksum, strlen('sha256:'));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sumRows(array $payload): int
    {
        $tables = ['files', 'nodes', 'edges', 'classifications', 'boundaries', 'memberships', 'diagnostics'];
        $sum = 0;
        foreach ($tables as $table) {
            $sum += is_array($payload[$table] ?? null) ? count($payload[$table]) : 0;
        }

        return $sum;
    }
}
