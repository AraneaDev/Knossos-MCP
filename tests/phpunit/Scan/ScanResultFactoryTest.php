<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryDiagnostic;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Query\ResultEnvelope;
use Knossos\Reconciliation\ReconciliationResult;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scan\ScanResultFactory;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-factory')]
final class ScanResultFactoryTest extends TestCase
{
    private function makePreparation(array $diagnostics = [], int $files = 0): ScanPreparation
    {
        return new ScanPreparation(
            configuration: new ProjectConfiguration(),
            discovery: new DiscoveryResult(
                rootRealpath: '/tmp/foo',
                files: array_fill(0, $files, 'placeholder'),
                units: [],
                diagnostics: $diagnostics,
                inputHash: '',
                configurationHash: '',
            ),
            maxFiles: 0,
            maxFileBytes: 0,
            explicitBoundaries: [],
            requestedMode: 'fast',
            snapshotRetention: 0,
            executionPolicy: new WorkerExecutionPolicy(),
            laravel: false,
            symfony: false,
            configurationHashes: [],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
    }

    private function makeLanguageResult(int $parsed, int $unchanged, int $added, int $changed, array $metadata): LanguageScanResult
    {
        return new LanguageScanResult(
            manifests: [],
            contributions: [],
            cacheEntries: [],
            parsed: $parsed,
            unchanged: $unchanged,
            added: $added,
            changed: $changed,
            scannerMetadata: $metadata,
            stageMilliseconds: [],
        );
    }

    public function testCreateFormatsSummaryAndPassesThroughFlatFields(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 7,
        );
        $language = $this->makeLanguageResult(12, 8, 3, 1, ['kind' => 'php']);
        $result = new ReconciliationResult(
            projectId: 'rec-proj',
            scanId: 'scan-abc',
            files: 5,
            nodes: 10,
            edges: 15,
            diagnostics: 2,
            unresolvedNodes: 0,
        );

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, ['discovery' => 1.5]);

        assertSame('Scanned 5 files into 10 nodes and 15 relationships.', $envelope->summary);
        assertSame('rec-proj', $envelope->projectId);
        assertSame('scan-abc', $envelope->snapshotId);
        assertSame(5, $envelope->data['files']);
        assertSame(10, $envelope->data['nodes']);
        assertSame(15, $envelope->data['edges']);
        assertSame(2, $envelope->data['diagnostics']);
        assertSame(0, $envelope->data['unresolved_nodes']);
        assertSame('fast', $envelope->data['mode']);
        assertSame(7, $envelope->data['deleted_files']);
        assertSame(12, $envelope->data['parsed_files']);
        assertSame(8, $envelope->data['unchanged_files']);
        assertSame(3, $envelope->data['added_files']);
        assertSame(1, $envelope->data['changed_files']);
        assertSame(['kind' => 'php'], $envelope->data['scanner_metadata']);
    }

    public function testCreateBuildsWarningsFromDiagnostics(): void
    {
        $factory = new ScanResultFactory();
        $diagnostics = [
            new DiscoveryDiagnostic(severity: 'warning', code: 'PARSE_ERR', message: 'Syntax error on line 5'),
            new DiscoveryDiagnostic(severity: 'info', code: 'SLOW_FILE', message: 'Large file detected'),
        ];
        $plan = new ScanPlan(
            preparation: $this->makePreparation(diagnostics: $diagnostics),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(0, 0, 0, 0, []);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 0, 0, 0, 0, 0);

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, []);

        assertSame(['PARSE_ERR: Syntax error on line 5', 'SLOW_FILE: Large file detected'], $envelope->warnings);
    }

    public function testCreateHandlesEmptyDiagnosticsSafely(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(diagnostics: []),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(0, 0, 0, 0, []);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 0, 0, 0, 0, 0);

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, []);

        assertSame([], $envelope->warnings);
    }

    public function testCreateAggregatesConfigurationAndMetrics(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(files: 4),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(2, 1, 1, 0, []);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 0, 0, 0, 0, 0);
        $stageMilliseconds = ['discovery' => 1.5, 'parsing' => 4.25];

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, $stageMilliseconds);

        assertSame(4, $envelope->data['metrics']['discovered_files']);
        assertSame(2, $envelope->data['metrics']['parsed_files']);
        assertSame(1, $envelope->data['metrics']['replayed_files']);
        assertSame($stageMilliseconds, $envelope->data['metrics']['stages_ms']);
        assertSame('explicit override > project configuration > built-in default', $envelope->data['configuration']['precedence']);
        assertSame(0, $envelope->data['configuration']['policies']);
        assertSame([], $envelope->data['configuration']['framework_hints']);
        assertSame([], $envelope->data['configuration']['quality_budgets']);
        assertSame(30000, $envelope->data['worker_execution']['request_timeout_ms']);
        assertSame(120000, $envelope->data['worker_execution']['maximum_request_timeout_ms']);
    }

    public function testCreateExposesElapsedMsAndPeakMemoryInMetrics(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(0, 0, 0, 0, []);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 0, 0, 0, 0, 0);
        $startedAt = hrtime(true);

        $envelope = $factory->create($plan, $language, $result, $startedAt, []);

        $elapsed = $envelope->data['metrics']['elapsed_ms'];
        assertSame(true, is_numeric($elapsed));
        assertSame(true, $elapsed >= 0.0);
        assertSame(true, $envelope->data['metrics']['peak_memory_bytes'] > 0);
    }

    public function testCreateSummaryFormatUsesCorrectArgumentsInOrder(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(0, 0, 0, 0, []);
        // Asymmetric values to ensure sprintf arg-order is tested
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 3, 7, 11, 0, 0);

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, []);

        assertSame('Scanned 3 files into 7 nodes and 11 relationships.', $envelope->summary);
    }

    public function testCreateDataArrayHasExactShapeAndAllValues(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(files: 4),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 9,
        );
        $language = $this->makeLanguageResult(12, 8, 3, 1, ['kind' => 'php', 'version' => '8.3']);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 5, 10, 15, 2, 0);
        $stageMilliseconds = ['discovery' => 1.5, 'parsing' => 4.25, 'reconciliation' => 2.0];

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, $stageMilliseconds);

        assertSame(5, $envelope->data['files']);
        assertSame(10, $envelope->data['nodes']);
        assertSame(15, $envelope->data['edges']);
        assertSame(2, $envelope->data['diagnostics']);
        assertSame(0, $envelope->data['unresolved_nodes']);
        assertSame('fast', $envelope->data['mode']);
        assertSame(12, $envelope->data['parsed_files']);
        assertSame(8, $envelope->data['unchanged_files']);
        assertSame(3, $envelope->data['added_files']);
        assertSame(1, $envelope->data['changed_files']);
        assertSame(9, $envelope->data['deleted_files']);
        assertSame(['kind' => 'php', 'version' => '8.3'], $envelope->data['scanner_metadata']);
        assertSame($stageMilliseconds, $envelope->data['metrics']['stages_ms']);
        assertSame(4, $envelope->data['metrics']['discovered_files']);
    }

    public function testCreateMetricsCalculationsAreAccurate(): void
    {
        $factory = new ScanResultFactory();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(),
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(0, 0, 0, 0, []);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 0, 0, 0, 0, 0);
        // 2 seconds elapsed: 2_000_000_000 nanoseconds / 1_000_000 = 2000.0 milliseconds
        $startedAt = hrtime(true) - 2_000_000_000;

        $envelope = $factory->create($plan, $language, $result, $startedAt, []);

        $elapsed = $envelope->data['metrics']['elapsed_ms'];
        assertSame(true, is_float($elapsed));
        assertSame(true, $elapsed >= 1990.0 && $elapsed <= 2100.0);
    }

    public function testCreateConfigurationSourceUsesPlanConfigurationPath(): void
    {
        $factory = new ScanResultFactory();
        $config = new ProjectConfiguration(path: '/custom/config/path.json');
        $preparation = new ScanPreparation(
            configuration: $config,
            discovery: new DiscoveryResult(
                rootRealpath: '/tmp/foo',
                files: [],
                units: [],
                diagnostics: [],
                inputHash: '',
                configurationHash: '',
            ),
            maxFiles: 0,
            maxFileBytes: 0,
            explicitBoundaries: [],
            requestedMode: 'fast',
            snapshotRetention: 0,
            executionPolicy: new WorkerExecutionPolicy(),
            laravel: false,
            symfony: false,
            configurationHashes: [],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
        $plan = new ScanPlan(
            preparation: $preparation,
            projectId: 'plan-proj',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );
        $language = $this->makeLanguageResult(0, 0, 0, 0, []);
        $result = new ReconciliationResult('rec-proj', 'scan-abc', 0, 0, 0, 0, 0);

        $envelope = $factory->create($plan, $language, $result, 1_000_000_000, []);

        assertSame('/custom/config/path.json', $envelope->data['configuration']['source']);
    }
}
