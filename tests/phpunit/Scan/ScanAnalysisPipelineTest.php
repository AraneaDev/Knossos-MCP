<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scan\ScanAnalysis;
use Knossos\Scan\ScanAnalysisPipeline;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-analysis')]
final class ScanAnalysisPipelineTest extends TestCase
{
    private function makePreparation(bool $laravel = false, bool $symfony = false): ScanPreparation
    {
        return new ScanPreparation(
            configuration: new ProjectConfiguration(),
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
            laravel: $laravel,
            symfony: $symfony,
            configurationHashes: ['php' => '', 'typescript' => '', 'python' => ''],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
    }

    public function testAnalyzeReturnsScanAnalysisWithEmptyInputs(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(),
            projectId: 'plan-empty',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        $analysis = $pipeline->analyze($plan, []);

        assertSame(true, $analysis instanceof ScanAnalysis);
        assertSame([], $analysis->classifications);
        assertSame([], $analysis->boundaries);
    }

    public function testAnalyzeWithLaravelEnabledDoesNotThrow(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(laravel: true),
            projectId: 'plan-laravel',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        $analysis = $pipeline->analyze($plan, []);

        assertSame(true, $analysis instanceof ScanAnalysis);
        assertSame(true, is_array($analysis->classifications));
        assertSame(true, is_array($analysis->boundaries));
    }

    public function testAnalyzeWithSymfonyEnabledDoesNotThrow(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(symfony: true),
            projectId: 'plan-symfony',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        $analysis = $pipeline->analyze($plan, []);

        assertSame(true, $analysis instanceof ScanAnalysis);
        assertSame(true, is_array($analysis->classifications));
        assertSame(true, is_array($analysis->boundaries));
    }

    public function testAnalyzeWithBothLaravelAndSymfonyEnabledDoesNotThrow(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(laravel: true, symfony: true),
            projectId: 'plan-both',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        $analysis = $pipeline->analyze($plan, []);

        assertSame(true, $analysis instanceof ScanAnalysis);
        assertSame(true, is_array($analysis->classifications));
        assertSame(true, is_array($analysis->boundaries));
    }

    public function testAnalyzeWithContributionsReturnsScanAnalysis(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(),
            projectId: 'plan-with-contribs',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        // Pass a non-empty list of contributions with no nodes/edges/diagnostics
        $analysis = $pipeline->analyze($plan, [new ScanContribution('test'), new ScanContribution('test2')]);

        assertSame(true, $analysis instanceof ScanAnalysis);
        assertSame(true, is_array($analysis->classifications));
        assertSame(true, is_array($analysis->boundaries));
    }
}
