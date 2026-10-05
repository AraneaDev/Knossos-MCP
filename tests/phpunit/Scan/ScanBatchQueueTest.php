<?php

declare(strict_types=1);

namespace Knossos\Tests\Scan;

use Knossos\Scan\LanguageDescriptor;
use Knossos\Scan\ScanBatchQueue;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The order a language's batches are sent in, and what each kind of retry costs.
 *
 * The runner tests drive this through a live worker, which pins outcomes but
 * not the arithmetic behind them: which split is free, how far the allowance
 * goes, where each re-split lands in the queue.
 */
#[Group('scan-runner')]
final class ScanBatchQueueTest extends TestCase
{
    private const SCANNER = 'knossos.fake';

    public function testBatchesAreSentInFileOrderBoundedByCountAndBytes(): void
    {
        $queue = $this->queue($this->files(10, 100), batchFiles: 4, batchBytes: 300);

        // Three files reach 300 bytes, so the byte bound closes each batch first.
        assertSame([['f00', 'f01', 'f02'], ['f03', 'f04', 'f05'], ['f06', 'f07', 'f08'], ['f09']], $this->drain($queue));
    }

    public function testAFrameSearchSendsTheFirstUnansweredFileAloneThenTheRestHalvedThenTheAnswered(): void
    {
        $queue = $this->queue($this->files(10, 100), batchFiles: 8, batchBytes: 10_000);
        $first = $queue->next();
        assertSame(8, count($first['files']));

        // Answered f00 and f01, failed in f02's frame.
        $file = $queue->searchFrame($first, $this->frame(), $this->answers(['f00', 'f01']), self::SCANNER);

        // The rest held 500 bytes, so it goes on at 250: two files a batch.
        assertSame(null, $file);
        assertSame([['f02'], ['f03', 'f04'], ['f05', 'f06'], ['f07'], ['f00', 'f01'], ['f08', 'f09']], $this->drain($queue));
    }

    public function testASearchSplitThatConfirmedAsManyFilesAsItSuspectsIsFree(): void
    {
        $allowance = WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS + 2 * 3;
        $queue = $this->queue($this->files(8, 100), batchFiles: 8, batchBytes: 10_000);
        $batch = $queue->next();

        // Four answered, four suspected: free, however often it happens.
        for ($round = 0; $round <= $allowance + 2; ++$round) {
            $queue->searchFrame($batch, $this->frame(), $this->answers(['f00', 'f01', 'f02', 'f03']), self::SCANNER);
        }

        $this->addToAssertionCount(1);
    }

    public function testASearchSplitThatConfirmedFewerIsChargedUntilTheAllowanceIsSpent(): void
    {
        // 8 files: 4 + 2 * ceil(log2 8) = 10 charged splits, then the eleventh throws.
        $queue = $this->queue($this->files(8, 100), batchFiles: 8, batchBytes: 10_000);
        $batch = $queue->next();
        for ($round = 0; $round < 10; ++$round) {
            $queue->searchFrame($batch, $this->frame(), $this->answers(['f00', 'f01', 'f02']), self::SCANNER);
        }

        $error = captureThrows(
            fn() => $queue->searchFrame($batch, $this->frame(), $this->answers(['f00']), self::SCANNER),
            WorkerException::class,
        );

        assertContains('after 10 frame-search retries, the most a language of 8 files to scan is allowed', $error->getMessage());
    }

    public function testTheChargeIsDecidedOnTheCountsBeforeTheProbeIsTakenOff(): void
    {
        // 7 files, 3 answered, 4 suspected: charged. Taking the probe off
        // first would leave 3 suspected against 3 answered and make it free.
        // 7 files: 4 + 2 * ceil(log2 7) = 10 charged splits, then the eleventh throws.
        $queue = $this->queue($this->files(7, 100), batchFiles: 8, batchBytes: 10_000);
        $batch = $queue->next();
        $answered = $this->answers(['f00', 'f01', 'f02']);
        for ($round = 0; $round < 10; ++$round) {
            $queue->searchFrame($batch, $this->frame(), $answered, self::SCANNER);
        }

        $error = captureThrows(
            fn() => $queue->searchFrame($batch, $this->frame(), $answered, self::SCANNER),
            WorkerException::class,
        );

        assertContains('after 10 frame-search retries, the most a language of 7 files to scan is allowed', $error->getMessage());
    }

    public function testASingleUnansweredFileGoesOutAloneAheadOfTheAnsweredOnes(): void
    {
        // Nothing is left to halve behind the probe, so no empty batch is queued.
        $queue = $this->queue($this->files(4, 100), batchFiles: 8, batchBytes: 10_000);
        $batch = $queue->next();

        $file = $queue->searchFrame($batch, $this->frame(), $this->answers(['f00', 'f01', 'f03']), self::SCANNER);

        assertSame(null, $file);
        assertSame([['f02'], ['f00', 'f01', 'f03']], $this->drain($queue));
    }

    public function testASearchOfOneFileHandsThatFileBackToBeLeftOut(): void
    {
        $queue = $this->queue($this->files(1, 100), batchFiles: 8, batchBytes: 10_000);
        $batch = $queue->next();

        $file = $queue->searchFrame($batch, $this->frame(), [], self::SCANNER);

        assertSame('f00', $file?->relativePath);
        assertSame([], $this->drain($queue));
    }

    public function testAFrameThatIsNoFilesOrCameAfterEveryAnswerIsNotSearched(): void
    {
        $queue = $this->queue($this->files(2, 100), batchFiles: 8, batchBytes: 10_000);
        $batch = $queue->next();

        $response = captureThrows(
            fn() => $queue->searchFrame($batch, $this->frame(false, "it was the worker's response to the request"), [], self::SCANNER),
            WorkerException::class,
        );
        $afterAll = captureThrows(
            fn() => $queue->searchFrame($batch, $this->frame(), $this->answers(['f00', 'f01']), self::SCANNER),
            WorkerException::class,
        );

        assertContains("belongs to no file (it was the worker's response to the request)", $response->getMessage());
        assertContains('belongs to no file (the worker had already answered every file in the batch)', $afterAll->getMessage());
    }

    public function testAnOrdinaryRetryHalvesTheBatchAndLowersTheSettledBudget(): void
    {
        $sourceBytes = 10_000;
        $queue = new ScanBatchQueue($this->files(4, 100), $this->descriptor(8, 10_000), $sourceBytes);
        $batch = $queue->next();

        $queue->retry($batch, new WorkerException('WORKER_OUTPUT_LIMIT', 'too much'));

        // Half of the 400 bytes the batch held, not of its 10,000 budget.
        assertSame(200, $sourceBytes);
        assertSame([['f00', 'f01'], ['f02', 'f03']], $this->drain($queue));
    }

    public function testAFrameSearchLeavesTheSettledBudgetAlone(): void
    {
        $sourceBytes = 10_000;
        $queue = new ScanBatchQueue($this->files(4, 100), $this->descriptor(8, 10_000), $sourceBytes);

        $queue->searchFrame($queue->next(), $this->frame(), [], self::SCANNER);

        assertSame(10_000, $sourceBytes);
    }

    public function testAnOrdinaryRetryIsBoundedPerLineageNotPerLanguage(): void
    {
        $sourceBytes = 10_000;
        $queue = new ScanBatchQueue($this->files(64, 10), $this->descriptor(64, 10_000), $sourceBytes);
        $error = new WorkerException('WORKER_OUTPUT_LIMIT', 'too much');
        $batch = $queue->next();
        for ($halving = 0; $halving < WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS; ++$halving) {
            $queue->retry($batch, $error);
            $batch = $queue->next();
        }

        $thrown = captureThrows(static fn() => $queue->retry($batch, $error), WorkerException::class);

        assertSame(4, count($batch['files']));
        assertContains('after 4 retries in smaller batches', $thrown->getMessage());
    }

    public function testTheRequestCapIsNamedWhenReached(): void
    {
        $sourceBytes = 10_000;
        $queue = new ScanBatchQueue($this->files(4, 100), $this->descriptor(2, 10_000), $sourceBytes, requestCap: 1);
        $queue->next();
        $queue->failed(new WorkerException('WORKER_OUTPUT_LIMIT', 'too much'));

        $error = captureThrows(static fn() => $queue->next(), WorkerException::class);

        assertSame('WORKER_REQUEST_CAP', $error->diagnosticCode);
        assertContains('after 1 requests, the cap for 4 files in 2 batches', $error->getMessage());
        assertContains('The last failure was: too much', $error->getMessage());
        assertSame(2 * 4 + 4 * 2, ScanBatchQueue::requestCap(4, 2));
    }

    /** @return list<object> */
    private function files(int $count, int $size): array
    {
        $files = [];
        for ($index = 0; $index < $count; ++$index) {
            $file = new \stdClass();
            $file->relativePath = sprintf('f%02d', $index);
            $file->size = $size;
            $files[] = $file;
        }

        return $files;
    }

    /** @param list<object> $files */
    private function queue(array $files, int $batchFiles, int $batchBytes): ScanBatchQueue
    {
        $sourceBytes = $batchBytes;

        return new ScanBatchQueue($files, $this->descriptor($batchFiles, $batchBytes), $sourceBytes);
    }

    private function descriptor(int $batchFiles, int $batchBytes): LanguageDescriptor
    {
        return new LanguageDescriptor(
            key: 'typescript',
            stage: 'typescript-analysis',
            languages: ['typescript'],
            command: ['node'],
            scanBatchFiles: $batchFiles,
            scanBatchSourceBytes: $batchBytes,
        );
    }

    private function frame(?bool $isAFilesAnswer = null, ?string $description = null): WorkerException
    {
        return new WorkerException('WORKER_FRAME_TOO_LARGE', 'too large', frameIsAFilesAnswer: $isAFilesAnswer, frameDescription: $description);
    }

    /**
     * @param list<string> $paths
     * @return list<ScanContribution>
     */
    private function answers(array $paths): array
    {
        return array_map(static fn(string $path): ScanContribution => new ScanContribution(self::SCANNER . ':file:' . $path), $paths);
    }

    /** @return list<list<string>> every batch left, as paths, in the order they would be sent */
    private function drain(ScanBatchQueue $queue): array
    {
        $batches = [];
        while (($item = $queue->next()) !== null) {
            $batches[] = array_map(static fn(object $file): string => $file->relativePath, $item['files']);
        }

        return $batches;
    }
}
