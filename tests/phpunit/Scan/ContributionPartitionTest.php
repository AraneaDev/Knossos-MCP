<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Reconciliation\ContributionCacheEntry;
use Knossos\Scan\ContributionPartition;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\ScanContribution;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('contribution-partition')]
final class ContributionPartitionTest extends TestCase
{
    public function testConstructorAssignsAllFieldsViaNamedArgs(): void
    {
        $contribution = $this->makeScanContribution();
        $cacheEntry = $this->makeContributionCacheEntry($contribution);
        $filesToScan = [$this->fakeObject('file')];

        $partition = new ContributionPartition(
            cached: [$contribution],
            cacheEntries: [$cacheEntry],
            filesToScan: $filesToScan,
            added: 3,
            changed: 5,
        );

        assertSame([$contribution], $partition->cached);
        assertSame([$cacheEntry], $partition->cacheEntries);
        assertSame($filesToScan, $partition->filesToScan);
        assertSame(3, $partition->added);
        assertSame(5, $partition->changed);
    }

    public function testConstructorAssignsAllFieldsViaPositionalArgs(): void
    {
        $partition = new ContributionPartition([], [], [], 0, 0);

        assertSame([], $partition->cached);
        assertSame([], $partition->cacheEntries);
        assertSame([], $partition->filesToScan);
        assertSame(0, $partition->added);
        assertSame(0, $partition->changed);
    }

    public function testReadonlyFieldsCannotBeReassignedForScalarCounters(): void
    {
        $partition = new ContributionPartition([], [], [], 0, 0);

        $error = captureThrows(static function () use ($partition): void {
            $partition->added = 9999;
        }, \Error::class);

        assertContains('readonly', $error->getMessage());
    }

    public function testReadonlyFieldsCannotBeReassignedForArrayFields(): void
    {
        $partition = new ContributionPartition([], [], [], 0, 0);

        $error = captureThrows(static function () use ($partition): void {
            $partition->cached = ['hacked-contribution'];
        }, \Error::class);

        assertContains('readonly', $error->getMessage());
    }

    public function testEmptyListsAndZeroCountersAreAccepted(): void
    {
        $partition = new ContributionPartition(cached: [], cacheEntries: [], filesToScan: [], added: 0, changed: 0);

        assertSame([], $partition->cached);
        assertSame([], $partition->cacheEntries);
        assertSame([], $partition->filesToScan);
        assertSame(0, $partition->added);
        assertSame(0, $partition->changed);
    }

    public function testLargeAddedAndChangedValuesPropagate(): void
    {
        $contribution = $this->makeScanContribution();
        $partition = new ContributionPartition(
            cached: [$contribution, $contribution],
            cacheEntries: [],
            filesToScan: [],
            added: 42,
            changed: 13,
        );

        assertSame(2, count($partition->cached));
        assertSame(42, $partition->added);
        assertSame(13, $partition->changed);
    }

    private function makeScanContribution(): ScanContribution
    {
        return new ScanContribution(
            'knossos.php:file:src/Example.php',
            [new NodeFact(
                'php:class:Example',
                'class',
                'Example',
                'Example',
                Origin::Ast,
                Confidence::Certain,
                new Evidence('src/Example.php', 1, 5),
            )],
            [],
            [],
        );
    }

    private function makeContributionCacheEntry(ScanContribution $contribution): ContributionCacheEntry
    {
        return new ContributionCacheEntry(
            filePath: 'src/Example.php',
            contentHash: 'abc123',
            scannerId: 'knossos.php',
            scannerVersion: '0.1.0',
            configurationHash: 'def456',
            contribution: $contribution,
        );
    }

    private function fakeObject(string $tag): object
    {
        return new class ($tag) {
            public function __construct(public readonly string $tag) {}
        };
    }
}
