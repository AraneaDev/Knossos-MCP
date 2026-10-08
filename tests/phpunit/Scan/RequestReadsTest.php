<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Scan\RequestReads;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\WorkerException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Which reads belong to one file and which to the whole request, so a change to
 * a file invalidates exactly the contributions that depended on it.
 */
#[Group('scan')]
final class RequestReadsTest extends TestCase
{
    public function testAWorkerThatDoesNotAttributeLeavesEveryFileDependingOnTheWholeMap(): void
    {
        $inputs = ['a.ts' => self::h('a'), 'node_modules/x.d.ts' => self::h('x')];

        $reads = RequestReads::forRequest([], $this->manifest(['scan']), $inputs, [new ScanContribution('o:a'), new ScanContribution('o:b')]);

        $group = $reads['owners']['o:a']['group'];
        self::assertIsString($group);
        assertSame($inputs, $reads['groups'][$group]);
        assertSame(['reads' => [], 'group' => $group, 'attributed' => false], $reads['owners']['o:a']);
        assertSame(['reads' => [], 'group' => $group, 'attributed' => false], $reads['owners']['o:b']);
    }

    public function testAnAttributingWorkerGivesEachFileItsOwnReadsAndSharesTheResultReads(): void
    {
        $inputs = ['a.ts' => self::h('a'), 'b.ts' => self::h('b'), 'tsconfig.json' => self::h('t')];
        $contribution = new ScanContribution('o:a', reads: ['b.ts' => self::h('b'), 'c.ts' => null]);

        $reads = RequestReads::forRequest(
            ['reads' => ['tsconfig.json' => self::h('t')]],
            $this->manifest(['scan', 'read_attribution']),
            $inputs + ['c.ts' => null],
            [$contribution],
            ['a.ts'],
        );

        $group = $reads['owners']['o:a']['group'];
        self::assertIsString($group);
        assertSame(['b.ts' => self::h('b'), 'c.ts' => null], $reads['owners']['o:a']['reads']);
        assertSame(true, $reads['owners']['o:a']['attributed']);
        assertSame(['tsconfig.json' => self::h('t')], $reads['groups'][$group]);
    }

    public function testAnAttributingWorkerWithNoSharedReadsHasNoGroup(): void
    {
        $reads = RequestReads::forRequest([], $this->manifest(['read_attribution']), [], [new ScanContribution('o:a', reads: [])]);

        assertSame(null, $reads['owners']['o:a']['group']);
        assertSame([], $reads['groups']);
    }

    public function testAReadThatDisagreesWithInputHashesIsRefused(): void
    {
        $error = captureThrows(
            fn() => RequestReads::forRequest([], $this->manifest(['read_attribution']), ['b.ts' => self::h('9')], [new ScanContribution('o:a', reads: ['b.ts' => self::h('b')])]),
            WorkerException::class,
        );

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
    }

    public function testASharedReadMissingFromInputHashesIsRefused(): void
    {
        $error = captureThrows(
            fn() => RequestReads::forRequest(['reads' => ['t.json' => self::h('t')]], $this->manifest(['read_attribution']), [], [new ScanContribution('o:a', reads: [])]),
            WorkerException::class,
        );

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
    }

    /**
     * A read no contribution and no shared set names would invalidate nothing
     * when it changes, so an attributing worker that leaves one out is refused.
     */
    public function testAReadNoContributionAndNoSharedSetNamesIsRefused(): void
    {
        $error = captureThrows(
            fn() => RequestReads::forRequest(
                ['reads' => ['tsconfig.json' => self::h('t')]],
                $this->manifest(['read_attribution']),
                ['a.ts' => self::h('a'), 'tsconfig.json' => self::h('t'), 'node_modules/x/index.d.ts' => self::h('x')],
                [new ScanContribution('o:a', reads: [])],
                ['a.ts'],
            ),
            WorkerException::class,
        );

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
        assertSame('knossos.fake read node_modules/x/index.d.ts but named it in no contribution\'s reads and not in the request\'s shared reads.', $error->getMessage());
    }

    public function testARequestedFilesOwnReadNeedsNoAttribution(): void
    {
        $reads = RequestReads::forRequest(
            [],
            $this->manifest(['read_attribution']),
            ['a.ts' => self::h('a'), 'gone.ts' => null],
            [new ScanContribution('o:a', reads: []), new ScanContribution('o:gone', reads: [])],
            ['a.ts', 'gone.ts'],
        );

        assertSame([], $reads['groups']);
    }

    public function testAWorkerThatDoesNotAttributeOwesNoCoverage(): void
    {
        $reads = RequestReads::forRequest([], $this->manifest(['scan']), ['node_modules/x.d.ts' => self::h('x')], [new ScanContribution('o:a')]);

        self::assertCount(1, $reads['groups']);
    }

    public function testAnAttributingWorkerMustReportReadsOnEveryContribution(): void
    {
        $error = captureThrows(
            fn() => RequestReads::forRequest([], $this->manifest(['read_attribution']), [], [new ScanContribution('o:a')]),
            WorkerException::class,
        );

        assertSame('WORKER_CONTRIBUTION_INVALID', $error->diagnosticCode);
    }

    public function testTheGroupIdIgnoresInsertionOrder(): void
    {
        $manifest = $this->manifest(['scan']);
        $one = RequestReads::forRequest([], $manifest, ['a.ts' => self::h('a'), 'b.ts' => self::h('b')], [new ScanContribution('o:a')]);
        $two = RequestReads::forRequest([], $manifest, ['b.ts' => self::h('b'), 'a.ts' => self::h('a')], [new ScanContribution('o:a')]);

        assertSame($one['owners']['o:a']['group'], $two['owners']['o:a']['group']);
    }

    public function testDifferentMapsGetDifferentGroupIds(): void
    {
        $manifest = $this->manifest(['scan']);
        $one = RequestReads::forRequest([], $manifest, ['a.ts' => self::h('a')], [new ScanContribution('o:a')]);
        $two = RequestReads::forRequest([], $manifest, ['a.ts' => null], [new ScanContribution('o:a')]);

        self::assertNotSame($one['owners']['o:a']['group'], $two['owners']['o:a']['group']);
    }

    /** @param list<string> $capabilities */
    private function manifest(array $capabilities): ScannerManifest
    {
        return new ScannerManifest('knossos.fake', '0.1.0', '1', '1', ['typescript'], ['ts'], $capabilities);
    }

    private static function h(string $seed): string
    {
        return hash('sha256', $seed);
    }
}
