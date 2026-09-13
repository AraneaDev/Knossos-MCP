<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\ContributionPartition;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scanner\Protocol\{Confidence, Evidence, NodeFact, Origin};
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('contribution-cache')]
final class ContributionCacheServiceTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/knossos-ccs-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    private function manifest(): ScannerManifest
    {
        return new ScannerManifest('knossos.php', '0.1.0', '1', '1', ['php'], ['php'], ['scan']);
    }

    private function hashingManifest(): ScannerManifest
    {
        return new ScannerManifest('knossos.php', '0.1.0', '1', '1', ['php'], ['php'], ['scan', 'content_hash']);
    }

    private function node(string $path): NodeFact
    {
        return new NodeFact('class:Foo', 'class', 'Foo', 'Foo', Origin::Ast, Confidence::Certain, new Evidence($path, 1, 1), []);
    }

    private function writeFile(string $name, string $contents): DiscoveredFile
    {
        $absolute = $this->dir . '/' . $name;
        file_put_contents($absolute, $contents);
        $hash = hash('sha256', $contents);

        return new DiscoveredFile($name, $absolute, 'php', strlen($contents), 0, $hash);
    }

    public function testEntriesForScannedCachesWhenDiskContentStillMatches(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('Foo.php', "<?php // stable\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(1, count($result['cache_entries']));
    }

    public function testEntriesForScannedDropsCacheEntryWhenContentChangedDuringScan(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('Foo.php', "<?php // original\n");
        // Simulate the TOCTOU window: the worker read (and this contribution reflects)
        // bytes that no longer match the discovery-time hash carried on $file.
        file_put_contents($file->absolutePath, "<?php // mutated after discovery\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        // The contribution is still returned for this scan's graph,
        // but no poisoned cache entry is persisted.
        assertSame(1, count($result['contributions']));
        assertSame(0, count($result['cache_entries']));
    }

    public function testEntriesForScannedDropsCacheEntryWhenFileUnreadableAtScanTime(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('Gone.php', "<?php\n");
        unlink($file->absolutePath); // vanished before the scan-time re-fingerprint
        $contribution = new ScanContribution('knossos.php:file:Gone.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(0, count($result['cache_entries']));
    }

    public function testPartitionObservesCancellation(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $files = [];
        for ($i = 0; $i < 300; ++$i) {
            $file = new \stdClass();
            $file->relativePath = "src/File{$i}.php";
            $file->contentHash = 'h' . $i;
            $files[] = $file;
        }
        $token = new CancellationToken();
        $token->cancel();

        $this->expectException(ScanCancelledException::class);
        $service->partition($files, $manifest, 'cfg', [], false, $token);
    }

    public function testPartitionWithoutTokenReturnsPartition(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = new \stdClass();
        $file->relativePath = 'src/Only.php';
        $file->contentHash = 'abc';

        $partition = $service->partition([$file], $manifest, 'cfg', [], false);

        assertSame(true, $partition instanceof ContributionPartition);
        assertSame(1, $partition->added);
    }

    public function testPartitionRebuildsFromSourceWhenCachedPayloadIsNotAnArray(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = new \stdClass();
        $file->relativePath = 'src/Bad.php';
        $file->contentHash = 'hash1';
        $cache = [
            $manifest->id . "\0src/Bad.php" => [
                'content_hash' => 'hash1',
                'scanner_version' => $manifest->version,
                'configuration_hash' => 'cfg',
                // Valid JSON but decodes to a scalar, not an array -> treated as corrupt cache.
                'payload_json' => '5',
            ],
        ];

        $partition = $service->partition([$file], $manifest, 'cfg', $cache, false);

        assertSame(0, count($partition->cached));
        assertSame([$file], $partition->filesToScan);
        assertSame(1, $partition->changed);
    }

    public function testEntriesForScannedKeepsCacheEntryWhenFileLacksAbsolutePath(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        // Non-DiscoveredFile input (no absolutePath/contentHash pair available for
        // re-fingerprinting) — verification is skipped and the entry is kept.
        $file = new \stdClass();
        $file->relativePath = 'src/NoAbsolutePath.php';
        $file->contentHash = 'abc123';
        $contribution = new ScanContribution($manifest->id . ':file:src/NoAbsolutePath.php');

        $result = $service->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(1, count($result['cache_entries']));
    }

    public function testMissingContributionBecomesADiagnosticRatherThanAnException(): void
    {
        // A worker that fails to emit for one file must cost that file, not the
        // scan: every other file in the batch has already contributed facts.
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $fileA = $this->writeFile('a.php', "<?php // a\n");
        $fileB = $this->writeFile('b.php', "<?php // b\n");
        $contributionA = new ScanContribution($manifest->id . ':file:a.php');

        $result = $service->entriesForScanned([$contributionA], [$fileA, $fileB], $manifest, 'cfg');

        self::assertCount(2, $result['contributions']);
        $missing = $result['contributions'][1];
        self::assertSame($manifest->id . ':file:b.php', $missing->ownerKey);
        self::assertSame([], $missing->nodes);
        self::assertSame('SCANNER_OMITTED_CONTRIBUTION', $missing->diagnostics[0]->code);
        // Never cached: the next scan must retry the file from source.
        self::assertCount(1, $result['cache_entries']);
    }

    /**
     * A worker that answers under the wrong owner key is not the same failure
     * as one that skips a file, and must not be reported as one.
     *
     * The misattributed contribution held real nodes and edges; nothing maps
     * them to a scanned file, so they are discarded and the file they should
     * have described comes back empty. Reporting that under
     * SCANNER_OMITTED_CONTRIBUTION filed a bug in the worker's own bookkeeping
     * under the ordinary, expected "that file produced nothing" code, where it
     * reads as coverage noise rather than as the defect it is.
     */
    public function testAContributionUnderAnUnrequestedOwnerGetsItsOwnDiagnostic(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('b.php', "<?php // b\n");
        $misattributed = new ScanContribution($manifest->id . ':file:not/asked/for.php');

        $result = $service->entriesForScanned([$misattributed], [$file], $manifest, 'cfg');

        self::assertCount(1, $result['contributions']);
        $diagnostic = $result['contributions'][0]->diagnostics[0];
        self::assertSame('SCANNER_MISATTRIBUTED_CONTRIBUTION', $diagnostic->code);
        self::assertStringContainsString('not/asked/for.php', $diagnostic->message);
        self::assertCount(0, $result['cache_entries']);
    }

    /**
     * An unrequested owner key was only ever reported through the requested
     * file that went missing because of it. A worker that answers for every
     * file it was asked about *and* adds a stray owner leaves no such file
     * behind, so its discarded nodes and edges went unrecorded entirely.
     */
    public function testAnUnrequestedOwnerIsReportedEvenWhenEveryRequestedFileWasAnswered(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('b.php', "<?php // b\n");
        $answered = new ScanContribution($manifest->id . ':file:b.php');
        $stray = new ScanContribution($manifest->id . ':file:not/asked/for.php');

        $result = $service->entriesForScanned([$answered, $stray], [$file], $manifest, 'cfg');

        self::assertCount(2, $result['contributions']);
        $report = $result['contributions'][1];
        self::assertSame($manifest->id . ':scan', $report->ownerKey);
        self::assertSame('SCANNER_MISATTRIBUTED_CONTRIBUTION', $report->diagnostics[0]->code);
        self::assertStringContainsString('not/asked/for.php', $report->diagnostics[0]->message);
        // The answered file is legitimate and still cached.
        self::assertCount(1, $result['cache_entries']);
    }

    /**
     * Two contributions under one owner key silently overwrote each other in
     * the lookup index, so the earlier one's facts vanished and the survivor
     * — picked by arrival order — was cached as if it were authoritative.
     */
    public function testADuplicateOwnerKeyIsReportedAndKeepsTheFileOutOfTheCache(): void
    {
        $service = new ContributionCacheService();
        $manifest = $this->manifest();
        $file = $this->writeFile('b.php', "<?php // b\n");
        $first = new ScanContribution($manifest->id . ':file:b.php');
        $second = new ScanContribution($manifest->id . ':file:b.php');

        $result = $service->entriesForScanned([$first, $second], [$file], $manifest, 'cfg');

        self::assertCount(1, $result['contributions']);
        $diagnostic = $result['contributions'][0]->diagnostics[0];
        self::assertSame('SCANNER_DUPLICATE_CONTRIBUTION', $diagnostic->code);
        self::assertStringContainsString('b.php', $diagnostic->message);
        self::assertCount(0, $result['cache_entries']);
    }

    public function testAHashThatMatchesDiscoveryIsCached(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // stable\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')], [], [], $file->contentHash);

        $result = (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $this->hashingManifest(), 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(1, count($result['cache_entries']));
    }

    /**
     * The race #79 cannot see: the file on disk still matches discovery, so
     * only the worker's own hash reveals it parsed something else.
     */
    public function testAHashThatDiffersFromDiscoveryFailsTheScanEvenWhenDiskStillMatches(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // A\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')], [], [], hash('sha256', "<?php // B\n"));

        $error = captureThrows(
            fn() => (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $this->hashingManifest(), 'cfg'),
            ScanSnapshotChangedException::class,
        );

        assertContains('Foo.php', $error->getMessage());
        assertContains('was parsed from different content', $error->getMessage());
    }

    /** A present hash is evidence whoever sent it, so it is checked without the capability too. */
    public function testAHashIsVerifiedEvenWhenTheCapabilityIsNotDeclared(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // A\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php', [], [], [], hash('sha256', "<?php // B\n"));

        $error = captureThrows(
            fn() => (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $this->manifest(), 'cfg'),
            ScanSnapshotChangedException::class,
        );

        assertContains('Foo.php', $error->getMessage());
    }

    public function testFactsWithoutAHashFromADeclaringWorkerAreAContractViolation(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // A\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')]);

        $error = captureThrows(
            fn() => (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $this->hashingManifest(), 'cfg'),
            WorkerException::class,
        );

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        assertContains('knossos.php', $error->getMessage());
        assertContains('Foo.php', $error->getMessage());
    }

    /** A worker cannot hash a file it failed to read; that diagnostic is kept but never cached. */
    public function testADiagnosticWithoutAHashFromADeclaringWorkerIsAcceptedButNotCached(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // A\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php', [], [], [
            new \Knossos\Scanner\Protocol\Diagnostic('error', 'PHP_UNSCANNABLE_FILE', 'unreadable', new Evidence('Foo.php', 1, 1)),
        ]);

        $result = (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $this->hashingManifest(), 'cfg');

        assertSame(1, count($result['contributions']));
        assertSame(0, count($result['cache_entries']));
    }

    public function testFactsWithoutAHashFromANonDeclaringWorkerAreCachedAsBefore(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // A\n");
        $contribution = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')]);

        $result = (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $this->manifest(), 'cfg');

        assertSame(1, count($result['cache_entries']));
    }

    public function testADuplicatedContributionKeepsItsHashAndIsStillVerified(): void
    {
        $file = $this->writeFile('Foo.php', "<?php // A\n");
        $first = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')], [], [], $file->contentHash);
        $second = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')], [], [], $file->contentHash);

        $result = (new ContributionCacheService())->entriesForScanned([$first, $second], [$file], $this->hashingManifest(), 'cfg');

        assertSame($file->contentHash, $result['contributions'][0]->contentHash);

        $bad = new ScanContribution('knossos.php:file:Foo.php', [$this->node('Foo.php')], [], [], hash('sha256', 'other'));
        captureThrows(
            fn() => (new ContributionCacheService())->entriesForScanned([$first, $bad], [$file], $this->hashingManifest(), 'cfg'),
            ScanSnapshotChangedException::class,
        );
    }
}
