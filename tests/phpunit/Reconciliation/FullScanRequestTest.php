<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use InvalidArgumentException;
use Knossos\Boundary\BoundaryFact;
use Knossos\Classification\ClassificationFact;
use Knossos\Discovery\DiscoveryResult;
use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Reconciliation\FullScanRequest;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[Group('full-scan-request')]
final class FullScanRequestTest extends TestCase
{
    // ----- helpers -----

    /**
     * @return array<string, mixed> Named-arg bag for the FullScanRequest ctor.
     * Most tests override exactly one key for the branch under test.
     */
    private static function minimalValidArgs(): array
    {
        return [
            'projectIdentity' => 'proj-id',
            'projectName' => 'Project Name',
            'discovery' => new DiscoveryResult(
                rootRealpath: '/tmp/proj',
                files: [],
                units: [],
                diagnostics: [],
                inputHash: 'h',
                configurationHash: 'c',
            ),
            'scanners' => [new ScannerManifest(
                id: 'test.knossos',
                version: '0.1.0',
                protocolVersion: '1.0',
                outputSchemaVersion: '1.0',
                languages: ['php'],
                fileExtensions: ['php'],
                capabilities: [],
            )],
            'contributions' => [new ScanContribution(
                ownerKey: 'test.knossos:file:src/x.php',
            )],
            'projectConfig' => [],
            'classifications' => [],
            'boundaries' => [],
            // mode defaults to 'full'
            // contributionCache defaults to []
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private static function buildRequest(array $overrides = []): FullScanRequest
    {
        $args = array_merge(self::minimalValidArgs(), $overrides);
        return new FullScanRequest(
            $args['projectIdentity'],
            $args['projectName'],
            $args['discovery'],
            $args['scanners'],
            $args['contributions'],
            $args['projectConfig'],
            $args['classifications'],
            $args['boundaries'],
            // mode is positional 9; default 'full' if absent
            $args['mode'] ?? 'full',
            $args['contributionCache'] ?? [],
        );
    }

    private static function minimalEvidence(): Evidence
    {
        return new Evidence('src/Foo.php', 1, 5);
    }

    private static function minimalClassification(): ClassificationFact
    {
        return new ClassificationFact(
            nodeReference: 'php:class:App\\Foo',
            role: 'module',
            ruleId: 'rule.php_module',
            origin: Origin::Derived,
            confidence: Confidence::Probable,
            evidence: self::minimalEvidence(),
        );
    }

    private static function minimalBoundary(): BoundaryFact
    {
        return new BoundaryFact(
            name: 'Core',
            matcher: ['path_prefix' => 'src/Domain'],
            source: 'explicit',
            nodeReferences: ['php:class:App\\Foo'],
        );
    }

    private static function minimalScanContribution(): ScanContribution
    {
        return new ScanContribution(ownerKey: 'test.knossos:file:src/Foo.php');
    }

    private static function minimalContributionCacheEntry(): ContributionCacheEntry
    {
        return new ContributionCacheEntry(
            filePath: 'src/Foo.php',
            contentHash: 'h1',
            scannerId: 'test.knossos',
            scannerVersion: '0.1.0',
            configurationHash: 'c1',
            contribution: self::minimalScanContribution(),
        );
    }

    // ----- class shape -----

    public function testClassIsFinal(): void
    {
        $this->assertTrue((new ReflectionClass(FullScanRequest::class))->isFinal());
    }

    public function testClassIsReadonly(): void
    {
        $this->assertTrue((new ReflectionClass(FullScanRequest::class))->isReadOnly());
    }

    // ----- happy path -----

    public function testConstructorStoresAllPropertiesFromArguments(): void
    {
        $discovery = new DiscoveryResult('/p', [], [], [], 'h', 'c');
        $scanner = new ScannerManifest('s.knossos', '0.1.0', '1.0', '1.0', ['php'], ['php'], []);
        $contrib = new ScanContribution('s.knossos:file:src/x.php');
        $cls = self::minimalClassification();
        $bnd = self::minimalBoundary();
        $cache = self::minimalContributionCacheEntry();
        $config = ['snapshot_retention' => 7];

        $request = new FullScanRequest(
            projectIdentity: 'proj-id',
            projectName: 'Project Name',
            discovery: $discovery,
            scanners: [$scanner],
            contributions: [$contrib],
            projectConfig: $config,
            classifications: [$cls],
            boundaries: [$bnd],
            mode: 'incremental',
            contributionCache: [$cache],
        );

        assertSame('proj-id', $request->projectIdentity);
        assertSame('Project Name', $request->projectName);
        assertSame($discovery, $request->discovery);
        assertSame([$scanner], $request->scanners);
        assertSame([$contrib], $request->contributions);
        assertSame($config, $request->projectConfig);
        assertSame([$cls], $request->classifications);
        assertSame([$bnd], $request->boundaries);
        assertSame('incremental', $request->mode);
        assertSame([$cache], $request->contributionCache);
    }

    public function testConstructorAcceptsDefaultModeAndEmptyCollections(): void
    {
        $request = self::buildRequest();

        assertSame('full', $request->mode);
        assertSame([], $request->projectConfig);
        assertSame([], $request->classifications);
        assertSame([], $request->boundaries);
        assertSame([], $request->contributionCache);
    }

    public function testConstructorAcceptsEmptyScannerListAndEmptyContributionList(): void
    {
        $request = self::buildRequest([
            'scanners' => [],
            'contributions' => [],
        ]);

        assertSame([], $request->scanners);
        assertSame([], $request->contributions);
    }

    // ----- identity / name empty-string rejection -----

    public function testThrowsOnEmptyProjectIdentity(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['projectIdentity' => '']),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsOnEmptyProjectName(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['projectName' => '']),
            InvalidArgumentException::class,
        );
    }

    // ----- scanners list-of-class -----

    public function testThrowsWhenScannersIsAssociativeNotList(): void
    {
        // The associative (non-list) wrapper around the ScannerManifest forces the
        // assertListOf guard to trip on array_is_list. Extracted to a local var so
        // the static fn body doesn't reach 4-+ levels of nested brackets, which
        // tripped PHP's parser in earlier iterations.
        $notAList = ['not-a-zero-key' => new ScannerManifest('a', '0.1', '1.0', '1.0', ['php'], ['php'], [])];
        assertThrows(
            static fn () => self::buildRequest(['scanners' => $notAList]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsWhenScannersContainsNonManifestValue(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['scanners' => [new \stdClass()]]),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsEmptyScannersList(): void
    {
        $request = self::buildRequest(['scanners' => []]);
        assertSame([], $request->scanners);
    }

    // ----- contributions list-of-class -----

    public function testThrowsWhenContributionsIsAssociativeNotList(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['contributions' => ['k' => self::minimalScanContribution()]]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsWhenContributionsContainsNonContributionValue(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['contributions' => ['not-an-object']]),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsEmptyContributionsList(): void
    {
        $request = self::buildRequest(['contributions' => []]);
        assertSame([], $request->contributions);
    }

    // ----- classifications list-of-class -----

    public function testThrowsWhenClassificationsIsAssociativeNotList(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['classifications' => ['k' => self::minimalClassification()]]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsWhenClassificationsContainsNonClassificationValue(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['classifications' => [new \stdClass()]]),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsEmptyClassificationsList(): void
    {
        $request = self::buildRequest(['classifications' => []]);
        assertSame([], $request->classifications);
    }

    // ----- boundaries list-of-class -----

    public function testThrowsWhenBoundariesIsAssociativeNotList(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['boundaries' => ['k' => self::minimalBoundary()]]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsWhenBoundariesContainsNonBoundaryValue(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['boundaries' => ['plain-string']]),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsEmptyBoundariesList(): void
    {
        $request = self::buildRequest(['boundaries' => []]);
        assertSame([], $request->boundaries);
    }

    // ----- contributionCache list-of-class -----

    public function testThrowsWhenContributionCacheIsAssociativeNotList(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['contributionCache' => ['k' => self::minimalContributionCacheEntry()]]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsWhenContributionCacheContainsNonEntryValue(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['contributionCache' => [new \stdClass()]]),
            InvalidArgumentException::class,
        );
    }

    public function testAcceptsEmptyContributionCacheList(): void
    {
        $request = self::buildRequest(['contributionCache' => []]);
        assertSame([], $request->contributionCache);
    }

    // ----- mode enum -----

    public function testAcceptsFullMode(): void
    {
        $request = self::buildRequest(['mode' => 'full']);
        assertSame('full', $request->mode);
    }

    public function testAcceptsIncrementalMode(): void
    {
        $request = self::buildRequest(['mode' => 'incremental']);
        assertSame('incremental', $request->mode);
    }

    public function testThrowsOnModeWithWrongCase(): void
    {
        // Strict in_array check rejects case-mismatched modes.
        assertThrows(
            static fn () => self::buildRequest(['mode' => 'FULL']),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsOnUnknownMode(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['mode' => 'partial']),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsOnEmptyMode(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['mode' => '']),
            InvalidArgumentException::class,
        );
    }

    // ----- snapshot_retention validation -----

    public function testAcceptsEmptyProjectConfigAndPreservesUnrelatedConfigKeys(): void
    {
        // Kills `?? 5` mutations that yield out-of-range values (e.g. `?? 21`,
        // `?? -1`): when projectConfig is empty the source's retention fallback
        // is used and empty config must NOT throw.
        $request = self::buildRequest(['projectConfig' => []]);
        assertSame([], $request->projectConfig);
    }

    public function testAcceptsProjectConfigWithOnlyUnrelatedKeys(): void
    {
        // Same kill: when snapshot_retention key is absent, the `?? 5` fallback is
        // exercised; the unrelated key must be preserved verbatim.
        $request = self::buildRequest(['projectConfig' => ['unrelated' => 'value']]);
        assertSame(['unrelated' => 'value'], $request->projectConfig);
    }

    public function testAcceptsRetentionAtZeroBoundary(): void
    {
        // 0 is the lower inclusive bound — kills `< 0` → `< 1` mutation.
        $request = self::buildRequest(['projectConfig' => ['snapshot_retention' => 0]]);
        assertSame(['snapshot_retention' => 0], $request->projectConfig);
    }

    public function testAcceptsRetentionAtTwentyBoundary(): void
    {
        // 20 is the upper inclusive bound — kills `> 20` → `> 19` or `>= 21` mutations.
        $request = self::buildRequest(['projectConfig' => ['snapshot_retention' => 20]]);
        assertSame(['snapshot_retention' => 20], $request->projectConfig);
    }

    public function testAcceptsRetentionAtMidValue(): void
    {
        $request = self::buildRequest(['projectConfig' => ['snapshot_retention' => 7]]);
        assertSame(['snapshot_retention' => 7], $request->projectConfig);
    }

    public function testThrowsOnRetentionBelowZero(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['projectConfig' => ['snapshot_retention' => -1]]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsOnRetentionAboveTwenty(): void
    {
        assertThrows(
            static fn () => self::buildRequest(['projectConfig' => ['snapshot_retention' => 21]]),
            InvalidArgumentException::class,
        );
    }

    public function testThrowsOnRetentionThatIsNotAnInteger(): void
    {
        // kills `is_int($retention)` mutation — strings / floats must fail.
        assertThrows(
            static fn () => self::buildRequest(['projectConfig' => ['snapshot_retention' => '5']]),
            InvalidArgumentException::class,
        );
        assertThrows(
            static fn () => self::buildRequest(['projectConfig' => ['snapshot_retention' => 5.0]]),
            InvalidArgumentException::class,
        );
    }

    // ----- combinations -----

    public function testAcceptsAllOptionalCollectionsPopulatedAtOnce(): void
    {
        $request = self::buildRequest([
            'classifications' => [self::minimalClassification()],
            'boundaries' => [self::minimalBoundary()],
            'contributionCache' => [self::minimalContributionCacheEntry()],
            'projectConfig' => ['snapshot_retention' => 3],
            'mode' => 'incremental',
        ]);

        assertSame(1, count($request->classifications));
        assertSame(1, count($request->boundaries));
        assertSame(1, count($request->contributionCache));
        assertSame(['snapshot_retention' => 3], $request->projectConfig);
        assertSame('incremental', $request->mode);
    }
}
