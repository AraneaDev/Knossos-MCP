<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use Knossos\Bundle\GraphBundleDecoder;
use Knossos\Bundle\GraphBundleService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The published bundle schema and the code must describe the same format.
 *
 * The schema once declared version 1 and a 50 MB ceiling while the exporter
 * wrote version 2 and the importer refused anything over 8 MB, so an editor or
 * a CI step validating a real bundle against the published file was told the
 * bundle was invalid. Nothing compared the two.
 */
#[Group('bundle')]
final class BundleSchemaAgreementTest extends KnossosTestCase
{
    private const SCHEMA = '/schemas/graph-bundle-v2.schema.json';

    /** @return array<string, mixed> */
    private static function schema(): array
    {
        return json_decode((string) file_get_contents(self::repositoryRoot() . self::SCHEMA), true, 512, JSON_THROW_ON_ERROR);
    }

    public function testSchemaStatesTheVersionAndLimitsTheCodeEnforces(): void
    {
        $manifest = self::schema()['properties']['manifest']['properties'];

        assertSame(GraphBundleDecoder::FORMAT, $manifest['format']['const']);
        assertSame(GraphBundleDecoder::VERSION, $manifest['version']['const']);
        assertSame(GraphBundleDecoder::MAX_UNCOMPRESSED_BYTES, $manifest['uncompressed_bytes']['maximum']);
        assertSame(GraphBundleDecoder::MAX_FACTS, $manifest['fact_count']['maximum']);
        assertSame('https://knossos.local/schemas/graph-bundle-v' . GraphBundleDecoder::VERSION . '.schema.json', self::schema()['$id']);
    }

    public function testASchemaFileExistsForNoOtherBundleVersion(): void
    {
        $files = glob(self::repositoryRoot() . '/schemas/graph-bundle-v*.schema.json') ?: [];

        assertSame([self::repositoryRoot() . self::SCHEMA], $files);
    }

    public function testABundleTheExporterWritesHasExactlyTheFieldsTheSchemaDeclares(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/configured';
        $database = tempnam(sys_get_temp_dir(), 'knossos-bundle-schema-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate bundle database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Schema Source');
            // The fixture scans clean, and a table with no rows proves nothing about its columns.
            $scanId = (string) $pdo->query('SELECT active_scan_id FROM projects WHERE id = ' . $pdo->quote($scan->projectId))->fetchColumn();
            $pdo->prepare('INSERT INTO diagnostics(id, project_id, scan_id, file_id, severity, code, message, start_line, end_line, owner_key) VALUES (?, ?, ?, NULL, ?, ?, ?, ?, ?, ?)')
                ->execute(['diag-schema', $scan->projectId, $scanId, 'warning', 'SCHEMA_PROBE', 'A diagnostic for the schema test.', 3, 4, 'owner:probe']);
            $bundle = json_decode((string) gzdecode((new GraphBundleService($pdo))->export($scan->projectId)), true, 128, JSON_THROW_ON_ERROR);
        } finally {
            @unlink($database);
        }
        $schema = self::schema();

        $this->assertSameKeys($schema['properties']['manifest']['properties'], $bundle['manifest'], 'manifest');
        $this->assertSameKeys($schema['properties']['payload']['properties'], $bundle['payload'], 'payload');
        $this->assertSameKeys($schema['properties']['payload']['properties']['scan']['properties'], $bundle['payload']['scan'], 'scan');
        $checked = [];
        foreach ($schema['properties']['payload']['properties'] as $table => $declared) {
            if (($declared['type'] ?? null) !== 'array') {
                continue;
            }
            foreach ($bundle['payload'][$table] as $row) {
                $this->assertSameKeys($declared['items']['properties'], $row, $table);
                foreach ($row as $column => $value) {
                    $types = (array) $declared['items']['properties'][$column]['type'];
                    assertSame(true, in_array(self::jsonType($value), $types, true), $table . '.' . $column . ' is ' . self::jsonType($value) . ', the schema allows ' . implode('|', $types));
                }
                $checked[$table] = ($checked[$table] ?? 0) + 1;
            }
        }
        $tables = array_keys(array_filter($schema['properties']['payload']['properties'], static fn(array $declared): bool => ($declared['type'] ?? null) === 'array'));
        sort($tables);
        $exercised = array_keys($checked);
        sort($exercised);
        assertSame($tables, $exercised, 'Every table must have at least one row checked; one with none passes without proving its columns.');
    }

    /**
     * @param array<string, mixed> $declared
     * @param array<string, mixed> $actual
     */
    private function assertSameKeys(array $declared, array $actual, string $scope): void
    {
        $left = array_keys($declared);
        $right = array_keys($actual);
        sort($left);
        sort($right);
        assertSame($left, $right, 'The ' . $scope . ' fields differ between the schema and the exporter.');
    }

    private static function jsonType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_int($value) => 'integer',
            is_string($value) => 'string',
            default => get_debug_type($value),
        };
    }
}
