<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryDiagnostic;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\ProjectUnit;
use Knossos\Reconciliation\ReconciliationResult;
use Knossos\Scan\ScanAnalysis;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the six nano-DTO / value-object declarations in src/
 * covered by this batch:
 *
 *   src/Discovery/DiscoveryDiagnostic.php       (15 LoC, final readonly)
 *   src/Discovery/ProjectUnit.php               (16 LoC, final readonly)
 *   src/Scan/ScanPlan.php                       (17 LoC, final readonly)
 *   src/Discovery/DiscoveredFile.php            (18 LoC, final readonly)
 *   src/Reconciliation/ReconciliationResult.php (18 LoC, final readonly)
 *   src/Scan/ScanAnalysis.php                   (11 LoC, final readonly)
 *
 * Each source file is a `final readonly class` with constructor-promoted
 * public properties. Infection 0.31.9 cannot produce mutations against
 * these bodies because the only method is the constructor — mutation
 * operations like RemoveMethod/EraseMethod target methods and the
 * constructor is implicit (variant: ParamRemoveMutator) but those are
 * structurally anchorless. PHPUnit ground truth at 100% line + property
 * coverage is the binding surface for these files; engine MSI is at or
 * near zero.
 *
 * Conventions match tests/phpunit/ExceptionsTest.php (batch 5): bare
 * global assertSame from tests/phpunit/Support/Assertions.php,
 * `#[Group('dtos')]` at class level. NO `#[CoversClass]` (same reason
 * as batch 5 — PHPUnit's strict-attribute parser rejected it and
 * per-method assertions suffice for `failOnRisky=\"true\"`).
 */
#[Group('dtos')]
final class NanoDtosTest extends KnossosTestCase
{
    public function testDiscoveryDiagnosticHoldsPromotedProperties(): void
    {
        $d = new DiscoveryDiagnostic('warning', 'CODE_MUMBLE', 'something is off', 'src/x.php');
        assertSame('warning', $d->severity);
        assertSame('CODE_MUMBLE', $d->code);
        assertSame('something is off', $d->message);
        assertSame('src/x.php', $d->relativePath);

        // Default for relativePath is null.
        $d2 = new DiscoveryDiagnostic('error', 'CODE2', 'm2');
        assertSame('error', $d2->severity);
        assertSame(null, $d2->relativePath);
    }

    public function testProjectUnitHoldsPromotedProperties(): void
    {
        $unit = new ProjectUnit('composer', 'composer.json', str_repeat('a', 64), ['k' => 'v']);
        assertSame('composer', $unit->kind);
        assertSame('composer.json', $unit->configPath);
        assertSame(str_repeat('a', 64), $unit->contentHash);
        assertSame(['k' => 'v'], $unit->metadata);

        // Default for metadata is [].
        $unit2 = new ProjectUnit('node', 'package.json', str_repeat('b', 64));
        assertSame('node', $unit2->kind);
        assertSame([], $unit2->metadata);
    }

    public function testScanPlanHoldsPromotedPropertiesAndFixtureChain(): void
    {
        $configuration = new ProjectConfiguration();
        $discovery = new DiscoveryResult(
            rootRealpath: '/repo',
            files: [],
            units: [],
            diagnostics: [],
            inputHash: str_repeat('a', 64),
            configurationHash: str_repeat('b', 64),
        );
        $preparation = new ScanPreparation(
            configuration: $configuration,
            discovery: $discovery,
            maxFiles: 1_000,
            maxFileBytes: 1_024,
            explicitBoundaries: [],
            requestedMode: 'full',
            snapshotRetention: 5,
            executionPolicy: new WorkerExecutionPolicy(),
            laravel: false,
            symfony: false,
            configurationHashes: [],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
        $cache = ['knossos.php' => ['cache-key-1']];
        $plan = new ScanPlan(
            preparation: $preparation,
            projectId: 'project-id',
            effectiveMode: 'full',
            cacheByScannerPath: $cache,
            deletedFiles: 0,
        );
        assertSame('project-id', $plan->projectId);
        assertSame('full', $plan->effectiveMode);
        assertSame($cache, $plan->cacheByScannerPath);
        assertSame(0, $plan->deletedFiles);
        assertSame($preparation, $plan->preparation);

        // Boundary: deletedFiles accepts negative (int promotion is permissive).
        $plan2 = new ScanPlan($preparation, 'p', 'incremental', [], -1);
        assertSame(-1, $plan2->deletedFiles);
    }

    public function testDiscoveredFileHoldsPromotedProperties(): void
    {
        $file = new DiscoveredFile(
            relativePath: 'src/x.php',
            absolutePath: '/repo/src/x.php',
            language: 'php',
            size: 1024,
            mtime: 1_700_000_000,
            contentHash: str_repeat('c', 64),
            lineCount: 42,
        );
        assertSame('src/x.php', $file->relativePath);
        assertSame('/repo/src/x.php', $file->absolutePath);
        assertSame('php', $file->language);
        assertSame(1024, $file->size);
        assertSame(1_700_000_000, $file->mtime);
        assertSame(str_repeat('c', 64), $file->contentHash);
        assertSame(42, $file->lineCount);

        // Default for lineCount is 0.
        $file2 = new DiscoveredFile('src/y.php', '/repo/src/y.php', 'php', 0, 0, str_repeat('d', 64));
        assertSame(0, $file2->lineCount);
    }

    public function testReconciliationResultHoldsPromotedProperties(): void
    {
        $r = new ReconciliationResult(
            projectId: 'p',
            scanId: 's',
            files: 10,
            nodes: 20,
            edges: 30,
            diagnostics: 4,
            unresolvedNodes: 5,
        );
        assertSame('p', $r->projectId);
        assertSame('s', $r->scanId);
        assertSame(10, $r->files);
        assertSame(20, $r->nodes);
        assertSame(30, $r->edges);
        assertSame(4, $r->diagnostics);
        assertSame(5, $r->unresolvedNodes);
    }

    public function testScanAnalysisHoldsPromotedProperties(): void
    {
        // Classifications and boundaries are list<object> per the source.
        $classifications = [(object) ['rule' => 'r1'], (object) ['rule' => 'r2']];
        $boundaries = [(object) ['name' => 'n1']];
        $analysis = new ScanAnalysis($classifications, $boundaries);
        assertSame($classifications, $analysis->classifications);
        assertSame($boundaries, $analysis->boundaries);

        // Default-empty arrays.
        $analysis2 = new ScanAnalysis([], []);
        assertSame([], $analysis2->classifications);
        assertSame([], $analysis2->boundaries);
    }
}
