<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use Knossos\Boundary\BoundaryFact;
use Knossos\Classification\ClassificationFact;
use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\DiscoveryDiagnostic;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Reconciliation\GraphReconciler;
use Knossos\Reconciliation\ReconciliationException;
use Knossos\Reconciliation\ReconciliationResult;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('graph-reconciler')]
final class GraphReconcilerTest extends TestCase
{
    private FakeGraphRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new FakeGraphRepository();
    }

    // ----- class shape -----

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(GraphReconciler::class);
        $this->assertTrue($reflection->isFinal());
        $this->assertTrue($reflection->isReadOnly());
    }

    public function testPublicReconcileSignature(): void
    {
        $reflection = new \ReflectionMethod(GraphReconciler::class, 'reconcile');
        $this->assertTrue($reflection->isPublic());
        $returnType = $reflection->getReturnType();
        $this->assertNotNull($returnType);
        assertSame(ReconciliationResult::class, $returnType->getName());
    }

    // ----- happy path -----

    public function testReconcileExecutesFullLifecycle(): void
    {
        $request = $this->buildRequest();
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        $this->assertInstanceOf(ReconciliationResult::class, $result);
        $this->assertCount(1, $this->repo->transactions);
        $this->assertCount(1, $this->repo->projects);
        $this->assertCount(1, $this->repo->scans);
        $this->assertCount(1, $this->repo->clearedGraphs);
        $this->assertCount(1, $this->repo->completedScans);
        assertSame([], $this->repo->files);
        assertSame([], $this->repo->nodes);
        assertSame([], $this->repo->edges);
        assertSame([], $this->repo->classifications);
        assertSame([], $this->repo->boundaries);
        assertSame([], $this->repo->boundaryMemberships);
        assertSame(0, $result->files);
        assertSame(0, $result->nodes);
        assertSame(0, $result->edges);
        assertSame(0, $result->diagnostics);
        assertSame(0, $result->unresolvedNodes);
    }

    public function testReconcileReturnsIdsMatchingStableIdFactory(): void
    {
        $request = $this->buildRequest([
            'projectIdentity' => 'proj-stable',
            'mode' => 'incremental',
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        $expectedProjectId = StableId::project('proj-stable');
        $expectedScanArgs = $this->repo->scans[0];

        assertSame($expectedProjectId, $result->projectId);
        assertSame($expectedProjectId, $expectedScanArgs[1]);
        assertSame('incremental', $expectedScanArgs[2]);
        $this->assertIsString($result->scanId);
        $this->assertStringStartsWith('scan_', $result->scanId);
        assertSame($result->scanId, $expectedScanArgs[0]);
    }

    public function testReconcilePassesProjectNameAndRootRealpathToSaveProject(): void
    {
        $request = $this->buildRequest([
            'projectName' => 'My Cool Project',
            'discovery' => new DiscoveryResult(
                rootRealpath: sys_get_temp_dir() . '/knossos-discovery-root',
                files: [],
                units: [],
                diagnostics: [],
                inputHash: 'h',
                configurationHash: 'c',
            ),
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame('My Cool Project', $this->repo->projects[0][1]);
        assertSame(sys_get_temp_dir() . '/knossos-discovery-root', $this->repo->projects[0][2]);
        // saveProject receives the FULL projectConfig dict (not the retention
        // fallback). The minimal request carries an empty projectConfig.
        assertSame([], $this->repo->projects[0][3]);
    }

    public function testReconcilePersistsConfigFromProjectConfig(): void
    {
        $config = ['snapshot_retention' => 7, 'extra' => 'value'];
        $request = $this->buildRequest(['projectConfig' => $config]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame($config, $this->repo->projects[0][3]);
    }

    public function testReconcileArchivesPreviousSnapshotWithConfigHash(): void
    {
        $this->repo->findProjectStub = ['config_json' => '{"retention":10}'];
        $request = $this->buildRequest(['projectConfig' => ['snapshot_retention' => 3]]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->archives);
        assertSame(hash('sha256', '{"retention":10}'), $this->repo->archives[0][1]);
        assertSame(3, $this->repo->archives[0][2]);
    }

    public function testReconcileArchivesWithEmptyConfigJsonHashWhenNoPreviousProject(): void
    {
        $this->repo->findProjectStub = null;
        $request = $this->buildRequest();
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->archives);
        assertSame(hash('sha256', '{}'), $this->repo->archives[0][1]);
    }

    public function testReconcileArchivesUsingDefaultRetentionWhenConfigLacksSnapshotRetention(): void
    {
        $request = $this->buildRequest(['projectConfig' => ['other_key' => 'value']]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame(5, $this->repo->archives[0][2]);
    }

    public function testReconcileSavesScannerSetHashAsFourthScanArg(): void
    {
        $request = $this->buildRequest();
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertIsString($this->repo->scans[0][3]);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $this->repo->scans[0][3]);
    }

    public function testScannerSetHashIsOrderIndependentWithinSameSet(): void
    {
        $manifestA = new ScannerManifest('a.knossos', '1.0.0', '1.0', '1.0', ['php'], ['php'], []);
        $manifestB = new ScannerManifest('b.knossos', '1.0.0', '1.0', '1.0', ['php'], ['php'], []);

        $requestForward = $this->buildRequest(['scanners' => [$manifestA, $manifestB]]);
        $requestReverse = $this->buildRequest(['scanners' => [$manifestB, $manifestA]]);

        (new GraphReconciler($this->repo))->reconcile($requestForward);
        $hashForward = $this->repo->scans[0][3];

        $this->repo->reset();
        (new GraphReconciler($this->repo))->reconcile($requestReverse);
        $hashReverse = $this->repo->scans[0][3];

        assertSame($hashForward, $hashReverse);
    }

    public function testScannerSetHashDiffersAcrossDistinctSets(): void
    {
        $manifestA = new ScannerManifest('a.knossos', '1.0.0', '1.0', '1.0', ['php'], ['php'], []);
        $manifestB = new ScannerManifest('b.knossos', '2.0.0', '1.0', '1.0', ['php'], ['php'], []);

        $request1 = $this->buildRequest(['scanners' => [$manifestA]]);
        $request2 = $this->buildRequest(['scanners' => [$manifestB]]);

        (new GraphReconciler($this->repo))->reconcile($request1);
        $hash1 = $this->repo->scans[0][3];

        $this->repo->reset();
        (new GraphReconciler($this->repo))->reconcile($request2);
        $hash2 = $this->repo->scans[0][3];

        assertNotSame($hash1, $hash2);
    }

    public function testReconcilePersistsDiscoveryFiles(): void
    {
        $file = $this->minimalDiscoveredFile('src/Foo.php');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$file]),
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->files);
        $expectedFileId = StableId::file($this->repo->scans[0][1], 'src/Foo.php');
        $fileArgs = $this->repo->files[0];
        assertSame($expectedFileId, $fileArgs[0]);
        assertSame('src/Foo.php', $fileArgs[2]);
        assertSame('hash-src/Foo.php', $fileArgs[3]);  // contentHash (arg 4)
        assertSame(123, $fileArgs[4]);  // size (arg 5)
        assertSame('php', $fileArgs[6]);
        $this->assertStringContainsString('@', $fileArgs[7]); // scannerVersion
        assertSame(7, $fileArgs[9]); // lineCount
    }

    public function testReconcileUsesUnknownAsScannerVersionForLanguagesNotInAnyManifest(): void
    {
        $file = $this->minimalDiscoveredFile('src/Foo.php', language: 'unknown-lang');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$file]),
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame('unknown', $this->repo->files[0][7]);
    }

    public function testReconcilePersistsNodesWithCorrectStableIds(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->nodes);
        $nodeArgs = $this->repo->nodes[0];
        $expectedId = StableId::symbol($this->repo->scans[0][1], 'php', 'class', 'App\\Foo');
        assertSame($expectedId, $nodeArgs[0]);
        assertSame('class', $nodeArgs[3]);
        assertSame('App\\Foo', $nodeArgs[4]);
        assertSame('App\\Foo', $nodeArgs[5]); // displayName
        assertSame($this->repo->files[0][0], $nodeArgs[7]); // file_id matches saved file
        assertSame(1, $nodeArgs[8]); // startLine
        assertSame(5, $nodeArgs[9]); // endLine
        assertSame('ast', $nodeArgs[10]); // origin
        assertSame('certain', $nodeArgs[11]); // confidence
        $this->assertArrayHasKey('scanner', $nodeArgs[12]);
        assertSame('test.knossos', $nodeArgs[12]['scanner']);
        assertSame($node->localId, $nodeArgs[12]['scanner_local_id']);
    }

    public function testCollectNodesDeduplicatesIdenticalNodes(): void
    {
        $node1 = $this->minimalNode('php:class:App\\Foo');
        $node2 = $this->minimalNode('php:class:App\\Foo');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                $this->minimalContribution([$node1]),
                $this->minimalContribution([$node2]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        assertSame(1, $result->nodes);
        $this->assertCount(1, $this->repo->nodes);
    }

    public function testCollectNodesDeduplicatesIdenticalNodesWithoutWarning(): void
    {
        // Same stable id AND same evidence file: a clean duplicate, no warning.
        $node1 = $this->minimalNode('php:class:App\\Foo');
        $node2 = $this->minimalNode('php:class:App\\Foo');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                $this->minimalContribution([$node1]),
                $this->minimalContribution([$node2]),
            ],
        ]);

        $result = (new GraphReconciler($this->repo))->reconcile($request);

        assertSame(0, $result->diagnostics);
        assertSame([], $this->repo->diagnostics);
    }

    public function testCollectNodesWarnsOnRedeclarationWithDifferentEvidenceFile(): void
    {
        // Two declarations that hash to the same stable id (same language/kind/
        // canonical name) but cite different evidence files. The first is kept;
        // the divergent second surfaces a warning diagnostic instead of throwing.
        $first = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $second = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Duplicate.php', 9, 12),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                $this->minimalContribution([$first]),
                $this->minimalContribution([$second]),
            ],
        ]);

        $result = (new GraphReconciler($this->repo))->reconcile($request);

        assertSame(1, $result->nodes);
        assertSame(1, $result->diagnostics);
        $this->assertCount(1, $this->repo->diagnostics);
        $diagnostic = $this->repo->diagnostics[0];
        assertSame('warning', $diagnostic[4]);
        assertSame('reconciler.duplicate_symbol_evidence', $diagnostic[5]);
        $this->assertStringContainsString('src/Foo.php', $diagnostic[6]);
        $this->assertStringContainsString('src/Duplicate.php', $diagnostic[6]);
    }

    public function testCollectNodesPackageReDeclaredAcrossFilesEmitsNoDuplicateWarning(): void
    {
        // Two files both importing the same package produce two NodeFacts with
        // identical (language, kind, canonical_name) — hence one stable id — but
        // different evidence files. Packages are shared by design: no warning.
        $first = new NodeFact(
            localId: 'ts:package:node:fs',
            kind: 'package',
            canonicalName: 'node:fs',
            displayName: 'fs',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/a.ts', 1, 1),
        );
        $second = new NodeFact(
            localId: 'ts:package:node:fs',
            kind: 'package',
            canonicalName: 'node:fs',
            displayName: 'fs',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/b.ts', 1, 1),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([
                $this->minimalDiscoveredFile('src/a.ts', 'typescript'),
                $this->minimalDiscoveredFile('src/b.ts', 'typescript'),
            ]),
            'contributions' => [
                $this->minimalContribution([$first]),
                $this->minimalContribution([$second]),
            ],
        ]);

        $result = (new GraphReconciler($this->repo))->reconcile($request);

        assertSame(1, $result->nodes);
        assertSame(0, $result->diagnostics);
        $this->assertCount(0, $this->repo->diagnostics);
    }

    public function testCollectNodesClassReDeclaredFromThreeFilesEmitsOneWarningPerStableId(): void
    {
        // A class re-declared from two additional evidence files previously
        // produced two warnings (one per re-declaring file); a single stable id
        // warrants a single warning, not one per re-declaration.
        $first = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $second = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Duplicate.php', 9, 12),
        );
        $third = new NodeFact(
            localId: 'php:class:App\\Foo',
            kind: 'class',
            canonicalName: 'App\\Foo',
            displayName: 'App\\Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/AnotherDuplicate.php', 3, 6),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                $this->minimalContribution([$first]),
                $this->minimalContribution([$second]),
                $this->minimalContribution([$third]),
            ],
        ]);

        $result = (new GraphReconciler($this->repo))->reconcile($request);

        assertSame(1, $result->nodes);
        assertSame(1, $result->diagnostics);
        $this->assertCount(1, $this->repo->diagnostics);
        assertSame('reconciler.duplicate_symbol_evidence', $this->repo->diagnostics[0][5]);
    }

    public function testReconcilePersistsEdgesWithCorrectStableIds(): void
    {
        // Distinct canonicalNames keep each node's stable id unique; otherwise
        // collectNodes' dedup collapses both emissions into one row.
        $source = $this->minimalNode('php:class:App\\Foo', canonicalName: 'App\\Foo');
        $target = $this->minimalNode('php:class:App\\Bar', canonicalName: 'App\\Bar');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: $target->localId,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source, $target], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(2, $this->repo->nodes);
        $this->assertCount(1, $this->repo->edges);
        $edgeArgs = $this->repo->edges[0];
        assertSame('depends_on', $edgeArgs[2]);
        assertSame($this->repo->nodes[0][0], $edgeArgs[3]); // source_id (arg 4)
        assertSame($this->repo->nodes[1][0], $edgeArgs[4]); // target_id (arg 5)
        assertSame($this->repo->files[0][0], $edgeArgs[5]); // file_id (arg 6)
        assertSame('test.knossos:file:src/Foo.php', $edgeArgs[11]); // ownerKey (arg 12)
    }

    public function testReconcilePersistsClassifications(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $classification = new ClassificationFact(
            nodeReference: $node->localId,
            role: 'module',
            ruleId: 'rule.php_module',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
            'classifications' => [$classification],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->classifications);
        $clsArgs = $this->repo->classifications[0];
        assertSame('module', $clsArgs[3]);
        assertSame('derived', $clsArgs[4]);
        assertSame('probable', $clsArgs[5]);
        assertSame('rule.php_module', $clsArgs[6]);
        assertSame($this->repo->files[0][0], $clsArgs[7]); // file_id
        assertSame(1, $clsArgs[8]); // startLine
        assertSame(5, $clsArgs[9]); // endLine
    }

    public function testReconcilePersistsBoundariesAndMemberships(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $boundary = new BoundaryFact(
            name: 'Core',
            matcher: ['path_prefix' => 'src/Domain'],
            source: 'explicit',
            nodeReferences: [$node->localId],
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
            'boundaries' => [$boundary],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->boundaries);
        $bArgs = $this->repo->boundaries[0];
        assertSame('Core', $bArgs[2]);
        assertSame(['path_prefix' => 'src/Domain'], $bArgs[3]);
        assertSame('explicit', $bArgs[4]);

        $this->assertCount(1, $this->repo->boundaryMemberships);
        assertSame($bArgs[0], $this->repo->boundaryMemberships[0][0]);
        assertSame($this->repo->nodes[0][0], $this->repo->boundaryMemberships[0][2]);
    }

    public function testReconcileDerivesBoundaryIdFromIdentityNameNotSuffixedDisplayName(): void
    {
        // The name carries a merged-from suffix, as BoundaryInference produces for a
        // merged inferred boundary, but identityName holds the pre-suffix primary rule
        // name. The persisted id must key off identityName so a merge-composition change
        // (name changes) never re-ids the boundary.
        $node = $this->minimalNode('php:class:App\\Foo');
        $boundary = new BoundaryFact(
            name: 'composer:vendor/app (+node:web-app)',
            matcher: ['type' => 'path_prefix', 'value' => ''],
            source: 'inferred',
            nodeReferences: [$node->localId],
            identityName: 'composer:vendor/app',
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
            'boundaries' => [$boundary],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->boundaries);
        $bArgs = $this->repo->boundaries[0];
        assertSame(StableId::boundary(StableId::project('proj-id'), 'composer:vendor/app', 'inferred'), $bArgs[0]);
        assertSame('composer:vendor/app (+node:web-app)', $bArgs[2]);
    }

    public function testReconcileDeduplicatesBoundaryMembersWithinSameFact(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $boundary = new BoundaryFact(
            name: 'Core',
            matcher: ['path_prefix' => 'src/Domain'],
            source: 'explicit',
            nodeReferences: [$node->localId, $node->localId, $node->localId],
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
            'boundaries' => [$boundary],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->boundaryMemberships);
    }

    public function testReconcileReplacesContributionCache(): void
    {
        $cacheEntry = $this->minimalContributionCacheEntry();
        $request = $this->buildRequest(['contributionCache' => [$cacheEntry]]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->contributionCaches);
        assertSame([$cacheEntry], $this->repo->contributionCaches[0][1]);
    }

    public function testReconcileSavesScannerDiagnosticsWithOwnerKey(): void
    {
        $diagnostic = new Diagnostic(
            severity: 'warning',
            code: 'DEAD_CODE',
            message: 'Unreachable branch detected',
            evidence: new Evidence('src/Foo.php', 10, 15),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                new ScanContribution('test.knossos:file:src/Foo.php', [], [], [$diagnostic]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->diagnostics);
        $dArgs = $this->repo->diagnostics[0];
        assertSame('warning', $dArgs[4]);
        assertSame('DEAD_CODE', $dArgs[5]);
        assertSame('Unreachable branch detected', $dArgs[6]);
        assertSame(10, $dArgs[7]);
        assertSame(15, $dArgs[8]);
        assertSame('test.knossos:file:src/Foo.php', $dArgs[9]);
        assertSame($this->repo->files[0][0], $dArgs[3]); // file_id
    }

    public function testReconcileSavesScannerDiagnosticWithoutEvidence(): void
    {
        $diagnostic = new Diagnostic(
            severity: 'error',
            code: 'TIMEOUT',
            message: 'Scan timed out',
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                new ScanContribution('test.knossos:file:src/Foo.php', [], [], [$diagnostic]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $dArgs = $this->repo->diagnostics[0];
        assertSame(null, $dArgs[3]);
        assertSame(null, $dArgs[7]);
        assertSame(null, $dArgs[8]);
    }

    public function testReconcileSavesDiscoveryDiagnosticsAsOriginDiscovery(): void
    {
        $diagnostic = new DiscoveryDiagnostic(
            severity: 'info',
            code: 'DISCOVERY_OK',
            message: 'All files classified',
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([], [$diagnostic]),
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $this->assertCount(1, $this->repo->diagnostics);
        assertSame('discovery', $this->repo->diagnostics[0][9]);
        assertSame(null, $this->repo->diagnostics[0][3]);
        assertSame(null, $this->repo->diagnostics[0][7]);
    }

    public function testReconcileSavesDiscoveryDiagnosticsWithRelativePath(): void
    {
        $diagnostic = new DiscoveryDiagnostic(
            severity: 'warning',
            code: 'FILE_TOO_LARGE',
            message: 'File exceeds 5MB',
            relativePath: 'src/Big.php',
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Big.php')], [$diagnostic]),
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame($this->repo->files[0][0], $this->repo->diagnostics[0][3]);
    }

    public function testReconcileCountsAllDiagnosticsInResult(): void
    {
        $scannerDiagnostic = new Diagnostic('info', 'X', 'msg', new Evidence('src/Foo.php', 1, 1));
        $discoveryDiagnostic = new DiscoveryDiagnostic('info', 'Y', 'msg');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery(
                [$this->minimalDiscoveredFile('src/Foo.php')],
                [$discoveryDiagnostic],
            ),
            'contributions' => [
                new ScanContribution('test.knossos:file:src/Foo.php', [], [], [$scannerDiagnostic]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        assertSame(2, $result->diagnostics);
        $this->assertCount(2, $this->repo->diagnostics);
    }

    // ----- resolveEdges: external nodes -----

    public function testResolveEdgesCreatesExternalNodeForUnresolvedTarget(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'php:class:External\\Bar',  // not emitted as a node
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        // One real node + one external node. The source merges externalNodes
        // into $nodes before returning ReconciliationResult, so count($nodes)
        // (which feeds result->nodes) includes both.
        assertSame(2, $result->nodes);
        assertSame(1, $result->unresolvedNodes);
        $this->assertCount(2, $this->repo->nodes);

        // The external node carries the external_ prefix on its kind.
        $external = collectMatching($this->repo->nodes, fn($n) => str_starts_with($n[3], 'external_'));
        $this->assertCount(1, $external);
        assertSame('external_class', $external[0][3]);
        assertSame('derived', $external[0][10]);
        assertSame('possible', $external[0][11]);
        assertSame(true, $external[0][12]['unresolved']);
        assertSame('php:class:External\\Bar', $external[0][12]['reference']);
    }

    public function testExternalNodeDoesNotDoublePrefixWhenAlreadyPrefixed(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'php:external_function:strlen',  // already prefixed
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $external = collectMatching($this->repo->nodes, fn($n) => str_starts_with($n[3], 'external_'));
        $this->assertCount(1, $external);
        assertSame('external_function', $external[0][3]);
    }

    public function testExternalNodeDisplayNameExtractsLastSegmentOfCanonical(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'php:class:Foo\\Bar\\Baz',
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $external = collectMatching($this->repo->nodes, fn($n) => str_starts_with($n[3], 'external_'));
        assertSame('Baz', $external[0][5]);
    }

    public function testExternalNodeDisplayNameExtractsAfterDoubleColon(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'php:external_method:App::Foo::bar',
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $external = collectMatching($this->repo->nodes, fn($n) => str_starts_with($n[3], 'external_'));
        assertSame('bar', $external[0][5]);
    }

    public function testExternalNodeDisplayNameSplitsOnBackslash(): void
    {
        // The displayName regex matches one literal backslash (the source's
        // `\\\\\\\\` PHP single-quoted pattern = `\\` actual string = `\\` regex =
        // single literal `\`). For canonical = '\\' (one backslash) the regex
        // matches and splits the 1-char string into ['', '']; end() is ''.
        // This exercises the single-backslash split branch with a tight payload.
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'php:external_method:\\',
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        $external = collectMatching($this->repo->nodes, fn($n) => str_starts_with($n[3], 'external_'));
        assertSame('', $external[0][5]);
    }

    public function testScannerFromOwnerExtractsScannerIdBeforeFirstColon(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                new ScanContribution('php.knossos:more:parts:here', [$node]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame('php.knossos', $this->repo->nodes[0][12]['scanner']);
    }

    public function testScannerFromOwnerReturnsEntireStringWhenNoColon(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [
                new ScanContribution('lonely', [$node]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame('lonely', $this->repo->nodes[0][12]['scanner']);
    }

    public function testScannerVersionsMapsLanguagesAcrossManifests(): void
    {
        $manifest = new ScannerManifest('multi.knossos', '3.0.0', '1.0', '1.0', ['php', 'python'], ['php', 'py'], []);
        $request = $this->buildRequest([
            'scanners' => [$manifest],
            'discovery' => $this->minimalDiscovery([
                $this->minimalDiscoveredFile('src/A.php', language: 'php'),
                $this->minimalDiscoveredFile('src/B.py', language: 'python'),
            ]),
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $reconciler->reconcile($request);

        assertSame('multi.knossos@3.0.0', $this->repo->files[0][7]);
        assertSame('multi.knossos@3.0.0', $this->repo->files[1][7]);
    }

    // ----- throw paths -----

    public function testThrowsConflictingScannerReferenceForSameLocalId(): void
    {
        $nodeA = new NodeFact(
            localId: 'php:class:Foo',
            kind: 'class',
            canonicalName: 'FooA',
            displayName: 'FooA',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/A.php', 1, 1),
        );
        $nodeB = new NodeFact(
            localId: 'php:class:Foo',  // same localId, different (kind, canonical) → stable id differs
            kind: 'class',
            canonicalName: 'FooB',
            displayName: 'FooB',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/A.php', 2, 2),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/A.php')]),
            'contributions' => [
                $this->minimalContribution([$nodeA]),
                $this->minimalContribution([$nodeB]),
            ],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Conflicting scanner reference', $error->getMessage());
    }

    public function testThrowsNodeLocalIdWithoutLanguageNamespace(): void
    {
        $node = new NodeFact(
            localId: 'NoColonHere',  // missing language:
            kind: 'class',
            canonicalName: 'Foo',
            displayName: 'Foo',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Foo.php', 1, 1),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('no language namespace', $error->getMessage());
    }

    public function testThrowsAttachNodeFilesForEvidenceFileNotInDiscovery(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $rogue = new NodeFact(
            localId: 'php:class:Rogue',
            kind: 'class',
            canonicalName: 'Rogue',
            displayName: 'Rogue',
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Rogue.php', 1, 1),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node, $rogue])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Node evidence file was not discovered', $error->getMessage());
    }

    public function testThrowsEdgeSourceNotEmittedByAnyScanner(): void
    {
        $target = $this->minimalNode('php:class:App\\Bar');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: 'php:class:Unknown\\Src',
            targetReference: $target->localId,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$target], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Edge source was not emitted', $error->getMessage());
    }

    public function testThrowsEdgeEvidenceFileNotDiscovered(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $target = $this->minimalNode('php:class:App\\Bar');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: $target->localId,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Missing.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source, $target], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Edge evidence file was not discovered', $error->getMessage());
    }

    public function testThrowsExternalNodeUnresolvableReferenceWithoutThreeParts(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'no_colons',  // missing 2 of 3 colons
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Unresolvable edge target reference', $error->getMessage());
    }

    public function testThrowsExternalNodeUnresolvableReferenceWithEmptyPart(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: ':empty_lang:class',  // empty language segment
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Unresolvable edge target reference', $error->getMessage());
    }

    public function testThrowsClassificationTargetNotEmitted(): void
    {
        $classification = new ClassificationFact(
            nodeReference: 'php:class:Unknown\\Class',
            role: 'module',
            ruleId: 'rule.php_module',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'classifications' => [$classification],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Classification target was not emitted', $error->getMessage());
    }

    public function testThrowsClassificationEvidenceFileNotDiscovered(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $classification = new ClassificationFact(
            nodeReference: $node->localId,
            role: 'module',
            ruleId: 'rule.php_module',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Missing.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
            'classifications' => [$classification],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Classification evidence file was not discovered', $error->getMessage());
    }

    public function testThrowsBoundaryMemberNotEmitted(): void
    {
        $node = $this->minimalNode('php:class:App\\Foo');
        $boundary = new BoundaryFact(
            name: 'Core',
            matcher: ['path_prefix' => 'src/Domain'],
            source: 'explicit',
            nodeReferences: [$node->localId, 'php:class:NotEmitted'],
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$node])],
            'boundaries' => [$boundary],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $error = captureThrows(
            fn() => $reconciler->reconcile($request),
            ReconciliationException::class,
        );

        assertContains('Boundary member was not emitted', $error->getMessage());
    }

    public function testResultCountsExternalNodesInUnresolvedNodesField(): void
    {
        $source = $this->minimalNode('php:class:App\\Foo');
        $edge = new EdgeFact(
            kind: 'depends_on',
            sourceReference: $source->localId,
            targetReference: 'php:class:External\\Bar',
            origin: Origin::Ast,
            confidence: Confidence::Probable,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
        $request = $this->buildRequest([
            'discovery' => $this->minimalDiscovery([$this->minimalDiscoveredFile('src/Foo.php')]),
            'contributions' => [$this->minimalContribution([$source], [$edge])],
        ]);
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        assertSame(1, $result->unresolvedNodes);
    }

    public function testReconcileReportsPhaseTimings(): void
    {
        $request = $this->buildRequest();
        $reconciler = new GraphReconciler($this->repo);

        $result = $reconciler->reconcile($request);

        $expected = [
            'prepare', 'archive_snapshot', 'clear_graph', 'save_files', 'save_nodes',
            'save_edges', 'save_classifications', 'save_boundaries', 'contribution_cache',
            'save_diagnostics',
        ];
        assertSame($expected, array_keys($result->phaseMilliseconds));
        foreach ($result->phaseMilliseconds as $milliseconds) {
            assertSame(true, is_float($milliseconds) && $milliseconds >= 0.0);
        }
        // NOTE: Window-placement (e.g., whether 'prepare' starts before collectNodes or after)
        // cannot be asserted without wall-clock duration assertions, which would be flaky.
    }

    // ----- helpers -----

    /**
     * @param array<string, mixed> $overrides
     */
    private function buildRequest(array $overrides = []): FullScanRequest
    {
        $args = array_merge($this->minimalRequestArgs(), $overrides);
        return new FullScanRequest(
            $args['projectIdentity'],
            $args['projectName'],
            $args['discovery'],
            $args['scanners'],
            $args['contributions'],
            $args['projectConfig'],
            $args['classifications'],
            $args['boundaries'],
            $args['mode'] ?? 'full',
            $args['contributionCache'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalRequestArgs(): array
    {
        return [
            'projectIdentity' => 'proj-id',
            'projectName' => 'Project Name',
            'discovery' => $this->minimalDiscovery(),
            'scanners' => [$this->minimalScannerManifest()],
            'contributions' => [],
            'projectConfig' => [],
            'classifications' => [],
            'boundaries' => [],
        ];
    }

    private function minimalDiscovery(
        array $files = [],
        array $diagnostics = [],
    ): DiscoveryResult {
        return new DiscoveryResult(
            rootRealpath: sys_get_temp_dir() . '/knossos-root',
            files: $files,
            units: [],
            diagnostics: $diagnostics,
            inputHash: 'h',
            configurationHash: 'c',
        );
    }

    private function minimalDiscoveredFile(
        string $relativePath,
        string $language = 'php',
        int $size = 123,
    ): DiscoveredFile {
        return new DiscoveredFile(
            relativePath: $relativePath,
            absolutePath: sys_get_temp_dir() . '/knossos-root/' . $relativePath,
            language: $language,
            size: $size,
            mtime: 1_700_000_000,
            contentHash: 'hash-' . $relativePath,
            lineCount: 7,
        );
    }

    private function minimalScannerManifest(): ScannerManifest
    {
        return new ScannerManifest(
            id: 'test.knossos',
            version: '0.1.0',
            protocolVersion: '1.0',
            outputSchemaVersion: '1.0',
            languages: ['php'],
            fileExtensions: ['php'],
            capabilities: [],
        );
    }

    /**
     * @param list<\Knossos\Scanner\Protocol\NodeFact> $nodes
     * @param list<\Knossos\Scanner\Protocol\EdgeFact> $edges
     * @param list<\Knossos\Scanner\Protocol\Diagnostic> $diagnostics
     */
    private function minimalContribution(
        array $nodes = [],
        array $edges = [],
        array $diagnostics = [],
    ): ScanContribution {
        return new ScanContribution('test.knossos:file:src/Foo.php', $nodes, $edges, $diagnostics);
    }

    private function minimalNode(string $localId, ?string $canonicalName = null): NodeFact
    {
        // Default canonicalName = 'App\Foo' preserves the prior behaviour for
        // single-node tests. Tests that need two distinct nodes with different
        // stable IDs (e.g. for edges) pass an explicit canonicalName so the
        // two emissions don't collapse into one row in collectNodes' dedup.
        $canonical = $canonicalName ?? 'App\\Foo';
        return new NodeFact(
            localId: $localId,
            kind: 'class',
            canonicalName: $canonical,
            displayName: $canonical,
            origin: Origin::Ast,
            confidence: Confidence::Certain,
            evidence: new Evidence('src/Foo.php', 1, 5),
        );
    }

    private function minimalContributionCacheEntry(): ContributionCacheEntry
    {
        return new ContributionCacheEntry(
            filePath: 'src/Foo.php',
            contentHash: 'h1',
            scannerId: 'test.knossos',
            scannerVersion: '0.1.0',
            configurationHash: 'c1',
            contribution: new ScanContribution('test.knossos:file:src/Foo.php'),
        );
    }
}

/**
 * In-file helper for the test above. Kept in the same file (not split out)
 * because there is no shared FakeRepository helper under tests/phpunit/Support/
 * today and creating one is out of scope.
 */

/**
 * @param list<array<int, mixed>> $rows
 * @param callable(array<int, mixed>): bool $predicate
 * @return list<array<int, mixed>>
 */
function collectMatching(array $rows, callable $predicate): array
{
    $matched = [];
    foreach ($rows as $row) {
        if ($predicate($row)) {
            $matched[] = $row;
        }
    }
    return $matched;
}

final class FakeGraphRepository implements GraphRepository
{
    /** @var list<true> */
    public array $transactions = [];
    /** @var list<array<int, mixed>> */
    public array $projects = [];
    /** @var list<array<int, mixed>> */
    public array $scans = [];
    /** @var list<array<int, mixed>> */
    public array $completedScans = [];
    /** @var list<array<int, mixed>> */
    public array $archives = [];
    /** @var list<array<int, mixed>> */
    public array $clearedGraphs = [];
    /** @var list<array<int, mixed>> */
    public array $files = [];
    /** @var list<array<int, mixed>> */
    public array $nodes = [];
    /** @var list<array<int, mixed>> */
    public array $edges = [];
    /** @var list<array<int, mixed>> */
    public array $diagnostics = [];
    /** @var list<array<int, mixed>> */
    public array $classifications = [];
    /** @var list<array<int, mixed>> */
    public array $boundaries = [];
    /** @var list<array<int, mixed>> */
    public array $boundaryMemberships = [];
    /** @var list<array<int, mixed>> */
    public array $contributionCaches = [];

    public ?array $findProjectStub = null;

    public function reset(): void
    {
        $this->transactions = [];
        $this->projects = [];
        $this->scans = [];
        $this->completedScans = [];
        $this->archives = [];
        $this->clearedGraphs = [];
        $this->files = [];
        $this->nodes = [];
        $this->edges = [];
        $this->diagnostics = [];
        $this->classifications = [];
        $this->boundaries = [];
        $this->boundaryMemberships = [];
        $this->contributionCaches = [];
        $this->findProjectStub = null;
    }

    public function transaction(callable $operation): mixed
    {
        $this->transactions[] = true;
        return $operation($this);
    }

    public function saveProject(string $id, string $name, string $rootRealpath, array $config = []): void
    {
        $this->projects[] = [$id, $name, $rootRealpath, $config];
    }

    public function findProject(string $id): ?array
    {
        return $this->findProjectStub;
    }

    public function createScan(string $id, string $projectId, string $mode, string $scannerSetHash): void
    {
        $this->scans[] = [$id, $projectId, $mode, $scannerSetHash];
    }

    public function completeScan(string $projectId, string $scanId): void
    {
        $this->completedScans[] = [$projectId, $scanId];
    }

    public function recordFailedScan(string $id, string $projectId, string $mode, string $status): void
    {
        $this->scans[] = [$id, $projectId, $mode, $status];
    }

    public function archiveActiveSnapshot(string $projectId, string $configHash, int $retention): void
    {
        $this->archives[] = [$projectId, $configHash, $retention];
    }

    public function clearProjectGraph(string $projectId): void
    {
        $this->clearedGraphs[] = [$projectId];
    }

    public function saveFile(
        string $id,
        string $projectId,
        string $relativePath,
        string $contentHash,
        int $size,
        int $mtime,
        string $language,
        string $scannerVersion,
        string $scanId,
        int $lineCount = 0,
    ): void {
        $this->files[] = [$id, $projectId, $relativePath, $contentHash, $size, $mtime, $language, $scannerVersion, $scanId, $lineCount];
    }

    public function saveNode(
        string $id,
        string $projectId,
        string $language,
        string $kind,
        string $canonicalName,
        string $displayName,
        ?string $parentId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void {
        $this->nodes[] = [$id, $projectId, $language, $kind, $canonicalName, $displayName, $parentId, $fileId, $startLine, $endLine, $origin, $confidence, $attributes, $ownerKey, $scanId];
    }

    public function saveEdge(
        string $id,
        string $projectId,
        string $kind,
        string $sourceId,
        string $targetId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void {
        $this->edges[] = [$id, $projectId, $kind, $sourceId, $targetId, $fileId, $startLine, $endLine, $origin, $confidence, $attributes, $ownerKey, $scanId];
    }

    /** @param list<array<string, mixed>> $nodes */
    public function saveNodes(array $nodes, string $projectId, string $scanId): void
    {
        foreach ($nodes as $node) {
            $this->saveNode(
                $node['id'],
                $projectId,
                $node['language'],
                $node['kind'],
                $node['canonical_name'],
                $node['display_name'],
                null,
                $node['file_id'],
                $node['start_line'],
                $node['end_line'],
                $node['origin'],
                $node['confidence'],
                $node['attributes'],
                $node['owner_key'],
                $scanId,
            );
        }
    }

    /** @param list<array<string, mixed>> $edges */
    public function saveEdges(array $edges, string $projectId, string $scanId): void
    {
        foreach ($edges as $edge) {
            $this->saveEdge(
                $edge['id'],
                $projectId,
                $edge['kind'],
                $edge['source_id'],
                $edge['target_id'],
                $edge['file_id'],
                $edge['start_line'],
                $edge['end_line'],
                $edge['origin'],
                $edge['confidence'],
                $edge['attributes'],
                $edge['owner_key'],
                $scanId,
            );
        }
    }

    /** @param list<array<string, mixed>> $files */
    public function saveFiles(array $files, string $projectId, string $scanId): void
    {
        foreach ($files as $file) {
            $this->saveFile(
                $file['id'],
                $projectId,
                $file['relative_path'],
                $file['content_hash'],
                $file['size'],
                $file['mtime'],
                $file['language'],
                $file['scanner_version'],
                $scanId,
                $file['line_count'],
            );
        }
    }

    /** @param list<array<string, mixed>> $classifications */
    public function saveClassifications(array $classifications, string $projectId, string $scanId): void
    {
        foreach ($classifications as $classification) {
            $this->saveClassification(
                $classification['id'],
                $projectId,
                $classification['node_id'],
                $classification['role'],
                $classification['origin'],
                $classification['confidence'],
                $classification['rule_id'],
                $classification['file_id'],
                $classification['start_line'],
                $classification['end_line'],
                $classification['attributes'],
                $scanId,
            );
        }
    }

    /** @param list<array<string, mixed>> $memberships */
    public function saveBoundaryMemberships(array $memberships, string $projectId, string $scanId): void
    {
        foreach ($memberships as $membership) {
            $this->saveBoundaryMembership($membership['boundary_id'], $projectId, $membership['node_id'], $scanId);
        }
    }

    public function saveDiagnostic(
        string $id,
        string $projectId,
        string $scanId,
        ?string $fileId,
        string $severity,
        string $code,
        string $message,
        ?int $startLine,
        ?int $endLine,
        string $ownerKey,
    ): void {
        $this->diagnostics[] = [$id, $projectId, $scanId, $fileId, $severity, $code, $message, $startLine, $endLine, $ownerKey];
    }

    public function saveClassification(
        string $id,
        string $projectId,
        string $nodeId,
        string $role,
        string $origin,
        string $confidence,
        string $ruleId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        array $attributes,
        string $scanId,
    ): void {
        $this->classifications[] = [$id, $projectId, $nodeId, $role, $origin, $confidence, $ruleId, $fileId, $startLine, $endLine, $attributes, $scanId];
    }

    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void
    {
        $this->boundaries[] = [$id, $projectId, $name, $matcher, $source, $scanId];
    }

    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void
    {
        $this->boundaryMemberships[] = [$boundaryId, $projectId, $nodeId, $scanId];
    }

    public function replaceContributionCache(string $projectId, array $entries): void
    {
        $this->contributionCaches[] = [$projectId, $entries];
    }

    public function findNodesByName(string $projectId, string $name, int $limit = 20): array
    {
        return [];
    }

    public function outgoing(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return [];
    }

    public function incoming(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array
    {
        return [];
    }

}