<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Bundle;

use InvalidArgumentException;
use Knossos\Bundle\GraphBundleService;
use Knossos\Classification\TestModuleRule;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class BundleTest extends KnossosTestCase
{
    #[Group('bundle')]
    public function testPortableGraphBundlesAreDeterministicChecksummedRedactedAndAtomic(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/configured';
        $database = tempnam(sys_get_temp_dir(), 'knossos-bundle-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate bundle database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Bundle Source');
            $service = new GraphBundleService($pdo);
            $first = $service->export($scan->projectId);
            $second = $service->export($scan->projectId);
            assertSame($first, $second);
            $decoded = json_decode((string) gzdecode($first), true, 128, JSON_THROW_ON_ERROR);
            assertSame('knossos.graph.bundle', $decoded['manifest']['format']);
            assertSame(false, array_key_exists('root_realpath', $decoded['payload']));
            assertSame('sha256:' . hash('sha256', json_encode(canonicalJsonValue($decoded['payload']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $decoded['manifest']['checksum']);

            // Redacted exports were byte-identical too, because the path hash
            // was unsalted. Each export now draws its own salt: two `paths`
            // exports of one snapshot differ, and both still import whole.
            $pathsBytes = $service->export($scan->projectId, 'paths');
            $pathsAgain = $service->export($scan->projectId, 'paths');
            assertSame(false, $pathsBytes === $pathsAgain);
            $paths = json_decode((string) gzdecode($pathsBytes), true, 128, JSON_THROW_ON_ERROR);
            assertSame(true, str_starts_with($paths['payload']['files'][0]['relative_path'], 'redacted/'));
            $pathsFacts = $paths['manifest']['fact_count'];
            assertSame($pathsFacts, $service->import($pathsBytes)->data['fact_count']);
            assertSame($pathsFacts, $service->import($pathsAgain)->data['fact_count']);
            assertSame($decoded['manifest']['fact_count'], $pathsFacts);
            $strict = json_decode((string) gzdecode($service->export($scan->projectId, 'strict')), true, 128, JSON_THROW_ON_ERROR);
            assertSame('{}', $strict['payload']['nodes'][0]['attributes_json']);

            $sourceNodes = (int) $pdo->query('SELECT COUNT(*) FROM nodes WHERE project_id = ' . $pdo->quote($scan->projectId))->fetchColumn();
            $sourceEdges = (int) $pdo->query('SELECT COUNT(*) FROM edges WHERE project_id = ' . $pdo->quote($scan->projectId))->fetchColumn();
            $imported = $service->import($first, 'Imported Bundle');
            assertSame($sourceNodes, (int) $pdo->query('SELECT COUNT(*) FROM nodes WHERE project_id = ' . $pdo->quote($imported->projectId))->fetchColumn());
            assertSame($sourceEdges, (int) $pdo->query('SELECT COUNT(*) FROM edges WHERE project_id = ' . $pdo->quote($imported->projectId))->fetchColumn());
            $importedRoot = (string) $pdo->query('SELECT root_realpath FROM projects WHERE id = ' . $pdo->quote($imported->projectId))->fetchColumn();
            assertSame(true, str_starts_with($importedRoot, 'bundle://'));
            assertSame(false, str_contains($importedRoot, $root));
            assertThrows(fn() => $service->import($first), InvalidArgumentException::class);

            $tampered = $decoded;
            $tampered['payload']['project_name'] = 'Tampered';
            $tamperedBytes = gzencode(json_encode(canonicalJsonValue($tampered), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9, ZLIB_ENCODING_GZIP);
            $projectsBefore = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
            assertThrows(fn() => $service->import((string) $tamperedBytes), InvalidArgumentException::class);
            assertSame($projectsBefore, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());

            $invalidFact = $decoded;
            $invalidFact['payload']['nodes'][0]['confidence'] = 'untrusted';
            $invalidPayloadJson = json_encode(canonicalJsonValue($invalidFact['payload']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $invalidFact['manifest']['checksum'] = 'sha256:' . hash('sha256', $invalidPayloadJson);
            $invalidFact['manifest']['uncompressed_bytes'] = strlen($invalidPayloadJson);
            $invalidFactBytes = gzencode(json_encode(canonicalJsonValue($invalidFact), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9, ZLIB_ENCODING_GZIP);
            assertThrows(fn() => $service->import((string) $invalidFactBytes), InvalidArgumentException::class);
            assertSame($projectsBefore, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
            assertThrows(fn() => $service->import('not-gzip'), InvalidArgumentException::class);
            assertThrows(fn() => $service->export($scan->projectId, 'unknown'), InvalidArgumentException::class);
        } finally {
            unset($service, $pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    /**
     * Redaction now re-keys every id and rewrites names in every column; an
     * import must still see the same graph: the same number of every fact and
     * the same edges between the same (redacted) names.
     */
    #[Group('bundle')]
    public function testARedactedBundleImportsAsTheSameGraph(): void
    {
        $root = self::repositoryRoot() . '/tests/Fixtures/configured';
        $database = tempnam(sys_get_temp_dir(), 'knossos-bundle-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate bundle database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $source = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Bundle Source')->projectId;
            $service = new GraphBundleService($pdo);
            $count = static function (string $table, string $project) use ($pdo): int {
                $statement = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE project_id = :project');
                $statement->execute(['project' => $project]);
                return (int) $statement->fetchColumn();
            };
            $edges = static function (string $project) use ($pdo): array {
                $statement = $pdo->prepare('SELECT e.kind, s.canonical_name, t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE e.project_id = :project');
                $statement->execute(['project' => $project]);
                $rows = array_map(static fn(array $row): string => implode("\0", $row), $statement->fetchAll(\PDO::FETCH_NUM));
                sort($rows, SORT_STRING);
                return $rows;
            };
            foreach (['paths', 'strict'] as $mode) {
                $bytes = $service->export($source, $mode);
                $payload = json_decode((string) gzdecode($bytes), true, 128, JSON_THROW_ON_ERROR)['payload'];
                $imported = $service->import($bytes)->projectId;
                foreach (['files', 'nodes', 'edges', 'classifications', 'boundaries', 'boundary_memberships', 'diagnostics'] as $table) {
                    assertSame($count($table, $source), $count($table, $imported), $mode . ' ' . $table);
                }
                assertSame(true, $count('boundary_memberships', $source) > 0);
                $names = array_column($payload['nodes'], 'canonical_name', 'id');
                $expected = array_map(static fn(array $edge): string => implode("\0", [$edge['kind'], $names[$edge['source_id']], $names[$edge['target_id']]]), $payload['edges']);
                sort($expected, SORT_STRING);
                assertSame($expected, $edges($imported), $mode);
                assertSame(count($edges($source)), count($expected));
            }
        } finally {
            unset($service, $pdo);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('bundle')]
    public function testFileMetricsQueryFiltersSortsPaginatesAndSurvivesBundleRoundTrips(): void
    {
        $root = sys_get_temp_dir() . '/knossos-metrics-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o700, true)) {
            throw new RuntimeException('Unable to create file-metrics fixture.');
        }
        // Physical line counts: a=1, bee=2, cee=3 (CRLF), dee=4 (no trailing newline).
        file_put_contents($root . '/src/a.php', "<?php\n");
        file_put_contents($root . '/src/bee.php', "<?php\necho 1;\n");
        file_put_contents($root . '/src/cee.php', "<?php\r\necho 2;\r\necho 3;\r\n");
        file_put_contents($root . '/src/dee.php', "<?php\necho 4;\necho 5;\necho 6;");
        $database = tempnam(sys_get_temp_dir(), 'knossos-metrics-db-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate file-metrics database.');
        }

        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Metrics Source');
            $queries = new ArchitectureQueryService($pdo);

            $ranked = $queries->fileMetrics($scan->projectId);
            $byPath = [];
            foreach ($ranked->data['files'] as $file) {
                $byPath[$file['path']] = $file['line_count'];
            }
            assertSame(1, $byPath['src/a.php']);
            assertSame(2, $byPath['src/bee.php']);
            assertSame(3, $byPath['src/cee.php']);
            assertSame(4, $byPath['src/dee.php']);
            assertSame(4, $ranked->data['total']);
            // Default ranking is line_count descending; the largest file comes first.
            assertSame('src/dee.php', $ranked->data['files'][0]['path']);
            // The active snapshot identity is reported so rankings are reproducible.
            assertSame($scan->snapshotId, $ranked->snapshotId);

            // Path substring filter.
            $filtered = $queries->fileMetrics($scan->projectId, pathContains: 'cee');
            assertSame(1, $filtered->data['total']);
            assertSame('src/cee.php', $filtered->data['files'][0]['path']);

            // Language filter (present and absent).
            assertSame(4, $queries->fileMetrics($scan->projectId, language: 'php')->data['total']);
            $none = $queries->fileMetrics($scan->projectId, language: 'python');
            assertSame(0, $none->data['total']);
            assertSame([], $none->data['files']);

            // Pagination by path ascending.
            $page1 = $queries->fileMetrics($scan->projectId, sortBy: 'path', order: 'asc', limit: 2, offset: 0);
            assertSame(['src/a.php', 'src/bee.php'], array_map(fn(array $f): string => $f['path'], $page1->data['files']));
            assertSame(true, $page1->truncated);
            $page2 = $queries->fileMetrics($scan->projectId, sortBy: 'path', order: 'asc', limit: 2, offset: 2);
            assertSame(['src/cee.php', 'src/dee.php'], array_map(fn(array $f): string => $f['path'], $page2->data['files']));
            assertSame(false, $page2->truncated);

            // Invalid sort/order are rejected.
            assertThrows(fn() => $queries->fileMetrics($scan->projectId, sortBy: 'mtime'), InvalidArgumentException::class);
            assertThrows(fn() => $queries->fileMetrics($scan->projectId, order: 'sideways'), InvalidArgumentException::class);

            // Bundle export carries line_count and import preserves it.
            $service = new GraphBundleService($pdo);
            $bundle = $service->export($scan->projectId);
            $decoded = json_decode((string) gzdecode($bundle), true, 128, JSON_THROW_ON_ERROR);
            foreach ($decoded['payload']['files'] as $file) {
                assertSame(true, array_key_exists('line_count', $file));
            }
            $imported = $service->import($bundle, 'Imported Metrics');
            $importedMetrics = $queries->fileMetrics($imported->projectId);
            $importedByPath = [];
            foreach ($importedMetrics->data['files'] as $file) {
                $importedByPath[$file['path']] = $file['line_count'];
            }
            assertSame($byPath, $importedByPath);
        } finally {
            unset($service, $queries, $pdo);
            foreach (['src/a.php', 'src/bee.php', 'src/cee.php', 'src/dee.php'] as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('bundle')]
    public function testStalenessprobeReportsFreshThenStaleAfterAFileChanges(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('php-scanner');
        try {
            // finished_at is set at scan time; use a wall clock a few seconds later.
            $probe = new \Knossos\Query\StalenessProbe($pdo, fn(): int => time() + 5);
            $fresh = $probe->probe($projectId);
            assertSame('fresh', $fresh['state']);
            assertSame(true, is_int($fresh['age_seconds']));
            assertSame(0, $fresh['changed_files_since']);

            // Rewrite a scanned file's contents so its hash no longer matches the stored one.
            $target = $root . '/src/Architecture.php';
            file_put_contents($target, file_get_contents($target) . "\n// drift\n");
            $stale = (new \Knossos\Query\StalenessProbe($pdo, fn(): int => time() + 5))->probe($projectId);
            assertSame('stale', $stale['state']);
            assertContains('rescan', $stale['guidance']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testToolserviceRejectsAnInvalidVerbosity(): void
    {
        [$tools, , $root] = $this->buildToolServiceWithScan('php-scanner');
        try {
            assertThrows(
                fn() => $tools->call('architecture_summary', ['project_id' => 'x', 'verbosity' => 'loud']),
                InvalidArgumentException::class,
            );
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testToolserviceNormalisesTheVerbosityArgumentLikeEveryOtherStringArgument(): void
    {
        // Surrounding whitespace is invisible in a JSON payload, and every other
        // literal string argument is trimmed before it is matched. Reading
        // verbosity straight out of $arguments made ' full ' an input error.
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('php-scanner');
        try {
            $json = $tools->call('architecture_summary', ['project_id' => $projectId, 'verbosity' => "  full\n"])->jsonSerialize();
            assertSame('full', $json['meta']['verbosity']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testToolserviceEnrichesQueryResultsWithStalenessAndMeta(): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('php-scanner');
        try {
            $envelope = $tools->call('architecture_summary', ['project_id' => $projectId]);
            $json = $envelope->jsonSerialize();
            assertSame('compact', $json['meta']['verbosity']);
            assertArrayContains($json['staleness']['state'], ['fresh', 'stale']); // scanned, so not missing
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testReadToolsAdvertiseAVerbosityInput(): void
    {
        [$tools, , $root] = $this->buildToolServiceWithScan('php-scanner');
        try {
            $defs = $tools->definitions();
            $byName = [];
            foreach ($defs as $d) {
                $byName[$d['name']] = $d;
            }
            $verbosity = $byName['impact_analysis']['inputSchema']['properties']['verbosity'] ?? null;
            assertSame('string', $verbosity['type']);
            assertSame(['compact', 'full'], $verbosity['enum']);
            assertSame('compact', $verbosity['default']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testToolDescriptionsAreIntentFirstNotJargonFirst(): void
    {
        [$tools, , $root] = $this->buildToolServiceWithScan('php-scanner');
        try {
            $defs = $tools->definitions();
            $byName = [];
            foreach ($defs as $d) {
                $byName[$d['name']] = $d;
            }
            // impact_analysis should tell an agent WHEN to reach for it.
            assertContains('before', strtolower($byName['impact_analysis']['description']));
            assertContains('depend', strtolower($byName['impact_analysis']['description']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testTestmoduleruleTagsTestPathsAndLeavesProductCodeAlone(): void
    {
        $rule = new TestModuleRule();
        assertSame('core.test.modules.v1', $rule->id());

        $node = fn(string $path): NodeFact => new NodeFact(
            'n:' . $path,
            'module',
            $path,
            basename($path),
            Origin::Ast,
            Confidence::Certain,
            new Evidence($path, 1, 2),
        );

        $tagged = [
            'src/__tests__/handler.test.ts',
            'src/handler.test.ts',
            'src/handler.spec.js',
            'tests/test_worker.py',
            'tests/Unit/ThingTest.php',
        ];
        foreach ($tagged as $path) {
            $facts = $rule->classify($node($path));
            assertSame(1, count($facts));
            assertSame('quality.test_module', $facts[0]->role);
        }

        $untagged = ['src/handler.ts', 'src/latest/news.ts', 'src/contest.ts', 'src/protester.php'];
        foreach ($untagged as $path) {
            assertSame([], $rule->classify($node($path)));
        }
    }

    #[Group('bundle')]
    public function testDeadCodeNominationSkipsTestModules(): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-modules');
        try {
            $envelope = $tools->call('architecture_health', ['project_id' => $projectId, 'limit' => 100]);
            $dead = $envelope->jsonSerialize()['data']['dead_code_candidates'] ?? [];

            $names = array_map(
                fn(array $c): string => $c['component'],
                $dead,
            );

            // The test module is discovered by a runner's glob, never imported. It must
            // not be nominated just because its in-degree is 0.
            foreach ($names as $name) {
                assertSame(false, str_contains($name, '__tests__'));
            }

            // Guard against the rule over-matching and silently emptying the result:
            // the unreferenced product module must still be nominated.
            $orphanNominated = false;
            foreach ($names as $name) {
                if (str_contains($name, 'orphan')) {
                    $orphanNominated = true;
                }
            }
            assertSame(true, $orphanNominated);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('bundle')]
    public function testDeadCodeReasonsReportAbsenceOfEvidenceNotProvenAbsence(): void
    {
        [$tools, $projectId, $root] = $this->buildToolServiceWithScan('test-modules');
        try {
            $envelope = $tools->call('architecture_health', ['project_id' => $projectId, 'limit' => 100]);
            $dead = $envelope->jsonSerialize()['data']['dead_code_candidates'] ?? [];

            // The fixture's orphan module guarantees at least one candidate; without
            // it the loop below would assert nothing.
            assertNotSame(0, count($dead));

            foreach ($dead as $candidate) {
                // The old wording asserted a universal negative the analyser cannot establish.
                assertSame(
                    false,
                    str_contains($candidate['reason'], 'No selected inbound static dependency references this component.'),
                );
                // Both reachability classes phrase the same absence: `test_only`
                // names the references it DID find and says what they are not,
                // rather than claiming there are none.
                assertContains(
                    $candidate['reachability'] === 'test_only' ? 'come from test code' : 'No inbound static reference was found',
                    $candidate['reason'],
                );
                // Per-candidate uncertainty text was folded into a single method-level
                // warning; candidates no longer carry their own uncertainty key.
                assertSame(false, array_key_exists('uncertainty', $candidate));
            }
        } finally {
            $this->removeTempTree($root);
        }
    }
}
