<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Classification\ClassificationFact;
use Knossos\Classification\ManifestEntryPointRule;
use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scan\ScanAnalysis;
use Knossos\Scan\ScanAnalysisPipeline;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scan-analysis')]
final class ScanAnalysisPipelineTest extends TestCase
{
    /** @param list<ProjectUnit> $units */
    private function makePreparation(bool $laravel = false, bool $symfony = false, array $units = []): ScanPreparation
    {
        return new ScanPreparation(
            configuration: new ProjectConfiguration(),
            discovery: new DiscoveryResult(
                rootRealpath: '/tmp/foo',
                files: [],
                units: $units,
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

    /**
     * Discovery is the only stage that reads a manifest, so the entry points it
     * recorded have to travel through the plan into the classification rule --
     * a scanner never sees them. A `npm run build` script carries an in-degree
     * of zero however central it is, and this is the path that stops it from
     * reading as dead code.
     */
    public function testAnalyzeTagsFilesTheDiscoveredManifestsNameAsEntryPoints(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(units: [
                new ProjectUnit('npm', 'package.json', 'hash-npm', ['entry_points' => ['scripts/build.mjs', 'bin/tool.js']]),
                // The same path from a second manifest must not double-tag, and a
                // manifest that names nothing must not disturb the collected set.
                new ProjectUnit('npm', 'packages/web/package.json', 'hash-web', ['entry_points' => ['scripts/build.mjs']]),
                new ProjectUnit('composer', 'composer.json', 'hash-composer', []),
            ]),
            projectId: 'plan-entry-points',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        $analysis = $pipeline->analyze($plan, [new ScanContribution('js', [
            $this->makeModuleNode('scripts/build.mjs'),
            $this->makeModuleNode('bin/tool.js'),
            $this->makeModuleNode('src/index.ts'),
        ])]);

        $tagged = [];
        foreach ($analysis->classifications as $fact) {
            if ($fact->role === ManifestEntryPointRule::ROLE) {
                $tagged[] = $fact->nodeReference;
            }
        }
        sort($tagged, SORT_STRING);

        assertSame(['file:bin/tool.js', 'file:scripts/build.mjs'], $tagged);
    }

    /** A unit whose manifest named nothing leaves every file untagged. */
    public function testAnalyzeTagsNoEntryPointWhenNoManifestNamesOne(): void
    {
        $pipeline = new ScanAnalysisPipeline();
        $plan = new ScanPlan(
            preparation: $this->makePreparation(units: [
                new ProjectUnit('composer', 'composer.json', 'hash-composer', ['entry_points' => []]),
            ]),
            projectId: 'plan-no-entry-points',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        $analysis = $pipeline->analyze($plan, [new ScanContribution('js', [$this->makeModuleNode('scripts/build.mjs')])]);

        $roles = array_map(static fn(ClassificationFact $fact): string => $fact->role, $analysis->classifications);
        assertSame(false, in_array(ManifestEntryPointRule::ROLE, $roles, true));
    }

    private function makeModuleNode(string $relativePath): NodeFact
    {
        return new NodeFact(
            'file:' . $relativePath,
            'module',
            $relativePath,
            basename($relativePath),
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            [],
        );
    }
}
