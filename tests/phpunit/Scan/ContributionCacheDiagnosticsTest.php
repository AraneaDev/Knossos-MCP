<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ContributionCacheService;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use stdClass;

/**
 * What the cache service reports when a worker's answers do not line up with
 * what was asked, and when it checks for cancellation.
 *
 * ContributionCacheService scored 78% under mutation testing. Its tests cover
 * one bad answer at a time, so the loop could stop at the first one it
 * reported, the diagnostics could point at the wrong line, the list of stray
 * owner keys could name a different number of them, and the cancellation check
 * could run on every file or never.
 */
final class ContributionCacheDiagnosticsTest extends KnossosTestCase
{
    /** Cancellation is checked once per 256 files, so 255 files never reach a check. */
    #[Group('scan')]
    public function testCancellationIsCheckedEveryTwoHundredAndFiftySixFiles(): void
    {
        $cancelled = new CancellationToken();
        $cancelled->cancel();

        (new ContributionCacheService())->partition(self::files(255), self::manifest(), 'cfg', [], false, $cancelled);

        assertThrows(
            static fn() => (new ContributionCacheService())->partition(self::files(256), self::manifest(), 'cfg', [], false, $cancelled),
            ScanCancelledException::class,
        );
    }

    /**
     * Every file that was answered badly is reported, not only the first: one
     * file skipped and another answered twice both get their diagnostic.
     */
    #[Group('scan')]
    public function testEveryMisansweredFileIsReported(): void
    {
        $manifest = self::manifest();
        $files = [self::file('src/skipped.php'), self::file('src/twice.php'), self::file('src/fine.php')];
        $twice = $manifest->id . ':file:src/twice.php';
        $scanned = [
            new ScanContribution($twice, [], [], []),
            new ScanContribution($twice, [], [], []),
            new ScanContribution($manifest->id . ':file:src/fine.php', [], [], []),
        ];

        $result = (new ContributionCacheService())->entriesForScanned($scanned, $files, $manifest, 'cfg');

        assertSame(
            ['SCANNER_OMITTED_CONTRIBUTION', 'SCANNER_DUPLICATE_CONTRIBUTION'],
            array_map(
                static fn(ScanContribution $contribution): string => $contribution->diagnostics[0]->code,
                array_values(array_filter($result['contributions'], static fn(ScanContribution $c): bool => $c->diagnostics !== [])),
            ),
        );
        // Only the file that was answered once and cleanly is cached.
        assertSame(
            ['src/fine.php'],
            array_map(static fn(ContributionCacheEntry $entry): string => $entry->filePath, $result['cache_entries']),
        );
    }

    /** Every diagnostic about a file points at the file's first line, not a range. */
    #[Group('scan')]
    public function testEveryFileDiagnosticPointsAtItsFirstLine(): void
    {
        $manifest = self::manifest();
        $twice = $manifest->id . ':file:src/twice.php';
        $scanned = [new ScanContribution($twice, [], [], []), new ScanContribution($twice, [], [], [])];

        $result = (new ContributionCacheService())->entriesForScanned(
            $scanned,
            [self::file('src/skipped.php'), self::file('src/twice.php')],
            $manifest,
            'cfg',
        );

        foreach ([0 => 'src/skipped.php', 1 => 'src/twice.php'] as $index => $path) {
            $evidence = $result['contributions'][$index]->diagnostics[0]->evidence;
            assertSame($path, $evidence?->relativePath);
            assertSame(1, $evidence?->startLine);
            assertSame(1, $evidence?->endLine, $path);
        }
    }

    /** Stray owner keys are counted in full and the first three are named. */
    #[Group('scan')]
    public function testStrayOwnerKeysAreCountedInFullAndThreeAreNamed(): void
    {
        $manifest = self::manifest();
        $scanned = [];
        foreach (['a', 'b', 'c', 'd'] as $stray) {
            $scanned[] = new ScanContribution($manifest->id . ':file:stray-' . $stray . '.php', [], [], []);
        }
        $scanned[] = new ScanContribution($manifest->id . ':file:src/fine.php', [], [], []);

        $result = (new ContributionCacheService())->entriesForScanned($scanned, [self::file('src/fine.php')], $manifest, 'cfg');

        $message = $result['contributions'][1]->diagnostics[0]->message;
        assertSame(
            'The scanner answered under 4 owner key(s) that were never requested (knossos.test:file:stray-a.php, knossos.test:file:stray-b.php, knossos.test:file:stray-c.php); those facts were discarded.',
            $message,
        );
    }

    /**
     * The check repeats every 256 files rather than firing once: 767 files
     * reach two checks and the 768th reaches a third.
     */
    #[Group('scan')]
    public function testCancellationIsCheckedAgainEveryTwoHundredAndFiftySixFiles(): void
    {
        $polls = 0;
        $counting = new CancellationToken(function () use (&$polls): bool {
            ++$polls;

            return false;
        });

        (new ContributionCacheService())->partition(self::files(767), self::manifest(), 'cfg', [], false, $counting);
        assertSame(2, $polls);

        $polls = 0;
        (new ContributionCacheService())->partition(self::files(768), self::manifest(), 'cfg', [], false, $counting);
        assertSame(3, $polls);
    }

    /**
     * A requested file left unanswered while the worker answered under stray
     * owner keys names the first three of them, and counts all four.
     */
    #[Group('scan')]
    public function testAnOmittedFileNamesTheFirstThreeStrayOwnerKeys(): void
    {
        $manifest = self::manifest();
        $scanned = [];
        foreach (['a', 'b', 'c', 'd'] as $stray) {
            $scanned[] = new ScanContribution($manifest->id . ':file:stray-' . $stray . '.php', [], [], []);
        }

        $result = (new ContributionCacheService())->entriesForScanned($scanned, [self::file('src/skipped.php')], $manifest, 'cfg');

        assertSame(1, count($result['contributions']));
        $diagnostic = $result['contributions'][0]->diagnostics[0];
        assertSame('SCANNER_MISATTRIBUTED_CONTRIBUTION', $diagnostic->code);
        assertSame(
            'The scanner returned no contribution for src/skipped.php and answered under 4 owner key(s) that were never requested (knossos.test:file:stray-a.php, knossos.test:file:stray-b.php, knossos.test:file:stray-c.php); those facts were discarded.',
            $diagnostic->message,
        );
    }

    /** A duplicated answer keeps the diagnostics the worker itself reported, ahead of the duplicate record. */
    #[Group('scan')]
    public function testADuplicatedContributionKeepsTheWorkersOwnDiagnostics(): void
    {
        $manifest = self::manifest();
        $twice = $manifest->id . ':file:src/twice.php';
        $own = new Diagnostic('warning', 'WORKER_OWN', 'reported by the worker', new Evidence('src/twice.php', 3, 3));
        $scanned = [new ScanContribution($twice, [], [], []), new ScanContribution($twice, [], [], [$own])];

        $result = (new ContributionCacheService())->entriesForScanned($scanned, [self::file('src/twice.php')], $manifest, 'cfg');

        assertSame(
            ['WORKER_OWN', 'SCANNER_DUPLICATE_CONTRIBUTION'],
            array_map(static fn(Diagnostic $diagnostic): string => $diagnostic->code, $result['contributions'][0]->diagnostics),
        );
    }

    /**
     * A reported hash for a file discovery never hashed has nothing to be
     * verified against, and is refused rather than taken as verified, whether
     * the discovery hash is missing or is not a string.
     */
    #[Group('scan')]
    public function testAReportedHashWithoutADiscoveryHashIsRefused(): void
    {
        $manifest = self::manifest();
        $missing = new stdClass();
        $missing->relativePath = 'src/Unhashed.php';
        $notAString = self::file('src/Unhashed.php');
        $notAString->contentHash = 5;

        foreach ([$missing, $notAString] as $file) {
            $contribution = new ScanContribution($manifest->id . ':file:src/Unhashed.php', [], [], [], hash('sha256', 'anything'));
            $error = captureThrows(
                static fn() => (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $manifest, 'cfg'),
                WorkerException::class,
            );

            assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
            assertSame(
                'knossos.test reported a content hash for src/Unhashed.php, but discovery recorded no hash for it, so the hash cannot be verified.',
                $error->getMessage(),
            );
        }
    }

    /** A file with no string discovery hash has nothing to key a cache entry on, so it is kept but never cached. */
    #[Group('scan')]
    public function testAFileWithoutAStringDiscoveryHashIsNeverCached(): void
    {
        $manifest = self::manifest();
        $file = self::file('src/Unhashed.php');
        $file->contentHash = 5;
        $contribution = new ScanContribution($manifest->id . ':file:src/Unhashed.php', [], [], []);

        $result = (new ContributionCacheService())->entriesForScanned([$contribution], [$file], $manifest, 'cfg');

        assertSame([$contribution], $result['contributions']);
        assertSame([], $result['cache_entries']);
    }

    /** @return list<stdClass> */
    private static function files(int $count): array
    {
        $files = [];
        for ($index = 0; $index < $count; ++$index) {
            $files[] = self::file(sprintf('src/File%03d.php', $index));
        }

        return $files;
    }

    private static function file(string $relativePath): stdClass
    {
        $file = new stdClass();
        $file->relativePath = $relativePath;
        $file->contentHash = 'hash-' . $relativePath;

        return $file;
    }

    private static function manifest(): ScannerManifest
    {
        return new ScannerManifest('knossos.test', '1.0.0', '1.0', '1.0', ['php'], ['.php'], []);
    }
}
