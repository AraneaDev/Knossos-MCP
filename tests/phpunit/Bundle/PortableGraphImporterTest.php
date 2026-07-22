<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use InvalidArgumentException;
use Knossos\Bundle\BundleIdMapBuilder;
use Knossos\Bundle\PortableGraphImporter;
use Knossos\Store\SqliteConnection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('portable-graph-importer')]
final class PortableGraphImporterTest extends TestCase
{
    private PDO $pdo;
    private PortableGraphImporter $importer;

    private const PROJECT_ID = 'test-project';
    private const SCAN_ID = 'test-scan';
    private const CHECKSUM = '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

    protected function setUp(): void
    {
        $this->pdo = SqliteConnection::open(':memory:');
        $this->buildSchema($this->pdo);
        $this->importer = new PortableGraphImporter($this->pdo);
    }

    // ----- shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(PortableGraphImporter::class);

        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testConstructorIsPublicTakesPdo(): void
    {
        $constructor = (new \ReflectionClass(PortableGraphImporter::class))->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPublic());
        assertSame(1, $constructor->getNumberOfParameters());
    }

    public function testImportIsPublicInstanceMethod(): void
    {
        $method = (new \ReflectionClass(PortableGraphImporter::class))->getMethod('import');

        $this->assertTrue($method->isPublic());
        $this->assertFalse($method->isStatic());
    }

    // ----- import(): happy path -----

    public function testImportPopulatesAllTablesAndUpdatesActiveScan(): void
    {
        $payload = $this->payloadWith([
            'files' => [$this->fileRow('f1', 'src/A.php')],
            'nodes' => [$this->nodeRow('n1')],
            'edges' => [$this->edgeRow('e1', 'n1', 'n1')],
            'boundaries' => [$this->boundaryRow('b1', 'Core')],
            'memberships' => [['boundary_id' => 'b1', 'node_id' => 'n1']],
            'classifications' => [$this->classificationRow('c1', 'n1', null)],
            'diagnostics' => [$this->diagnosticRow('d1', null)],
        ]);
        $manifest = $this->manifestWith([]); // redaction=none, format=knossos.graph.bundle, etc.

        $this->callImport($payload, $manifest);

        assertSame(1, $this->countTable('projects'));
        assertSame(1, $this->countTable('scans'));
        assertSame(1, $this->countTable('files'));
        assertSame(1, $this->countTable('nodes'));
        assertSame(1, $this->countTable('edges'));
        assertSame(1, $this->countTable('boundaries'));
        assertSame(1, $this->countTable('boundary_memberships'));
        assertSame(1, $this->countTable('classifications'));
        assertSame(1, $this->countTable('diagnostics'));
        // active_scan_id is set via UPDATE at the end of import().
        $row = $this->pdo->query('SELECT active_scan_id FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        assertSame(self::SCAN_ID, $row['active_scan_id']);
    }

    public function testImportHandlesEmptyTables(): void
    {
        $payload = $this->payloadWith([]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        assertSame(1, $this->countTable('projects'));
        assertSame(1, $this->countTable('scans'));
        foreach (['files', 'nodes', 'edges', 'boundaries', 'boundary_memberships', 'classifications', 'diagnostics'] as $table) {
            assertSame(0, $this->countTable($table));
        }
    }

    public function testImportFillsNullFinishedAtFallbackToDefault(): void
    {
        // payload.scan has no finished_at key (or non-string). Source falls back to '1970-01-01T00:00:00+00:00'.
        $payload = $this->payloadWith([]);
        unset($payload['scan']['finished_at']);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $row = $this->pdo->query('SELECT finished_at FROM scans WHERE id = ' . $this->pdo->quote(self::SCAN_ID))->fetch();
        $this->assertNotFalse($row);
        assertSame('1970-01-01T00:00:00+00:00', $row['finished_at']);
    }

    public function testImportNameOverrideTakesPriority(): void
    {
        $payload = $this->payloadWith([]);
        $payload['project_name'] = 'From Payload';
        $manifest = $this->manifestWith([]);

        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $this->importer->import($payload, $manifest, $maps, self::PROJECT_ID, self::SCAN_ID, self::CHECKSUM, 'Explicit Name');

        $row = $this->pdo->query('SELECT name FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        assertSame('Explicit Name', $row['name']);
    }

    public function testImportUsesPayloadProjectNameWhenNameIsNull(): void
    {
        $payload = $this->payloadWith([]);
        $payload['project_name'] = 'From Payload';
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $row = $this->pdo->query('SELECT name FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        assertSame('From Payload', $row['name']);
    }

    public function testImportFallsBackToImportedGraphWhenBothNameAndPayloadNameMissing(): void
    {
        $payload = $this->payloadWith([]);
        unset($payload['project_name']);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $row = $this->pdo->query('SELECT name FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        assertSame('Imported graph', $row['name']);
    }

    public function testImportRejectsPayloadMissingScanObject(): void
    {
        $payload = $this->payloadWith([]);
        unset($payload['scan']);
        $manifest = $this->manifestWith([]);

        $error = captureThrows(
            fn () => $this->callImport($payload, $manifest),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle scan must be an object', $error->getMessage());
    }

    public function testImportRejectsScanThatIsAList(): void
    {
        $payload = $this->payloadWith([]);
        $payload['scan'] = [1, 2];
        $manifest = $this->manifestWith([]);

        $error = captureThrows(
            fn () => $this->callImport($payload, $manifest),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle scan must be an object', $error->getMessage());
    }

    public function testImportStoresManifestRedactionInProjectConfigJson(): void
    {
        $payload = $this->payloadWith([]);
        $manifest = $this->manifestWith(['redaction' => 'strict']);

        $this->callImport($payload, $manifest);

        $row = $this->pdo->query('SELECT config_json FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        $this->assertStringContainsString('"redaction":"strict"', $row['config_json']);
    }

    public function testImportFallsBackToUnknownRedactionWhenManifestMissing(): void
    {
        $payload = $this->payloadWith([]);
        $manifest = $this->minimalManifest();
        unset($manifest['redaction']);

        $this->callImport($payload, $manifest);

        $row = $this->pdo->query('SELECT config_json FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        $this->assertStringContainsString('"redaction":"unknown"', $row['config_json']);
    }

    public function testImportRootRealpathEncodesChecksumPrefix(): void
    {
        $payload = $this->payloadWith([]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $row = $this->pdo->query('SELECT root_realpath FROM projects WHERE id = ' . $this->pdo->quote(self::PROJECT_ID))->fetch();
        $this->assertNotFalse($row);
        // 'bundle://' prefix + first 32 chars of checksum.
        assertSame('bundle://' . substr(self::CHECKSUM, 0, 32), $row['root_realpath']);
    }

    // ----- insertFiles: relativePath + safety checks -----

    public function testImportRejectsFileWithParentTraversal(): void
    {
        $error = $this->expectImportError([
            'files' => [$this->fileRow('f1', '../etc/passwd')],
        ]);
        $this->assertStringContainsString('unsafe file path', $error->getMessage());
    }

    public function testImportRejectsFileWithLeadingSlash(): void
    {
        $error = $this->expectImportError([
            'files' => [$this->fileRow('f1', '/abs/path')],
        ]);
        $this->assertStringContainsString('unsafe file path', $error->getMessage());
    }

    public function testImportRejectsFileWithNulByteInPath(): void
    {
        $error = $this->expectImportError([
            'files' => [$this->fileRow('f1', "a\0b")],
        ]);
        $this->assertStringContainsString('unsafe file path', $error->getMessage());
    }

    public function testImportRejectsFileWithEmptyRelativePath(): void
    {
        $error = $this->expectImportError([
            'files' => [$this->fileRow('f1', '')],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportRejectsFileWithNullLanguage(): void
    {
        $row = $this->fileRow('f1', 'src/A.php');
        $row['language'] = null;
        $error = $this->expectImportError([
            'files' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportRejectsFileWithEmptyScannerVersion(): void
    {
        $row = $this->fileRow('f1', 'src/A.php');
        $row['scanner_version'] = '';
        $error = $this->expectImportError([
            'files' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportRejectsFileWithNegativeSize(): void
    {
        $row = $this->fileRow('f1', 'src/A.php');
        $row['size'] = -1;
        $error = $this->expectImportError([
            'files' => [$row],
        ]);
        $this->assertStringContainsString('non-negative integer', $error->getMessage());
    }

    public function testImportRejectsFileWithStringSize(): void
    {
        $row = $this->fileRow('f1', 'src/A.php');
        $row['size'] = '0';
        $error = $this->expectImportError([
            'files' => [$row],
        ]);
        $this->assertStringContainsString('non-negative integer', $error->getMessage());
    }

    public function testImportAcceptsFilesWithZeroSize(): void
    {
        $row = $this->fileRow('f1', 'src/A.php');
        $row['size'] = 0;
        $this->expectImportSuccess([
            'files' => [$row],
        ]);
        assertSame(1, $this->countTable('files'));
    }

    // ----- insertNodes: text + parent_id deferred -----

    public function testImportRejectsNodeWithEmptyKind(): void
    {
        $row = $this->nodeRow('n1');
        $row['kind'] = '';
        $error = $this->expectImportError([
            'nodes' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportRejectsNodeWithNullOwnerKey(): void
    {
        // text() rejects null — owner_key on nodes MUST be a non-null string.
        $row = $this->nodeRow('n1');
        $row['owner_key'] = null;
        $error = $this->expectImportError([
            'nodes' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportRejectsNodeWithInvalidConfidence(): void
    {
        $row = $this->nodeRow('n1');
        $row['confidence'] = 'maybe';
        $error = $this->expectImportError([
            'nodes' => [$row],
        ]);
        $this->assertStringContainsString('Bundle confidence', $error->getMessage());
    }

    public function testImportAcceptsAllValidConfidenceLevels(): void
    {
        foreach (['certain', 'probable', 'possible'] as $confidence) {
            $this->setUp();
            $row = $this->nodeRow('n1');
            $row['confidence'] = $confidence;
            $this->expectImportSuccess([
                'nodes' => [$row],
            ]);
            assertSame(1, $this->countTable('nodes'));
        }
    }

    public function testImportFillsParentIdAfterAllInserts(): void
    {
        // parent_id references another node; insertNodes inserts all rows first,
        // THEN runs an UPDATE to set parent_id (deferred-foreign-key work-around).
        $payload = $this->payloadWith([
            'nodes' => [
                $this->nodeRowWith('parent', ['parent_id' => null]),
                $this->nodeRowWith('child', ['parent_id' => 'parent']),
            ],
        ]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        // Both rows present; child.parent_id is mapped via maps['nodes'] to the parent's bundle-id.
        assertSame(2, $this->countTable('nodes'));
        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $childMapped = $maps['nodes']['child'];
        $parentMapped = $maps['nodes']['parent'];
        $row = $this->pdo->query('SELECT parent_id FROM nodes WHERE id = ' . $this->pdo->quote($childMapped))->fetch();
        $this->assertNotFalse($row);
        assertSame($parentMapped, $row['parent_id']);
    }

    public function testImportSkipsParentIdUpdateWhenNull(): void
    {
        $payload = $this->payloadWith([
            'nodes' => [$this->nodeRowWith('n1', ['parent_id' => null])],
        ]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $mapped = $maps['nodes']['n1'];
        $row = $this->pdo->query('SELECT parent_id FROM nodes WHERE id = ' . $this->pdo->quote($mapped))->fetch();
        $this->assertNotFalse($row);
        assertSame(null, $row['parent_id']);
    }

    public function testImportCanonicalizesNodeAttributesJson(): void
    {
        $row = $this->nodeRow('n1');
        $row['attributes_json'] = '{"b":2,"a":1}';
        $payload = $this->payloadWith(['nodes' => [$row]]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $mapped = $maps['nodes']['n1'];
        $row = $this->pdo->query('SELECT attributes_json FROM nodes WHERE id = ' . $this->pdo->quote($mapped))->fetch();
        $this->assertNotFalse($row);
        // jsonObject() canonicalizes: keys sorted alphabetically.
        assertSame('{"a":1,"b":2}', $row['attributes_json']);
    }

    public function testImportRejectsNodeAttributesJsonThatIsAList(): void
    {
        $row = $this->nodeRow('n1');
        $row['attributes_json'] = '[1,2,3]';
        $error = $this->expectImportError([
            'nodes' => [$row],
        ]);
        $this->assertStringContainsString('JSON attributes must be objects', $error->getMessage());
    }

    public function testImportFileIdReferencesFileMap(): void
    {
        $payload = $this->payloadWith([
            'files' => [$this->fileRow('f1', 'src/A.php')],
            'nodes' => [$this->nodeRow('n1', 'f1')],
        ]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $nodeMapped = $maps['nodes']['n1'];
        $fileMapped = $maps['files']['f1'];
        $row = $this->pdo->query('SELECT file_id FROM nodes WHERE id = ' . $this->pdo->quote($nodeMapped))->fetch();
        $this->assertNotFalse($row);
        assertSame($fileMapped, $row['file_id']);
    }

    public function testImportRejectsNodeWithDanglingFileId(): void
    {
        // node.file_id = 'missing' is not in $maps['files'] — text() doesn't
        // fire; the dangling-ref check fires via mappedNullable→mappedRequired.
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1', 'missing')],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    public function testImportRejectsRowThatIsAList(): void
    {
        // The BundleIdMapBuilder rejects non-object rows BEFORE the importer sees them.
        $error = captureThrows(
            fn () => $this->callImportWithPayload(
                $this->payloadWith(['files' => [['this-is', 'a-list']]])
            ),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle files row must be an object', $error->getMessage());
    }

    public function testImportRejectsRowThatIsNotAnArray(): void
    {
        $error = captureThrows(
            fn () => $this->callImportWithPayload(
                $this->payloadWith(['files' => ['not-an-object-string']])
            ),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('Bundle files row must be an object', $error->getMessage());
    }

    // ----- insertEdges: dangling refs + text -----

    public function testImportRejectsEdgeWithDanglingSourceId(): void
    {
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1')],
            'edges' => [$this->edgeRow('e1', 'missing', 'n1')],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    public function testImportRejectsEdgeWithDanglingTargetId(): void
    {
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1')],
            'edges' => [$this->edgeRow('e1', 'n1', 'missing')],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    public function testImportRejectsEdgeWithEmptyKind(): void
    {
        $row = $this->edgeRow('e1', 'n1', 'n1');
        $row['kind'] = '';
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1')],
            'edges' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportEdgeIdMappedViaBundleIdMapBuilder(): void
    {
        // Edges aren't in $maps, so id is computed via BundleIdMapBuilder::mappedId($projectId, 'edges', $id).
        $payload = $this->payloadWith([
            'nodes' => [$this->nodeRow('n1')],
            'edges' => [$this->edgeRow('e1', 'n1', 'n1')],
        ]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $expectedId = BundleIdMapBuilder::mappedId(self::PROJECT_ID, 'edges', 'e1');
        $row = $this->pdo->query('SELECT id FROM edges WHERE id = ' . $this->pdo->quote($expectedId))->fetch();
        $this->assertNotFalse($row);
    }

    // ----- insertBoundaries: source enum -----

    public function testImportRejectsBoundaryWithInvalidSource(): void
    {
        $row = $this->boundaryRow('b1', 'Core');
        $row['source'] = 'who-knows';
        $error = $this->expectImportError([
            'boundaries' => [$row],
        ]);
        $this->assertStringContainsString('Boundary source is invalid', $error->getMessage());
    }

    public function testImportAcceptsExplicitAndInferredSources(): void
    {
        foreach (['explicit', 'inferred'] as $source) {
            $this->setUp();
            $row = $this->boundaryRow('b1', 'Core');
            $row['source'] = $source;
            $this->expectImportSuccess([
                'boundaries' => [$row],
            ]);
            assertSame(1, $this->countTable('boundaries'));
        }
    }

    public function testImportRejectsBoundaryWithEmptyName(): void
    {
        $error = $this->expectImportError([
            'boundaries' => [$this->boundaryRow('b1', '')],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportRejectsBoundaryWithInvalidMatcherJson(): void
    {
        $row = $this->boundaryRow('b1', 'Core');
        $row['matcher_json'] = '[1,2,3]';
        $error = $this->expectImportError([
            'boundaries' => [$row],
        ]);
        $this->assertStringContainsString('JSON attributes must be objects', $error->getMessage());
    }

    // ----- insertMemberships: dangling refs -----

    public function testImportRejectsMembershipWithDanglingBoundaryId(): void
    {
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1')],
            'boundaries' => [$this->boundaryRow('b1', 'Core')],
            'memberships' => [['boundary_id' => 'missing', 'node_id' => 'n1']],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    public function testImportRejectsMembershipWithDanglingNodeId(): void
    {
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1')],
            'boundaries' => [$this->boundaryRow('b1', 'Core')],
            'memberships' => [['boundary_id' => 'b1', 'node_id' => 'missing']],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    public function testImportAcceptedMembershipIsInsertedWithMappedIds(): void
    {
        $payload = $this->payloadWith([
            'nodes' => [$this->nodeRow('n1')],
            'boundaries' => [$this->boundaryRow('b1', 'Core')],
            'memberships' => [['boundary_id' => 'b1', 'node_id' => 'n1']],
        ]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $row = $this->pdo->query('SELECT boundary_id, node_id FROM boundary_memberships')->fetch();
        $this->assertNotFalse($row);
        assertSame($maps['boundaries']['b1'], $row['boundary_id']);
        assertSame($maps['nodes']['n1'], $row['node_id']);
    }

    // ----- insertClassifications -----

    public function testImportRejectsClassificationWithDanglingNodeId(): void
    {
        $error = $this->expectImportError([
            'classifications' => [$this->classificationRow('c1', 'missing', null)],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    public function testImportRejectsClassificationWithEmptyRole(): void
    {
        $row = $this->classificationRow('c1', 'n1', null);
        $row['role'] = '';
        $error = $this->expectImportError([
            'nodes' => [$this->nodeRow('n1')],
            'classifications' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    // ----- insertDiagnostics: severity enum + text -----

    public function testImportRejectsDiagnosticWithInvalidSeverity(): void
    {
        $row = $this->diagnosticRow('d1', null);
        $row['severity'] = 'panic';
        $error = $this->expectImportError([
            'diagnostics' => [$row],
        ]);
        $this->assertStringContainsString('Diagnostic severity is invalid', $error->getMessage());
    }

    public function testImportAcceptsInfoWarningAndErrorSeverities(): void
    {
        foreach (['info', 'warning', 'error'] as $severity) {
            $this->setUp();
            $row = $this->diagnosticRow('d1', null);
            $row['severity'] = $severity;
            $this->expectImportSuccess([
                'diagnostics' => [$row],
            ]);
            assertSame(1, $this->countTable('diagnostics'));
        }
    }

    public function testImportRejectsDiagnosticWithEmptyMessage(): void
    {
        $row = $this->diagnosticRow('d1', null);
        $row['message'] = '';
        $error = $this->expectImportError([
            'diagnostics' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
    }

    public function testImportDiagnosticFileIdReferencesFileMap(): void
    {
        $payload = $this->payloadWith([
            'files' => [$this->fileRow('f1', 'src/A.php')],
            'diagnostics' => [$this->diagnosticRow('d1', 'f1')],
        ]);
        $manifest = $this->manifestWith([]);

        $this->callImport($payload, $manifest);

        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $diagMapped = BundleIdMapBuilder::mappedId(self::PROJECT_ID, 'diagnostics', 'd1');
        $fileMapped = $maps['files']['f1'];
        $row = $this->pdo->query('SELECT file_id FROM diagnostics WHERE id = ' . $this->pdo->quote($diagMapped))->fetch();
        $this->assertNotFalse($row);
        assertSame($fileMapped, $row['file_id']);
    }

    public function testImportRejectsDiagnosticWithDanglingFileId(): void
    {
        $error = $this->expectImportError([
            'diagnostics' => [$this->diagnosticRow('d1', 'missing')],
        ]);
        $this->assertStringContainsString('dangling reference', $error->getMessage());
    }

    // ----- text() boundary: 1MB cap -----

    public function testImportRejectsTextLongerThanOneMegabyte(): void
    {
        // scanner_version is 'sv' by default; override with a >1M string.
        $row = $this->fileRow('f1', 'src/A.php');
        $row['scanner_version'] = str_repeat('a', 1_000_001);
        $error = $this->expectImportError([
            'files' => [$row],
        ]);
        $this->assertStringContainsString('invalid text', $error->getMessage());
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

    // ----- payload helpers -----

    /**
     * Build a payload that overrides selected tables; the base comes from
     * minimalPayload() with empty tables. Uses direct array assignment so
     * existing keys are replaced (PHP `+` keeps left-side keys on conflicts).
     *
     * @param array<string, list<array<string, mixed>>> $overrides
     */
    private function payloadWith(array $overrides): array
    {
        $payload = $this->minimalPayload();
        foreach ($overrides as $table => $rows) {
            $payload[$table] = $rows;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function manifestWith(array $extras): array
    {
        return array_merge($this->minimalManifest(), $extras);
    }

    /**
     * @param array<string, mixed> $extras
     *
     * @return array<string, mixed>
     */
    private function nodeRowWith(string $id, array $extras): array
    {
        return array_merge($this->nodeRow($id), $extras);
    }

    private function minimalPayload(): array
    {
        return [
            'project_name' => 'Test',
            'scan' => ['scanner_set_hash' => 'h', 'finished_at' => '2025-01-01T00:00:00+00:00'],
            'files' => [],
            'nodes' => [],
            'edges' => [],
            'classifications' => [],
            'boundaries' => [],
            'memberships' => [],
            'diagnostics' => [],
        ];
    }

    private function minimalManifest(): array
    {
        return [
            'format' => 'knossos.graph.bundle',
            'version' => 2,
            'redaction' => 'none',
            'checksum' => 'sha256:abc',
            'uncompressed_bytes' => 0,
            'fact_count' => 0,
            'created_at' => '2025-01-01T00:00:00+00:00',
        ];
    }

    private function fileRow(string $id, string $relativePath): array
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

    private function nodeRow(string $id, ?string $fileId = null): array
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
            'attributes_json' => '{}',
            'owner_key' => 'owner-' . $id,
        ];
    }

    private function edgeRow(string $id, string $sourceId, string $targetId): array
    {
        return [
            'id' => $id,
            'kind' => 'depends_on',
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'file_id' => null,
            'start_line' => null,
            'end_line' => null,
            'origin' => 'scanner',
            'confidence' => 'certain',
            'attributes_json' => '{}',
            'owner_key' => 'owner-edge-' . $id,
        ];
    }

    private function boundaryRow(string $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'matcher_json' => '{"type":"path","prefix":"src/Domain"}',
            'source' => 'explicit',
        ];
    }

    private function classificationRow(string $id, string $nodeId, ?string $fileId): array
    {
        return [
            'id' => $id,
            'node_id' => $nodeId,
            'role' => 'controller',
            'origin' => 'scanner',
            'confidence' => 'certain',
            'rule_id' => 'r',
            'file_id' => $fileId,
            'start_line' => null,
            'end_line' => null,
            'attributes_json' => '{}',
        ];
    }

    private function diagnosticRow(string $id, ?string $fileId): array
    {
        return [
            'id' => $id,
            'file_id' => $fileId,
            'severity' => 'warning',
            'code' => 'c',
            'message' => 'm',
            'start_line' => null,
            'end_line' => null,
            'owner_key' => 'owner-' . $id,
        ];
    }

    // ----- helpers -----

    private function callImport(array $payload, array $manifest): void
    {
        $maps = (new BundleIdMapBuilder())->build(self::PROJECT_ID, $payload);
        $this->importer->import($payload, $manifest, $maps, self::PROJECT_ID, self::SCAN_ID, self::CHECKSUM, null);
    }

    private function callImportWithPayload(array $payload): void
    {
        $this->callImport($payload, $this->minimalManifest());
    }

    private function expectImportError(array $payloadOverrides): \Throwable
    {
        return captureThrows(
            fn () => $this->callImportWithPayload($this->payloadWith($payloadOverrides)),
            InvalidArgumentException::class,
        );
    }

    private function expectImportSuccess(array $payloadOverrides): void
    {
        $this->callImportWithPayload($this->payloadWith($payloadOverrides));
    }

    private function countTable(string $table): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
    }
}
