<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class QueryTest extends KnossosTestCase
{
    #[Group('query')]
    public function testSnapshotDiffReportsBoundedArchitecturalChangesAndRenameHeuristics(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
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
            'php',
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
        assertThrows(fn() => $queries->snapshotDiff($ids['project'], 'active'), InvalidArgumentException::class);
        assertThrows(fn() => $queries->snapshotDiff($ids['project'], 'missing'), InvalidArgumentException::class);
        assertThrows(fn() => $queries->snapshotDiff($ids['project'], $ids['scan'], maxChanges: 0), InvalidArgumentException::class);
        $pdo->exec("UPDATE scan_snapshots SET complete = 0 WHERE scan_id = '" . $ids['scan'] . "'");
        assertThrows(fn() => $queries->snapshotDiff($ids['project'], $ids['scan']), InvalidArgumentException::class);
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
        assertThrows(fn() => $queries->qualityGate($ids['project'], $ids['scan'], []), InvalidArgumentException::class);
        assertThrows(fn() => $queries->qualityGate($ids['project'], $ids['scan'], ['boundary_violations' => 0]), InvalidArgumentException::class);

        $trends = $queries->architectureTrends($ids['project'], 2, $ids['scan']);
        assertSame([$ids['scan'], $next], array_column($trends->data['series'], 'scan_id'));
        assertContains('## Architecture changes', $trends->data['release_notes']['markdown']);
        assertContains('Components: +1 / -1', $trends->data['release_notes']['markdown']);
        assertThrows(fn() => $queries->architectureTrends($ids['project'], 1), InvalidArgumentException::class);

        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            $queries,
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
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
    }

    #[Group('query')]
    public function testScanAndQueryServicesAnswerMixedLanguageArchitectureQuestions(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-query-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate query database.');
        }
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        try {
            $pdo = SqliteConnection::open($path);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Mixed Fixture');
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
    }

    #[Group('query')]
    public function testProjectCatalogueReportsBoundedFreshnessCountsAndPrivateRoots(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
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
        $repository->saveProject($second, 'Second Project', self::repositoryRoot() . '/tests/Fixtures/mixed');
        $page = $queries->listProjects(limit: 1);
        assertSame(true, $page->truncated);
        assertSame(1, $page->data['pagination']['next_offset']);
        assertSame(1, count($queries->listProjects(limit: 1, offset: 1)->data['projects']));
        assertThrows(fn() => $queries->listProjects(offset: -1), InvalidArgumentException::class);
        assertThrows(fn() => $queries->listProjects(limit: 101), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testComponentDossierCombinesIdentityContextRelationshipsAndAmbiguity(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
        $other = StableId::symbol($ids['project'], 'php', 'class', 'App\\OtherService');
        $repository->saveNode(
            $other,
            $ids['project'],
            'php',
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
        assertThrows(fn() => $queries->inspectComponent($ids['project'], 'Checkout', minConfidence: 'invalid'), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testArchitectureContextBundlesDeterministicBoundedTaskEvidence(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();
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
        assertThrows(fn() => $queries->architectureContext($ids['project']), InvalidArgumentException::class);
        assertThrows(fn() => $queries->architectureContext($ids['project'], 'task', maxChars: 3999), InvalidArgumentException::class);
    }

    #[Group('query')]
    public function testMcpStdioLifecycleListsToolsAndContainsValidationErrors(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-mcp-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate MCP database.');
        }
        $root = self::repositoryRoot() . '/tests/Fixtures/mixed';
        $process = null;
        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, self::repositoryRoot() . '/bin/knossos', 'serve', '--allow-root=' . $root, '--db=' . $path],
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
            $responses = array_map(fn(string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
            assertSame('2025-11-25', $responses[0]['result']['protocolVersion']);
            assertSame(28, count($responses[1]['result']['tools']));
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
    }
}
