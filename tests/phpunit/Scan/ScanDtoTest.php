<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Configuration\ProjectConfiguration;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Scan\ContributionPartition;
use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\LanguageScanResult;
use Knossos\Scan\ScanPlan;
use Knossos\Scan\ScanPreparation;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the 4 Scan module DTO files:
 *
 *   - src/Scan/ScanPlan.php               (readonly DTO with 5 promoted props:
 *                                          preparation + projectId + effectiveMode
 *                                          + cacheByScannerPath + deletedFiles).
 *   - src/Scan/LanguageScanResult.php     (readonly DTO with 9 promoted props).
 *   - src/Scan/ContributionPartition.php  (readonly DTO with 5 promoted props;
 *                                          imports ScanContribution +
 *                                          ContributionCacheEntry for list<>
 *                                          generics).
 *   - src/Scan/LanguageDescriptor.php     (readonly DTO with 4 promoted props
 *                                          + 1 static factory `defaults()`).
 *
 * Per the close-out doc § 8 plan from batch 12a: Batch 12b picks the
 * 4 DTOs. These should reach 100% MSI per the batch 11b Discovery DTO
 * pattern. The first-round verifier reported 5 surviving mutants on
 * LanguageDescriptor::defaults() (all on the python entry); round-2
 * adds 2 strict-assertion tests to kill them.
 *
 * Conventions match batches 1–12a: bare global helpers from
 * `tests/phpunit/Support/Assertions.php`; class-level
 * `#[Group('scan-dto')]`. NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('scan-dto')]
final class ScanDtoTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    /**
     * Build a minimal valid ScanPreparation for ScanPlan tests.
     *
     * ScanPreparation's constructor requires 13 args including
     * ProjectConfiguration (10 optional args, default null/empty),
     * DiscoveryResult (6 required promoted props), and
     * WorkerExecutionPolicy (1 default arg). The factory uses
     * default-friendly values for every primitive + list arg so
     * the construction is trivial.
     */
    private function makePreparation(): ScanPreparation
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
            laravel: false,
            symfony: false,
            configurationHashes: [],
            configurationMilliseconds: 0.0,
            discoveryMilliseconds: 0.0,
            planningMilliseconds: 0.0,
        );
    }

    // ===== ScanPlan =======================================================

    public function testScanPlanConstructorStoresAllPromotedProperties(): void
    {
        $preparation = $this->makePreparation();
        $cache = ['scanner_php' => ['k1' => 'v1', 'k2' => 2]];
        $plan = new ScanPlan(
            preparation: $preparation,
            projectId: 'abc123',
            effectiveMode: 'full',
            cacheByScannerPath: $cache,
            deletedFiles: 5,
        );

        assertSame($preparation, $plan->preparation);
        assertSame('abc123', $plan->projectId);
        assertSame('full', $plan->effectiveMode);
        assertSame($cache, $plan->cacheByScannerPath);
        assertSame(5, $plan->deletedFiles);
    }

    public function testScanPlanAcceptsEmptyCacheAndZeroDeletedFiles(): void
    {
        $preparation = $this->makePreparation();
        $plan = new ScanPlan(
            preparation: $preparation,
            projectId: 'p1',
            effectiveMode: 'fast',
            cacheByScannerPath: [],
            deletedFiles: 0,
        );

        assertSame([], $plan->cacheByScannerPath);
        assertSame(0, $plan->deletedFiles);
    }

    public function testScanPlanCacheByScannerPathIsPassedThroughVerbatim(): void
    {
        $preparation = $this->makePreparation();
        $cache = [
            'scanner_php' => ['a' => 1, 'b' => 2],
            'scanner_typescript' => ['x' => 'y'],
        ];
        $plan = new ScanPlan(
            preparation: $preparation,
            projectId: 'p2',
            effectiveMode: 'full',
            cacheByScannerPath: $cache,
            deletedFiles: 10,
        );

        assertSame($cache, $plan->cacheByScannerPath);
        assertSame('a', array_key_first($plan->cacheByScannerPath['scanner_php'] ?? []));
    }

    // ===== LanguageScanResult ============================================

    public function testLanguageScanResultConstructorStoresAllPromotedProperties(): void
    {
        $result = new LanguageScanResult(
            manifests: [],
            contributions: [],
            cacheEntries: [],
            parsed: 100,
            unchanged: 50,
            added: 30,
            changed: 20,
            scannerMetadata: ['k' => 'v'],
            stageMilliseconds: ['parse' => 1.5, 'extract' => 0.7],
        );

        assertSame([], $result->manifests);
        assertSame([], $result->contributions);
        assertSame([], $result->cacheEntries);
        assertSame(100, $result->parsed);
        assertSame(50, $result->unchanged);
        assertSame(30, $result->added);
        assertSame(20, $result->changed);
        assertSame(['k' => 'v'], $result->scannerMetadata);
        assertSame(['parse' => 1.5, 'extract' => 0.7], $result->stageMilliseconds);
    }

    public function testLanguageScanResultAcceptsArbitraryObjectsInListFields(): void
    {
        $manifest = new \stdClass();
        $manifest->id = 'm1';
        $contribution = new \stdClass();
        $contribution->id = 'c1';
        $cacheEntry = new \stdClass();
        $cacheEntry->id = 'ce1';

        $result = new LanguageScanResult(
            manifests: [$manifest],
            contributions: [$contribution],
            cacheEntries: [$cacheEntry],
            parsed: 1,
            unchanged: 0,
            added: 1,
            changed: 0,
            scannerMetadata: [],
            stageMilliseconds: [],
        );

        assertSame($manifest, $result->manifests[0]);
        assertSame($contribution, $result->contributions[0]);
        assertSame($cacheEntry, $result->cacheEntries[0]);
    }

    public function testLanguageScanResultStageMillisecondsAcceptsFloatValues(): void
    {
        $result = new LanguageScanResult(
            manifests: [],
            contributions: [],
            cacheEntries: [],
            parsed: 0,
            unchanged: 0,
            added: 0,
            changed: 0,
            scannerMetadata: [],
            stageMilliseconds: ['parse' => 1.234567, 'extract' => 0.000123, 'flush' => 100.0],
        );

        assertSame(1.234567, $result->stageMilliseconds['parse']);
        assertSame(0.000123, $result->stageMilliseconds['extract']);
        assertSame(100.0, $result->stageMilliseconds['flush']);
    }

    public function testLanguageScanResultParsedUnchangedAddedChangedAreIndependent(): void
    {
        $result = new LanguageScanResult(
            manifests: [],
            contributions: [],
            cacheEntries: [],
            parsed: 1000,
            unchanged: 999,
            added: 1,
            changed: 0,
            scannerMetadata: [],
            stageMilliseconds: [],
        );

        assertSame(1000, $result->parsed);
        assertSame(999, $result->unchanged);
        assertSame(1, $result->added);
        assertSame(0, $result->changed);
    }

    // ===== ContributionPartition ==========================================

    public function testContributionPartitionConstructorStoresAllPromotedProperties(): void
    {
        $partition = new ContributionPartition(
            cached: [],
            cacheEntries: [],
            filesToScan: [],
            added: 0,
            changed: 0,
        );

        assertSame([], $partition->cached);
        assertSame([], $partition->cacheEntries);
        assertSame([], $partition->filesToScan);
        assertSame(0, $partition->added);
        assertSame(0, $partition->changed);
    }

    public function testContributionPartitionAcceptsArbitraryObjectsInFilesToScanList(): void
    {
        $f1 = new \stdClass();
        $f1->path = 'src/A.php';
        $f2 = new \stdClass();
        $f2->path = 'src/B.php';

        $partition = new ContributionPartition(
            cached: [],
            cacheEntries: [],
            filesToScan: [$f1, $f2],
            added: 2,
            changed: 0,
        );

        assertSame(2, count($partition->filesToScan));
        assertSame($f1, $partition->filesToScan[0]);
        assertSame($f2, $partition->filesToScan[1]);
        assertSame(2, $partition->added);
    }

    public function testContributionPartitionAddedAndChangedCountersAreIndependent(): void
    {
        $partition = new ContributionPartition(
            cached: [],
            cacheEntries: [],
            filesToScan: [],
            added: 5,
            changed: 3,
        );

        assertSame(5, $partition->added);
        assertSame(3, $partition->changed);
    }

    // ===== LanguageDescriptor =============================================

    public function testLanguageDescriptorConstructorStoresAllPromotedProperties(): void
    {
        $descriptor = new LanguageDescriptor(
            key: 'ruby',
            languages: ['ruby'],
            command: ['ruby', '--disable-gems', '-rworker', '/path/to/worker.rb'],
            stage: 'scanner_ruby',
        );

        assertSame('ruby', $descriptor->key);
        assertSame(['ruby'], $descriptor->languages);
        assertSame(['ruby', '--disable-gems', '-rworker', '/path/to/worker.rb'], $descriptor->command);
        assertSame('scanner_ruby', $descriptor->stage);
    }

    public function testLanguageDescriptorDefaultsReturnsThreeBuiltInDescriptors(): void
    {
        $defaults = LanguageDescriptor::defaults('/opt/knossos');

        assertSame(3, count($defaults));
        $keys = array_map(static fn($d) => $d->key, $defaults);
        assertSame(['php', 'typescript', 'python'], $keys);
    }

    public function testLanguageDescriptorDefaultsPhpDescriptorHasExpectedShape(): void
    {
        $defaults = LanguageDescriptor::defaults('/opt/knossos');
        $php = $defaults[0];

        assertSame('php', $php->key);
        assertSame(['php'], $php->languages);
        assertSame('scanner_php', $php->stage);
        assertSame(true, count($php->command) >= 4);
        $lastArg = $php->command[count($php->command) - 1];
        assertSame(true, str_contains($lastArg, '/opt/knossos/workers/php/'));
    }

    public function testLanguageDescriptorDefaultsTypescriptDescriptorHasExpectedShape(): void
    {
        $defaults = LanguageDescriptor::defaults('/opt/knossos');
        $ts = $defaults[1];

        assertSame('typescript', $ts->key);
        assertSame(['typescript', 'javascript'], $ts->languages);
        assertSame('scanner_typescript', $ts->stage);
        assertSame(true, count($ts->command) >= 3);
        assertSame('node', $ts->command[0]);
        $lastArg = $ts->command[count($ts->command) - 1];
        assertSame(true, str_contains($lastArg, '/opt/knossos/workers/typescript/'));
    }

    public function testLanguageDescriptorDefaultsPythonDescriptorHasExpectedShape(): void
    {
        $defaults = LanguageDescriptor::defaults('/opt/knossos');
        $py = $defaults[2];

        assertSame('python', $py->key);
        assertSame(['python'], $py->languages);
        assertSame('scanner_python', $py->stage);
        assertSame(true, count($py->command) >= 4);
        assertSame('python3', $py->command[0]);
        assertSame('-I', $py->command[1]);
        assertSame('-B', $py->command[2]);
        $lastArg = $py->command[count($py->command) - 1];
        assertSame(true, str_contains($lastArg, '/opt/knossos/workers/python/'));
    }

    public function testLanguageDescriptorDefaultsInterpolatesInstallationRootIntoWorkerPath(): void
    {
        $a = LanguageDescriptor::defaults('/install/A');
        $b = LanguageDescriptor::defaults('/install/B');

        $aLast = $a[0]->command[count($a[0]->command) - 1];
        $bLast = $b[0]->command[count($b[0]->command) - 1];

        assertSame(true, str_contains($aLast, '/install/A/workers/php/'));
        assertSame(true, str_contains($bLast, '/install/B/workers/php/'));
        assertSame(false, $aLast === $bLast);
    }

    // ===== LanguageDescriptor MSI-killer tests (round 2) ==================
    //
    // Round 1 reported 5 surviving mutants on LanguageDescriptor::defaults()
    // (all on the python entry). Round 2 adds 2 tests with rigid
    // length + index + exact-string assertions that throw on every
    // surviving mutation.

    public function testLanguageDescriptorDefaultsPythonLanguagesIsStrictlyCorrect(): void
    {
        $defaults = LanguageDescriptor::defaults('/opt/knossos');
        $py = $defaults[2];

        // Kills M#1 (ArrayItemRemoval on `['python']` -> `[]`).
        // If the array is empty, $py->languages[0] throws an undefined
        // index error; count() returns 0; either path kills the mutant.
        assertSame(1, count($py->languages));
        assertSame('python', $py->languages[0]);
    }

    public function testLanguageDescriptorDefaultsPythonCommandIsStrictlyCorrect(): void
    {
        $defaults = LanguageDescriptor::defaults('/opt/knossos');
        $py = $defaults[2];
        $command = $py->command;

        // Kills M#5 (ArrayItemRemoval on `['python3', ...]` -> `['-I', ...]`).
        // Enforces exact length so a dropped element fails the count check.
        assertSame(4, count($command));
        assertSame('python3', $command[0]);

        $lastArg = $command[3];

        // Kills M#4 (ConcatOperandRemoval: suffix `/workers/python/bin/worker.py`
        // dropped, leaving just `$installationRoot`).
        assertSame(true, str_ends_with($lastArg, '/worker.py'));

        // Kills M#3 (ConcatOperandRemoval: `$installationRoot` dropped, leaving
        // just the string suffix `/workers/python/bin/worker.py`).
        assertSame(true, str_starts_with($lastArg, '/opt/knossos/'));

        // Kills M#2 (Concat swap: `$root . '/workers/...'` becomes
        // `'/workers/...' . $root`). Checks the exact combined string exists
        // exactly as expected.
        assertSame('/opt/knossos/workers/python/bin/worker.py', $lastArg);
    }
}