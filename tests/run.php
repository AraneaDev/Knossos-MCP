<?php

declare(strict_types=1);

use Knossos\Application;
use Knossos\Boundary\BoundaryInference;
use Knossos\Bundle\GraphBundleService;
use Knossos\Classification\ClassificationEngine;
use Knossos\Classification\ClassificationFact;
use Knossos\Classification\ExplicitRoleRule;
use Knossos\Classification\NameSuffixRule;
use Knossos\Cli\CliOptionParser;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\FileFingerprint;
use Knossos\Discovery\JsonConfig;
use Knossos\Discovery\ProjectDiscoverer;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\HttpEndpoint;
use Knossos\Mcp\HttpSessionStore;
use Knossos\Mcp\StdioServer;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\SemanticRanker;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Reconciliation\ReconciliationException;
use Knossos\Runtime\DoctorService;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ProjectWriterLock;
use Knossos\Scan\ScanBusyException;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\RelativePath;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Sdk\FixtureBuilder;
use Knossos\Scanner\Worker\ProcessScannerClient;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use Knossos\Scanner\Worker\WorkerLimits;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Watch\WatchService;

require dirname(__DIR__) . '/vendor/autoload.php';

$tests = [];
$testGroups = [];

$tests['application exposes a version'] = static function (): void {
    assertSame('0.1.0-dev', Application::VERSION);
};

$tests['CLI failures expose stable automation diagnostics'] = static function (): void {
    [$exit, , $stderr] = runFixtureCommandOutput([PHP_BINARY, dirname(__DIR__) . '/bin/knossos', 'unknown-command']);
    assertSame(2, $exit);
    assertContains('KNOSSOS_INVALID_ARGUMENT:', $stderr);
};
$testGroups['CLI failures expose stable automation diagnostics'] = 'cli';

$tests['CLI option parsing preserves repeated values flags and positional order'] = static function (): void {
    $parser = new CliOptionParser();
    [$positionals, $options] = $parser->parse(['project', '--edge-kind=calls', 'target', '--edge-kind=imports', '--json']);
    assertSame(['project', 'target'], $positionals);
    assertSame(['calls', 'imports'], $options['edge-kind']);
    assertSame(['true'], $options['json']);
    assertSame(12, $parser->integer(['limit' => ['12']], 'limit', 20, 1, 100));
    assertThrows(static fn() => $parser->single(['limit' => ['1', '2']], 'limit'), InvalidArgumentException::class);
};
$testGroups['CLI option parsing preserves repeated values flags and positional order'] = 'cli';

$tests['CLI router keeps help version and unknown command behavior stable'] = static function (): void {
    $binary = dirname(__DIR__) . '/bin/knossos';
    [$versionExit, $versionOutput] = runFixtureCommandOutput([PHP_BINARY, $binary, '--version', '--json']);
    assertSame(0, $versionExit);
    assertSame(['name' => 'knossos', 'version' => Application::VERSION], json_decode(trim($versionOutput), true, 512, JSON_THROW_ON_ERROR));
    [$helpExit, $helpOutput] = runFixtureCommandOutput([PHP_BINARY, $binary, '--help']);
    assertSame(0, $helpExit);
    assertContains('Knossos architecture intelligence', $helpOutput);
};
$testGroups['CLI router keeps help version and unknown command behavior stable'] = 'cli';

$tests['generated CLI MCP references and documentation links stay current'] = static function (): void {
    $root = dirname(__DIR__);
    [$referenceExit, $referenceOutput, $referenceErrors] = runFixtureCommandOutput([PHP_BINARY, $root . '/tools/generate-reference.php', '--check']);
    if ($referenceExit !== 0) {
        throw new RuntimeException($referenceErrors);
    }
    assertContains('Generated reference is current.', $referenceOutput);
    assertContains('knossos architecture-summary', (string) file_get_contents($root . '/docs/CLI-REFERENCE.md'));
    assertContains('## `architecture_summary`', (string) file_get_contents($root . '/docs/MCP-REFERENCE.md'));

    [$linksExit, $linksOutput, $linksErrors] = runFixtureCommandOutput([PHP_BINARY, $root . '/tools/documentation-check.php']);
    if ($linksExit !== 0) {
        throw new RuntimeException($linksErrors);
    }
    assertContains('Documentation links passed:', $linksOutput);

    [$apiExit, $apiOutput, $apiErrors] = runFixtureCommandOutput([PHP_BINARY, $root . '/tools/api-documentation-check.php']);
    if ($apiExit !== 0) {
        throw new RuntimeException($apiErrors);
    }
    assertContains('API documentation passed:', $apiOutput);
};
$testGroups['generated CLI MCP references and documentation links stay current'] = 'documentation';

$tests['manifest round trips through JSON'] = static function (): void {
    $manifest = ScannerManifest::fromArray([
        'id' => 'knossos.typescript',
        'version' => '0.1.0',
        'protocol_version' => Protocol::VERSION,
        'output_schema_version' => Protocol::OUTPUT_SCHEMA_VERSION,
        'languages' => ['typescript'],
        'file_extensions' => ['ts', 'tsx'],
        'capabilities' => ['discover', 'cancel'],
    ]);

    $decoded = json_decode(json_encode($manifest, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    assertSame('knossos.typescript', $decoded['id']);
    assertSame(['ts', 'tsx'], $decoded['file_extensions']);
};

$tests['scanner SDK fixtures capabilities schemas and conformance runner agree'] = static function (): void {
    $node = FixtureBuilder::node('demo:class:Checkout', 'class', 'Demo\\Checkout', 'Checkout', 'src/Checkout.demo', 2, 4);
    $edge = FixtureBuilder::edge('calls', 'demo:function:run', 'demo:class:Checkout', 'src/Checkout.demo', 6);
    $contribution = FixtureBuilder::contribution('demo:file:src/Checkout.demo', [$node], [$edge]);
    $decoded = \Knossos\Scanner\Worker\ContributionDecoder::decode($contribution);
    assertSame('Demo\\Checkout', $decoded->nodes[0]->canonicalName);
    assertSame('calls', $decoded->edges[0]->kind);

    foreach (['manifest.schema.json', 'contribution.schema.json'] as $schema) {
        $decodedSchema = json_decode((string) file_get_contents(dirname(__DIR__) . '/schemas/scanner/v1/' . $schema), true, 512, JSON_THROW_ON_ERROR);
        assertSame('https://json-schema.org/draft/2020-12/schema', $decodedSchema['$schema']);
    }
    $golden = json_decode((string) file_get_contents(__DIR__ . '/Fixtures/scanner-sdk/golden.json'), true, 512, JSON_THROW_ON_ERROR);
    assertSame(Protocol::VERSION, $golden['initialize']['protocol_version']);

    $client = fakeWorkerClient('compliant');
    assertSame('knossos.fake', $client->requireCapabilities(['discover', 'cancel'])->id);
    $client->shutdown();
    $error = captureThrows(static fn() => fakeWorkerClient('compliant')->requireCapabilities(['incremental']), WorkerException::class);
    assertSame('WORKER_CAPABILITY_MISMATCH', $error->diagnosticCode);

    $process = proc_open([
        PHP_BINARY,
        dirname(__DIR__) . '/tools/scanner-conformance',
        '--require=discover',
        '--',
        PHP_BINARY,
        __DIR__ . '/Fixtures/workers/fake-worker.php',
        'compliant',
    ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start scanner conformance runner.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        throw new RuntimeException('Conformance runner failed: ' . ($stderr === false ? '' : $stderr));
    }
    $report = json_decode($stdout === false ? '' : $stdout, true, 512, JSON_THROW_ON_ERROR);
    assertSame(true, $report['conformant']);
    assertSame(['initialize', 'discover', 'empty_scan', 'shutdown'], array_column($report['checks'], 'name'));
};
$testGroups['scanner SDK fixtures capabilities schemas and conformance runner agree'] = 'scanner-sdk';

$tests['manifest rejects malformed lists'] = static function (): void {
    assertThrows(
        static fn(): ScannerManifest => ScannerManifest::fromArray([
            'id' => 'broken',
            'version' => '1',
            'protocol_version' => '1.0',
            'output_schema_version' => '1.0',
            'languages' => 'typescript',
            'file_extensions' => [],
            'capabilities' => [],
        ]),
        InvalidArgumentException::class,
    );
};

$tests['facts serialize with evidence and confidence'] = static function (): void {
    $evidence = new Evidence('src/Checkout.ts', 3, 7);
    $node = new NodeFact(
        'class:Checkout',
        'class',
        'src/Checkout.Checkout',
        'Checkout',
        Origin::Ast,
        Confidence::Certain,
        $evidence,
    );
    $edge = new EdgeFact(
        'implements',
        'class:Checkout',
        'symbol:Payable',
        Origin::Ast,
        Confidence::Certain,
        $evidence,
    );
    $diagnostic = new Diagnostic('warning', 'TS_DYNAMIC_CALL', 'Call target is dynamic.', $evidence);
    $contribution = new ScanContribution('knossos.typescript:file:src/Checkout.ts', [$node], [$edge], [$diagnostic]);

    $decoded = json_decode(json_encode($contribution, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    assertSame('certain', $decoded['nodes'][0]['confidence']);
    assertSame('src/Checkout.ts', $decoded['edges'][0]['evidence']['path']);
    assertSame('TS_DYNAMIC_CALL', $decoded['diagnostics'][0]['code']);
};

$tests['evidence rejects unsafe paths and line ranges'] = static function (): void {
    assertThrows(static fn(): Evidence => new Evidence('../secret', 1, 1), InvalidArgumentException::class);
    assertThrows(static fn(): Evidence => new Evidence('/etc/passwd', 1, 1), InvalidArgumentException::class);
    assertThrows(static fn(): Evidence => new Evidence('C:/secret.txt', 1, 1), InvalidArgumentException::class);
    assertThrows(static fn(): Evidence => new Evidence('src\\File.php', 1, 1), InvalidArgumentException::class);
    assertThrows(static fn(): Evidence => new Evidence('src/File.php', 5, 4), InvalidArgumentException::class);
    assertSame('src/Foo..php', (new Evidence('src/Foo..php', 1, 1))->relativePath);
};

$tests['relative path properties reject traversal absolute and malformed variants'] = static function (): void {
    $state = 0x5EED1234;
    $next = static function () use (&$state): int {
        $state = (int) (($state * 1103515245 + 12345) & 0x7fffffff);
        return $state;
    };
    for ($case = 0; $case < 250; ++$case) {
        $segments = [];
        $count = 1 + ($next() % 6);
        for ($segment = 0; $segment < $count; ++$segment) {
            $segments[] = 'part-' . dechex($next());
        }
        $valid = implode('/', $segments);
        RelativePath::assertValid($valid);
        foreach ([
            '', '/' . $valid, 'C:/' . $valid, 'root\\' . $valid,
            $valid . "\0tail", $valid . '/', 'root//' . $valid,
            'root/./' . $valid, 'root/../' . $valid,
        ] as $invalid) {
            assertThrows(static fn() => RelativePath::assertValid($invalid), InvalidArgumentException::class);
        }
    }
};
$testGroups['relative path properties reject traversal absolute and malformed variants'] = 'mutation-critical';

$tests['JSONC randomized strings retain comment tokens and trailing comma semantics'] = static function (): void {
    for ($case = 0; $case < 200; ++$case) {
        $token = sprintf('https://example.test/%d/*literal*/ // value', $case);
        $json = sprintf("{\n// generated comment\n\"token\":%s,\"values\":[%d,%d,],\n}\n", json_encode($token, JSON_THROW_ON_ERROR), $case, $case + 1);
        $decoded = JsonConfig::decode($json, true);
        assertSame($token, $decoded['token']);
        assertSame([$case, $case + 1], $decoded['values']);
    }
};
$testGroups['JSONC randomized strings retain comment tokens and trailing comma semantics'] = 'property';

$tests['seeded malformed configuration and contribution corpora fail closed'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-invalid-config-' . bin2hex(random_bytes(6));
    if (!mkdir($root, 0700)) {
        throw new RuntimeException('Unable to create fuzz configuration fixture.');
    }
    $invalidConfigurations = [
        ['version' => 2],
        ['version' => 1, 'unknown' => true],
        ['version' => 1, 'ignores' => ['../escape']],
        ['version' => 1, 'limits' => ['max_files' => 0]],
        ['version' => 1, 'boundaries' => [['name' => 'missing matcher']]],
        ['version' => 1, 'frameworks' => ['dynamic-framework']],
        ['version' => 1, 'quality_budgets' => ['new_cycles' => -1]],
    ];
    try {
        for ($case = 0; $case < 140; ++$case) {
            $configuration = $invalidConfigurations[$case % count($invalidConfigurations)];
            file_put_contents($root . '/knossos.json', json_encode($configuration, JSON_THROW_ON_ERROR));
            $error = captureThrows(static fn() => ProjectConfigurationLoader::load($root, [$root]), DiscoveryException::class);
            assertSame(true, str_starts_with($error->getMessage(), 'PROJECT_CONFIG_'));

            $invalidContribution = [
                'owner_key' => $case % 3 === 0 ? '' : 'owner',
                'nodes' => $case % 3 === 1 ? 'not-a-list' : [],
                'edges' => [],
                'diagnostics' => $case % 3 === 2 ? [['severity' => 1]] : [],
            ];
            $workerError = captureThrows(
                static fn() => \Knossos\Scanner\Worker\ContributionDecoder::decode($invalidContribution),
                WorkerException::class,
            );
            assertSame('WORKER_CONTRIBUTION_INVALID', $workerError->diagnosticCode);
        }
    } finally {
        @unlink($root . '/knossos.json');
        @rmdir($root);
    }
};
$testGroups['seeded malformed configuration and contribution corpora fail closed'] = 'property';

$tests['stable identifier properties are deterministic domain separated and collision free'] = static function (): void {
    $seen = [];
    for ($case = 0; $case < 1000; ++$case) {
        $root = sprintf('/workspace/generated/%08x', $case * 2654435761 & 0xffffffff);
        $project = StableId::project($root);
        assertSame($project, StableId::project($root));
        assertSame(false, isset($seen[$project]));
        $seen[$project] = true;
        assertNotSame($project, StableId::scan($project, 'same-input'));
        assertNotSame(StableId::file($project, 'same-input'), StableId::symbol($project, 'php', 'file', 'same-input'));
    }
};
$testGroups['stable identifier properties are deterministic domain separated and collision free'] = 'property';

$tests['contributions require lists of typed facts'] = static function (): void {
    $evidence = new Evidence('src/File.php', 1, 1);
    $node = new NodeFact('class:File', 'class', 'App\\File', 'File', Origin::Ast, Confidence::Certain, $evidence);

    assertThrows(
        static fn(): ScanContribution => new ScanContribution('owner', ['node' => $node]),
        InvalidArgumentException::class,
    );
    assertThrows(
        static fn(): ScanContribution => new ScanContribution('owner', ['not-a-node']),
        InvalidArgumentException::class,
    );
};

$tests['migrations are versioned and idempotent'] = static function (): void {
    $pdo = SqliteConnection::open(':memory:');
    $runner = new MigrationRunner($pdo, dirname(__DIR__) . '/migrations');

    assertSame(['001_initial_graph', '002_classifications', '003_boundary_memberships', '004_contribution_cache', '005_scan_locks', '006_http_sessions', '007_scan_snapshots', '008_occurrence_edges', '009_file_line_count'], $runner->migrate());
    assertSame([], $runner->migrate());
    assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
    $edgeSchema = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'edges'")->fetchColumn();
    assertSame(false, str_contains($edgeSchema, 'UNIQUE (project_id, kind, source_id, target_id, owner_key)'));
};
$testGroups['migrations are versioned and idempotent'] = 'store';

$tests['occurrence edge migration preserves legacy rows and permits repeated relations'] = static function (): void {
    $directory = sys_get_temp_dir() . '/knossos-edge-migration-' . bin2hex(random_bytes(6));
    mkdir($directory, 0700);
    copy(dirname(__DIR__) . '/migrations/001_initial_graph.sql', $directory . '/001_initial_graph.sql');
    // The fixture writer persists line_count, so the baseline includes migration 009.
    copy(dirname(__DIR__) . '/migrations/009_file_line_count.sql', $directory . '/009_file_line_count.sql');

    try {
        [$pdo, $repository, $ids] = storeFixture($directory);
        assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());

        copy(dirname(__DIR__) . '/migrations/008_occurrence_edges.sql', $directory . '/008_occurrence_edges.sql');
        assertSame(['008_occurrence_edges'], (new MigrationRunner($pdo, $directory))->migrate());
        assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());

        $repository->saveEdge(
            StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'src/Checkout.php:13'),
            $ids['project'],
            'calls',
            $ids['checkout'],
            $ids['invoice'],
            $ids['file'],
            13,
            13,
            'ast',
            'certain',
            [],
            'php:file:src/Checkout.php',
            $ids['scan'],
        );
        assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
    } finally {
        unset($repository, $pdo);
        foreach (glob($directory . '/*.sql') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
};
$testGroups['occurrence edge migration preserves legacy rows and permits repeated relations'] = 'store';

$tests['file sqlite connections enable WAL'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-store-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate temporary database path.');
    }

    try {
        $pdo = SqliteConnection::open($path);
        assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
        unset($pdo);
    } finally {
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['file sqlite connections enable WAL'] = 'store';

$tests['graph repository persists and traverses facts'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();

    $byCanonical = $repository->findNodesByName($ids['project'], 'App\\Checkout');
    assertSame($ids['checkout'], $byCanonical[0]['id']);
    $byDisplay = $repository->findNodesByName($ids['project'], 'InvoiceService');
    assertSame($ids['invoice'], $byDisplay[0]['id']);

    $outgoing = $repository->outgoing($ids['project'], $ids['checkout'], 'calls');
    assertSame($ids['invoice'], $outgoing[0]['target_id']);
    $incoming = $repository->incoming($ids['project'], $ids['invoice']);
    assertSame($ids['checkout'], $incoming[0]['source_id']);

    $repository->completeScan($ids['project'], $ids['scan']);
    assertSame($ids['scan'], $repository->findProject($ids['project'])['active_scan_id']);

    $repository->deleteFactsByOwner($ids['project'], 'php:file:src/Checkout.php');
    assertSame([], $repository->findNodesByName($ids['project'], 'App\\Checkout'));
    assertSame([], $repository->incoming($ids['project'], $ids['invoice']));
    assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
};
$testGroups['graph repository persists and traverses facts'] = 'store';

$tests['repository hot writes reuse prepared statements'] = static function (): void {
    [, $repository, $ids] = storeFixture();
    $property = new ReflectionProperty($repository, 'statements');
    $before = $property->getValue($repository);
    $repository->saveFile(
        $ids['file'],
        $ids['project'],
        'src/Checkout.php',
        hash('sha256', 'checkout-updated'),
        43,
        2,
        'php',
        '1.0.0',
        $ids['scan'],
    );
    $after = $property->getValue($repository);
    assertSame(count($before), count($after));
    assertSame(1, count(array_filter(array_keys($after), static fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO files'))));
};
$testGroups['repository hot writes reuse prepared statements'] = 'store';

$tests['repository transaction rolls back all writes'] = static function (): void {
    [, $repository, $ids] = storeFixture();

    assertThrows(
        static function () use ($repository, $ids): void {
            $repository->transaction(static function (SqliteGraphRepository $store) use ($ids): void {
                $id = StableId::symbol($ids['project'], 'php', 'class', 'App\\RolledBack');
                $store->saveNode(
                    $id,
                    $ids['project'],
                    'class',
                    'App\\RolledBack',
                    'RolledBack',
                    null,
                    $ids['file'],
                    20,
                    30,
                    'ast',
                    'certain',
                    [],
                    'php:file:src/RolledBack.php',
                    $ids['scan'],
                );
                throw new RuntimeException('force rollback');
            });
        },
        RuntimeException::class,
    );

    assertSame([], $repository->findNodesByName($ids['project'], 'App\\RolledBack'));
};
$testGroups['repository transaction rolls back all writes'] = 'store';

$tests['graph lookup and traversal queries use indexes'] = static function (): void {
    [$pdo, , $ids] = storeFixture();

    $nodePlan = $pdo->query(
        "EXPLAIN QUERY PLAN SELECT * FROM nodes WHERE project_id = '" . $ids['project'] . "' " .
        "AND canonical_name = 'App\\Checkout'",
    )->fetchAll();
    $edgePlan = $pdo->query(
        "EXPLAIN QUERY PLAN SELECT * FROM edges WHERE project_id = '" . $ids['project'] . "' " .
        "AND source_id = '" . $ids['checkout'] . "' AND kind = 'calls'",
    )->fetchAll();

    assertContains('nodes_project_canonical_idx', implode(' ', array_column($nodePlan, 'detail')));
    assertContains('edges_project_source_idx', implode(' ', array_column($edgePlan, 'detail')));
};
$testGroups['graph lookup and traversal queries use indexes'] = 'store';

$tests['stable IDs are deterministic and route methods are order independent'] = static function (): void {
    $project = StableId::project('shop');
    assertSame($project, StableId::project('shop'));
    assertSame(
        StableId::route($project, ['POST', 'GET'], '/checkout', 'CheckoutController'),
        StableId::route($project, ['GET', 'POST'], '/checkout', 'CheckoutController'),
    );
    assertNotSame(
        StableId::symbol($project, 'php', 'class', 'Checkout'),
        StableId::symbol($project, 'typescript', 'class', 'Checkout'),
    );
};
$testGroups['stable IDs are deterministic and route methods are order independent'] = 'store';

$tests['mixed project discovery finds language and package inputs'] = static function (): void {
    $root = dirname(__DIR__) . '/tests/Fixtures/mixed';
    $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$root]));
    $result = $discoverer->discover($root);

    assertSame([
        'frontend/src/index.ts',
        'frontend/src/legacy.js',
        'src/CheckoutService.php',
    ], array_column($result->files, 'relativePath'));
    assertSame(['typescript', 'javascript', 'php'], array_column($result->files, 'language'));
    assertSame(['composer', 'node', 'node', 'typescript'], array_column($result->units, 'kind'));
    assertSame(64, strlen($result->inputHash));
    assertSame(64, strlen($result->configurationHash));
    assertSame([], $result->diagnostics);
    assertSame(false, file_exists($root . '/SHOULD_NOT_EXIST'));

    $typescriptUnits = array_values(array_filter(
        $result->units,
        static fn($unit): bool => $unit->kind === 'typescript',
    ));
    assertSame(true, $typescriptUnits[0]->metadata['allow_js']);
    assertSame(['../shared'], $typescriptUnits[0]->metadata['references']);
    assertSame($result->inputHash, $discoverer->discover($root)->inputHash);
};
$testGroups['mixed project discovery finds language and package inputs'] = 'discovery';

$tests['discovery applies custom ignores and file limits'] = static function (): void {
    $root = dirname(__DIR__) . '/tests/Fixtures/mixed';
    $result = (new ProjectDiscoverer(new DiscoveryConfig(
        [$root],
        ['frontend/src/legacy.js'],
    )))->discover($root);
    assertSame([
        'frontend/src/index.ts',
        'src/CheckoutService.php',
    ], array_column($result->files, 'relativePath'));

    assertThrows(
        static fn() => (new ProjectDiscoverer(new DiscoveryConfig([$root], maxFiles: 1)))->discover($root),
        DiscoveryException::class,
    );

    $limited = (new ProjectDiscoverer(new DiscoveryConfig([$root], maxFileBytes: 10)))->discover($root);
    assertContains('DISCOVERY_FILE_TOO_LARGE', implode(' ', array_column($limited->diagnostics, 'code')));
};
$testGroups['discovery applies custom ignores and file limits'] = 'discovery';

$tests['discovery rejects roots and symlinks outside allowed scope'] = static function (): void {
    $base = sys_get_temp_dir() . '/knossos-discovery-' . bin2hex(random_bytes(6));
    $root = $base . '/project';
    $outside = $base . '/outside.php';
    mkdir($root, 0700, true);
    file_put_contents($outside, "<?php\n");
    symlink($outside, $root . '/escape.php');

    try {
        $discoverer = new ProjectDiscoverer(new DiscoveryConfig([$root]));
        $result = $discoverer->discover($root);
        assertSame([], $result->files);
        assertSame('DISCOVERY_SYMLINK_ESCAPE', $result->diagnostics[0]->code);
        assertThrows(static fn() => $discoverer->discover($base), DiscoveryException::class);
    } finally {
        unlink($root . '/escape.php');
        unlink($outside);
        rmdir($root);
        rmdir($base);
    }
};
$testGroups['discovery rejects roots and symlinks outside allowed scope'] = 'discovery';

$tests['JSONC parsing preserves comment-like string content'] = static function (): void {
    $decoded = JsonConfig::decode(<<<'JSON'
{
  // comment
  "url": "https://example.test/a//b",
  "items": [1, 2,],
}
JSON, true);

    assertSame('https://example.test/a//b', $decoded['url']);
    assertSame([1, 2], $decoded['items']);
};
$testGroups['JSONC parsing preserves comment-like string content'] = 'discovery';

$tests['checked-in project configuration validates merges and yields to explicit overrides'] = static function (): void {
    $root = __DIR__ . '/Fixtures/configured';
    $configuration = ProjectConfigurationLoader::load($root, [$root]);
    assertSame('knossos.jsonc', $configuration->path);
    assertSame(['ignored/**'], $configuration->ignores);
    assertSame(['symfony'], $configuration->frameworks);
    assertSame(40_000, $configuration->workerTimeoutMs);
    assertSame(2, $configuration->snapshotRetention);
    assertSame(['new_cycles' => 0, 'error_diagnostics' => 0], $configuration->qualityBudgets);

    $database = tempnam(sys_get_temp_dir(), 'knossos-configured-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate configured project database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $service = new ProjectScanService($pdo, dirname(__DIR__), [$root]);
        $first = $service->scan($root, 'Configured Fixture');
        assertSame('knossos.jsonc', $first->data['configuration']['source']);
        assertSame(40_000, $first->data['worker_execution']['request_timeout_ms']);
        assertSame(120_000, $first->data['worker_execution']['maximum_request_timeout_ms']);
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
        assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM files WHERE relative_path LIKE 'ignored/%'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'Configured Source' AND source = 'explicit'")->fetchColumn());

        $overridden = $service->scan($root, 'Configured Fixture', 100, 100_000, [], 'full', snapshotRetention: 0, workerTimeoutMs: 30_000);
        assertSame(30_000, $overridden->data['worker_execution']['request_timeout_ms']);
        assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'Configured Source' AND source = 'explicit'")->fetchColumn());
    } finally {
        unset($service, $pdo);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }

    $invalid = sys_get_temp_dir() . '/knossos-invalid-config-' . bin2hex(random_bytes(6));
    if (!mkdir($invalid, 0o700)) {
        throw new RuntimeException('Unable to create invalid configuration fixture.');
    }
    try {
        file_put_contents($invalid . '/knossos.json', '{"version":1,"unknown":true}');
        $error = captureThrows(static fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
        assertContains('PROJECT_CONFIG_UNKNOWN_KEY', $error->getMessage());
        file_put_contents($invalid . '/knossos.json', '{"version":1,"ignores":["../outside"]}');
        $error = captureThrows(static fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
        assertContains('PROJECT_CONFIG_UNSAFE', $error->getMessage());
        $invalidCases = [
            ['{"version":2}', 'VERSION_UNSUPPORTED'],
            ['{"version":1,"frameworks":["unsupported"]}', 'unsupported framework'],
            ['{"version":1,"limits":[1]}', 'limits must be an object'],
            ['{"version":1,"limits":{"max_files":0}}', 'max_files must be between'],
            ['{"version":1,"limits":{"worker_timeout_ms":999}}', 'worker_timeout_ms must be between'],
            ['{"version":1,"ignores":"invalid"}', 'ignores must be a bounded list'],
            ['{"version":1,"ignores":[""]}', 'ignores must contain non-empty strings'],
            ['{"version":1,"boundaries":"invalid"}', 'boundaries must be a bounded list'],
            ['{"version":1,"boundaries":[[]]}', 'boundaries entries must be objects'],
            ['{"version":1,"boundaries":[{"name":""}]}', 'boundary name must be non-empty'],
            ['{"version":1,"boundaries":[{"name":"Core"}]}', 'boundary must declare'],
            ['{"version":1,"boundaries":[{"name":"Core","path_prefix":"/root"}]}', 'path_prefix must be project-relative'],
            ['{"version":1,"boundaries":[{"name":"Core","namespace_prefix":7}]}', 'namespace_prefix must be a string'],
            ['{"version":1,"policies":[{"id":"","from_boundary":"Core","allow_targets":[]}]}', 'policies require non-empty'],
            ['{"version":1,"policies":[{"id":"core","from_boundary":"Core"}]}', 'policies require allow_targets'],
            ['{"version":1,"quality_budgets":{"new_cycles":-1}}', 'quality budget new_cycles'],
        ];
        foreach ($invalidCases as [$document, $message]) {
            file_put_contents($invalid . '/knossos.json', $document);
            $error = captureThrows(static fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
            assertContains($message, $error->getMessage());
        }
        file_put_contents($invalid . '/knossos.jsonc', '{"version":1}');
        $error = captureThrows(static fn() => ProjectConfigurationLoader::load($invalid, [$invalid]), DiscoveryException::class);
        assertContains('PROJECT_CONFIG_AMBIGUOUS', $error->getMessage());
    } finally {
        @unlink($invalid . '/knossos.json');
        @unlink($invalid . '/knossos.jsonc');
        @rmdir($invalid);
    }
};
$testGroups['checked-in project configuration validates merges and yields to explicit overrides'] = 'project-config';

$tests['watch orchestration debounces incremental changes recovers overflow and cancels'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-watch-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/src', 0o700, true)) {
        throw new RuntimeException('Unable to create watch fixture.');
    }
    file_put_contents($root . '/src/A.php', "<?php\nfinal class A {}\n");
    $database = tempnam(sys_get_temp_dir(), 'knossos-watch-db-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate watch database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $scanner = new ProjectScanService($pdo, dirname(__DIR__), [$root]);
        $watcher = new WatchService($scanner, [$root]);
        $changed = false;
        $overflow = $watcher->run($root, 1, 0, 1, observer: static function (array $event) use ($root, &$changed): void {
            if ($event['event'] === 'ready' && !$changed) {
                $changed = true;
                file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function changed(): void {} }\n");
                file_put_contents($root . '/src/B.php', "<?php\nfinal class B {}\n");
                file_put_contents($root . '/src/C.php', "<?php\nfinal class C {}\n");
            }
        }, maxPolls: 2);
        assertSame(1, $overflow->data['queue_overflows']);
        assertSame(1, $overflow->data['full_scans']);
        assertSame(2, $overflow->data['scans']);

        $changed = false;
        $incremental = $watcher->run($root, 1, 0, 10, observer: static function (array $event) use ($root, &$changed): void {
            if ($event['event'] === 'ready' && !$changed) {
                $changed = true;
                file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function twice(): void {} }\n");
            }
        }, maxPolls: 2);
        assertSame(1, $incremental->data['incremental_scans']);
        assertSame(0, $incremental->data['queue_overflows']);

        $token = new CancellationToken();
        $cancelled = $watcher->run($root, 1, 0, 10, $token, static function (array $event) use ($token): void {
            if ($event['event'] === 'ready') {
                $token->cancel();
            }
        }, 1);
        assertSame('cancelled', $cancelled->data['events'][array_key_last($cancelled->data['events'])]['reason']);
        assertThrows(static fn() => $watcher->run($root, 0), InvalidArgumentException::class);
    } finally {
        unset($watcher, $scanner, $pdo);
        foreach (['src/A.php', 'src/B.php', 'src/C.php'] as $relative) {
            @unlink($root . '/' . $relative);
        }
        @rmdir($root . '/src');
        @rmdir($root);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }
};
$testGroups['watch orchestration debounces incremental changes recovers overflow and cancels'] = 'watch';

$tests['watch mode retries transient scan failures and stops cleanly on fatal ones'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-watch-fail-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/src', 0o700, true)) {
        throw new RuntimeException('Unable to create watch fixture.');
    }
    file_put_contents($root . '/src/A.php', "<?php\nfinal class A {}\n");

    // Scripted scanner: the first call is the baseline scan, later calls follow the
    // provided outcome list (a Throwable is raised, anything else scans cleanly).
    $scriptedScanner = static function (array $outcomes) use ($root): ProjectScanner {
        return new class ($outcomes) implements ProjectScanner {
            private int $calls = 0;

            /** @param list<mixed> $outcomes */
            public function __construct(private array $outcomes) {}

            public function scan(
                string $root,
                ?string $name = null,
                ?int $maxFiles = null,
                ?int $maxFileBytes = null,
                ?array $explicitBoundaries = null,
                ?string $mode = null,
                ?CancellationToken $cancellation = null,
                ?int $snapshotRetention = null,
                ?int $workerTimeoutMs = null,
            ): ResultEnvelope {
                $index = $this->calls;
                ++$this->calls;
                $outcome = $this->outcomes[$index] ?? $this->outcomes[array_key_last($this->outcomes)];
                if ($outcome instanceof Throwable) {
                    throw $outcome;
                }
                return new ResultEnvelope('watch-project', 'snapshot-' . $this->calls, 'ok', ['parsed_files' => 1]);
            }
        };
    };

    $touch = 0;
    $drive = static function (ProjectScanner $scanner) use ($root, &$touch): ResultEnvelope {
        $watcher = new WatchService($scanner, [$root]);
        return $watcher->run($root, 1, 0, 10, observer: static function (array $event) use ($root, &$touch): void {
            if ($event['event'] === 'ready') {
                ++$touch;
                file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function v{$touch}(): void {} }\n");
            }
        }, maxPolls: 8);
    };

    try {
        // Worker timeout: baseline scan succeeds, the rescan times out once, then recovers.
        $recovered = $drive($scriptedScanner([
            'ok',
            new WorkerException('WORKER_TIMEOUT', 'Scanner worker request timed out.'),
            'ok',
        ]));
        $events = $recovered->data['events'];
        $errors = array_values(array_filter($events, static fn(array $e): bool => $e['event'] === 'error'));
        assertSame(1, count($errors));
        assertSame(true, $errors[0]['retryable']);
        assertSame(1, $recovered->data['scan_errors']);
        assertSame(1, count(array_filter($events, static fn(array $e): bool => $e['event'] === 'scan_completed')));
        assertSame(0, $recovered->data['pending_changes']);
        assertSame('poll_limit', $events[array_key_last($events)]['reason']);

        // Transient storage failure surfaces as a runtime exception and is also retried.
        $storage = $drive($scriptedScanner([
            'ok',
            new RuntimeException('database is locked'),
            'ok',
        ]));
        assertSame(1, $storage->data['scan_errors']);
        assertSame(1, count(array_filter($storage->data['events'], static fn(array $e): bool => $e['event'] === 'scan_completed')));
        assertSame(0, $storage->data['pending_changes']);

        // Engine-level fault is terminal: emit a non-retryable error and stop without recovery.
        $fatal = $drive($scriptedScanner([
            'ok',
            new TypeError('Return value must be of type int, string returned.'),
        ]));
        $fatalEvents = $fatal->data['events'];
        $fatalErrors = array_values(array_filter($fatalEvents, static fn(array $e): bool => $e['event'] === 'error'));
        assertSame(1, count($fatalErrors));
        assertSame(false, $fatalErrors[0]['retryable']);
        assertSame('error', $fatalEvents[array_key_last($fatalEvents)]['reason']);
        assertSame(0, count(array_filter($fatalEvents, static fn(array $e): bool => $e['event'] === 'scan_completed')));
    } finally {
        @unlink($root . '/src/A.php');
        @rmdir($root . '/src');
        @rmdir($root);
    }
};
$testGroups['watch mode retries transient scan failures and stops cleanly on fatal ones'] = 'watch';

$tests['portable graph bundles are deterministic checksummed redacted and atomic'] = static function (): void {
    $root = __DIR__ . '/Fixtures/configured';
    $database = tempnam(sys_get_temp_dir(), 'knossos-bundle-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate bundle database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $scan = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Bundle Source');
        $service = new GraphBundleService($pdo);
        $first = $service->export($scan->projectId);
        $second = $service->export($scan->projectId);
        assertSame($first, $second);
        $decoded = json_decode((string) gzdecode($first), true, 128, JSON_THROW_ON_ERROR);
        assertSame('knossos.graph.bundle', $decoded['manifest']['format']);
        assertSame(false, array_key_exists('root_realpath', $decoded['payload']));
        assertSame('sha256:' . hash('sha256', json_encode(canonicalJsonValue($decoded['payload']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), $decoded['manifest']['checksum']);

        $paths = json_decode((string) gzdecode($service->export($scan->projectId, 'paths')), true, 128, JSON_THROW_ON_ERROR);
        assertSame(true, str_starts_with($paths['payload']['files'][0]['relative_path'], 'redacted/'));
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
        assertThrows(static fn() => $service->import($first), InvalidArgumentException::class);

        $tampered = $decoded;
        $tampered['payload']['project_name'] = 'Tampered';
        $tamperedBytes = gzencode(json_encode(canonicalJsonValue($tampered), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9, ZLIB_ENCODING_GZIP);
        $projectsBefore = (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn();
        assertThrows(static fn() => $service->import((string) $tamperedBytes), InvalidArgumentException::class);
        assertSame($projectsBefore, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());

        $invalidFact = $decoded;
        $invalidFact['payload']['nodes'][0]['confidence'] = 'untrusted';
        $invalidPayloadJson = json_encode(canonicalJsonValue($invalidFact['payload']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $invalidFact['manifest']['checksum'] = 'sha256:' . hash('sha256', $invalidPayloadJson);
        $invalidFact['manifest']['uncompressed_bytes'] = strlen($invalidPayloadJson);
        $invalidFactBytes = gzencode(json_encode(canonicalJsonValue($invalidFact), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 9, ZLIB_ENCODING_GZIP);
        assertThrows(static fn() => $service->import((string) $invalidFactBytes), InvalidArgumentException::class);
        assertSame($projectsBefore, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        assertThrows(static fn() => $service->import('not-gzip'), InvalidArgumentException::class);
        assertThrows(static fn() => $service->export($scan->projectId, 'unknown'), InvalidArgumentException::class);
    } finally {
        unset($service, $pdo);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }
};
$testGroups['portable graph bundles are deterministic checksummed redacted and atomic'] = 'bundle';

$tests['file fingerprint counts physical lines and preserves the content hash'] = static function (): void {
    $cases = [
        ['', 0],
        ["line\n", 1],
        ['line', 1],
        ["a\nb", 2],
        ["a\nb\n", 2],
        ["a\r\nb\r\n", 2],
        ["\n", 1],
        ["\n\n\n", 3],
    ];
    foreach ($cases as [$content, $expected]) {
        $path = tempnam(sys_get_temp_dir(), 'knossos-fp-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate fingerprint fixture.');
        }
        try {
            file_put_contents($path, $content);
            $fingerprint = FileFingerprint::compute($path);
            assertSame($expected, $fingerprint?->lineCount);
            assertSame(hash('sha256', $content), $fingerprint?->contentHash);
        } finally {
            @unlink($path);
        }
    }
    // Large bounded file: exactly N newline-terminated physical lines.
    $large = tempnam(sys_get_temp_dir(), 'knossos-fp-large-');
    if ($large === false) {
        throw new RuntimeException('Unable to allocate large fingerprint fixture.');
    }
    try {
        file_put_contents($large, str_repeat("x\n", 5000));
        assertSame(5000, FileFingerprint::compute($large)?->lineCount);
    } finally {
        @unlink($large);
    }
    // An unreadable path returns null rather than throwing mid-discovery.
    assertSame(null, FileFingerprint::compute(sys_get_temp_dir() . '/knossos-missing-' . bin2hex(random_bytes(6))));
};
$testGroups['file fingerprint counts physical lines and preserves the content hash'] = 'store';

$tests['file metrics query filters sorts paginates and survives bundle round-trips'] = static function (): void {
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
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $scan = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Metrics Source');
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
        assertSame(['src/a.php', 'src/bee.php'], array_map(static fn(array $f): string => $f['path'], $page1->data['files']));
        assertSame(true, $page1->truncated);
        $page2 = $queries->fileMetrics($scan->projectId, sortBy: 'path', order: 'asc', limit: 2, offset: 2);
        assertSame(['src/cee.php', 'src/dee.php'], array_map(static fn(array $f): string => $f['path'], $page2->data['files']));
        assertSame(false, $page2->truncated);

        // Invalid sort/order are rejected.
        assertThrows(static fn() => $queries->fileMetrics($scan->projectId, sortBy: 'mtime'), InvalidArgumentException::class);
        assertThrows(static fn() => $queries->fileMetrics($scan->projectId, order: 'sideways'), InvalidArgumentException::class);

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
};
$testGroups['file metrics query filters sorts paginates and survives bundle round-trips'] = 'bundle';

$tests['worker supervisor initializes discovers scans cancels and shuts down'] = static function (): void {
    $client = fakeWorkerClient('compliant');
    $manifest = $client->initialize();
    assertSame('knossos.fake', $manifest->id);
    assertSame('/workspace', $client->discover(['root' => '/workspace'])['root']);

    $contributions = iterator_to_array($client->scan(['request_id' => 'scan-1']));
    assertSame(1, count($contributions));
    assertSame('worker:file:src/Checkout.ts', $contributions[0]->ownerKey);
    assertSame('Checkout', $contributions[0]->nodes[0]->displayName);
    assertContains('fake worker scan log', $client->stderr());

    $client->cancel('scan-2');
    assertSame(['scan-2'], $client->discover(['root' => '/workspace'])['cancelled']);
    $client->shutdown();
};
$testGroups['worker supervisor initializes discovers scans cancels and shuts down'] = 'worker';

$tests['worker supervisor rejects protocol mismatches before discovery'] = static function (): void {
    $client = fakeWorkerClient('mismatch');
    $error = captureThrows(static fn() => $client->initialize(), WorkerException::class);
    assertSame('WORKER_PROTOCOL_VERSION_MISMATCH', $error->diagnosticCode);
};
$testGroups['worker supervisor rejects protocol mismatches before discovery'] = 'worker';

$tests['worker supervisor contains malformed crashed and unexpected workers'] = static function (): void {
    $cases = [
        'malformed' => 'WORKER_JSON_INVALID',
        'crash' => 'WORKER_EXITED',
        'unexpected_id' => 'WORKER_UNEXPECTED_RESPONSE',
    ];
    foreach ($cases as $mode => $code) {
        $error = captureThrows(static fn() => fakeWorkerClient($mode)->initialize(), WorkerException::class);
        assertSame($code, $error->diagnosticCode);
    }
};
$testGroups['worker supervisor contains malformed crashed and unexpected workers'] = 'worker';

$tests['worker supervisor enforces timeout and stream limits'] = static function (): void {
    $timeout = fakeWorkerClient('slow', new WorkerLimits(requestTimeoutMs: 30));
    $error = captureThrows(static fn() => $timeout->initialize(), WorkerException::class);
    assertSame('WORKER_TIMEOUT', $error->diagnosticCode);

    $stderr = fakeWorkerClient('stderr_flood', new WorkerLimits(maxStderrBytes: 100));
    $error = captureThrows(static fn() => iterator_to_array($stderr->scan([])), WorkerException::class);
    assertSame('WORKER_STDERR_LIMIT', $error->diagnosticCode);

    $output = fakeWorkerClient('output_flood', new WorkerLimits(maxLineBytes: 1024, maxOutputBytes: 2048));
    $error = captureThrows(static fn() => iterator_to_array($output->scan([])), WorkerException::class);
    assertSame('WORKER_OUTPUT_LIMIT', $error->diagnosticCode);
};
$testGroups['worker supervisor enforces timeout and stream limits'] = 'worker';

$tests['production worker execution policy permits valid requests beyond five seconds within a finite ceiling'] = static function (): void {
    $policy = new WorkerExecutionPolicy();
    assertSame(30_000, $policy->requestTimeoutMs);
    assertSame(120_000, $policy->metadata()['maximum_request_timeout_ms']);
    assertThrows(static fn() => new WorkerExecutionPolicy(999), InvalidArgumentException::class);
    assertThrows(static fn() => new WorkerExecutionPolicy(120_001), InvalidArgumentException::class);

    $client = fakeWorkerClient('valid_over_five_seconds', $policy->limits());
    $contributions = iterator_to_array($client->scan(['files' => ['src/Checkout.ts']]));
    assertSame(1, count($contributions));
    assertSame(1, $client->lastScanResult()['count']);
    $client->shutdown();
};
$testGroups['production worker execution policy permits valid requests beyond five seconds within a finite ceiling'] = 'worker';

$tests['worker supervisor schema-validates contributions'] = static function (): void {
    $client = fakeWorkerClient('invalid_contribution');
    $error = captureThrows(static fn() => iterator_to_array($client->scan([])), WorkerException::class);
    assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
};
$testGroups['worker supervisor schema-validates contributions'] = 'worker';

$tests['PHP worker discovers Composer and extracts labelled architecture'] = static function (): void {
    $root = __DIR__ . '/Fixtures/php-scanner';
    $client = phpWorkerClient();
    assertSame('knossos.php', $client->initialize()->id);
    assertSame(['composer.json'], $client->discover(['root' => $root])['config_files']);

    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => ['src/Architecture.php'],
    ]));
    $contribution = $contributions[0];
    $names = array_map(static fn(NodeFact $node): string => $node->canonicalName, $contribution->nodes);
    sort($names, SORT_STRING);
    $expected = [
        'Fixture\\Invoice',
        'Fixture\\LogsPayments',
        'Fixture\\LogsPayments::audit',
        'Fixture\\Payable',
        'Fixture\\Payable::pay',
        'Fixture\\PaymentService',
        'Fixture\\PaymentService::__construct',
        'Fixture\\PaymentService::pay',
        'Fixture\\UserRepository',
        'Fixture\\UserRepository::save',
        'Fixture\\runPayment',
    ];
    sort($expected, SORT_STRING);
    assertSame($expected, $names);

    $edgeTuples = array_map(
        static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
        $contribution->edges,
    );
    assertArrayContains(
        ['implements', 'php:class:Fixture\\PaymentService', 'php:interface:Fixture\\Payable'],
        $edgeTuples,
    );
    assertArrayContains(
        ['uses_trait', 'php:class:Fixture\\PaymentService', 'php:trait:Fixture\\LogsPayments'],
        $edgeTuples,
    );
    assertArrayContains(
        ['injects', 'php:class:Fixture\\PaymentService', 'php:class:Fixture\\UserRepository'],
        $edgeTuples,
    );
    assertArrayContains(
        ['constructs', 'php:method:Fixture\\PaymentService::pay', 'php:class:Fixture\\Invoice'],
        $edgeTuples,
    );
    assertArrayContains(
        ['calls', 'php:method:Fixture\\PaymentService::pay', 'php:method:Fixture\\UserRepository::save'],
        $edgeTuples,
    );
    assertArrayContains(
        ['returns', 'php:method:Fixture\\PaymentService::pay', 'php:class:Fixture\\Invoice'],
        $edgeTuples,
    );
    assertSame([], $contribution->diagnostics);
    $client->shutdown();
};
$testGroups['PHP worker discovers Composer and extracts labelled architecture'] = 'php-scanner';

$tests['PHP worker reports parse errors without executing project code'] = static function (): void {
    $root = __DIR__ . '/Fixtures/php-scanner';
    $marker = $root . '/src/EXECUTED';
    assertSame(false, file_exists($marker));

    $client = phpWorkerClient();
    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => ['src/Invalid.php', 'src/NoExecute.php'],
    ]));

    assertSame('PHP_PARSE_ERROR', $contributions[0]->diagnostics[0]->code);
    assertSame('Fixture\\NoExecute', $contributions[1]->nodes[0]->canonicalName);
    assertSame(false, file_exists($marker));
    $client->shutdown();
};
$testGroups['PHP worker reports parse errors without executing project code'] = 'php-scanner';

$tests['PHP worker output is deterministic and rejects escaping paths'] = static function (): void {
    $root = __DIR__ . '/Fixtures/php-scanner';
    $client = phpWorkerClient();
    $request = ['root' => $root, 'files' => ['src/Architecture.php']];
    $first = iterator_to_array($client->scan($request));
    $second = iterator_to_array($client->scan($request));
    assertSame(
        json_encode($first, JSON_THROW_ON_ERROR),
        json_encode($second, JSON_THROW_ON_ERROR),
    );

    $error = captureThrows(
        static fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../composer.json']])),
        WorkerException::class,
    );
    assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);

    $limited = phpWorkerClient();
    $error = captureThrows(
        static fn() => iterator_to_array($limited->scan([
            'root' => $root,
            'files' => ['src/Architecture.php'],
            'limits' => ['max_file_bytes' => 1],
        ])),
        WorkerException::class,
    );
    assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
};
$testGroups['PHP worker output is deterministic and rejects escaping paths'] = 'php-scanner';

$tests['PHP worker validates every public request boundary'] = static function (): void {
    require_once dirname(__DIR__) . '/workers/php/vendor/autoload.php';
    $server = new \KnossosPhpScanner\WorkerServer();
    $handle = new ReflectionMethod($server, 'handle');
    $root = __DIR__ . '/Fixtures/php-scanner';
    $invalidRequests = [
        [],
        ['method' => 'unknown', 'params' => []],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => 'invalid']],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => [], 'frameworks' => 'invalid']],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => [], 'limits' => ['max_files' => 0]]],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => [1]]],
        ['method' => 'discover', 'params' => []],
        ['method' => 'discover', 'params' => ['root' => $root . '/missing']],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src/Architecture.php'], 'limits' => ['max_file_bytes' => 1]]],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src//Architecture.php']]],
        ['method' => 'scan', 'params' => ['root' => $root, 'files' => ['src/Missing.php']]],
    ];
    foreach ($invalidRequests as $request) {
        assertThrows(static fn() => $handle->invoke($server, $request), \KnossosPhpScanner\WorkerInputException::class);
    }
    assertSame(null, $handle->invoke($server, ['method' => 'cancel', 'params' => []]));
};
$testGroups['PHP worker validates every public request boundary'] = 'php-scanner';

$tests['TypeScript worker discovers configs and extracts cross-project architecture'] = static function (): void {
    $root = __DIR__ . '/Fixtures/typescript-scanner';
    $client = typescriptWorkerClient();
    assertSame('knossos.typescript', $client->initialize()->id);
    $discovery = $client->discover(['root' => $root]);
    assertSame([
        'packages/app/tsconfig.json',
        'packages/shared/tsconfig.json',
        'tsconfig.base.json',
        'tsconfig.json',
    ], $discovery['config_files']);
    assertSame(3, count($discovery['package_files']));

    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => typescriptFixtureFiles(),
    ]));
    $byOwner = [];
    foreach ($contributions as $contribution) {
        $byOwner[$contribution->ownerKey] = $contribution;
    }

    $service = $byOwner['knossos.typescript:file:packages/app/src/service.ts'];
    $serviceNames = array_map(static fn(NodeFact $node): string => $node->canonicalName, $service->nodes);
    assertArrayContains('packages/app/src/service.ts#PaymentService', $serviceNames);
    assertSame(1, count(array_filter(
        $serviceNames,
        static fn(string $name): bool => $name === 'packages/app/src/service.ts#PaymentService::format',
    )));

    $edgeTuples = array_map(
        static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
        $service->edges,
    );
    assertArrayContains([
        'implements',
        'ts:class:packages/app/src/service.ts#PaymentService',
        'ts:interface:packages/shared/src/contracts.ts#Payable',
    ], $edgeTuples);
    assertArrayContains([
        'injects',
        'ts:class:packages/app/src/service.ts#PaymentService',
        'ts:class:packages/shared/src/contracts.ts#UserRepository',
    ], $edgeTuples);
    assertArrayContains([
        'constructs',
        'ts:method:packages/app/src/service.ts#PaymentService::pay',
        'ts:class:packages/shared/src/contracts.ts#Invoice',
    ], $edgeTuples);
    assertArrayContains([
        'calls',
        'ts:method:packages/app/src/service.ts#PaymentService::pay',
        'ts:method:packages/shared/src/contracts.ts#UserRepository::save',
    ], $edgeTuples);

    $sharedImports = array_values(array_filter(
        $service->edges,
        static fn(EdgeFact $edge): bool => $edge->kind === 'imports'
            && $edge->targetReference === 'ts:module:packages/shared/src/contracts.ts',
    ));
    assertSame(1, count($sharedImports));
    assertSame([false, true], $sharedImports[0]->attributes['type_only_variants']);

    $shared = $byOwner['knossos.typescript:file:packages/shared/src/contracts.ts'];
    assertSame(1, count(array_filter(
        $shared->nodes,
        static fn(NodeFact $node): bool => $node->canonicalName === 'packages/shared/src/contracts.ts#Payable',
    )));
    assertSame(false, file_exists($root . '/packages/app/src/EXECUTED'));
    $client->shutdown();
};
$testGroups['TypeScript worker discovers configs and extracts cross-project architecture'] = 'typescript-scanner';

$tests['TypeScript worker captures ESM CommonJS TSX external and compiler facts'] = static function (): void {
    $root = __DIR__ . '/Fixtures/typescript-scanner';
    $client = typescriptWorkerClient();
    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => typescriptFixtureFiles(),
    ]));
    $byOwner = [];
    foreach ($contributions as $contribution) {
        $byOwner[$contribution->ownerKey] = $contribution;
    }

    $service = $byOwner['knossos.typescript:file:packages/app/src/service.ts'];
    assertSame(1, count(array_filter(
        $service->nodes,
        static fn(NodeFact $node): bool => $node->kind === 'package' && $node->canonicalName === 'rxjs',
    )));
    assertContains('TS2307', implode(' ', array_map(
        static fn(Diagnostic $diagnostic): string => $diagnostic->code,
        $service->diagnostics,
    )));

    $index = $byOwner['knossos.typescript:file:packages/app/src/index.ts'];
    assertContains('re_exports', implode(' ', array_map(static fn(EdgeFact $edge): string => $edge->kind, $index->edges)));
    assertSame(true, (bool) array_values(array_filter(
        $index->edges,
        static fn(EdgeFact $edge): bool => ($edge->attributes['dynamic'] ?? false) === true,
    ))[0]->attributes['dynamic']);

    $legacy = $byOwner['knossos.typescript:file:packages/app/src/legacy.cjs'];
    assertSame(true, (bool) array_values(array_filter(
        $legacy->edges,
        static fn(EdgeFact $edge): bool => ($edge->attributes['commonjs'] ?? false) === true,
    ))[0]->attributes['commonjs']);

    $view = $byOwner['knossos.typescript:file:packages/app/src/view.tsx'];
    assertArrayContains('packages/app/src/view.tsx#CheckoutView', array_map(
        static fn(NodeFact $node): string => $node->canonicalName,
        $view->nodes,
    ));

    $invalid = $byOwner['knossos.typescript:file:packages/app/src/invalid.ts'];
    assertContains('TS2322', implode(' ', array_map(
        static fn(Diagnostic $diagnostic): string => $diagnostic->code,
        $invalid->diagnostics,
    )));
    $client->shutdown();
};
$testGroups['TypeScript worker captures ESM CommonJS TSX external and compiler facts'] = 'typescript-scanner';

$tests['TypeScript worker output is deterministic bounded and path safe'] = static function (): void {
    $root = __DIR__ . '/Fixtures/typescript-scanner';
    $client = typescriptWorkerClient();
    $request = ['root' => $root, 'files' => ['packages/app/src/service.ts']];
    $first = iterator_to_array($client->scan($request));
    $second = iterator_to_array($client->scan($request));
    assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));

    $error = captureThrows(
        static fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../package.json']])),
        WorkerException::class,
    );
    assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);

    $limited = typescriptWorkerClient();
    $error = captureThrows(
        static fn() => iterator_to_array($limited->scan([
            'root' => $root,
            'files' => ['packages/app/src/service.ts'],
            'limits' => ['max_file_bytes' => 1],
        ])),
        WorkerException::class,
    );
    assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
};
$testGroups['TypeScript worker output is deterministic bounded and path safe'] = 'typescript-scanner';

$tests['Python worker discovers packages and extracts static architecture without imports'] = static function (): void {
    $root = __DIR__ . '/Fixtures/python';
    $client = pythonWorkerClient();
    $manifest = $client->initialize();
    assertSame('knossos.python', $manifest->id);
    assertSame(['python'], $manifest->languages);
    assertSame([
        'pyproject.toml',
    ], $client->discover(['root' => $root])['config_files']);

    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => pythonFixtureFiles(),
    ]));
    $byOwner = [];
    foreach ($contributions as $contribution) {
        $byOwner[$contribution->ownerKey] = $contribution;
    }

    $service = $byOwner['knossos.python:file:shop/service.py'];
    $names = array_map(static fn(NodeFact $node): string => $node->canonicalName, $service->nodes);
    foreach (['shop.service', 'shop.service.Gateway', 'shop.service.Gateway::charge', 'shop.service.CheckoutService', 'shop.service.CheckoutService::checkout', 'shop.service.CheckoutService::validate'] as $name) {
        assertArrayContains($name, $names);
    }
    $checkout = array_values(array_filter($service->nodes, static fn(NodeFact $node): bool => $node->canonicalName === 'shop.service.CheckoutService'))[0];
    assertSame(['registered'], $checkout->attributes['decorators']);
    $async = array_values(array_filter($service->nodes, static fn(NodeFact $node): bool => $node->canonicalName === 'shop.service.CheckoutService::checkout'))[0];
    assertSame(true, $async->attributes['async']);
    $edges = array_map(static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $service->edges);
    assertArrayContains(['extends', 'py:class:shop.service.CheckoutService', 'py:class:shop.service.Gateway'], $edges);
    assertArrayContains(['calls', 'py:method:shop.service.CheckoutService::checkout', 'py:method:shop.service.CheckoutService::validate'], $edges);

    $api = $byOwner['knossos.python:file:shop/api.py'];
    assertArrayContains('shop.api.checkout_endpoint', array_map(static fn(NodeFact $node): string => $node->canonicalName, $api->nodes));
    assertSame(['router.get'], array_values(array_filter($api->nodes, static fn(NodeFact $node): bool => $node->kind === 'function'))[0]->attributes['decorators']);
    assertArrayContains(['calls', 'py:function:shop.api.checkout_endpoint', 'py:class:shop.service.CheckoutService'], array_map(
        static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
        $api->edges,
    ));

    $package = $byOwner['knossos.python:file:shop/__init__.py'];
    assertSame(1, count(array_filter($package->nodes, static fn(NodeFact $node): bool => $node->kind === 'package' && $node->canonicalName === 'shop')));
    assertSame('PY_SYNTAX_ERROR', $byOwner['knossos.python:file:shop/bad.py']->diagnostics[0]->code);
    assertSame(true, array_values(array_filter($byOwner['knossos.python:file:shop/contracts.pyi']->nodes, static fn(NodeFact $node): bool => $node->kind === 'module'))[0]->attributes['stub']);
    $client->shutdown();
};
$testGroups['Python worker discovers packages and extracts static architecture without imports'] = 'python-scanner';

$tests['Python worker is deterministic bounded and path safe'] = static function (): void {
    $root = __DIR__ . '/Fixtures/python';
    $client = pythonWorkerClient();
    $request = ['root' => $root, 'files' => ['shop/service.py', 'shop/api.py']];
    $first = iterator_to_array($client->scan($request));
    $second = iterator_to_array($client->scan($request));
    assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));
    $error = captureThrows(
        static fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['../pyproject.toml']])),
        WorkerException::class,
    );
    assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
    $error = captureThrows(
        static fn() => iterator_to_array($client->scan(['root' => $root, 'files' => ['shop/service.py'], 'limits' => ['max_file_bytes' => 1]])),
        WorkerException::class,
    );
    assertSame('WORKER_RPC_ERROR', $error->diagnosticCode);
};
$testGroups['Python worker is deterministic bounded and path safe'] = 'python-scanner';

$tests['Python worker gives nested functions lexical identities and call targets'] = static function (): void {
    $root = __DIR__ . '/Fixtures/python-nested';
    $client = pythonWorkerClient();
    $first = iterator_to_array($client->scan(['root' => $root, 'files' => ['nested.py']]));
    $second = iterator_to_array($client->scan(['root' => $root, 'files' => ['nested.py']]));
    $client->shutdown();
    assertSame(json_encode($first, JSON_THROW_ON_ERROR), json_encode($second, JSON_THROW_ON_ERROR));

    $contribution = $first[0];
    $byCanonical = [];
    foreach ($contribution->nodes as $node) {
        $byCanonical[$node->canonicalName] = $node;
    }
    $firstHelper = 'nested.first.<locals>.helper';
    $secondHelper = 'nested.second.<locals>.helper';
    $deeper = 'nested.second.<locals>.helper.<locals>.deeper';
    foreach (['nested.first', 'nested.second', $firstHelper, $secondHelper, $deeper] as $canonical) {
        assertSame('nested.py', $byCanonical[$canonical]->evidence->relativePath);
    }
    assertSame(false, $byCanonical[$firstHelper]->attributes['async']);
    assertSame(true, $byCanonical[$secondHelper]->attributes['async']);
    assertSame(true, $byCanonical[$deeper]->attributes['async']);

    $edges = array_map(
        static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
        $contribution->edges,
    );
    assertArrayContains(['contains', 'py:function:nested.first', 'py:function:' . $firstHelper], $edges);
    assertArrayContains(['contains', 'py:function:nested.second', 'py:function:' . $secondHelper], $edges);
    assertArrayContains(['contains', 'py:function:' . $secondHelper, 'py:function:' . $deeper], $edges);
    assertArrayContains(['calls', 'py:function:nested.first', 'py:function:' . $firstHelper], $edges);
    assertArrayContains(['calls', 'py:function:' . $firstHelper, 'py:function:' . $firstHelper], $edges);
    assertArrayContains(['calls', 'py:function:nested.second', 'py:function:' . $secondHelper], $edges);
    assertArrayContains(['calls', 'py:function:' . $secondHelper, 'py:function:' . $deeper], $edges);
    assertArrayContains(['calls', 'py:function:' . $deeper, 'py:function:' . $secondHelper], $edges);
};
$testGroups['Python worker gives nested functions lexical identities and call targets'] = 'python-scanner';

$tests['Python worker contains protocol and edge-case syntax paths'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-python-edge-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/shop', 0o700, true)) {
        throw new RuntimeException('Unable to create Python edge-case fixture.');
    }
    file_put_contents($root . '/shop/__init__.py', "\n");
    file_put_contents($root . '/shop/service.py', "class Gateway:\n    pass\n");
    file_put_contents($root . '/edge.py', <<<'PYTHON'
import json as codec
import shop.service as service
from somewhere import *

@unknown.decorator()
class Derived(service.Gateway):
    def invoke(self) -> None:
        (lambda: None)()
        self.missing()
PYTHON);
    file_put_contents($root . '/notes.txt', "not Python\n");
    symlink($root . '/edge.py', $root . '/outside.py');

    try {
        $messages = [
            'not-json',
            json_encode([], JSON_THROW_ON_ERROR),
            json_encode(['id' => 1, 'params' => (object) []], JSON_THROW_ON_ERROR),
            json_encode(['id' => 2, 'method' => 'cancel', 'params' => (object) []], JSON_THROW_ON_ERROR),
            json_encode(['id' => 3, 'method' => 'unknown', 'params' => (object) []], JSON_THROW_ON_ERROR),
            json_encode(['id' => 4, 'method' => 'discover', 'params' => ['root' => '']], JSON_THROW_ON_ERROR),
            json_encode(['id' => 5, 'method' => 'discover', 'params' => ['root' => $root . '/notes.txt']], JSON_THROW_ON_ERROR),
            json_encode(['id' => 6, 'method' => 'scan', 'params' => ['root' => $root, 'files' => 'edge.py']], JSON_THROW_ON_ERROR),
            json_encode(['id' => 7, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['bad\\path.py']]], JSON_THROW_ON_ERROR),
            json_encode(['id' => 8, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['notes.txt']]], JSON_THROW_ON_ERROR),
            json_encode(['id' => 9, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['edge.py'], 'limits' => ['max_files' => 0]]], JSON_THROW_ON_ERROR),
            json_encode(['id' => 10, 'method' => 'scan', 'params' => ['root' => $root, 'files' => ['edge.py', 'shop/service.py']]], JSON_THROW_ON_ERROR),
            json_encode(['id' => 11, 'method' => 'shutdown', 'params' => (object) []], JSON_THROW_ON_ERROR),
        ];
        $responses = runPythonWorkerProtocol($messages);
        $errors = array_values(array_filter($responses, static fn(array $frame): bool => isset($frame['error'])));
        assertSame(10, count($errors));
        assertSame(-32602, $errors[0]['error']['code']);
        assertSame('bye', array_values(array_filter(
            $responses,
            static fn(array $frame): bool => ($frame['id'] ?? null) === 11,
        ))[0]['result']['status']);
        $contributions = array_values(array_filter(
            $responses,
            static fn(array $frame): bool => ($frame['method'] ?? null) === 'scan/contribution',
        ));
        assertSame(2, count($contributions));
        $edgeContribution = array_values(array_filter(
            $contributions,
            static fn(array $frame): bool => $frame['params']['owner_key'] === 'knossos.python:file:edge.py',
        ))[0];
        assertArrayContains('extends', array_column($edgeContribution['params']['edges'], 'kind'));
    } finally {
        foreach (['outside.py', 'edge.py', 'notes.txt', 'shop/__init__.py', 'shop/service.py'] as $relative) {
            @unlink($root . '/' . $relative);
        }
        @rmdir($root . '/shop');
        @rmdir($root);
    }
};
$testGroups['Python worker contains protocol and edge-case syntax paths'] = 'python-scanner';

$tests['Python framework enrichment extracts FastAPI Django dependencies settings and tasks'] = static function (): void {
    $root = __DIR__ . '/Fixtures/python-frameworks';
    $files = [
        'app/__init__.py',
        'app/dependencies.py',
        'app/django_app.py',
        'app/fastapi_app.py',
        'app/settings.py',
    ];
    $client = pythonWorkerClient();
    $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files]));
    $client->shutdown();
    $nodes = array_merge(...array_map(static fn(ScanContribution $item): array => $item->nodes, $contributions));
    $edges = array_merge(...array_map(static fn(ScanContribution $item): array => $item->edges, $contributions));
    $diagnostics = array_merge(...array_map(static fn(ScanContribution $item): array => $item->diagnostics, $contributions));
    $byCanonical = [];
    foreach ($nodes as $node) {
        $byCanonical[$node->canonicalName] = $node;
    }

    assertSame('fastapi', $byCanonical['GET /api/orders => app.fastapi_app.list_orders']->attributes['framework']);
    assertSame('django', $byCanonical['ANY /checkout/ => checkout_view']->attributes['framework']);
    assertSame(['django.model'], $byCanonical['app.django_app.Product']->attributes['python_framework_roles']);
    assertSame(['django.middleware'], $byCanonical['app.django_app.AuditMiddleware']->attributes['python_framework_roles']);
    assertSame(['python.task'], $byCanonical['app.django_app.reconcile_orders']->attributes['python_framework_roles']);
    assertSame(['app'], $byCanonical['app.settings.INSTALLED_APPS']->attributes['value']);

    $edgeTuples = array_map(static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $edges);
    assertArrayContains(['routes_to', 'py:route:GET /api/orders => app.fastapi_app.list_orders', 'py:function:app.fastapi_app.list_orders'], $edgeTuples);
    assertArrayContains(['depends_on', 'py:function:app.fastapi_app.list_orders', 'py:function:app.dependencies.require_admin'], $edgeTuples);
    assertArrayContains(['depends_on', 'py:function:app.fastapi_app.list_orders', 'py:function:app.dependencies.load_user'], $edgeTuples);
    assertArrayContains(['mounts', 'py:module:app.fastapi_app', 'py:router:app.fastapi_app.router'], $edgeTuples);
    assertArrayContains(['uses_middleware', 'py:module:app.fastapi_app', 'py:class:app.fastapi_app.AuthenticationMiddleware'], $edgeTuples);
    assertArrayContains(['routes_to', 'py:route:ANY /products/ => ProductView', 'py:class:app.django_app.ProductView'], $edgeTuples);
    assertArrayContains(['configures', 'py:module:app.settings', 'py:setting:app.settings.INSTALLED_APPS'], $edgeTuples);
    assertSame(['PY_DYNAMIC_ROUTE_PATH', 'PY_DYNAMIC_ROUTE_PATH'], array_column($diagnostics, 'code'));

    $database = tempnam(sys_get_temp_dir(), 'knossos-python-frameworks-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate Python framework database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $result = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Python Frameworks');
        assertSame(5, $result->data['parsed_files']);
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'fastapi.route_handler'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'django.model'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'django.middleware'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'python.task'")->fetchColumn());
    } finally {
        unset($pdo);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }
};
$testGroups['Python framework enrichment extracts FastAPI Django dependencies settings and tasks'] = 'python-frameworks';

$tests['Python project scan persists classifications boundaries diagnostics and cache'] = static function (): void {
    $root = __DIR__ . '/Fixtures/python';
    $database = tempnam(sys_get_temp_dir(), 'knossos-python-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate Python database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $service = new ProjectScanService($pdo, dirname(__DIR__), [$root]);
        $first = $service->scan($root, 'Python Fixture');
        assertSame(5, $first->data['parsed_files']);
        assertSame('5', (string) $pdo->query("SELECT COUNT(*) FROM files WHERE language = 'python'")->fetchColumn());
        assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'application.service'")->fetchColumn());
        assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM boundaries WHERE name = 'python:knossos-python-fixture'")->fetchColumn());
        assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM diagnostics WHERE code = 'PY_SYNTAX_ERROR'")->fetchColumn());
        assertSame('5', (string) $pdo->query("SELECT COUNT(*) FROM contribution_cache WHERE scanner_id = 'knossos.python'")->fetchColumn());

        $second = $service->scan($root, 'Python Fixture');
        assertSame(0, $second->data['parsed_files']);
        assertSame(5, $second->data['unchanged_files']);
    } finally {
        unset($service, $pdo);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['Python project scan persists classifications boundaries diagnostics and cache'] = 'python-scanner';

$tests['NestJS enricher extracts decorator roles modules and routes without booting the app'] = static function (): void {
    $root = __DIR__ . '/Fixtures/nest';
    $files = ['src/app.module.ts', 'src/cats.controller.ts', 'src/cats.service.ts'];
    $client = typescriptWorkerClient();
    $contributions = iterator_to_array($client->scan(['root' => $root, 'files' => $files, 'config_files' => ['tsconfig.json']]));
    $client->shutdown();
    $nodes = array_merge(...array_map(static fn(ScanContribution $item): array => $item->nodes, $contributions));
    $edges = array_merge(...array_map(static fn(ScanContribution $item): array => $item->edges, $contributions));
    $roles = [];
    foreach ($nodes as $node) {
        foreach ($node->attributes['nestjs_roles'] ?? [] as $role) {
            $roles[] = $role;
        }
    }
    sort($roles);
    assertSame(['nestjs.controller', 'nestjs.module', 'nestjs.provider'], $roles);
    $routes = array_values(array_filter($nodes, static fn(NodeFact $node): bool => $node->kind === 'route'));
    assertSame(2, count($routes));
    assertSame(['GET /cats', 'POST /cats/adopt'], array_column($routes, 'displayName'));
    assertSame(2, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'routes_to')));
    assertSame(2, count(array_filter($edges, static fn(EdgeFact $edge): bool => in_array($edge->attributes['nestjs_module_field'] ?? null, ['controllers', 'providers'], true))));
    assertSame(1, count(array_filter($edges, static fn(EdgeFact $edge): bool => ($edge->attributes['nestjs_module_field'] ?? null) === 'exports')));

    $database = tempnam(sys_get_temp_dir(), 'knossos-nest-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate NestJS database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $scan = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Nest Fixture');
        assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
        assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role LIKE 'nestjs.%'")->fetchColumn());
        assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'routes_to'")->fetchColumn());
        assertSame(true, $scan->data['nodes'] >= 10);
    } finally {
        unset($pdo);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['NestJS enricher extracts decorator roles modules and routes without booting the app'] = 'nestjs';

$tests['TypeScript application enrichment covers Next React Vue state and client endpoints'] = static function (): void {
    $root = __DIR__ . '/Fixtures/typescript-app';
    $files = [
        'app/api/orders/route.ts',
        'app/layout.tsx',
        'app/page.tsx',
        'src/client.ts',
        'src/hooks.ts',
        'src/store.ts',
        'src/vue.ts',
    ];
    $client = typescriptWorkerClient();
    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => $files,
        'config_files' => ['tsconfig.json'],
    ]));
    $client->shutdown();
    $nodes = array_merge(...array_map(static fn(ScanContribution $item): array => $item->nodes, $contributions));
    $edges = array_merge(...array_map(static fn(ScanContribution $item): array => $item->edges, $contributions));
    $roles = [];
    foreach ($nodes as $node) {
        foreach ($node->attributes['typescript_framework_roles'] ?? [] as $role) {
            $roles[] = $role;
        }
    }
    foreach (['nextjs.layout', 'nextjs.page', 'nextjs.route_handler', 'nextjs.server_action', 'react.component', 'react.hook', 'state.store', 'vue.component', 'vue.composable'] as $role) {
        assertArrayContains($role, $roles);
    }
    $route = array_values(array_filter($nodes, static fn(NodeFact $node): bool => $node->kind === 'route'))[0];
    assertSame('GET /api/orders => app/api/orders/route.ts#GET', $route->canonicalName);
    $endpoints = array_values(array_filter($nodes, static fn(NodeFact $node): bool => $node->kind === 'endpoint'));
    assertSame(['GET /api/orders', 'POST /api/orders'], array_map(static fn(NodeFact $node): string => $node->canonicalName, $endpoints));
    assertSame(1, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'uses_hook')));
    assertSame(2, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'calls_endpoint')));

    $database = tempnam(sys_get_temp_dir(), 'knossos-typescript-app-');
    if ($database === false) {
        throw new RuntimeException('Unable to allocate TypeScript app database.');
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $result = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'TypeScript App');
        assertSame(7, $result->data['parsed_files']);
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'nextjs.route_handler'")->fetchColumn());
        assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'react.component'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'vue.component'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'state.store'")->fetchColumn());
    } finally {
        unset($pdo);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }
};
$testGroups['TypeScript application enrichment covers Next React Vue state and client endpoints'] = 'typescript-app';

$tests['reconciler assembles mixed scanner facts and resolves exact references'] = static function (): void {
    [$pdo, $reconciler, $request] = reconciliationFixture();
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
};
$testGroups['reconciler assembles mixed scanner facts and resolves exact references'] = 'reconciliation';

$tests['reconciliation snapshots and bundles preserve repeated edge occurrences'] = static function (): void {
    $root = __DIR__ . '/Fixtures/mixed';
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
    (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
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
};
$testGroups['reconciliation snapshots and bundles preserve repeated edge occurrences'] = 'reconciliation';

$tests['full reconciliation is stable and activates snapshots atomically'] = static function (): void {
    [$pdo, $reconciler, $request] = reconciliationFixture();
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
    assertSame($firstNodes, array_map(static fn(array $node): array => [
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
    assertThrows(static fn() => (new ArchitectureQueryService($pdo))->listSnapshots($third->projectId, offset: -1), InvalidArgumentException::class);
};
$testGroups['full reconciliation is stable and activates snapshots atomically'] = 'reconciliation';

$tests['snapshot diff reports bounded architectural changes and rename heuristics'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $repository->completeScan($ids['project'], $ids['scan']);
    $repository->archiveActiveSnapshot($ids['project'], hash('sha256', '{}'), 5);
    $next = StableId::scan($ids['project'], 'diff-next');
    $repository->createScan($next, $ids['project'], 'incremental', hash('sha256', 'scanner-next'));
    $movedFile = StableId::file($ids['project'], 'src/MovedCheckout.php');
    $repository->saveFile($movedFile, $ids['project'], 'src/MovedCheckout.php', hash('sha256', 'moved'), 20, 2, 'php', '1', $next);
    $pdo->exec("UPDATE nodes SET confidence = 'probable', file_id = '" . $movedFile . "' WHERE id = '" . $ids['checkout'] . "'");
    $pdo->exec("DELETE FROM nodes WHERE id = '" . $ids['invoice'] . "'");
    $billing = StableId::symbol($ids['project'], 'php', 'class', 'App\\BillingService');
    $repository->saveNode(
        $billing,
        $ids['project'],
        'class',
        'App\\BillingService',
        'InvoiceService',
        null,
        $ids['file'],
        21,
        35,
        'ast',
        'certain',
        [],
        'php:file:src/Checkout.php',
        $next,
    );
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billingBoundary = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Moved'], 'explicit', $next);
    $repository->saveBoundary($billingBoundary, $ids['project'], 'Billing', ['path_prefix' => 'src/Billing'], 'explicit', $next);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $next);
    $repository->saveBoundaryMembership($billingBoundary, $ids['project'], $billing, $next);
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['checkout'], $billing, 'quality-boundary'),
        $ids['project'],
        'calls',
        $ids['checkout'],
        $billing,
        $movedFile,
        5,
        5,
        'ast',
        'certain',
        [],
        'php:file:src/MovedCheckout.php',
        $next,
    );
    $repository->completeScan($ids['project'], $next);

    $queries = new ArchitectureQueryService($pdo);
    $diff = $queries->snapshotDiff($ids['project'], $ids['scan']);
    assertSame($ids['scan'], $diff->data['from']['scan_id']);
    assertSame($next, $diff->data['to']['scan_id']);
    assertSame(1, $diff->data['changes']['components']['counts']['added']);
    assertSame(1, $diff->data['changes']['components']['counts']['removed']);
    assertSame(1, $diff->data['changes']['components']['counts']['changed']);
    assertSame(1, $diff->data['changes']['components']['counts']['moved']);
    assertSame('src/MovedCheckout.php', $diff->data['changes']['components']['moved'][0]['after']['path']);
    assertSame('exact_kind_and_display_name', $diff->data['changes']['components']['rename_candidates'][0]['heuristic']);
    assertSame(1, $diff->data['confidence_changes']['lowered']);
    assertSame(1, $diff->data['changes']['relationships']['counts']['removed']);
    assertSame(1, $diff->data['changes']['relationships']['counts']['added']);
    assertSame(true, $queries->snapshotDiff($ids['project'], $ids['scan'], maxChanges: 1)->truncated);
    assertThrows(static fn() => $queries->snapshotDiff($ids['project'], 'active'), InvalidArgumentException::class);
    assertThrows(static fn() => $queries->snapshotDiff($ids['project'], 'missing'), InvalidArgumentException::class);
    assertThrows(static fn() => $queries->snapshotDiff($ids['project'], $ids['scan'], maxChanges: 0), InvalidArgumentException::class);
    $pdo->exec("UPDATE scan_snapshots SET complete = 0 WHERE scan_id = '" . $ids['scan'] . "'");
    assertThrows(static fn() => $queries->snapshotDiff($ids['project'], $ids['scan']), InvalidArgumentException::class);
    $pdo->exec("UPDATE scan_snapshots SET complete = 1 WHERE scan_id = '" . $ids['scan'] . "'");

    $budgets = ['new_cycles' => 0, 'error_diagnostics' => 0, 'hub_degree_growth' => 10,
        'unreferenced_candidates' => 10, 'public_surface_changes' => 0];
    $gate = $queries->qualityGate($ids['project'], $ids['scan'], $budgets, sarif: true, proposeBaseline: true);
    assertSame(true, $gate->data['passed']);
    assertSame(false, $gate->data['proposed_baseline']['applied']);
    assertSame('2.1.0', $gate->data['sarif']['version']);
    assertSame(false, $queries->qualityGate($ids['project'], $ids['scan'], ['unreferenced_candidates' => 0])->data['passed']);
    $policyGate = $queries->qualityGate($ids['project'], $ids['scan'], ['boundary_violations' => 0], [[
        'id' => 'backend-no-billing', 'from_boundary' => $backend, 'deny_targets' => [$billingBoundary],
    ]], sarif: true);
    assertSame(false, $policyGate->data['passed']);
    assertSame(1, $policyGate->data['metrics']['boundary_violations']);
    assertSame('knossos.boundary', $policyGate->data['sarif']['runs'][0]['results'][0]['ruleId']);
    assertThrows(static fn() => $queries->qualityGate($ids['project'], $ids['scan'], []), InvalidArgumentException::class);
    assertThrows(static fn() => $queries->qualityGate($ids['project'], $ids['scan'], ['boundary_violations' => 0]), InvalidArgumentException::class);

    $trends = $queries->architectureTrends($ids['project'], 2, $ids['scan']);
    assertSame([$ids['scan'], $next], array_column($trends->data['series'], 'scan_id'));
    assertContains('## Architecture changes', $trends->data['release_notes']['markdown']);
    assertContains('Components: +1 / -1', $trends->data['release_notes']['markdown']);
    assertThrows(static fn() => $queries->architectureTrends($ids['project'], 1), InvalidArgumentException::class);

    $tools = new ToolService(
        new ProjectScanService($pdo, dirname(__DIR__), [__DIR__ . '/Fixtures/mixed']),
        $queries,
        new DatabaseMaintenanceService($pdo, ':memory:'),
    );
    assertSame($next, $tools->call('snapshot_diff', [
        'project_id' => $ids['project'], 'from_snapshot' => $ids['scan'],
    ])->snapshotId);
    assertSame(true, $tools->call('quality_gate', [
        'project_id' => $ids['project'], 'baseline_snapshot' => $ids['scan'], 'budgets' => $budgets,
    ])->data['passed']);
    assertSame(2, count($tools->call('architecture_trends', [
        'project_id' => $ids['project'], 'limit' => 2,
    ])->data['series']));
};
$testGroups['snapshot diff reports bounded architectural changes and rename heuristics'] = 'query';

$tests['reconciliation failures preserve the active graph'] = static function (): void {
    [$pdo, $reconciler, $request] = reconciliationFixture();
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

    assertThrows(static fn() => $reconciler->reconcile($badRequest), ReconciliationException::class);
    assertSame($nodeIds, $pdo->query('SELECT id FROM nodes ORDER BY id')->fetchAll());
    assertSame($active->scanId, (string) $pdo->query(
        "SELECT active_scan_id FROM projects WHERE id = '" . $active->projectId . "'",
    )->fetchColumn());
};
$testGroups['reconciliation failures preserve the active graph'] = 'reconciliation';

$tests['scan and query services answer mixed-language architecture questions'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-query-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate query database.');
    }
    $root = __DIR__ . '/Fixtures/mixed';
    try {
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $scan = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Mixed Fixture');
        assertSame(3, $scan->data['files']);
        assertSame(true, $scan->data['nodes'] >= 4);
        assertSame(true, $scan->data['metrics']['elapsed_ms'] >= 0);
        assertSame(3, $scan->data['metrics']['discovered_files']);

        $queries = new ArchitectureQueryService($pdo);
        $component = $queries->findComponent($scan->projectId, 'CheckoutService');
        assertSame($scan->snapshotId, $component->snapshotId);
        assertSame('Fixture\\CheckoutService', $component->data['components'][0]['canonical_name']);
        assertSame('application.service', $component->data['components'][0]['roles'][0]['role']);
        assertSame('src/CheckoutService.php', $component->evidence[0]['path']);
        assertSame(false, str_starts_with($component->evidence[0]['path'], '/'));

        $summary = $queries->architectureSummary($scan->projectId);
        assertSame($scan->snapshotId, $summary->snapshotId);
        assertArrayContains(['kind' => 'php', 'count' => 1], $summary->data['languages']);
        assertArrayContains(['kind' => 'typescript', 'count' => 1], $summary->data['languages']);
    } finally {
        unset($pdo);
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['scan and query services answer mixed-language architecture questions'] = 'query';

$tests['project catalogue reports bounded freshness counts and private roots'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $queries = new ArchitectureQueryService($pdo);

    $catalogue = $queries->listProjects();
    assertSame('catalog', $catalogue->projectId);
    assertSame(1, count($catalogue->data['projects']));
    $project = $catalogue->data['projects'][0];
    assertSame($ids['project'], $project['id']);
    assertSame('unscanned', $project['freshness']);
    assertSame(false, array_key_exists('root', $project));
    assertSame(['files' => 1, 'nodes' => 2, 'edges' => 1, 'diagnostics' => 0], $project['counts']);

    $repository->completeScan($ids['project'], $ids['scan']);
    $active = $queries->listProjects(includeRoots: true)->data['projects'][0];
    assertSame('root_unavailable', $active['freshness']);
    assertSame('/workspace/fixture-shop', $active['root']);
    assertSame('complete', $active['active_scan']['status']);

    $nextScan = StableId::scan($ids['project'], 'scan-2');
    $repository->createScan($nextScan, $ids['project'], 'incremental', hash('sha256', 'scanner-set'));
    $pdo->exec("UPDATE scans SET started_at = '9999-12-31T23:59:59+00:00' WHERE id = '" . $nextScan . "'");
    assertSame('scan_in_progress', $queries->listProjects()->data['projects'][0]['freshness']);

    $second = StableId::project('second-project');
    $repository->saveProject($second, 'Second Project', __DIR__ . '/Fixtures/mixed');
    $page = $queries->listProjects(limit: 1);
    assertSame(true, $page->truncated);
    assertSame(1, $page->data['pagination']['next_offset']);
    assertSame(1, count($queries->listProjects(limit: 1, offset: 1)->data['projects']));
    assertThrows(static fn() => $queries->listProjects(offset: -1), InvalidArgumentException::class);
    assertThrows(static fn() => $queries->listProjects(limit: 101), InvalidArgumentException::class);
};
$testGroups['project catalogue reports bounded freshness counts and private roots'] = 'query';

$tests['list-projects CLI exposes the catalogue without roots by default'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-catalogue-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate catalogue database.');
    }
    try {
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        (new SqliteGraphRepository($pdo))->saveProject(
            StableId::project('catalogue-cli'),
            'Catalogue CLI',
            __DIR__ . '/Fixtures/mixed',
        );
        unset($pdo);

        [$exit, $stdout, $stderr] = runFixtureCommandOutput([
            PHP_BINARY,
            dirname(__DIR__) . '/bin/knossos',
            'list-projects',
            '--db=' . $path,
            '--json',
        ]);
        assertSame(0, $exit);
        assertSame('', $stderr);
        $payload = json_decode(trim($stdout), true, 512, JSON_THROW_ON_ERROR);
        assertSame('Catalogue CLI', $payload['data']['projects'][0]['name']);
        assertSame(false, array_key_exists('root', $payload['data']['projects'][0]));
    } finally {
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['list-projects CLI exposes the catalogue without roots by default'] = 'cli';

$tests['component dossier combines identity context relationships and ambiguity'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $other = StableId::symbol($ids['project'], 'php', 'class', 'App\\OtherService');
    $repository->saveNode(
        $other,
        $ids['project'],
        'class',
        'App\\OtherService',
        'OtherService',
        null,
        $ids['file'],
        40,
        50,
        'ast',
        'possible',
        [],
        'php:file:src/Checkout.php',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['checkout'], $other, 'src/Checkout.php:15'),
        $ids['project'],
        'calls',
        $ids['checkout'],
        $other,
        $ids['file'],
        15,
        15,
        'ast',
        'possible',
        [],
        'php:file:src/Checkout.php',
        $ids['scan'],
    );
    $boundary = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $repository->saveBoundary($boundary, $ids['project'], 'Backend', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
    $repository->saveBoundaryMembership($boundary, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);

    $queries = new ArchitectureQueryService($pdo);
    $dossier = $queries->inspectComponent($ids['project'], $ids['checkout'], maxRelationships: 1);
    assertSame('App\\Checkout', $dossier->data['component']['canonical_name']);
    assertSame('Backend', $dossier->data['component']['boundaries'][0]['name']);
    assertSame('App\\InvoiceService', $dossier->data['component']['outgoing'][0]['component']['canonical_name']);
    assertSame('src/Checkout.php', $dossier->evidence[0]['path']);
    assertSame(true, $dossier->truncated);
    assertArrayContains('outgoing_relationship_limit', $dossier->data['limits']['truncation_reasons']);

    $certain = $queries->inspectComponent($ids['project'], 'Checkout', minConfidence: 'certain');
    assertSame(1, count($certain->data['component']['outgoing']));
    $ambiguous = $queries->inspectComponent($ids['project'], 'App\\');
    assertSame(true, $ambiguous->data['ambiguous']);
    assertSame(true, count($ambiguous->data['candidates']) >= 2);
    assertSame(null, $queries->inspectComponent($ids['project'], 'Missing')->data['component']);
    assertThrows(static fn() => $queries->inspectComponent($ids['project'], 'Checkout', minConfidence: 'invalid'), InvalidArgumentException::class);
};
$testGroups['component dossier combines identity context relationships and ambiguity'] = 'query';

$tests['architecture context bundles deterministic bounded task evidence'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);

    $queries = new ArchitectureQueryService($pdo);
    $first = $queries->architectureContext(
        $ids['project'],
        'fix checkout billing behavior',
        ['src/Checkout.php'],
    );
    $second = $queries->architectureContext(
        $ids['project'],
        'fix checkout billing behavior',
        ['src/Checkout.php'],
    );
    assertSame($first->data, $second->data);
    assertSame('included', $first->data['context']['sections']['summary']['status']);
    assertSame('included', $first->data['context']['sections']['locations']['status']);
    assertSame('included', $first->data['context']['sections']['change_impact']['status']);
    assertSame(true, $first->data['budget']['actual_chars'] <= 30_000);
    assertSame(true, $first->data['budget']['dossier_candidates'] >= 1);

    $bounded = $queries->architectureContext($ids['project'], 'refactor checkout', maxChars: 4000);
    assertSame(true, $bounded->data['budget']['actual_chars'] <= 4000);
    assertSame(true, $bounded->truncated);
    assertThrows(static fn() => $queries->architectureContext($ids['project']), InvalidArgumentException::class);
    assertThrows(static fn() => $queries->architectureContext($ids['project'], 'task', maxChars: 3999), InvalidArgumentException::class);
};
$testGroups['architecture context bundles deterministic bounded task evidence'] = 'query';

$tests['classification rules are deterministic multi-role and kind safe'] = static function (): void {
    $evidence = new Evidence('src/CheckoutService.php', 7, 9);
    $class = new NodeFact(
        'php:class:Fixture\\CheckoutService',
        'class',
        'Fixture\\CheckoutService',
        'CheckoutService',
        Origin::Ast,
        Confidence::Certain,
        $evidence,
    );
    $method = new NodeFact(
        'php:method:Fixture\\CheckoutService::run',
        'method',
        'Fixture\\CheckoutService::run',
        'CheckoutService',
        Origin::Ast,
        Confidence::Certain,
        $evidence,
    );
    $contribution = new ScanContribution('knossos.php:file:src/CheckoutService.php', [$class, $method]);
    $engine = new ClassificationEngine([
        new NameSuffixRule('test.naming.v1', ['Service' => 'application.service']),
        new ExplicitRoleRule('test.explicit.v1', ['Fixture\\CheckoutService' => ['domain.checkout', 'application.entry_point']]),
    ]);
    $first = $engine->classify([$contribution]);
    $second = $engine->classify([$contribution]);
    assertSame(3, count($first));
    assertSame(serialize($first), serialize($second));
    assertSame([], array_values(array_filter(
        $first,
        static fn($fact): bool => $fact->nodeReference === $method->localId,
    )));
    $confidenceByRole = [];
    foreach ($first as $fact) {
        $confidenceByRole[$fact->role] = $fact->confidence->value;
    }
    assertSame('probable', $confidenceByRole['application.service']);
    assertSame('certain', $confidenceByRole['domain.checkout']);
};
$testGroups['classification rules are deterministic multi-role and kind safe'] = 'classification';

$tests['classifications reconcile atomically with evidence and provenance'] = static function (): void {
    [$pdo, $reconciler, $request] = reconciliationFixture();
    $engine = new ClassificationEngine([
        new NameSuffixRule('test.naming.v1', ['Service' => 'application.service']),
        new ExplicitRoleRule('test.explicit.v1', ['Fixture\\CheckoutService' => ['domain.checkout', 'application.entry_point']]),
    ]);
    $facts = $engine->classify($request->contributions);
    $classified = new FullScanRequest(
        $request->projectIdentity,
        $request->projectName,
        $request->discovery,
        $request->scanners,
        $request->contributions,
        [],
        $facts,
    );
    $active = $reconciler->reconcile($classified);
    assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM classifications')->fetchColumn());
    $row = $pdo->query("SELECT role, origin, confidence, rule_id, start_line FROM classifications WHERE role = 'domain.checkout'")->fetch();
    assertSame('user_rule', $row['origin']);
    assertSame('certain', $row['confidence']);
    assertSame('test.explicit.v1', $row['rule_id']);
    assertSame(7, $row['start_line']);

    $bad = new ClassificationFact(
        'php:class:Fixture\\Missing',
        'application.service',
        'test.invalid.v1',
        Origin::Derived,
        Confidence::Possible,
        new Evidence('src/CheckoutService.php', 1, 1),
    );
    $badRequest = new FullScanRequest(
        $request->projectIdentity,
        $request->projectName,
        $request->discovery,
        $request->scanners,
        $request->contributions,
        [],
        [...$facts, $bad],
    );
    assertThrows(static fn() => $reconciler->reconcile($badRequest), ReconciliationException::class);
    assertSame($active->scanId, (string) $pdo->query('SELECT active_scan_id FROM projects')->fetchColumn());
    assertSame(4, (int) $pdo->query('SELECT COUNT(*) FROM classifications')->fetchColumn());
};
$testGroups['classifications reconcile atomically with evidence and provenance'] = 'classification';

$tests['Laravel enricher extracts static routes groups and framework relations'] = static function (): void {
    $root = __DIR__ . '/Fixtures/laravel';
    $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
    $files = array_map(static fn($file): string => $file->relativePath, $discovery->files);
    $client = phpWorkerClient();
    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => $files,
        'frameworks' => ['laravel'],
    ]));
    $client->shutdown();
    $nodes = array_merge(...array_map(static fn(ScanContribution $item): array => $item->nodes, $contributions));
    $edges = array_merge(...array_map(static fn(ScanContribution $item): array => $item->edges, $contributions));
    $diagnostics = array_merge(...array_map(static fn(ScanContribution $item): array => $item->diagnostics, $contributions));
    $routes = array_values(array_filter($nodes, static fn(NodeFact $node): bool => $node->kind === 'route'));
    $route = $routes[0];
    assertSame('GET /shop/checkout => App\\Http\\Controllers\\CheckoutController::show', $route->canonicalName);
    assertSame(['web', 'auth', 'verified'], $route->attributes['middleware']);
    assertSame('shop.checkout', $route->attributes['name']);
    $matchRoute = array_values(array_filter(
        $routes,
        static fn(NodeFact $node): bool => $node->canonicalName === 'GET|POST /matched => App\\Http\\Controllers\\CheckoutController::show',
    ));
    assertSame(1, count($matchRoute));
    assertSame(['GET', 'POST'], $matchRoute[0]->attributes['methods']);
    assertSame('/matched', $matchRoute[0]->attributes['uri']);
    assertSame('App\\Http\\Controllers\\CheckoutController::show', $matchRoute[0]->attributes['action']);

    $edgeTuples = array_map(
        static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference],
        $edges,
    );
    assertArrayContains([
        'routes_to',
        'php:route:' . $route->canonicalName,
        'php:method:App\\Http\\Controllers\\CheckoutController::show',
    ], $edgeTuples);
    assertArrayContains([
        'dispatches',
        'php:method:App\\Http\\Controllers\\CheckoutController::show',
        'php:class:App\\Events\\CheckoutCompleted',
    ], $edgeTuples);
    assertSame(3, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'uses_middleware')));
    assertSame(1, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'binds')));
    assertSame(1, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'listens_to')));
    assertSame(1, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'handles')));
    assertSame(1, count(array_filter($edges, static fn(EdgeFact $edge): bool => $edge->kind === 'observes')));
    assertSame(
        ['LARAVEL_DYNAMIC_ROUTE_URI', 'LARAVEL_DYNAMIC_ROUTE', 'LARAVEL_DYNAMIC_ROUTE_URI'],
        array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $diagnostics),
    );

    $plain = phpWorkerClient();
    $plainRoutes = iterator_to_array($plain->scan(['root' => $root, 'files' => ['routes/web.php']]));
    $plain->shutdown();
    assertSame([], array_values(array_filter(
        $plainRoutes[0]->nodes,
        static fn(NodeFact $node): bool => $node->kind === 'route',
    )));
};
$testGroups['Laravel enricher extracts static routes groups and framework relations'] = 'laravel';

$tests['Symfony enricher extracts attributes handlers subscribers and services statically'] = static function (): void {
    $root = __DIR__ . '/Fixtures/symfony';
    $discovery = (new ProjectDiscoverer(new DiscoveryConfig([$root])))->discover($root);
    $files = array_map(static fn($file): string => $file->relativePath, $discovery->files);
    $client = phpWorkerClient();
    $contributions = iterator_to_array($client->scan([
        'root' => $root,
        'files' => $files,
        'frameworks' => ['symfony'],
    ]));
    $client->shutdown();
    $nodes = array_merge(...array_map(static fn(ScanContribution $item): array => $item->nodes, $contributions));
    $edges = array_merge(...array_map(static fn(ScanContribution $item): array => $item->edges, $contributions));
    $diagnostics = array_merge(...array_map(static fn(ScanContribution $item): array => $item->diagnostics, $contributions));

    $route = array_values(array_filter($nodes, static fn(NodeFact $node): bool => $node->kind === 'route'))[0];
    assertSame('GET|POST /shop/checkout => App\\CheckoutController::checkout', $route->canonicalName);
    assertSame('shop.checkout', $route->attributes['name']);
    $edgeTuples = array_map(static fn(EdgeFact $edge): array => [$edge->kind, $edge->sourceReference, $edge->targetReference], $edges);
    assertArrayContains(['routes_to', 'php:route:' . $route->canonicalName, 'php:method:App\\CheckoutController::checkout'], $edgeTuples);
    assertArrayContains(['handles', 'php:command:app:reconcile', 'php:class:App\\ReconcileCommand'], $edgeTuples);
    assertArrayContains(['handles_message', 'php:class:App\\CheckoutHandler', 'php:class:App\\CheckoutRequested'], $edgeTuples);
    assertArrayContains(['listens_to', 'php:class:App\\RequestListener', 'php:class:Symfony\\Component\\HttpKernel\\Event\\RequestEvent'], $edgeTuples);
    assertArrayContains(['listens_to', 'php:class:App\\KernelSubscriber', 'php:event:Symfony\\Component\\HttpKernel\\KernelEvents::REQUEST'], $edgeTuples);
    assertArrayContains(['binds', 'php:service:app.checkout_gateway', 'php:class:App\\StripeGateway'], $edgeTuples);
    assertArrayContains(['injects', 'php:class:App\\CheckoutController', 'php:service:app.audit'], $edgeTuples);
    assertSame('SYMFONY_DYNAMIC_ROUTE_PATH', $diagnostics[0]->code);

    $path = tempnam(sys_get_temp_dir(), 'knossos-symfony-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate Symfony database.');
    }
    try {
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Symfony Fixture');
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.controller'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.command'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.message_handler'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'symfony.event_subscriber'")->fetchColumn());
    } finally {
        @unlink($path);
    }
};
$testGroups['Symfony enricher extracts attributes handlers subscribers and services statically'] = 'symfony';

$tests['Laravel scan persists explicit path and naming role confidence'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-laravel-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate Laravel database.');
    }
    $root = __DIR__ . '/Fixtures/laravel';
    try {
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $result = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan($root, 'Laravel Fixture');
        assertSame(3, $result->data['diagnostics']);
        assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
        assertSame(3, (int) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'uses_middleware'")->fetchColumn());
        assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM classifications WHERE role = 'laravel.controller' AND origin = 'framework_convention' AND confidence = 'certain'",
        )->fetchColumn());
        assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM classifications WHERE role = 'laravel.event' AND origin = 'framework_convention' AND confidence = 'probable'",
        )->fetchColumn());
        assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM classifications WHERE role = 'laravel.queued' AND confidence = 'certain'",
        )->fetchColumn());
        foreach (['laravel.command', 'laravel.middleware', 'laravel.repository', 'laravel.listener', 'laravel.policy', 'laravel.model', 'laravel.provider'] as $role) {
            $statement = $pdo->prepare('SELECT COUNT(*) FROM classifications WHERE role = :role');
            $statement->execute(['role' => $role]);
            assertSame(true, (int) $statement->fetchColumn() >= 1);
        }
        $architecture = new ArchitectureQueryService($pdo);
        $flow = $architecture->explainFlow(
            $result->projectId,
            'GET /shop/checkout',
            'CheckoutCompleted',
        );
        assertSame(['routes_to', 'dispatches'], array_column($flow->data['paths'][0]['hops'], 'kind'));
        assertSame(3, $flow->data['paths'][0]['score']['minimum_confidence']);
        $impact = $architecture->impactAnalysis($result->projectId, 'CheckoutCompleted');
        assertSame(true, count($impact->data['boundaries']) >= 1);
        assertSame(true, count(array_filter(
            $impact->data['entry_points'],
            static fn(array $entry): bool => $entry['node']['kind'] === 'route',
        )) >= 1);
    } finally {
        unset($pdo);
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['Laravel scan persists explicit path and naming role confidence'] = 'laravel';

$tests['flow query ranks confidence bounds cycles and ambiguity'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $route = StableId::symbol($ids['project'], 'php', 'route', 'GET /checkout');
    $typescriptCheckout = StableId::symbol($ids['project'], 'typescript', 'class', 'frontend/checkout#Checkout');
    $repository->saveNode($route, $ids['project'], 'route', 'GET /checkout', 'GET /checkout', null, $ids['file'], 1, 1, 'framework_convention', 'certain', [], 'laravel:routes', $ids['scan']);
    $repository->saveNode($typescriptCheckout, $ids['project'], 'class', 'frontend/checkout#Checkout', 'Checkout', null, $ids['file'], 40, 45, 'ast', 'certain', [], 'ts:file:checkout.ts', $ids['scan']);
    $repository->saveEdge(
        StableId::edge($ids['project'], 'routes_to', $route, $ids['checkout'], 'route'),
        $ids['project'],
        'routes_to',
        $route,
        $ids['checkout'],
        $ids['file'],
        1,
        1,
        'framework_convention',
        'certain',
        [],
        'laravel:routes',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'depends_on', $route, $ids['invoice'], 'shortcut'),
        $ids['project'],
        'depends_on',
        $route,
        $ids['invoice'],
        $ids['file'],
        2,
        2,
        'derived',
        'probable',
        [],
        'derived:flow',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'cycle'),
        $ids['project'],
        'calls',
        $ids['invoice'],
        $ids['checkout'],
        $ids['file'],
        30,
        30,
        'ast',
        'certain',
        [],
        'php:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $repository->completeScan($ids['project'], $ids['scan']);

    $query = new ArchitectureQueryService($pdo);
    $flow = $query->explainFlow($ids['project'], 'GET /checkout', 'InvoiceService', maxPaths: 1);
    assertSame(1, count($flow->data['paths']));
    assertSame(2, count($flow->data['paths'][0]['hops']));
    assertSame(3, $flow->data['paths'][0]['score']['minimum_confidence']);
    assertSame(true, $flow->truncated);
    assertSame('path_limit', $flow->data['bounds']['truncation_reason']);
    assertSame('src/Checkout.php', $flow->evidence[0]['path']);
    assertContains('--routes_to', $flow->data['paths'][0]['hops'][0]['explanation']);
    $nodeIds = array_column($flow->data['paths'][0]['nodes'], 'id');
    assertSame(count($nodeIds), count(array_unique($nodeIds)));

    $shallow = $query->explainFlow($ids['project'], $route, $ids['invoice'], maxDepth: 1);
    assertSame(1, count($shallow->data['paths'][0]['hops']));
    assertSame(2, $shallow->data['paths'][0]['score']['minimum_confidence']);
    $certain = $query->explainFlow($ids['project'], $route, $ids['invoice'], minConfidence: 'certain');
    assertSame(1, count($certain->data['paths']));
    assertSame(2, count($certain->data['paths'][0]['hops']));
    $filtered = $query->explainFlow($ids['project'], $route, $ids['invoice'], edgeKinds: ['calls']);
    assertSame([], $filtered->data['paths']);

    $ambiguous = $query->explainFlow($ids['project'], 'Checkout', 'InvoiceService');
    assertSame([], $ambiguous->data['paths']);
    assertSame(2, count($ambiguous->data['from']['candidates']));
    assertThrows(static fn() => $query->explainFlow($ids['project'], $route, $ids['invoice'], maxDepth: 9), InvalidArgumentException::class);
    assertThrows(static fn() => $query->explainFlow($ids['project'], $route, $ids['invoice'], timeoutMs: 0), InvalidArgumentException::class);
    $time = 0;
    $timedQuery = new ArchitectureQueryService($pdo, static function () use (&$time): int {
        $time += 2_000_000;
        return $time;
    });
    $timed = $timedQuery->explainFlow($ids['project'], $route, $ids['invoice'], timeoutMs: 1);
    assertSame(true, $timed->truncated);
    assertSame(0, $timed->data['bounds']['visited_states']);
    assertSame('time_limit', $timed->data['bounds']['truncation_reason']);
};
$testGroups['flow query ranks confidence bounds cycles and ambiguity'] = 'flow';

$tests['impact analysis groups reverse blast radius and entry points'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $route = StableId::symbol($ids['project'], 'php', 'route', 'GET /checkout');
    $typescriptInvoice = StableId::symbol($ids['project'], 'typescript', 'class', 'frontend#InvoiceService');
    $repository->saveNode($route, $ids['project'], 'route', 'GET /checkout', 'GET /checkout', null, $ids['file'], 1, 1, 'framework_convention', 'certain', [], 'laravel:routes', $ids['scan']);
    $repository->saveNode($typescriptInvoice, $ids['project'], 'class', 'frontend#InvoiceService', 'InvoiceService', null, $ids['file'], 40, 45, 'ast', 'certain', [], 'ts:file:invoice.ts', $ids['scan']);
    $repository->saveEdge(
        StableId::edge($ids['project'], 'routes_to', $route, $ids['checkout'], 'route'),
        $ids['project'],
        'routes_to',
        $route,
        $ids['checkout'],
        $ids['file'],
        1,
        1,
        'framework_convention',
        'certain',
        [],
        'laravel:routes',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'cycle'),
        $ids['project'],
        'calls',
        $ids['invoice'],
        $ids['checkout'],
        $ids['file'],
        30,
        30,
        'ast',
        'certain',
        [],
        'php:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $repository->saveClassification(
        StableId::classification($ids['project'], $ids['checkout'], 'application.controller', 'test.roles'),
        $ids['project'],
        $ids['checkout'],
        'application.controller',
        'user_rule',
        'certain',
        'test.roles',
        $ids['file'],
        3,
        18,
        [],
        $ids['scan'],
    );
    $repository->completeScan($ids['project'], $ids['scan']);

    $query = new ArchitectureQueryService($pdo);
    $impact = $query->impactAnalysis($ids['project'], $ids['invoice']);
    assertSame(1, count($impact->data['direct_dependants']));
    assertSame($ids['checkout'], $impact->data['direct_dependants'][0]['node']['id']);
    assertSame([1, 2], array_column($impact->data['by_distance'], 'distance'));
    assertSame(2, count($impact->data['entry_points']));
    assertSame(2, count($impact->data['by_confidence']['certain']));
    assertSame([], $impact->data['by_confidence']['probable']);
    assertSame('src/Checkout.php', $impact->evidence[0]['path']);
    assertContains('conservative static blast radius', $impact->warnings[0]);

    $direct = $query->impactAnalysis($ids['project'], $ids['invoice'], maxDepth: 1);
    assertSame(1, count($direct->data['by_distance']));
    $limited = $query->impactAnalysis($ids['project'], $ids['invoice'], limit: 1);
    assertSame(true, $limited->truncated);
    $filtered = $query->impactAnalysis($ids['project'], $ids['invoice'], edgeKinds: ['imports']);
    assertSame([], $filtered->data['direct_dependants']);
    $ambiguous = $query->impactAnalysis($ids['project'], 'InvoiceService');
    assertSame(2, count($ambiguous->data['candidates']));

    $time = 0;
    $timedQuery = new ArchitectureQueryService($pdo, static function () use (&$time): int {
        $time += 2_000_000;
        return $time;
    });
    $timed = $timedQuery->impactAnalysis($ids['project'], $ids['invoice'], timeoutMs: 1);
    assertSame(true, $timed->truncated);
    assertSame(0, $timed->data['bounds']['visited_states']);
    assertSame('time_limit', $timed->data['bounds']['truncation_reason']);
};
$testGroups['impact analysis groups reverse blast radius and entry points'] = 'impact';

$tests['dependency cycles compute deterministic bounded strongly connected components'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $worker = StableId::symbol($ids['project'], 'php', 'class', 'App\\Worker');
    $repository->saveNode(
        $worker,
        $ids['project'],
        'class',
        'App\\Worker',
        'Worker',
        null,
        $ids['file'],
        40,
        50,
        'ast',
        'certain',
        [],
        'php:file:src/Worker.php',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'reverse'),
        $ids['project'],
        'calls',
        $ids['invoice'],
        $ids['checkout'],
        $ids['file'],
        30,
        30,
        'ast',
        'probable',
        [],
        'php:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'depends_on', $worker, $worker, 'self'),
        $ids['project'],
        'depends_on',
        $worker,
        $worker,
        $ids['file'],
        45,
        45,
        'ast',
        'certain',
        [],
        'php:file:src/Worker.php',
        $ids['scan'],
    );
    $repository->completeScan($ids['project'], $ids['scan']);

    $query = new ArchitectureQueryService($pdo);
    $result = $query->dependencyCycles($ids['project']);
    assertSame([2, 1], array_column($result->data['cycles'], 'size'));
    assertSame('probable', $result->data['cycles'][0]['minimum_confidence']);
    assertSame('certain', $result->data['cycles'][1]['minimum_confidence']);
    assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column($result->data['cycles'][0]['members'], 'canonical_name'));
    assertSame(2, count($result->data['cycles'][0]['relationships']));
    assertSame(3, count($result->evidence));
    assertContains('selected static dependency', $result->warnings[0]);

    $certain = $query->dependencyCycles($ids['project'], minConfidence: 'certain');
    assertSame([1], array_column($certain->data['cycles'], 'size'));
    $filtered = $query->dependencyCycles($ids['project'], edgeKinds: ['imports']);
    assertSame([], $filtered->data['cycles']);
    $limited = $query->dependencyCycles($ids['project'], limit: 1);
    assertSame(true, $limited->truncated);
    assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
    $edgeLimited = $query->dependencyCycles($ids['project'], maxEdges: 1);
    assertSame(true, $edgeLimited->truncated);
    assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
    assertThrows(static fn() => $query->dependencyCycles($ids['project'], edgeKinds: ['contains']), InvalidArgumentException::class);
    assertThrows(static fn() => $query->dependencyCycles($ids['project'], maxNodes: 0), InvalidArgumentException::class);

    $time = 0;
    $timedQuery = new ArchitectureQueryService($pdo, static function () use (&$time): int {
        $time += 2_000_000;
        return $time;
    });
    $timed = $timedQuery->dependencyCycles($ids['project'], timeoutMs: 1);
    assertSame(true, $timed->truncated);
    assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
};
$testGroups['dependency cycles compute deterministic bounded strongly connected components'] = 'cycles';

$tests['architecture health ranks structural signals and labels dead-code uncertainty'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $orphan = StableId::symbol($ids['project'], 'php', 'class', 'App\\OrphanService');
    $model = StableId::symbol($ids['project'], 'php', 'class', 'App\\Order');
    foreach ([[$orphan, 'App\\OrphanService', 'OrphanService'], [$model, 'App\\Order', 'Order']] as [$id, $canonical, $display]) {
        $repository->saveNode(
            $id,
            $ids['project'],
            'class',
            $canonical,
            $display,
            null,
            $ids['file'],
            40,
            50,
            'ast',
            'certain',
            [],
            'php:file:src/' . $display . '.php',
            $ids['scan'],
        );
    }
    $repository->saveClassification(
        StableId::classification($ids['project'], $model, 'laravel.model', 'laravel.roles.v1'),
        $ids['project'],
        $model,
        'laravel.model',
        'framework_convention',
        'certain',
        'laravel.roles.v1',
        $ids['file'],
        40,
        50,
        [],
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'reverse'),
        $ids['project'],
        'calls',
        $ids['invoice'],
        $ids['checkout'],
        $ids['file'],
        30,
        30,
        'ast',
        'certain',
        [],
        'php:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);

    $query = new ArchitectureQueryService($pdo);
    $health = $query->architectureHealth($ids['project']);
    assertSame(['App\\Checkout', 'App\\InvoiceService'], array_column(array_column($health->data['hubs'], 'component'), 'canonical_name'));
    assertSame(2, $health->data['hubs'][0]['score']);
    assertSame(2, $health->data['hubs'][0]['metrics']['cross_boundary_degree']);
    assertSame(9, $health->data['static_hotspots'][0]['score']);
    assertSame(true, $health->data['static_hotspots'][0]['factors']['cycle_participant']);
    assertSame(['App\\Order', 'App\\OrphanService'], array_column(array_column($health->data['dead_code_candidates'], 'component'), 'canonical_name'));
    assertSame('possible', $health->data['dead_code_candidates'][0]['confidence']);
    assertSame('probable', $health->data['dead_code_candidates'][1]['confidence']);
    assertContains('candidates only', $health->warnings[1]);
    assertSame(4, count($health->evidence));

    $filtered = $query->architectureHealth($ids['project'], edgeKinds: ['imports']);
    assertSame([], $filtered->data['hubs']);
    $limited = $query->architectureHealth($ids['project'], limit: 1);
    assertSame(true, $limited->truncated);
    assertSame(true, in_array('result_limit', $limited->data['bounds']['truncation_reasons'], true));
    $nodeLimited = $query->architectureHealth($ids['project'], maxNodes: 1);
    assertSame(true, $nodeLimited->truncated);
    assertSame(true, in_array('node_limit', $nodeLimited->data['bounds']['truncation_reasons'], true));
    $edgeLimited = $query->architectureHealth($ids['project'], maxEdges: 1);
    assertSame(true, $edgeLimited->truncated);
    assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
    assertThrows(static fn() => $query->architectureHealth($ids['project'], edgeKinds: ['contains']), InvalidArgumentException::class);

    $time = 0;
    $timedQuery = new ArchitectureQueryService($pdo, static function () use (&$time): int {
        $time += 2_000_000;
        return $time;
    });
    $timed = $timedQuery->architectureHealth($ids['project'], timeoutMs: 1);
    assertSame(true, $timed->truncated);
    assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
};
$testGroups['architecture health ranks structural signals and labels dead-code uncertainty'] = 'health';

$tests['declared architecture policies report deterministic evidence-backed violations'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $worker = StableId::symbol($ids['project'], 'php', 'class', 'App\\Worker');
    $repository->saveNode(
        $worker,
        $ids['project'],
        'class',
        'App\\Worker',
        'Worker',
        null,
        $ids['file'],
        40,
        50,
        'ast',
        'certain',
        [],
        'php:file:src/Worker.php',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['checkout'], $worker, 'worker'),
        $ids['project'],
        'calls',
        $ids['checkout'],
        $worker,
        $ids['file'],
        15,
        15,
        'ast',
        'probable',
        [],
        'php:file:src/Checkout.php',
        $ids['scan'],
    );
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $duplicateBackend = StableId::boundary($ids['project'], 'Backend', 'inferred');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundary($duplicateBackend, $ids['project'], 'Backend', ['namespace_prefix' => 'App'], 'inferred', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);

    $policies = [
        ['id' => 'backend-allow', 'from_boundary' => $backend, 'allow_targets' => [$backend], 'edge_kinds' => ['calls']],
        ['id' => 'backend-deny-billing', 'from_boundary' => $backend, 'deny_targets' => [$billing], 'edge_kinds' => ['calls']],
        ['id' => 'backend-no-unassigned', 'from_boundary' => $backend, 'deny_targets' => ['@unassigned'], 'edge_kinds' => ['calls']],
    ];
    $query = new ArchitectureQueryService($pdo);
    $result = $query->checkArchitecture($ids['project'], $policies);
    assertSame(4, count($result->data['violations']));
    $policyCounts = array_count_values(array_column($result->data['violations'], 'policy_id'));
    ksort($policyCounts);
    assertSame(['backend-allow' => 2, 'backend-deny-billing' => 1, 'backend-no-unassigned' => 1], $policyCounts);
    $denyViolation = array_values(array_filter($result->data['violations'], static fn(array $item): bool => $item['policy_id'] === 'backend-deny-billing'))[0];
    $unassignedViolation = array_values(array_filter($result->data['violations'], static fn(array $item): bool => $item['policy_id'] === 'backend-no-unassigned'))[0];
    assertSame('denied_target', $denyViolation['reasons'][0]['type']);
    assertSame([], $unassignedViolation['target_boundaries']);
    assertSame(4, count($result->evidence));
    assertContains('static graph findings', $result->warnings[0]);

    $certain = $query->checkArchitecture($ids['project'], $policies, minConfidence: 'certain');
    assertSame(2, count($certain->data['violations']));
    $limited = $query->checkArchitecture($ids['project'], $policies, limit: 1);
    assertSame(true, $limited->truncated);
    assertSame(['result_limit'], $limited->data['bounds']['truncation_reasons']);
    $edgeLimited = $query->checkArchitecture($ids['project'], $policies, maxEdges: 1);
    assertSame(true, $edgeLimited->truncated);
    assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
    $filtered = $query->checkArchitecture($ids['project'], [[
        'id' => 'imports-only', 'from_boundary' => $backend, 'deny_targets' => [$billing], 'edge_kinds' => ['imports'],
    ]]);
    assertSame([], $filtered->data['violations']);
    assertThrows(static fn() => $query->checkArchitecture($ids['project'], [[
        'id' => 'ambiguous', 'from_boundary' => 'Backend', 'deny_targets' => [$billing],
    ]]), InvalidArgumentException::class);
    assertThrows(static fn() => $query->checkArchitecture($ids['project'], [[
        'id' => 'unknown', 'from_boundary' => 'Missing', 'deny_targets' => [$billing],
    ]]), InvalidArgumentException::class);
    assertThrows(static fn() => $query->checkArchitecture($ids['project'], [[
        'id' => 'empty', 'from_boundary' => $backend,
    ]]), InvalidArgumentException::class);

    $time = 0;
    $timedQuery = new ArchitectureQueryService($pdo, static function () use (&$time): int {
        $time += 2_000_000;
        return $time;
    });
    $timed = $timedQuery->checkArchitecture($ids['project'], $policies, timeoutMs: 1);
    assertSame(true, $timed->truncated);
    assertSame(['time_limit'], $timed->data['bounds']['truncation_reasons']);
};
$testGroups['declared architecture policies report deterministic evidence-backed violations'] = 'policy';

$tests['location suggestions rank deterministic factors against the evaluation set'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'evaluation-reverse'),
        $ids['project'],
        'calls',
        $ids['invoice'],
        $ids['checkout'],
        $ids['file'],
        30,
        30,
        'ast',
        'certain',
        [],
        'evaluation:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $repository->saveClassification(
        StableId::classification($ids['project'], $ids['checkout'], 'application.checkout', 'evaluation.roles'),
        $ids['project'],
        $ids['checkout'],
        'application.checkout',
        'user_rule',
        'certain',
        'evaluation.roles',
        $ids['file'],
        3,
        18,
        [],
        $ids['scan'],
    );
    $repository->completeScan($ids['project'], $ids['scan']);

    $evaluationJson = file_get_contents(__DIR__ . '/Fixtures/evaluation/suggest-location.json');
    if (!is_string($evaluationJson)) {
        throw new RuntimeException('Unable to read location evaluation set.');
    }
    $evaluation = json_decode($evaluationJson, true, 32, JSON_THROW_ON_ERROR);
    $query = new ArchitectureQueryService($pdo);
    foreach ($evaluation as $case) {
        $first = $query->suggestLocation($ids['project'], $case['feature_description']);
        $second = $query->suggestLocation($ids['project'], $case['feature_description']);
        assertSame($case['expected_boundary'], $first->data['candidates'][0]['boundary']['name']);
        assertSame($first->data['candidates'], $second->data['candidates']);
        assertSame(true, $first->data['candidates'][0]['score'] > 0);
        assertSame(true, count($first->data['candidates'][0]['matched_tokens']) >= 1);
        assertSame(true, count($first->evidence) >= 1);
    }
    $billingResult = $query->suggestLocation($ids['project'], 'build invoice billing workflow');
    assertSame(12, $billingResult->data['candidates'][0]['factors']['boundary_name_relevance']);
    assertSame('probable', $billingResult->data['candidates'][0]['confidence']);
    assertContains('uniquely correct', $billingResult->warnings[0]);

    $limited = $query->suggestLocation($ids['project'], 'checkout service', limit: 1);
    assertSame(true, $limited->truncated);
    assertSame(true, in_array('result_limit', $limited->data['bounds']['truncation_reasons'], true));
    $memberLimited = $query->suggestLocation($ids['project'], 'checkout service', maxMembers: 1);
    assertSame(true, $memberLimited->truncated);
    assertSame(true, in_array('member_limit', $memberLimited->data['bounds']['truncation_reasons'], true));
    $edgeLimited = $query->suggestLocation($ids['project'], 'checkout service', maxEdges: 1);
    assertSame(true, in_array('edge_limit', $edgeLimited->data['bounds']['truncation_reasons'], true));
    assertThrows(static fn() => $query->suggestLocation($ids['project'], 'add new feature'), InvalidArgumentException::class);

    $time = 0;
    $timedQuery = new ArchitectureQueryService($pdo, static function () use (&$time): int {
        $time += 2_000_000;
        return $time;
    });
    $timed = $timedQuery->suggestLocation($ids['project'], 'checkout service', timeoutMs: 1);
    assertSame(true, $timed->truncated);
    assertSame(true, in_array('time_limit', $timed->data['bounds']['truncation_reasons'], true));
};
$testGroups['location suggestions rank deterministic factors against the evaluation set'] = 'suggestion';

$tests['optional semantic location ranking validates providers and falls back deterministically'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);

    $deterministicQuery = new ArchitectureQueryService($pdo);
    $deterministic = $deterministicQuery->suggestLocation($ids['project'], 'checkout service');
    $unavailable = $deterministicQuery->suggestLocation($ids['project'], 'checkout service', rankingMode: 'semantic_if_available');
    assertSame($deterministic->data['candidates'], $unavailable->data['candidates']);
    assertSame('provider_unavailable', $unavailable->data['ranking']['fallback_reason']);
    assertContains('deterministic fallback', $unavailable->warnings[1]);

    $ranker = new class ($billing, $backend) implements SemanticRanker {
        public function __construct(private string $billing, private string $backend) {}
        public function id(): string
        {
            return 'fixture.semantic.v1';
        }
        public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
        {
            assertSame(true, $timeoutMs >= 1);
            assertContains('Checkout', implode(' ', array_column($candidates, 'text')));
            return [$this->billing => 1.0, $this->backend => 0.0];
        }
    };
    $semantic = (new ArchitectureQueryService($pdo, semanticRanker: $ranker))->suggestLocation(
        $ids['project'],
        'checkout service',
        rankingMode: 'semantic_if_available',
    );
    assertSame('Billing', $semantic->data['candidates'][0]['boundary']['name']);
    assertSame('semantic', $semantic->data['ranking']['applied_mode']);
    assertSame('fixture.semantic.v1', $semantic->data['ranking']['provider']);
    assertSame(20.0, $semantic->data['candidates'][0]['factors']['semantic_relevance']);

    $invalidRanker = new class implements SemanticRanker {
        public function id(): string
        {
            return 'fixture.invalid';
        }
        public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
        {
            return [$candidates[0]['id'] => 2.0];
        }
    };
    $invalid = (new ArchitectureQueryService($pdo, semanticRanker: $invalidRanker))->suggestLocation(
        $ids['project'],
        'checkout service',
        rankingMode: 'semantic_if_available',
    );
    assertSame($deterministic->data['candidates'], $invalid->data['candidates']);
    assertContains('provider_failed:', $invalid->data['ranking']['fallback_reason']);

    $failingRanker = new class implements SemanticRanker {
        public function id(): string
        {
            return 'fixture.failure';
        }
        public function rank(string $featureDescription, array $candidates, int $timeoutMs): array
        {
            throw new RuntimeException('offline');
        }
    };
    $failed = (new ArchitectureQueryService($pdo, semanticRanker: $failingRanker))->suggestLocation(
        $ids['project'],
        'checkout service',
        rankingMode: 'semantic_if_available',
    );
    assertSame($deterministic->data['candidates'], $failed->data['candidates']);
    assertContains('offline', $failed->data['ranking']['fallback_reason']);
    assertThrows(static fn() => $deterministicQuery->suggestLocation($ids['project'], 'checkout', rankingMode: 'semantic'), InvalidArgumentException::class);
};
$testGroups['optional semantic location ranking validates providers and falls back deterministically'] = 'semantic';

$tests['Git history and change-aware impact are bounded deterministic and read only'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $invoiceFile = StableId::file($ids['project'], 'src/InvoiceService.php');
    $repository->saveFile(
        $invoiceFile,
        $ids['project'],
        'src/InvoiceService.php',
        hash('sha256', 'invoice'),
        50,
        1,
        'php',
        '0.2.0',
        $ids['scan'],
    );
    $repository->saveNode(
        $ids['invoice'],
        $ids['project'],
        'class',
        'App\\InvoiceService',
        'InvoiceService',
        null,
        $invoiceFile,
        21,
        35,
        'ast',
        'certain',
        [],
        'php:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $repository->saveEdge(
        StableId::edge($ids['project'], 'calls', $ids['invoice'], $ids['checkout'], 'change-impact'),
        $ids['project'],
        'calls',
        $ids['invoice'],
        $ids['checkout'],
        $invoiceFile,
        30,
        30,
        'ast',
        'certain',
        [],
        'change:file:src/InvoiceService.php',
        $ids['scan'],
    );
    $repository->completeScan($ids['project'], $ids['scan']);
    $historyProvider = new class implements GitHistoryProvider {
        public function history(string $projectRoot, int $sinceDays, int $maxCommits, int $timeoutMs): array
        {
            assertSame('/workspace/fixture-shop', $projectRoot);
            return ['files' => [
                'src/Checkout.php' => ['commit_count' => 5, 'authors' => ['a@example.test', 'b@example.test'], 'last_changed_at' => '2026-07-17T10:00:00+00:00'],
                'src/InvoiceService.php' => ['commit_count' => 1, 'authors' => ['a@example.test'], 'last_changed_at' => '2026-07-16T10:00:00+00:00'],
            ], 'commits_examined' => 6, 'truncated' => false];
        }
    };
    $query = new ArchitectureQueryService($pdo, gitHistory: $historyProvider);
    $result = $query->changeImpact($ids['project'], $ids['invoice']);
    assertSame(true, $result->data['git']['available']);
    assertSame(6, $result->data['git']['commits_examined']);
    assertSame('App\\Checkout', $result->data['risk_ranking'][0]['component']['canonical_name']);
    assertSame(21, $result->data['risk_ranking'][0]['score']);
    assertSame(5, $result->data['risk_ranking'][0]['change_signals']['commit_count']);
    assertContains('not proof of risk', $result->warnings[array_key_last($result->warnings)]);

    $fallback = (new ArchitectureQueryService($pdo))->changeImpact($ids['project'], $ids['invoice']);
    assertSame(false, $fallback->data['git']['available']);
    assertSame(0, $fallback->data['risk_ranking'][0]['change_signals']['commit_count']);
    assertContains('provider_unavailable', implode(' ', $fallback->warnings));
    assertThrows(static fn() => $query->changeImpact($ids['project'], $ids['invoice'], sinceDays: 0), InvalidArgumentException::class);

    $root = sys_get_temp_dir() . '/knossos-git-' . bin2hex(random_bytes(6));
    $plain = sys_get_temp_dir() . '/knossos-git-' . bin2hex(random_bytes(6));
    if (!mkdir($root . '/src', 0700, true) || !mkdir($plain, 0700, true)) {
        throw new RuntimeException('Unable to create Git fixtures.');
    }
    try {
        runFixtureCommand(['git', 'init', '--quiet', $root]);
        runFixtureCommand(['git', '-C', $root, 'config', 'user.name', 'Knossos Test']);
        runFixtureCommand(['git', '-C', $root, 'config', 'user.email', 'test@example.test']);
        file_put_contents($root . '/src/example.php', "<?php\n");
        runFixtureCommand(['git', '-C', $root, 'add', 'src/example.php']);
        runFixtureCommand(['git', '-C', $root, 'commit', '--quiet', '-m', 'first']);
        file_put_contents($root . '/src/example.php', "<?php\n// second\n");
        runFixtureCommand(['git', '-C', $root, 'add', 'src/example.php']);
        runFixtureCommand(['git', '-C', $root, 'commit', '--quiet', '-m', 'second']);
        $history = (new ProcessGitHistoryProvider())->history($root, 30, 10, 2000);
        assertSame(2, $history['commits_examined']);
        assertSame(2, $history['files']['src/example.php']['commit_count']);
        assertSame(['test@example.test'], $history['files']['src/example.php']['authors']);
        file_put_contents($root . '/src/example.php', "<?php\n// working tree\n");
        file_put_contents($root . '/src/untracked.php', "<?php\n");
        $changes = (new ProcessGitWorkingTreeProvider())->changes($root, null, 10, 2000);
        assertSame(['src/example.php', 'src/untracked.php'], $changes['paths']);
        assertSame(['src/example.php'], (new ProcessGitWorkingTreeProvider())->changes($root, 'HEAD~1', 10, 2000)['paths']);
        assertThrows(static fn() => (new ProcessGitHistoryProvider())->history($plain, 30, 10, 2000), RuntimeException::class);
        assertThrows(static fn() => (new ProcessGitHistoryProvider(maxOutputBytes: 10))->history($root, 30, 10, 2000), RuntimeException::class);
        assertThrows(static fn() => (new ProcessGitWorkingTreeProvider())->changes($root, '--bad', 10, 2000), RuntimeException::class);
    } finally {
        removeGitFixture($root);
        removeGitFixture($plain);
    }
};
$testGroups['Git history and change-aware impact are bounded deterministic and read only'] = 'git';

$tests['changed-file impact maps explicit and working-tree paths without execution'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $repository->completeScan($ids['project'], $ids['scan']);
    $workingTree = new class implements GitWorkingTreeProvider {
        public function changes(string $projectRoot, ?string $baseRef, int $maxFiles, int $timeoutMs): array
        {
            assertSame('/workspace/fixture-shop', $projectRoot);
            assertSame('main', $baseRef);
            return ['paths' => ['src/Checkout.php', 'src/missing.php'], 'renames' => [
                ['from' => 'src/Old.php', 'to' => 'src/Checkout.php'],
            ], 'truncated' => false];
        }
    };
    $query = new ArchitectureQueryService($pdo, gitWorkingTree: $workingTree);
    $explicit = $query->changedFilesImpact($ids['project'], ['src/Checkout.php', 'src/missing.php']);
    assertSame(2, count($explicit->data['direct_components']));
    assertSame(['src/missing.php'], $explicit->data['unresolved_files']);
    assertSame(false, $explicit->data['git']['used']);
    assertSame('src/Checkout.php', $explicit->evidence[0]['path']);

    $discovered = $query->changedFilesImpact($ids['project'], workingTree: true, baseRef: 'main');
    assertSame(true, $discovered->data['git']['used']);
    assertSame('src/Old.php', $discovered->data['git']['renames'][0]['from']);
    assertThrows(static fn() => $query->changedFilesImpact($ids['project']), InvalidArgumentException::class);
    assertThrows(static fn() => $query->changedFilesImpact($ids['project'], ['../escape.php']), InvalidArgumentException::class);
    assertThrows(static fn() => $query->changedFilesImpact($ids['project'], ['src/Checkout.php'], workingTree: true), InvalidArgumentException::class);
};
$testGroups['changed-file impact maps explicit and working-tree paths without execution'] = 'git';

$tests['diagram export is deterministic scoped escaped and bounded'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $repository->saveNode(
        $ids['checkout'],
        $ids['project'],
        'class',
        'App\\Checkout',
        'Checkout "API" <unsafe>',
        null,
        $ids['file'],
        3,
        18,
        'ast',
        'certain',
        [],
        'php:file:src/Checkout.php',
        $ids['scan'],
    );
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $duplicateBackend = StableId::boundary($ids['project'], 'Backend', 'inferred');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundary($duplicateBackend, $ids['project'], 'Backend', ['namespace_prefix' => 'App'], 'inferred', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);

    $query = new ArchitectureQueryService($pdo);
    $mermaid = $query->exportDiagram($ids['project']);
    assertContains("flowchart LR\n", $mermaid->data['diagram']);
    assertContains('Checkout &quot;API&quot; &lt;unsafe&gt;', $mermaid->data['diagram']);
    assertContains('n1 -->|calls| n2', $mermaid->data['diagram']);
    assertSame($mermaid->data['diagram'], $query->exportDiagram($ids['project'])->data['diagram']);
    assertSame(2, $mermaid->data['bounds']['nodes_exported']);
    assertSame(1, $mermaid->data['bounds']['edges_exported']);

    $plant = $query->exportDiagram($ids['project'], format: 'plantuml', direction: 'TB');
    assertContains("@startuml\n", $plant->data['diagram']);
    assertSame(false, str_contains($plant->data['diagram'], 'left to right direction'));
    assertContains('Checkout \\"API\\" <unsafe>', $plant->data['diagram']);
    assertContains("@enduml\n", $plant->data['diagram']);
    $scoped = $query->exportDiagram($ids['project'], boundary: $backend);
    assertSame(1, $scoped->data['bounds']['nodes_exported']);
    assertSame(0, $scoped->data['bounds']['edges_exported']);
    assertSame($backend, $scoped->data['boundary_id']);
    $filtered = $query->exportDiagram($ids['project'], edgeKinds: ['imports']);
    assertSame(0, $filtered->data['bounds']['edges_exported']);
    $limited = $query->exportDiagram($ids['project'], maxNodes: 1);
    assertSame(true, $limited->truncated);
    assertSame(['node_limit'], $limited->data['bounds']['truncation_reasons']);
    assertThrows(static fn() => $query->exportDiagram($ids['project'], boundary: 'Backend'), InvalidArgumentException::class);
    assertThrows(static fn() => $query->exportDiagram($ids['project'], format: 'dot'), InvalidArgumentException::class);
    assertThrows(static fn() => $query->exportDiagram($ids['project'], direction: 'RL'), InvalidArgumentException::class);
};
$testGroups['diagram export is deterministic scoped escaped and bounded'] = 'diagram';

$tests['boundaries infer and configure membership with paginated search'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-boundary-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate boundary database.');
    }
    $root = __DIR__ . '/Fixtures/mixed';
    try {
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $scan = (new ProjectScanService($pdo, dirname(__DIR__), [$root]))->scan(
            $root,
            'Mixed Boundaries',
            explicitBoundaries: [
                ['name' => 'Backend', 'path_prefix' => 'src'],
                ['name' => 'Frontend', 'path_prefix' => 'frontend'],
            ],
        );
        $query = new ArchitectureQueryService($pdo);
        $explicit = $query->listBoundaries($scan->projectId, 'explicit');
        assertSame(2, count($explicit->data['boundaries']));
        assertSame(['Backend', 'Frontend'], array_column($explicit->data['boundaries'], 'name'));
        assertSame('explicit', $explicit->data['boundaries'][0]['source']);
        assertSame(false, str_starts_with($explicit->evidence[0]['path'], '/'));
        $inferred = $query->listBoundaries($scan->projectId, 'inferred');
        assertSame(true, count($inferred->data['boundaries']) >= 4);
        assertArrayContains('namespace:Fixture', array_column($inferred->data['boundaries'], 'name'));

        $page = $query->listBoundaries($scan->projectId, null, 1);
        assertSame(true, $page->truncated);
        assertSame(1, $page->data['pagination']['next_offset']);
        assertSame('result_limit', $page->data['pagination']['truncation_reason']);
        $backendId = $explicit->data['boundaries'][0]['id'];
        $found = $query->searchArchitecture(
            $scan->projectId,
            'CheckoutService',
            kinds: ['class'],
            roles: ['application.service'],
            boundaryIds: [$backendId],
            confidences: ['certain'],
        );
        assertSame(1, count($found->data['results']));
        assertSame('Fixture\\CheckoutService', $found->data['results'][0]['canonical_name']);
        assertSame('Backend', $found->data['results'][0]['boundaries'][0]['name']);
        assertSame('src/CheckoutService.php', $found->evidence[0]['path']);

        $roleSearch = $query->searchArchitecture($scan->projectId, 'application.service');
        assertSame(1, count($roleSearch->data['results']));
        $pagedSearch = $query->searchArchitecture($scan->projectId, 'Checkout', limit: 1);
        assertSame(1, count($pagedSearch->data['results']));
        assertSame(true, $pagedSearch->truncated);
        assertSame(1, $pagedSearch->data['pagination']['next_offset']);
        assertThrows(static fn() => $query->searchArchitecture($scan->projectId, 'x', confidences: ['unknown']), InvalidArgumentException::class);
        assertThrows(static fn() => $query->listBoundaries($scan->projectId, 'generated'), InvalidArgumentException::class);
    } finally {
        unset($pdo);
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
    assertThrows(
        static fn() => (new BoundaryInference())->infer([], [], [['name' => 'Escape', 'path_prefix' => '../outside']]),
        InvalidArgumentException::class,
    );
};
$testGroups['boundaries infer and configure membership with paginated search'] = 'boundary';

$tests['incremental contribution cache detects edits deletes and renames with full equivalence'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
    $database = tempnam(sys_get_temp_dir(), 'knossos-incremental-db-');
    if ($database === false || !mkdir($root . '/src', 0700, true)) {
        throw new RuntimeException('Unable to create incremental fixture.');
    }
    file_put_contents($root . '/composer.json', json_encode([
        'name' => 'fixture/incremental', 'autoload' => ['psr-4' => ['Fixture\\' => 'src/']],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/src/A.php', "<?php\nnamespace Fixture;\nfinal class A { public function __construct(private B \$value) {} }\n");
    file_put_contents($root . '/src/B.php', "<?php\nnamespace Fixture;\nfinal class B {}\n");
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $service = new ProjectScanService($pdo, dirname(__DIR__), [$root]);
        $first = $service->scan($root);
        assertSame('full', $first->data['mode']);
        assertSame(2, $first->data['parsed_files']);
        assertSame(2, $first->data['added_files']);

        $unchanged = $service->scan($root);
        assertSame('incremental', $unchanged->data['mode']);
        assertSame(0, $unchanged->data['parsed_files']);
        assertSame(2, $unchanged->data['unchanged_files']);
        assertSame(0, $unchanged->data['deleted_files']);

        file_put_contents($root . '/src/B.php', "<?php\nnamespace Fixture;\nfinal class C {}\n");
        $changed = $service->scan($root);
        assertSame(1, $changed->data['parsed_files']);
        assertSame(1, $changed->data['unchanged_files']);
        assertSame(1, $changed->data['changed_files']);
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE canonical_name = 'Fixture\\B' AND origin = 'derived'")->fetchColumn());

        file_put_contents($root . '/src/A.php', "<?php\nnamespace Fixture;\nfinal class A { public function __construct(private C \$value) {} }\n");
        $relinked = $service->scan($root);
        assertSame(1, $relinked->data['parsed_files']);
        assertSame(1, (int) $pdo->query(
            "SELECT COUNT(*) FROM edges e JOIN nodes t ON t.id = e.target_id WHERE e.kind = 'injects' AND t.canonical_name = 'Fixture\\C'",
        )->fetchColumn());

        rename($root . '/src/B.php', $root . '/src/C.php');
        $renamed = $service->scan($root);
        assertSame(1, $renamed->data['parsed_files']);
        assertSame(1, $renamed->data['added_files']);
        assertSame(1, $renamed->data['deleted_files']);
        assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM contribution_cache')->fetchColumn());

        $incrementalGraph = graphSignature($pdo);
        $full = $service->scan($root, mode: 'full');
        assertSame('full', $full->data['mode']);
        assertSame(2, $full->data['parsed_files']);
        assertSame($incrementalGraph, graphSignature($pdo));
    } finally {
        unset($pdo);
        removeFixtureTree($root);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['incremental contribution cache detects edits deletes and renames with full equivalence'] = 'incremental';

$tests['seeded edit sequences keep incremental and full graphs equivalent'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
    $database = tempnam(sys_get_temp_dir(), 'knossos-differential-');
    if ($database === false || !mkdir($root . '/src', 0700, true)) {
        throw new RuntimeException('Unable to create differential fixture.');
    }
    file_put_contents($root . '/composer.json', '{"name":"fixture/differential","autoload":{"psr-4":{"Fixture\\\\":"src/"}}}');
    foreach (range(0, 4) as $index) {
        file_put_contents(
            sprintf('%s/src/Service%d.php', $root, $index),
            sprintf("<?php\nnamespace Fixture;\nfinal class Service%d { public const REVISION = 0; }\n", $index),
        );
    }
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $service = new ProjectScanService($pdo, dirname(__DIR__), [$root]);
        $service->scan($root, mode: 'full');
        $state = 0xC0FFEE;
        for ($round = 1; $round <= 5; ++$round) {
            $state = (int) (($state * 1664525 + 1013904223) & 0x7fffffff);
            $index = $state % 5;
            file_put_contents(
                sprintf('%s/src/Service%d.php', $root, $index),
                sprintf("<?php\nnamespace Fixture;\nfinal class Service%d { public const REVISION = %d; }\n", $index, $round),
            );
            $incremental = $service->scan($root, mode: 'incremental');
            assertSame('incremental', $incremental->data['mode']);
            assertSame(1, $incremental->data['parsed_files']);
            $incrementalGraph = graphSignature($pdo);
            $service->scan($root, mode: 'full');
            assertSame($incrementalGraph, graphSignature($pdo));
        }
    } finally {
        unset($service, $pdo);
        removeFixtureTree($root);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }
};
$testGroups['seeded edit sequences keep incremental and full graphs equivalent'] = 'property';

$tests['long-lived scans reuse TypeScript programs and invalidate only affected analyzers'] = static function (): void {
    $root = sys_get_temp_dir() . '/knossos-incremental-' . bin2hex(random_bytes(6));
    $database = tempnam(sys_get_temp_dir(), 'knossos-language-cache-');
    if ($database === false || !mkdir($root . '/src', 0700, true)) {
        throw new RuntimeException('Unable to create language cache fixture.');
    }
    $composer = ['name' => 'fixture/mixed-cache', 'require' => ['laravel/framework' => '^12.0'], 'autoload' => ['psr-4' => ['Fixture\\' => 'src/']]];
    file_put_contents($root . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
    file_put_contents($root . '/package.json', json_encode(['name' => 'fixture-mixed-cache', 'private' => true], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/tsconfig.json', json_encode(['compilerOptions' => ['target' => 'ES2022'], 'include' => ['src/*.ts']], JSON_THROW_ON_ERROR));
    file_put_contents($root . '/src/A.php', "<?php\nnamespace Fixture;\nuse Illuminate\\Support\\Facades\\Route;\nfinal class A {}\nRoute::get('/a', static fn () => null);\n");
    file_put_contents($root . '/src/A.ts', "export class A { value = 1; }\n");
    try {
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $service = new ProjectScanService($pdo, dirname(__DIR__), [$root]);
        $first = $service->scan($root);
        assertSame(2, $first->data['parsed_files']);
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());
        $none = $service->scan($root);
        assertSame(0, $none->data['parsed_files']);

        file_put_contents($root . '/src/A.ts', "export class A { value = 2; }\n");
        $typescriptChange = $service->scan($root);
        assertSame(1, $typescriptChange->data['parsed_files']);
        assertSame(1, $typescriptChange->data['unchanged_files']);
        assertSame(true, $typescriptChange->data['scanner_metadata']['knossos.typescript']['programs_reused'] >= 1);

        file_put_contents($root . '/tsconfig.json', json_encode(['compilerOptions' => ['target' => 'ES2022', 'strict' => true], 'include' => ['src/*.ts']], JSON_THROW_ON_ERROR));
        $typescriptConfig = $service->scan($root);
        assertSame(1, $typescriptConfig->data['parsed_files']);
        assertSame(1, $typescriptConfig->data['unchanged_files']);
        assertSame(true, isset($typescriptConfig->data['scanner_metadata']['knossos.typescript']));
        assertSame(false, isset($typescriptConfig->data['scanner_metadata']['knossos.php']));

        $composer['description'] = 'invalidate only PHP and Laravel enrichment';
        file_put_contents($root . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR));
        $phpConfig = $service->scan($root);
        assertSame(1, $phpConfig->data['parsed_files']);
        assertSame(1, $phpConfig->data['unchanged_files']);
        assertSame(true, isset($phpConfig->data['scanner_metadata']['knossos.php']));
        assertSame(false, isset($phpConfig->data['scanner_metadata']['knossos.typescript']));
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route'")->fetchColumn());

        $pdo->exec("UPDATE contribution_cache SET scanner_version = '0.0.0' WHERE scanner_id = 'knossos.typescript'");
        $versionChange = $service->scan($root);
        assertSame(1, $versionChange->data['parsed_files']);
        assertSame(1, $versionChange->data['unchanged_files']);
        assertSame('0.3.0', (string) $pdo->query(
            "SELECT scanner_version FROM contribution_cache WHERE scanner_id = 'knossos.typescript'",
        )->fetchColumn());
    } finally {
        unset($service, $pdo);
        removeFixtureTree($root);
        foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['long-lived scans reuse TypeScript programs and invalidate only affected analyzers'] = 'incremental-language';

$tests['writer leases cancellation and stale recovery preserve query availability'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-concurrency-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate concurrency database.');
    }
    try {
        $writerPdo = SqliteConnection::open($path);
        (new MigrationRunner($writerPdo, dirname(__DIR__) . '/migrations'))->migrate();
        $repository = new SqliteGraphRepository($writerPdo);
        $project = StableId::project('concurrency-fixture');
        $scan = StableId::scan($project, 'active');
        $file = StableId::file($project, 'src/A.php');
        $node = StableId::symbol($project, 'php', 'class', 'Fixture\\A');
        $repository->saveProject($project, 'Concurrency Fixture', '/workspace/concurrency');
        $repository->createScan($scan, $project, 'full', hash('sha256', 'scanner'));
        $repository->saveFile($file, $project, 'src/A.php', hash('sha256', 'A'), 1, 1, 'php', '0.2.0', $scan);
        $repository->saveNode($node, $project, 'class', 'Fixture\\A', 'A', null, $file, 1, 1, 'ast', 'certain', [], 'php:file:src/A.php', $scan);
        $repository->completeScan($project, $scan);

        $readerPdo = SqliteConnection::open($path);
        $lease = (new ProjectWriterLock($writerPdo))->acquire($project);
        assertSame($scan, (new ArchitectureQueryService($readerPdo))->architectureSummary($project)->snapshotId);
        assertThrows(static fn() => (new ProjectWriterLock($readerPdo))->acquire($project), ScanBusyException::class);
        $lease->release();
        $second = (new ProjectWriterLock($readerPdo))->acquire($project);
        $second->release();

        $writerPdo->prepare('INSERT INTO scan_locks(project_id, owner_token, acquired_at) VALUES (:project, :token, 0)')
            ->execute(['project' => $project, 'token' => 'orphan']);
        $recovered = (new ProjectWriterLock($writerPdo, 10, static fn(): int => 100))->acquire($project);
        $recovered->release();
        assertSame(0, (int) $writerPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());

        $client = fakeWorkerClient('slow_scan', new WorkerLimits(requestTimeoutMs: 2_000));
        $client->initialize();
        $polls = 0;
        $error = captureThrows(
            static fn() => iterator_to_array($client->scan([], static function () use (&$polls): bool {
                return ++$polls >= 2;
            })),
            WorkerException::class,
        );
        assertSame('WORKER_CANCELLED', $error->diagnosticCode);

        $token = new CancellationToken();
        $token->cancel();
        assertThrows(
            static fn() => (new ProjectScanService($writerPdo, dirname(__DIR__), [__DIR__ . '/Fixtures/mixed']))->scan(__DIR__ . '/Fixtures/mixed', cancellation: $token),
            \Knossos\Scan\ScanCancelledException::class,
        );
        assertSame($scan, (string) $writerPdo->query("SELECT active_scan_id FROM projects WHERE id = '$project'")->fetchColumn());
        assertSame(0, (int) $writerPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());
    } finally {
        unset($readerPdo, $writerPdo);
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['writer leases cancellation and stale recovery preserve query availability'] = 'concurrency';

$tests['storage locks disk limits and corrupt caches recover without publishing partial state'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-fault-storage-');
    $cacheDatabase = tempnam(sys_get_temp_dir(), 'knossos-fault-cache-');
    if ($path === false || $cacheDatabase === false) {
        throw new RuntimeException('Unable to allocate fault-injection databases.');
    }
    try {
        $writer = SqliteConnection::open($path);
        $writer->exec('CREATE TABLE fault_payloads (id INTEGER PRIMARY KEY, payload BLOB NOT NULL)');
        $writer->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        $pages = (int) $writer->query('PRAGMA page_count')->fetchColumn();
        $writer->exec('PRAGMA max_page_count = ' . $pages);
        assertThrows(static fn() => $writer->exec('INSERT INTO fault_payloads(payload) VALUES (zeroblob(1048576))'), PDOException::class);
        assertSame(0, (int) $writer->query('SELECT COUNT(*) FROM fault_payloads')->fetchColumn());
        $writer->exec('PRAGMA max_page_count = 10000');
        $writer->exec("INSERT INTO fault_payloads(payload) VALUES (x'01')");

        $contender = SqliteConnection::open($path);
        $contender->exec('PRAGMA busy_timeout = 25');
        $writer->exec('BEGIN IMMEDIATE');
        assertThrows(static fn() => $contender->exec("INSERT INTO fault_payloads(payload) VALUES (x'02')"), PDOException::class);
        $writer->exec('ROLLBACK');
        $contender->exec("INSERT INTO fault_payloads(payload) VALUES (x'03')");
        assertSame(2, (int) $contender->query('SELECT COUNT(*) FROM fault_payloads')->fetchColumn());

        $root = __DIR__ . '/Fixtures/configured';
        $cachePdo = SqliteConnection::open($cacheDatabase);
        (new MigrationRunner($cachePdo, dirname(__DIR__) . '/migrations'))->migrate();
        $service = new ProjectScanService($cachePdo, dirname(__DIR__), [$root]);
        $first = $service->scan($root);
        $nodeCount = (int) $cachePdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn();
        $cachePdo->exec("UPDATE contribution_cache SET payload_json = '{corrupt' ");
        $recovered = $service->scan($root, mode: 'incremental');
        assertSame(true, $recovered->data['parsed_files'] > 0);
        assertSame($nodeCount, (int) $cachePdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
        assertNotSame($first->snapshotId, $recovered->snapshotId);
    } finally {
        unset($service, $cachePdo, $contender, $writer);
        foreach ([$path, $path . '-shm', $path . '-wal', $cacheDatabase, $cacheDatabase . '-shm', $cacheDatabase . '-wal'] as $candidate) {
            @unlink($candidate);
        }
    }
};
$testGroups['storage locks disk limits and corrupt caches recover without publishing partial state'] = 'fault-injection';

$tests['cancelled worker supervision terminates spawned process trees'] = static function (): void {
    if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill')) {
        return;
    }
    $pidFile = tempnam(sys_get_temp_dir(), 'knossos-child-pid-');
    if ($pidFile === false) {
        throw new RuntimeException('Unable to allocate child PID fixture.');
    }
    try {
        $client = new ProcessScannerClient(
            [PHP_BINARY, __DIR__ . '/Fixtures/workers/fake-worker.php', 'child_scan', $pidFile],
            new WorkerLimits(requestTimeoutMs: 3_000),
        );
        $client->initialize();
        $polls = 0;
        $error = captureThrows(
            static fn() => iterator_to_array($client->scan([new stdClass()], static function () use (&$polls): bool {
                return ++$polls >= 4;
            })),
            WorkerException::class,
        );
        assertSame('WORKER_CANCELLED', $error->diagnosticCode);
        $childPid = (int) trim((string) file_get_contents($pidFile));
        assertSame(true, $childPid > 0);
        for ($attempt = 0; $attempt < 50 && is_dir('/proc/' . $childPid); ++$attempt) {
            usleep(10_000);
        }
        assertSame(false, is_dir('/proc/' . $childPid));
    } finally {
        unset($client);
        @unlink($pidFile);
    }
};
$testGroups['cancelled worker supervision terminates spawned process trees'] = 'fault-injection';

$tests['maintenance previews destructive work and produces restorable backups'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-maintenance-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate maintenance database.');
    }
    try {
        $pdo = SqliteConnection::open($path);
        (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
        $repository = new SqliteGraphRepository($pdo);
        $project = StableId::project('maintenance-fixture');
        $active = StableId::scan($project, 'active');
        $stale = StableId::scan($project, 'stale');
        $protected = StableId::scan($project, 'protected');
        $repository->saveProject($project, 'Maintenance Fixture', '/workspace/maintenance');
        $repository->createScan($active, $project, 'full', hash('sha256', 'active'));
        $repository->completeScan($project, $active);
        $repository->createScan($stale, $project, 'incremental', hash('sha256', 'stale'));
        $pdo->exec("UPDATE scans SET status = 'failed', started_at = '2000-01-01T00:00:00+00:00' WHERE id = '" . $stale . "'");
        $repository->createScan($protected, $project, 'incremental', hash('sha256', 'protected'));
        $protectedFile = StableId::file($project, 'src/Protected.php');
        $repository->saveFile($protectedFile, $project, 'src/Protected.php', hash('sha256', 'protected'), 9, 1, 'php', '1', $protected);
        $pdo->exec("UPDATE scans SET status = 'cancelled', started_at = '2000-01-01T00:00:00+00:00' WHERE id = '" . $protected . "'");

        $service = new DatabaseMaintenanceService($pdo, $path);
        $cleanupPreview = $service->cleanupStaleScans($project);
        assertSame(false, $cleanupPreview->data['executed']);
        assertSame([$stale], $cleanupPreview->data['removable_scan_ids']);
        assertSame([$protected], $cleanupPreview->data['protected_scan_ids']);
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE id = '" . $stale . "'")->fetchColumn());
        assertSame(true, $service->cleanupStaleScans($project, execute: true)->data['executed']);
        assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE id = '" . $stale . "'")->fetchColumn());
        assertSame(1, (int) $pdo->query("SELECT COUNT(*) FROM scans WHERE id = '" . $protected . "'")->fetchColumn());
        assertThrows(static fn() => $service->cleanupStaleScans($project, 0), InvalidArgumentException::class);

        assertSame(true, $service->maintain('integrity')->data['ok']);
        assertSame(false, $service->maintain('checkpoint')->data['executed']);
        assertSame(false, $service->maintain('backup', backupName: 'preview.sqlite')->data['executed']);
        assertSame(true, $service->maintain('checkpoint', true)->data['executed']);
        assertSame(true, $service->maintain('optimize', true)->data['executed']);
        assertThrows(static fn() => $service->maintain('backup', backupName: '../escape.sqlite'), InvalidArgumentException::class);
        $backupResult = $service->maintain('backup', true, 'fixture.sqlite');
        assertSame(true, is_file($backupResult->data['target']));
        $backupPdo = SqliteConnection::open($backupResult->data['target']);
        assertSame('ok', $backupPdo->query('PRAGMA integrity_check')->fetchColumn());
        assertSame(1, (int) $backupPdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        assertSame(0, (int) $backupPdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());
        unset($backupPdo);
        assertThrows(static fn() => $service->maintain('backup', true, 'fixture.sqlite'), InvalidArgumentException::class);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM scan_locks')->fetchColumn());

        [$exit, $stdout, $stderr] = runFixtureCommandOutput([
            PHP_BINARY, dirname(__DIR__) . '/bin/knossos', 'maintain-database', 'integrity', '--db=' . $path, '--json',
        ]);
        assertSame(0, $exit);
        assertSame('', $stderr);
        assertSame(true, json_decode($stdout, true, 512, JSON_THROW_ON_ERROR)['data']['ok']);

        $lease = (new ProjectWriterLock($pdo))->acquire($project);
        assertThrows(static fn() => $service->maintain('optimize', true), ScanBusyException::class);
        $lease->release();
        assertSame(false, $service->removeProject($project)->data['executed']);
        assertSame(true, $service->removeProject($project, true)->data['executed']);
        assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        assertThrows(static fn() => $service->cleanupStaleScans($project), InvalidArgumentException::class);
        assertThrows(static fn() => $service->maintain('invalid'), InvalidArgumentException::class);
        assertThrows(static fn() => (new DatabaseMaintenanceService($pdo, ':memory:'))->maintain('backup'), InvalidArgumentException::class);
    } finally {
        unset($service, $repository, $pdo);
        $backup = dirname($path) . '/backups/fixture.sqlite';
        foreach ([$path, $path . '-shm', $path . '-wal', $backup, $backup . '-shm', $backup . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
        $backupDirectory = dirname($path) . '/backups';
        if (is_dir($backupDirectory)) {
            @rmdir($backupDirectory);
        }
    }
};
$testGroups['maintenance previews destructive work and produces restorable backups'] = 'maintenance';

$tests['protocol byte caps and stable tool diagnostics contain floods'] = static function (): void {
    $pdo = SqliteConnection::open(':memory:');
    (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    $root = __DIR__ . '/Fixtures/mixed';
    $tools = new ToolService(
        new ProjectScanService($pdo, dirname(__DIR__), [$root]),
        new ArchitectureQueryService($pdo),
        new DatabaseMaintenanceService($pdo, ':memory:'),
    );

    $input = fopen('php://temp', 'r+');
    $output = fopen('php://temp', 'r+');
    $errors = fopen('php://temp', 'r+');
    fwrite($input, str_repeat('x', 256) . "\n");
    rewind($input);
    (new StdioServer($tools, maxLineBytes: 128))->run($input, $output, $errors);
    rewind($output);
    $frame = json_decode(trim((string) stream_get_contents($output)), true, 512, JSON_THROW_ON_ERROR);
    assertSame(-32700, $frame['error']['code']);

    $input = fopen('php://temp', 'r+');
    $output = fopen('php://temp', 'r+');
    $errors = fopen('php://temp', 'r+');
    fwrite($input, json_encode([
        'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
        'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'cap', 'version' => '1']],
    ], JSON_THROW_ON_ERROR) . "\n");
    rewind($input);
    (new StdioServer($tools, maxResponseBytes: 100))->run($input, $output, $errors);
    rewind($output);
    $frame = json_decode(trim((string) stream_get_contents($output)), true, 512, JSON_THROW_ON_ERROR);
    assertSame(-32001, $frame['error']['code']);
    assertContains('byte limit', $frame['error']['message']);
};
$testGroups['protocol byte caps and stable tool diagnostics contain floods'] = 'limits';

$tests['doctor reports runtimes workers protocol database and migrations'] = static function (): void {
    $pdo = SqliteConnection::open(':memory:');
    (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    $report = (new DoctorService($pdo, dirname(__DIR__), ':memory:'))->run();
    $byName = [];
    foreach ($report['checks'] as $check) {
        $byName[$check['name']] = $check;
    }
    foreach (['php.version', 'php.extension.pdo_sqlite', 'node.version', 'python.version', 'sqlite.integrity', 'sqlite.migrations', 'worker.php', 'worker.typescript', 'worker.python'] as $name) {
        assertSame(true, isset($byName[$name]));
    }
    assertSame('ok', $byName['worker.php']['status']);
    assertContains('knossos.php@0.2.0 protocol 1.0', $byName['worker.php']['detail']);
    assertSame('ok', $byName['worker.typescript']['status']);
    assertSame('ok', $byName['worker.python']['status']);
    assertContains('knossos.python@0.2.0 protocol 1.0', $byName['worker.python']['detail']);
    assertSame('9 applied', $byName['sqlite.migrations']['detail']);
    preg_match('/v(\d+)\./', $byName['node.version']['detail'], $nodeVersion);
    $nodeMajor = (int) ($nodeVersion[1] ?? 0);
    assertSame($nodeMajor >= 22 && $nodeMajor <= 24 ? 'ok' : 'error', $byName['node.version']['status']);
};
$testGroups['doctor reports runtimes workers protocol database and migrations'] = 'packaging';

$tests['tool service dispatches every architecture query and rejects malformed arguments'] = static function (): void {
    [$pdo, $repository, $ids] = storeFixture();
    $backend = StableId::boundary($ids['project'], 'Backend', 'explicit');
    $billing = StableId::boundary($ids['project'], 'Billing', 'explicit');
    $repository->saveBoundary($backend, $ids['project'], 'Backend', ['path_prefix' => 'src/Checkout'], 'explicit', $ids['scan']);
    $repository->saveBoundary($billing, $ids['project'], 'Billing', ['path_prefix' => 'src/Invoice'], 'explicit', $ids['scan']);
    $repository->saveBoundaryMembership($backend, $ids['project'], $ids['checkout'], $ids['scan']);
    $repository->saveBoundaryMembership($billing, $ids['project'], $ids['invoice'], $ids['scan']);
    $repository->completeScan($ids['project'], $ids['scan']);
    $tools = new ToolService(
        new ProjectScanService($pdo, dirname(__DIR__), [__DIR__ . '/Fixtures/mixed']),
        new ArchitectureQueryService($pdo),
        new DatabaseMaintenanceService($pdo, ':memory:'),
    );
    $calls = [
        ['find_component', ['project_id' => $ids['project'], 'name' => 'Checkout']],
        ['list_snapshots', ['project_id' => $ids['project']]],
        ['architecture_summary', ['project_id' => $ids['project']]],
        ['explain_flow', ['project_id' => $ids['project'], 'from' => 'Checkout', 'to' => 'Invoice']],
        ['impact_analysis', ['project_id' => $ids['project'], 'symbol' => 'Invoice']],
        ['dependency_cycles', ['project_id' => $ids['project']]],
        ['architecture_health', ['project_id' => $ids['project']]],
        ['check_architecture', [
            'project_id' => $ids['project'],
            'policies' => [[
                'id' => 'backend-billing', 'from_boundary' => $backend, 'allow_targets' => [$billing],
            ]],
        ]],
        ['suggest_location', ['project_id' => $ids['project'], 'feature_description' => 'checkout billing']],
        ['change_impact', ['project_id' => $ids['project'], 'symbol' => 'Invoice']],
        ['changed_files_impact', ['project_id' => $ids['project'], 'files' => ['src/Checkout.php']]],
        ['architecture_context', ['project_id' => $ids['project'], 'task_description' => 'checkout billing']],
        ['export_diagram', ['project_id' => $ids['project']]],
        ['list_boundaries', ['project_id' => $ids['project']]],
        ['search_architecture', ['project_id' => $ids['project'], 'query' => 'Checkout']],
        ['cleanup_stale_scans', ['project_id' => $ids['project']]],
        ['remove_project', ['project_id' => $ids['project']]],
    ];
    assertSame('catalog', $tools->call('list_projects', [])->projectId);
    assertSame($ids['project'], $tools->call('inspect_component', [
        'project_id' => $ids['project'], 'component' => $ids['checkout'],
    ])->projectId);
    assertSame('database', $tools->call('maintain_database', ['action' => 'integrity'])->projectId);
    foreach ($calls as [$name, $arguments]) {
        assertSame($ids['project'], $tools->call($name, $arguments)->projectId);
    }
    assertThrows(static fn() => $tools->call('missing_tool', []), InvalidArgumentException::class);
    assertThrows(static fn() => $tools->call('find_component', ['project_id' => $ids['project']]), InvalidArgumentException::class);
    assertThrows(static fn() => $tools->call('architecture_summary', ['project_id' => $ids['project'], 'extra' => true]), InvalidArgumentException::class);
    assertThrows(static fn() => $tools->call('architecture_summary', ['project_id' => $ids['project'], 'limit' => 'many']), InvalidArgumentException::class);
    assertThrows(static fn() => $tools->call('explain_flow', ['project_id' => $ids['project'], 'from' => 'Checkout', 'to' => 'Invoice', 'edge_kinds' => [1]]), InvalidArgumentException::class);
    assertThrows(static fn() => $tools->call('check_architecture', ['project_id' => $ids['project'], 'policies' => 'invalid']), InvalidArgumentException::class);
    assertThrows(static fn() => $tools->call('list_projects', ['include_roots' => 'yes']), InvalidArgumentException::class);
};
$testGroups['tool service dispatches every architecture query and rejects malformed arguments'] = 'mcp';

$tests['stdio run loop contains malformed frames notifications and response caps'] = static function (): void {
    [$pdo] = storeFixture();
    $tools = new ToolService(
        new ProjectScanService($pdo, dirname(__DIR__), [__DIR__ . '/Fixtures/mixed']),
        new ArchitectureQueryService($pdo),
        new DatabaseMaintenanceService($pdo, ':memory:'),
    );
    $input = fopen('php://temp', 'w+');
    $output = fopen('php://temp', 'w+');
    $errors = fopen('php://temp', 'w+');
    if (!is_resource($input) || !is_resource($output) || !is_resource($errors)) {
        throw new RuntimeException('Unable to allocate stdio test streams.');
    }
    fwrite($input, "not-json\n");
    fwrite($input, "[]\n");
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => StdioServer::PROTOCOL_VERSION]], JSON_THROW_ON_ERROR) . "\n");
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR) . "\n");
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'], JSON_THROW_ON_ERROR) . "\n");
    fwrite($input, json_encode(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'unknown'], JSON_THROW_ON_ERROR) . "\n");
    rewind($input);
    assertSame(0, (new StdioServer($tools, maxResponseBytes: 100))->run($input, $output, $errors));
    rewind($output);
    $responses = (string) stream_get_contents($output);
    assertContains('Parse error', $responses);
    assertContains('Response exceeds the configured byte limit', $responses);
    assertContains('Method not found', $responses);
    rewind($errors);
    assertContains('Syntax error', (string) stream_get_contents($errors));
    fclose($input);
    fclose($output);
    fclose($errors);

    $server = new StdioServer($tools);
    assertSame(-32600, $server->handle(['id' => 10])['error']['code']);
    assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 11, 'method' => 'initialize'])['error']['code']);
    assertSame('2.0', $server->handle(['jsonrpc' => '2.0', 'id' => 12, 'method' => 'ping'])['jsonrpc']);
    assertSame(-32002, $server->handle(['jsonrpc' => '2.0', 'id' => 13, 'method' => 'tools/list'])['error']['code']);
    assertSame(null, $server->handle(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']));
    assertSame(-32602, $server->handle(['jsonrpc' => '2.0', 'id' => 14, 'method' => 'tools/call', 'params' => []])['error']['code']);
    $toolError = $server->handle([
        'jsonrpc' => '2.0', 'id' => 15, 'method' => 'tools/call',
        'params' => ['name' => 'architecture_summary', 'arguments' => ['project_id' => 'missing']],
    ]);
    assertSame(true, $toolError['result']['isError']);
    assertSame('KNOSSOS_INVALID_ARGUMENT', $toolError['result']['structuredContent']['error']['code']);
    assertSame(null, $server->handle([
        'jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 'scan-1'],
    ]));
    $cancelled = $server->handle([
        'jsonrpc' => '2.0', 'id' => 'scan-1', 'method' => 'tools/call',
        'params' => ['name' => 'scan_project', 'arguments' => ['path' => __DIR__ . '/Fixtures/mixed']],
    ]);
    assertSame('KNOSSOS_SCAN_CANCELLED', $cancelled['result']['structuredContent']['error']['code']);
    $scanned = $server->handle([
        'jsonrpc' => '2.0', 'id' => 16, 'method' => 'tools/call',
        'params' => ['name' => 'scan_project', 'arguments' => ['path' => __DIR__ . '/Fixtures/mixed']],
    ]);
    assertSame(false, $scanned['result']['isError']);
    assertContains('project_', $scanned['result']['structuredContent']['project_id']);

    $oversizedInput = fopen('php://temp', 'w+');
    $oversizedOutput = fopen('php://temp', 'w+');
    $oversizedErrors = fopen('php://temp', 'w+');
    fwrite($oversizedInput, str_repeat('x', 20) . "\n");
    rewind($oversizedInput);
    assertSame(0, (new StdioServer($tools, maxLineBytes: 10))->run($oversizedInput, $oversizedOutput, $oversizedErrors));
    rewind($oversizedOutput);
    assertContains('Invalid or oversized', (string) stream_get_contents($oversizedOutput));
    fclose($oversizedInput);
    fclose($oversizedOutput);
    fclose($oversizedErrors);

    $pollInput = fopen('php://temp', 'w+');
    if (!is_resource($pollInput)) {
        throw new RuntimeException('Unable to allocate cancellation polling stream.');
    }
    fwrite($pollInput, "not-json\n");
    fwrite($pollInput, json_encode(['jsonrpc' => '2.0', 'id' => 99, 'method' => 'ping'], JSON_THROW_ON_ERROR) . "\n");
    fwrite($pollInput, json_encode([
        'jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 'polled-scan'],
    ], JSON_THROW_ON_ERROR) . "\n");
    rewind($pollInput);
    $polledServer = new StdioServer($tools);
    $inputProperty = new ReflectionProperty($polledServer, 'input');
    $inputProperty->setValue($polledServer, $pollInput);
    $pollMethod = new ReflectionMethod($polledServer, 'pollCancellation');
    assertSame(true, $pollMethod->invoke($polledServer, 'polled-scan'));
    $pendingProperty = new ReflectionProperty($polledServer, 'pendingLines');
    assertSame(2, count($pendingProperty->getValue($polledServer)));
    fclose($pollInput);
};
$testGroups['stdio run loop contains malformed frames notifications and response caps'] = 'mcp';

$tests['seeded JSON-RPC message shapes return bounded protocol responses'] = static function (): void {
    [$pdo] = storeFixture();
    $tools = new ToolService(
        new ProjectScanService($pdo, dirname(__DIR__), [__DIR__ . '/Fixtures/mixed']),
        new ArchitectureQueryService($pdo),
        new DatabaseMaintenanceService($pdo, ':memory:'),
    );
    $server = new StdioServer($tools, maxResponseBytes: 4096);
    $templates = [
        [],
        ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 17],
        ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'initialize', 'params' => []],
        ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping', 'params' => [1, 2]],
        ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => ['nested']]],
        ['jsonrpc' => '2.0', 'id' => 5, 'method' => str_repeat('x', 1000)],
    ];
    for ($case = 0; $case < 700; ++$case) {
        $message = $templates[$case % count($templates)];
        if (isset($message['id'])) {
            $message['id'] = $case;
        }
        $response = $server->handle($message);
        if ($response !== null) {
            assertSame('2.0', $response['jsonrpc']);
            assertSame(true, strlen(json_encode($response, JSON_THROW_ON_ERROR)) < 4096);
            assertSame(true, isset($response['error']) || isset($response['result']));
        }
    }
};
$testGroups['seeded JSON-RPC message shapes return bounded protocol responses'] = 'property';

$tests['Streamable HTTP endpoint enforces sessions origin auth and protocol caps'] = static function (): void {
    $pdo = SqliteConnection::open(':memory:');
    (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    $root = __DIR__ . '/Fixtures/mixed';
    $tools = new ToolService(
        new ProjectScanService($pdo, dirname(__DIR__), [$root]),
        new ArchitectureQueryService($pdo),
        new DatabaseMaintenanceService($pdo, ':memory:'),
    );
    $store = new HttpSessionStore($pdo, ttlSeconds: 60, maxSessions: 4);
    $endpoint = new HttpEndpoint(
        $tools,
        $store,
        ['127.0.0.1:8080'],
        ['http://127.0.0.1:8080'],
        'secret',
        maxRequestBytes: 1024,
        maxResponseBytes: 1_000_000,
    );
    $headers = [
        'Host' => '127.0.0.1:8080', 'Origin' => 'http://127.0.0.1:8080',
        'Authorization' => 'Bearer secret', 'Content-Type' => 'application/json',
        'Accept' => 'application/json, text/event-stream', 'MCP-Protocol-Version' => '2025-11-25',
    ];
    $initialize = json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
        'protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1'],
    ]], JSON_THROW_ON_ERROR);
    assertSame(405, $endpoint->handle('GET', $headers, '')['status']);
    $badHost = $headers;
    $badHost['Host'] = 'evil.test';
    assertSame(421, $endpoint->handle('POST', $badHost, $initialize)['status']);
    $badOrigin = $headers;
    $badOrigin['Origin'] = 'https://evil.test';
    assertSame(403, $endpoint->handle('POST', $badOrigin, $initialize)['status']);
    $badAuth = $headers;
    $badAuth['Authorization'] = 'Bearer wrong';
    assertSame(401, $endpoint->handle('POST', $badAuth, $initialize)['status']);
    $badAccept = $headers;
    $badAccept['Accept'] = 'application/json';
    assertSame(406, $endpoint->handle('POST', $badAccept, $initialize)['status']);
    $badProtocol = $headers;
    $badProtocol['MCP-Protocol-Version'] = '2025-06-18';
    assertSame(400, $endpoint->handle('POST', $badProtocol, $initialize)['status']);
    assertSame(413, $endpoint->handle('POST', $headers, str_repeat('x', 1025))['status']);

    $initialized = $endpoint->handle('POST', $headers, $initialize);
    assertSame(200, $initialized['status']);
    $session = $initialized['headers']['Mcp-Session-Id'];
    assertSame(64, strlen($session));
    assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM http_sessions WHERE id = '" . $session . "'")->fetchColumn());
    $sessionHeaders = $headers + ['Mcp-Session-Id' => $session];
    $sessionHeaders['Mcp-Session-Id'] = $session;
    assertSame(400, $endpoint->handle('POST', $sessionHeaders, $initialize)['status']);
    $list = json_encode(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []], JSON_THROW_ON_ERROR);
    assertSame(409, $endpoint->handle('POST', $sessionHeaders, $list)['status']);
    $notification = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], JSON_THROW_ON_ERROR);
    assertSame(202, $endpoint->handle('POST', $sessionHeaders, $notification)['status']);
    $duplicateInitialization = $endpoint->handle('POST', $sessionHeaders, $notification);
    assertSame(409, $duplicateInitialization['status']);
    assertContains('already been initialized', $duplicateInitialization['body']);
    $listed = $endpoint->handle('POST', $sessionHeaders, $list);
    assertSame(200, $listed['status']);
    $listPayload = json_decode($listed['body'], true, 512, JSON_THROW_ON_ERROR);
    assertSame(25, count($listPayload['result']['tools']));
    $cancel = json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 99]], JSON_THROW_ON_ERROR);
    assertSame(202, $endpoint->handle('POST', $sessionHeaders, $cancel)['status']);

    $small = new HttpEndpoint($tools, $store, ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret', maxResponseBytes: 100);
    $oversized = $small->handle('POST', $sessionHeaders, $list);
    assertSame(500, $oversized['status']);
    assertContains('Response exceeds', $oversized['body']);
    $capacity = new HttpEndpoint($tools, new HttpSessionStore($pdo, maxSessions: 1), ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret');
    assertSame(503, $capacity->handle('POST', $headers, $initialize)['status']);
    assertSame(204, $endpoint->handle('DELETE', $sessionHeaders, '')['status']);
    assertSame(404, $endpoint->handle('POST', $sessionHeaders, $list)['status']);

    $brokenPdo = SqliteConnection::open(':memory:');
    (new MigrationRunner($brokenPdo, dirname(__DIR__) . '/migrations'))->migrate();
    $brokenPdo->exec('DROP TABLE http_sessions');
    $brokenEndpoint = new HttpEndpoint($tools, new HttpSessionStore($brokenPdo), ['127.0.0.1:8080'], ['http://127.0.0.1:8080'], 'secret');
    foreach ([
        $brokenEndpoint->handle('POST', $headers, $initialize),
        $brokenEndpoint->handle('POST', $sessionHeaders, $list),
        $brokenEndpoint->handle('DELETE', $sessionHeaders, ''),
    ] as $storageFailure) {
        assertSame(503, $storageFailure['status']);
        assertContains('temporarily unavailable', $storageFailure['body']);
        assertSame(false, str_contains($storageFailure['body'], 'no such table'));
    }
};
$testGroups['Streamable HTTP endpoint enforces sessions origin auth and protocol caps'] = 'http';

$tests['HTTP session capacity and initialization transitions are atomic across connections'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-http-session-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate HTTP session database.');
    }
    try {
        $firstPdo = SqliteConnection::open($path);
        (new MigrationRunner($firstPdo, dirname(__DIR__) . '/migrations'))->migrate();
        $secondPdo = SqliteConnection::open($path);
        $first = new HttpSessionStore($firstPdo, ttlSeconds: 60, maxSessions: 1);
        $second = new HttpSessionStore($secondPdo, ttlSeconds: 60, maxSessions: 1);

        $session = $first->create();
        $capacityError = captureThrows(static fn() => $second->create(), RuntimeException::class);
        assertSame(HttpSessionStore::CAPACITY_ERROR, $capacityError->getCode());
        assertSame(1, (int) $firstPdo->query('SELECT COUNT(*) FROM http_sessions')->fetchColumn());

        assertSame(HttpSessionStore::INITIALIZED, $first->markInitialized($session));
        assertSame(HttpSessionStore::ALREADY_INITIALIZED, $second->markInitialized($session));
        assertSame(1, (int) $firstPdo->query('SELECT initialized FROM http_sessions')->fetchColumn());
        $first->delete($session);
        assertSame(HttpSessionStore::UNKNOWN_OR_EXPIRED, $second->markInitialized($session));

        $expired = $first->create();
        $firstPdo->exec('UPDATE http_sessions SET expires_at = 0');
        assertSame(HttpSessionStore::UNKNOWN_OR_EXPIRED, $second->markInitialized($expired));
        $replacement = $second->create();
        assertSame(true, $first->exists($replacement));
        assertSame(1, (int) $firstPdo->query('SELECT COUNT(*) FROM http_sessions')->fetchColumn());

        $first->delete($replacement);
        $firstPdo->beginTransaction();
        $rolledBack = $first->create();
        assertSame(true, $firstPdo->inTransaction());
        assertSame(true, $first->exists($rolledBack));
        $firstPdo->rollBack();
        assertSame(false, $second->exists($rolledBack));
    } finally {
        unset($second, $first, $secondPdo, $firstPdo);
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['HTTP session capacity and initialization transitions are atomic across connections'] = 'http';

$tests['MCP stdio lifecycle lists tools and contains validation errors'] = static function (): void {
    $path = tempnam(sys_get_temp_dir(), 'knossos-mcp-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate MCP database.');
    }
    $root = __DIR__ . '/Fixtures/mixed';
    $process = null;
    try {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/knossos', 'serve', '--allow-root=' . $root, '--db=' . $path],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start MCP server.');
        }
        $messages = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => [], 'clientInfo' => ['name' => 'test', 'version' => '1']]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'scan_project', 'arguments' => ['path' => '/etc']]],
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'architecture_summary', 'arguments' => ['project_id' => 'missing', 'limit' => 0]]],
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => ['name' => 'explain_flow', 'arguments' => ['project_id' => 'missing', 'from' => 'A', 'to' => 'B', 'max_depth' => 9]]],
            ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => ['name' => 'impact_analysis', 'arguments' => ['project_id' => 'missing', 'symbol' => 'A', 'limit' => 0]]],
            ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => ['name' => 'scan_project', 'arguments' => ['path' => $root, 'boundaries' => [['name' => 'Bad', 'path_prefix' => 'src', 'namespace_prefix' => 'App\\']]]]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 8, 'reason' => 'integration test']],
            ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => ['name' => 'scan_project', 'arguments' => ['path' => $root]]],
        ];
        foreach ($messages as $message) {
            fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR) . "\n");
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        assertSame(0, proc_close($process));
        $process = null;
        assertSame('', $stderr);

        $lines = array_values(array_filter(explode("\n", trim($stdout))));
        assertSame(8, count($lines));
        $responses = array_map(static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
        assertSame('2025-11-25', $responses[0]['result']['protocolVersion']);
        assertSame(25, count($responses[1]['result']['tools']));
        assertSame(true, $responses[2]['result']['isError']);
        assertContains('allowed root', $responses[2]['result']['content'][0]['text']);
        assertSame('KNOSSOS_UNSAFE_PATH', $responses[2]['result']['structuredContent']['error']['code']);
        assertSame(true, $responses[3]['result']['isError']);
        assertContains('between 1 and 100', $responses[3]['result']['content'][0]['text']);
        assertSame(true, $responses[4]['result']['isError']);
        assertContains('between 1 and 8', $responses[4]['result']['content'][0]['text']);
        assertSame(true, $responses[5]['result']['isError']);
        assertContains('between 1 and 100', $responses[5]['result']['content'][0]['text']);
        assertSame(true, $responses[6]['result']['isError']);
        assertContains('exactly one matcher', $responses[6]['result']['content'][0]['text']);
        assertSame(true, $responses[7]['result']['isError']);
        assertContains('cancelled', strtolower($responses[7]['result']['content'][0]['text']));
    } finally {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
        foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
};
$testGroups['MCP stdio lifecycle lists tools and contains validation errors'] = 'query';

$failed = 0;
$executed = 0;
$selectedGroup = null;
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--group=')) {
        $selectedGroup = substr($argument, strlen('--group='));
    }
}

foreach ($tests as $name => $test) {
    $group = $testGroups[$name] ?? 'protocol';
    if ($selectedGroup !== null && $selectedGroup !== $group) {
        continue;
    }

    ++$executed;
    try {
        $test();
        fwrite(STDOUT, sprintf("PASS %s\n", $name));
    } catch (Throwable $error) {
        ++$failed;
        fwrite(STDERR, sprintf("FAIL %s: %s\n", $name, $error->getMessage()));
    }
}

fwrite(STDOUT, sprintf("\n%d tests, %d failures\n", $executed, $failed));
exit($failed === 0 ? 0 : 1);

function assertSame(mixed $expected, mixed $actual): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            'Expected %s, got %s.',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function canonicalJsonValue(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }
    if (array_is_list($value)) {
        return array_map(canonicalJsonValue(...), $value);
    }
    ksort($value, SORT_STRING);
    foreach ($value as &$item) {
        $item = canonicalJsonValue($item);
    }
    return $value;
}

function assertNotSame(mixed $unexpected, mixed $actual): void
{
    if ($unexpected === $actual) {
        throw new RuntimeException(sprintf('Did not expect %s.', var_export($actual, true)));
    }
}

function assertContains(string $needle, string $haystack): void
{
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException(sprintf('Expected "%s" to contain "%s".', $haystack, $needle));
    }
}

function assertArrayContains(mixed $needle, array $haystack): void
{
    if (!in_array($needle, $haystack, true)) {
        throw new RuntimeException(sprintf('Expected array to contain %s.', var_export($needle, true)));
    }
}

/** @param class-string<Throwable> $expected */
function assertThrows(callable $callback, string $expected): void
{
    captureThrows($callback, $expected);
}

/** @param class-string<Throwable> $expected */
function captureThrows(callable $callback, string $expected): Throwable
{
    try {
        $callback();
    } catch (Throwable $error) {
        if ($error instanceof $expected) {
            return $error;
        }

        throw new RuntimeException(sprintf('Expected %s, got %s.', $expected, $error::class));
    }

    throw new RuntimeException(sprintf('Expected %s to be thrown.', $expected));
}

function fakeWorkerClient(string $mode, ?WorkerLimits $limits = null): ProcessScannerClient
{
    return new ProcessScannerClient(
        [PHP_BINARY, __DIR__ . '/Fixtures/workers/fake-worker.php', $mode],
        $limits ?? new WorkerLimits(),
    );
}

function phpWorkerClient(): ProcessScannerClient
{
    $coverageDirectory = getenv('KNOSSOS_PHP_COVERAGE_DIR');
    $coverageArguments = is_string($coverageDirectory) && $coverageDirectory !== ''
        ? [
            '-d', 'pcov.directory=' . dirname(__DIR__),
            '-d', 'auto_prepend_file=' . dirname(__DIR__) . '/tools/pcov-prepend.php',
        ]
        : [];
    return new ProcessScannerClient(
        [PHP_BINARY, ...$coverageArguments, dirname(__DIR__) . '/workers/php/bin/worker'],
        new WorkerLimits(requestTimeoutMs: 10_000),
    );
}

function typescriptWorkerClient(): ProcessScannerClient
{
    $coverageDirectory = getenv('KNOSSOS_JS_COVERAGE_DIR');
    $command = is_string($coverageDirectory) && $coverageDirectory !== ''
        ? [
            'env',
            'NODE_V8_COVERAGE=' . $coverageDirectory,
            'node',
            dirname(__DIR__) . '/workers/typescript/bin/worker.js',
        ]
        : ['node', dirname(__DIR__) . '/workers/typescript/bin/worker.js'];
    return new ProcessScannerClient(
        $command,
        new WorkerLimits(requestTimeoutMs: 20_000, maxLineBytes: 2_000_000, maxOutputBytes: 30_000_000),
    );
}

function pythonWorkerClient(): ProcessScannerClient
{
    return new ProcessScannerClient(
        pythonWorkerCommand(),
        new WorkerLimits(requestTimeoutMs: 10_000, maxLineBytes: 2_000_000, maxOutputBytes: 30_000_000),
    );
}

/** @return non-empty-list<string> */
function pythonWorkerCommand(): array
{
    $coverageDirectory = getenv('KNOSSOS_PYTHON_COVERAGE_DIR');
    return is_string($coverageDirectory) && $coverageDirectory !== ''
        ? [
            'coverage',
            'run',
            '--branch',
            '--parallel-mode',
            '--data-file=' . $coverageDirectory . '/.coverage',
            '--source=' . dirname(__DIR__) . '/workers/python/bin',
            dirname(__DIR__) . '/workers/python/bin/worker.py',
        ]
        : ['python3', '-I', '-B', dirname(__DIR__) . '/workers/python/bin/worker.py'];
}

/** @param list<string> $messages @return list<array<string, mixed>> */
function runPythonWorkerProtocol(array $messages): array
{
    $pipes = [];
    $process = proc_open(pythonWorkerCommand(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Python worker protocol fixture.');
    }
    foreach ($messages as $message) {
        fwrite($pipes[0], $message . "\n");
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    assertSame(0, $exit);
    assertSame('', $stderr);

    return array_map(
        static fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
        array_values(array_filter(explode("\n", trim($stdout)))),
    );
}

/** @return list<string> */
function typescriptFixtureFiles(): array
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
function pythonFixtureFiles(): array
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
function reconciliationFixture(): array
{
    $root = __DIR__ . '/Fixtures/mixed';
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
    (new MigrationRunner($pdo, dirname(__DIR__) . '/migrations'))->migrate();
    $reconciler = new GraphReconciler(new SqliteGraphRepository($pdo));
    $request = new FullScanRequest('mixed-fixture', 'Mixed Fixture', $discovery, $scanners, [$php, $typescript]);

    return [$pdo, $reconciler, $request];
}

/**
 * @return array{0: PDO, 1: SqliteGraphRepository, 2: array<string, string>}
 */
function storeFixture(?string $migrationDirectory = null): array
{
    $pdo = SqliteConnection::open(':memory:');
    (new MigrationRunner($pdo, $migrationDirectory ?? dirname(__DIR__) . '/migrations'))->migrate();
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

function graphSignature(PDO $pdo): string
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

function removeFixtureTree(string $root): void
{
    $prefix = rtrim(sys_get_temp_dir(), '/') . '/knossos-incremental-';
    if (!str_starts_with($root, $prefix)) {
        throw new RuntimeException('Refusing to remove an unexpected fixture path.');
    }
    foreach (glob($root . '/src/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    foreach (glob($root . '/*') ?: [] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($root . '/src')) {
        rmdir($root . '/src');
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

/** @param non-empty-list<string> $command */
function runFixtureCommand(array $command): void
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start fixture command.');
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0) {
        throw new RuntimeException('Fixture command failed: ' . trim((string) $stdout . ' ' . (string) $stderr));
    }
}

/** @param non-empty-list<string> $command @return array{0: int, 1: string, 2: string} */
function runFixtureCommandOutput(array $command): array
{
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start fixture command.');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $stdout, $stderr];
}

function removeGitFixture(string $root): void
{
    $prefix = rtrim(sys_get_temp_dir(), '/') . '/knossos-git-';
    if (!str_starts_with($root, $prefix) || !is_dir($root)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);
}
