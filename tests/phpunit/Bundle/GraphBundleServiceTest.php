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

    /**
     * Was "keeps node owner key untouched": `paths` mode used to redact
     * files.relative_path alone, so an owner key (`scanner:file:<path>`)
     * still carried the path. It keeps its scanner prefix and loses the path.
     */
    public function testExportPathsRedactionKeepsTheOwnerKeyPrefixAndDropsThePath(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'secret/path.php', 'php');
        $this->seedNode('n1', 'proj-1', ['file_id' => 'f1', 'owner_key' => 'knossos.php:file:secret/path.php']);
        $this->seedEdge('e1', 'proj-1', 'n1', 'n1', ['owner_key' => 'knossos.php:file:secret/path.php']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        $redactedPath = $bundle['payload']['files'][0]['relative_path'];
        $this->assertStringStartsWith('redacted/', $redactedPath);
        assertSame('knossos.php:file:' . $redactedPath, $bundle['payload']['nodes'][0]['owner_key']);
        assertSame('knossos.php:file:' . $redactedPath, $bundle['payload']['edges'][0]['owner_key']);
    }

    /**
     * Was the first 24 hex of an unsalted sha256 of the path: anyone who can
     * guess a path (`src/Kernel.php`) could confirm it. The token is now a
     * salted HMAC with a salt that lives only for the one export.
     */
    public function testExportPathsRedactionHashesFilePathAndPreservesExtension(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/SecretClass.php', 'php');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        $redacted = $bundle['payload']['files'][0]['relative_path'];
        assertSame(1, preg_match('#^redacted/[0-9a-f]{24}\.php$#D', $redacted));
        assertSame(false, str_contains($redacted, substr(hash('sha256', 'src/SecretClass.php'), 0, 24)));
        $again = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));
        $this->assertNotSame($redacted, $again['payload']['files'][0]['relative_path'], 'A fresh salt per export.');
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

    public function testExportPathsRedactionRewritesNamesAttributesMessagesAndBoundaries(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1', name: 'Kept In Paths Mode');
        $this->seedFile('f1', 'proj-1', 'src/secret/a.ts', 'ts');
        $this->seedNode('n1', 'proj-1', [
            'file_id' => 'f1',
            'kind' => 'module',
            'canonical_name' => 'src/secret/a.ts',
            'display_name' => 'a.ts',
            'attributes_json' => '{"scanner_local_id":"ts:module:src/secret/a.ts","src/secret/a.ts":["src/secret"],"lines":3}',
        ]);
        $this->seedNode('n2', 'proj-1', ['file_id' => 'f1', 'kind' => 'function', 'canonical_name' => 'src/secret/a.ts#hidden', 'display_name' => 'hidden']);
        $this->seedDiagnostic('d1', 'proj-1', 'scan-1', 'f1', 'Could not resolve from src/secret/a.ts.');
        $this->pdo->prepare('INSERT INTO boundaries (id, project_id, name, matcher_json, source) VALUES (:id, :project, :name, :matcher, :source)')->execute([
            'id' => 'b1',
            'project' => 'proj-1',
            'name' => 'node:loc (src/secret)',
            'matcher' => '{"type":"path_prefix","value":"src/secret/"}',
            'source' => 'inferred',
        ]);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        $payload = $bundle['payload'];
        $file = $payload['files'][0]['relative_path'];
        $nodes = array_column($payload['nodes'], null, 'display_name');
        $module = $nodes[basename($file)];
        assertSame($file, $module['canonical_name']);
        assertSame($file . '#hidden', $nodes['hidden']['canonical_name']);
        $attributes = json_decode($module['attributes_json'], true, 8, JSON_THROW_ON_ERROR);
        assertSame('ts:module:' . $file, $attributes['scanner_local_id']);
        assertSame(3, $attributes['lines']);
        $directory = $attributes[$file][0];
        assertSame(1, preg_match('#^redacted-dir/[0-9a-f]{24}$#D', $directory));
        assertSame('Could not resolve from ' . $file . '.', $payload['diagnostics'][0]['message']);
        assertSame('node:loc (' . $directory . ')', $payload['boundaries'][0]['name']);
        assertSame('{"type":"path_prefix","value":"' . $directory . '/"}', $payload['boundaries'][0]['matcher_json']);
        assertSame('Kept In Paths Mode', $payload['project_name']);
        assertSame('h', $payload['files'][0]['content_hash'], 'Content hashes are salted in strict mode only.');
    }

    public function testExportPathsRedactionLeavesAttributesWithNoPathByteForByte(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/a.php', 'php');
        $this->seedNode('n1', 'proj-1', ['file_id' => 'f1', 'attributes_json' => '{"z":1, "a":{}}']);
        $this->seedEdge('e1', 'proj-1', 'n1', 'n1', ['attributes_json' => '[]']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        assertSame('{"z":1, "a":{}}', $bundle['payload']['nodes'][0]['attributes_json']);
        assertSame('[]', $bundle['payload']['edges'][0]['attributes_json']);
    }

    public function testExportPathsRedactionNamesAPythonPackageByItsModuleToken(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'pkg/secret/__init__.py', 'py');
        $this->seedNode('n1', 'proj-1', ['file_id' => 'f1', 'kind' => 'module', 'canonical_name' => 'pkg.secret', 'display_name' => 'pkg.secret']);
        $this->seedNode('n2', 'proj-1', ['file_id' => 'f1', 'kind' => 'package', 'canonical_name' => 'pkg.secret', 'display_name' => 'secret']);
        $this->seedNode('n3', 'proj-1', ['file_id' => 'f1', 'kind' => 'class', 'canonical_name' => 'pkg.secret.Ledger', 'display_name' => 'Ledger']);
        $this->seedNode('n4', 'proj-1', ['file_id' => 'f1', 'kind' => 'class', 'canonical_name' => 'App\\Other', 'display_name' => 'Other']);
        $this->pdo->exec("UPDATE nodes SET language = 'py' WHERE id IN ('n1', 'n2', 'n3')");

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'paths'));

        $byKind = array_column($bundle['payload']['nodes'], null, 'kind');
        $token = $byKind['module']['canonical_name'];
        assertSame(1, preg_match('/^redacted_[0-9a-f]{24}$/D', $token));
        assertSame($token, $byKind['module']['display_name']);
        assertSame($token, $byKind['package']['canonical_name']);
        assertSame($token, $byKind['package']['display_name'], 'A package is named after its directory.');
        $classes = array_column(array_values(array_filter($bundle['payload']['nodes'], static fn(array $node): bool => $node['kind'] === 'class')), 'display_name', 'canonical_name');
        $this->assertEquals(['App\\Other' => 'Other', $token . '.Ledger' => 'Ledger'], $classes, 'Declared names stay.');
    }

    public function testExportRedactionRekeysEveryIdAndKeepsEveryReference(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('file_1', 'proj-1', 'src/a.php', 'php');
        $this->seedNode('symbol_1', 'proj-1', ['file_id' => 'file_1']);
        $this->seedNode('symbol_2', 'proj-1', ['file_id' => 'file_1', 'parent_id' => 'symbol_1']);
        $this->seedEdge('edge_1', 'proj-1', 'symbol_1', 'symbol_2', ['file_id' => 'file_1']);
        $this->seedClassification('classification_1', 'proj-1', 'symbol_2', 'file_1');
        $this->seedDiagnostic('edge_2', 'proj-1', 'scan-1', 'file_1', 'm');
        $this->pdo->exec("INSERT INTO boundaries (id, project_id, name, matcher_json, source) VALUES ('boundary_1', 'proj-1', 'B', '{}', 'explicit')");
        $this->pdo->exec("INSERT INTO boundary_memberships (boundary_id, project_id, node_id) VALUES ('boundary_1', 'proj-1', 'symbol_1'), ('boundary_1', 'proj-1', 'symbol_2')");

        foreach (['paths', 'strict'] as $mode) {
            $payload = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', $mode))['payload'];

            $file = $payload['files'][0]['id'];
            $nodes = array_column($payload['nodes'], 'id');
            $this->assertMatchesRegularExpression('/^file_[0-9a-f]{48}$/D', $file);
            assertSame(2, count($nodes));
            foreach ($nodes as $node) {
                $this->assertMatchesRegularExpression('/^symbol_[0-9a-f]{48}$/D', $node);
            }
            $sorted = $nodes;
            sort($sorted, SORT_STRING);
            assertSame($sorted, $nodes, 'Rows are ordered by their new id.');
            $child = array_values(array_filter($payload['nodes'], static fn(array $node): bool => $node['parent_id'] !== null))[0];
            $parent = array_values(array_filter($payload['nodes'], static fn(array $node): bool => $node['parent_id'] === null))[0];
            assertSame($parent['id'], $child['parent_id']);
            assertSame([$file, $file], array_column($payload['nodes'], 'file_id'));
            assertSame([$parent['id'], $child['id'], $file], [$payload['edges'][0]['source_id'], $payload['edges'][0]['target_id'], $payload['edges'][0]['file_id']]);
            $this->assertMatchesRegularExpression('/^edge_[0-9a-f]{48}$/D', $payload['edges'][0]['id']);
            $this->assertMatchesRegularExpression('/^classification_[0-9a-f]{48}$/D', $payload['classifications'][0]['id']);
            assertSame([$child['id'], $file], [$payload['classifications'][0]['node_id'], $payload['classifications'][0]['file_id']]);
            assertSame($file, $payload['diagnostics'][0]['file_id']);
            $boundary = $payload['boundaries'][0]['id'];
            $this->assertMatchesRegularExpression('/^boundary_[0-9a-f]{48}$/D', $boundary);
            $members = $payload['memberships'];
            assertSame([$boundary, $boundary], array_column($members, 'boundary_id'));
            $memberNodes = array_column($members, 'node_id');
            assertSame($sorted, $memberNodes, 'Memberships are ordered by their new ids.');
        }
    }

    public function testExportWithoutRedactionKeepsEveryIdAndName(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('file_1', 'proj-1', 'src/a.php', 'php');
        $this->seedNode('symbol_1', 'proj-1', ['file_id' => 'file_1', 'canonical_name' => 'src/a.php', 'owner_key' => 'knossos.php:file:src/a.php']);

        $payload = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'none'))['payload'];

        assertSame('file_1', $payload['files'][0]['id']);
        assertSame(['symbol_1', 'file_1', 'src/a.php', 'knossos.php:file:src/a.php'], [$payload['nodes'][0]['id'], $payload['nodes'][0]['file_id'], $payload['nodes'][0]['canonical_name'], $payload['nodes'][0]['owner_key']]);
    }

    // ----- export(): redaction = 'strict' -----

    public function testExportStrictRedactionReplacesAttributesJsonWithEmptyObject(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedNode('n1', 'proj-1', ['attributes_json' => '{"secret":true}']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('{}', $bundle['payload']['nodes'][0]['attributes_json']);
    }

    /**
     * Was `redacted:` plus an unsalted sha256 prefix of the whole key: the
     * scanner prefix was lost and the hash of a guessable key named it. An
     * owner key is redacted as in `paths` mode: prefix kept, path replaced.
     */
    public function testExportStrictRedactionKeepsTheOwnerKeyPrefixAndDropsThePath(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedNode('n1', 'proj-1', ['file_id' => 'f1', 'owner_key' => 'knossos.php:file:src/A.php']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('knossos.php:file:' . $bundle['payload']['files'][0]['relative_path'], $bundle['payload']['nodes'][0]['owner_key']);
        $this->assertStringNotContainsString('src/A.php', $bundle['payload']['nodes'][0]['owner_key']);
    }

    /** Was an unsalted hash of the owner key; it is now redacted like any other owner key. */
    public function testExportStrictRedactionRewritesDiagnosticMessageAndOwnerKey(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedDiagnostic('d1', 'proj-1', 'scan-1', 'f1', 'a message naming a customer');
        $this->pdo->exec("UPDATE diagnostics SET owner_key = 'knossos.php:file:src/A.php' WHERE id = 'd1'");

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        $diagnostic = $bundle['payload']['diagnostics'][0];
        assertSame('[redacted]', $diagnostic['message'], 'A diagnostic message is free text and goes entirely.');
        assertSame('knossos.php:file:' . $bundle['payload']['files'][0]['relative_path'], $diagnostic['owner_key']);
    }

    public function testExportStrictRedactionLeavesNullOwnerKeyAsNull(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedNode('n1', 'proj-1', ['owner_key' => null]);
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedDiagnostic('d1', 'proj-1', 'scan-1', 'f1', 'm');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame(null, $bundle['payload']['nodes'][0]['owner_key']);
        assertSame(null, $bundle['payload']['diagnostics'][0]['owner_key']);
        assertSame(null, $bundle['payload']['nodes'][0]['file_id']);
    }

    public function testExportStrictRedactionAppliesToNodesEdgesAndClassifications(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedNode('n1', 'proj-1', ['owner_key' => 'knossos.php:file:src/A.php', 'attributes_json' => '{"secret":true}']);
        $this->seedEdge('e1', 'proj-1', 'n1', 'n1', ['owner_key' => 'knossos.php:file:src/A.php', 'attributes_json' => '{"secret":true}']);
        // The classifications table has no owner_key column; its attributes
        // are the redaction target there.
        $this->seedClassification('c1', 'proj-1', 'n1', 'f1', ['attributes_json' => '{"secret":true}']);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        $owner = 'knossos.php:file:' . $bundle['payload']['files'][0]['relative_path'];
        assertSame($owner, $bundle['payload']['nodes'][0]['owner_key']);
        assertSame($owner, $bundle['payload']['edges'][0]['owner_key']);
        assertSame('{}', $bundle['payload']['nodes'][0]['attributes_json']);
        assertSame('{}', $bundle['payload']['edges'][0]['attributes_json']);
        assertSame('{}', $bundle['payload']['classifications'][0]['attributes_json']);
    }

    public function testExportStrictRedactionRedactsDiagnosticMessage(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedDiagnostic('d1', 'proj-1', 'scan-1', 'f1', 'super-secret message');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('[redacted]', $bundle['payload']['diagnostics'][0]['message']);
    }

    public function testExportStrictRedactionSaltsContentHashesAndDropsTheProjectName(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1', name: 'Acme Payroll');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');
        $this->seedFile('f2', 'proj-1', 'src/B.php', 'php');

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('redacted', $bundle['payload']['project_name']);
        $hashes = array_column($bundle['payload']['files'], 'content_hash');
        assertSame(2, count($hashes));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $hashes[0]);
        assertSame($hashes[0], $hashes[1], 'Equal contents keep equal hashes within one bundle.');
        $this->assertNotSame('h', $hashes[0]);
    }

    /**
     * Was "does not touch boundaries": a boundary's matcher kept the
     * directory it names. A name that is not a path stays; the directory goes.
     */
    public function testExportStrictRedactionKeepsABoundaryNameAndRedactsItsDirectory(): void
    {
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->pdo->prepare('INSERT INTO boundaries (id, project_id, name, matcher_json, source) VALUES (:id, :project, :name, :matcher, :source)')->execute([
            'id' => 'b1',
            'project' => 'proj-1',
            'name' => 'Core',
            'matcher' => '{"type":"path_prefix","value":"src/Domain/"}',
            'source' => 'explicit',
        ]);

        $bundle = (new GraphBundleDecoder())->decodeAndValidate($this->service->export('proj-1', 'strict'));

        assertSame('Core', $bundle['payload']['boundaries'][0]['name']);
        $this->assertMatchesRegularExpression('#^\{"type":"path_prefix","value":"redacted-dir/[0-9a-f]{24}/"\}$#D', $bundle['payload']['boundaries'][0]['matcher_json']);
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
        // The duplicate check runs inside BEGIN IMMEDIATE; the aborted second
        // import must not leave SQLite's writer slot held open.
        assertSame(false, $this->pdo->inTransaction());
        $this->assertNotFalse($this->pdo->query('SELECT 1')->fetchColumn());
    }

    public function testExportLeavesNoOpenTransaction(): void
    {
        // export wraps its seven-table read in a transaction; it must be
        // committed (not left dangling) once the bundle is produced.
        $this->seedProjectAndScan('proj-1', 'scan-1');
        $this->seedFile('f1', 'proj-1', 'src/A.php', 'php');

        $this->service->export('proj-1', 'none');

        assertSame(false, $this->pdo->inTransaction());
    }

    public function testExportRollsBackReadTransactionOnFailure(): void
    {
        // No active snapshot: export throws from inside the read transaction and
        // must roll it back rather than leave it open.
        $this->pdo->prepare('INSERT INTO projects (id, name, active_scan_id) VALUES (:id, :name, NULL)')
            ->execute(['id' => 'proj-1', 'name' => 'P']);

        $error = captureThrows(
            fn () => $this->service->export('proj-1', 'none'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('no active snapshot', $error->getMessage());
        assertSame(false, $this->pdo->inTransaction());
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
    language TEXT,
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
            'content_hash' => 'abcdef0123456789',
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
            'scan' => ['scanner_set_hash' => 'abcdef0123456789', 'finished_at' => '2025-01-01T00:00:00+00:00'],
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
