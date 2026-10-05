<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerExecutionPolicy;

/**
 * The scan requests one language still has to send, and what retrying them
 * may cost.
 *
 * Three bounds, kept apart. An ordinary retry (an output overflow, a
 * memory-pressure kill, heap exhaustion) is bounded per batch lineage by
 * MAX_SCAN_BATCH_HALVINGS, so many batches that each overflow once all
 * finish. A search for the one file behind an oversized frame is bounded per
 * language: the halving bound plus two blind binary searches over its files,
 * enough to isolate two oversized frames the worker's answers do not narrow,
 * and far short of searching a broken worker's batch file by file. And a hard
 * cap on requests keeps any combination of the two linear in the files.
 */
final class ScanBatchQueue
{
    /** @var list<array{files: list<object>, budget: int, halvings: int, retries: int}> */
    private array $pending;

    private readonly int $requestCap;

    private readonly int $initialBatches;

    private readonly int $searchAllowance;

    private int $requests = 0;

    private int $searchRetries = 0;

    private ?WorkerException $lastFailure = null;

    /**
     * The narrowest budget an ordinary retry settled on, written through to
     * the caller's variable so it is current even when a retry throws.
     */
    private int $sourceBytes;

    /**
     * @param list<object> $files the files the language must scan
     * @param ?int $requestCap overrides {@see self::requestCap()}
     */
    public function __construct(
        private readonly array $files,
        private readonly LanguageDescriptor $descriptor,
        int &$sourceBytes,
        ?int $requestCap = null,
    ) {
        $this->sourceBytes = &$sourceBytes;
        $full = $descriptor->scanBatchSourceBytes;
        $this->pending = self::queued(self::batches($files, $descriptor->scanBatchFiles, $full), $full, 0, 0);
        $this->initialBatches = count($this->pending);
        $this->requestCap = $requestCap ?? self::requestCap(count($files), $this->initialBatches);
        $this->searchAllowance = WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS
            + 2 * (int) ceil(log(max(2, count($files)), 2));
    }

    /**
     * The next batch to send, or null when none is left.
     *
     * @return ?array{files: list<object>, budget: int, halvings: int, retries: int}
     * @throws WorkerException WORKER_REQUEST_CAP once the language has sent all it may
     */
    public function next(): ?array
    {
        $item = array_shift($this->pending);
        if ($item === null) {
            return null;
        }
        if (++$this->requests > $this->requestCap) {
            throw new WorkerException(
                'WORKER_REQUEST_CAP',
                sprintf(
                    'Knossos stopped scanning this language after %d requests, the cap for %d files in %d batches '
                    . '(two requests per file plus four per batch), so that no retry path grows past linear. The last '
                    . 'failure was: %s',
                    $this->requestCap,
                    count($this->files),
                    $this->initialBatches,
                    $this->lastFailure?->getMessage() ?? 'none',
                ),
                $this->lastFailure,
            );
        }

        return $item;
    }

    /** Remember a failed request, for the diagnostic if the cap is reached later. */
    public function failed(WorkerException $error): void
    {
        $this->lastFailure = $error;
    }

    /**
     * Search a batch for the file whose answer did not fit one frame.
     *
     * The files the worker already answered are not it, so only the rest is
     * searched and the answered ones go back as one batch. A split that
     * confirmed at least as many files as it still suspects at least halves
     * the search and is free; any other draws on the language's allowance, or
     * a worker answering one file before each failure would be searched file
     * by file for nothing.
     *
     * @param array{files: list<object>, budget: int, halvings: int, retries: int} $item
     * @param list<ScanContribution> $received what the worker answered before failing
     * @return ?object the batch's one file, to be left out, or null once the search is queued
     * @throws WorkerException when the frame belongs to no file, or the allowance is spent
     */
    public function searchFrame(array $item, WorkerException $error, array $received, string $scannerId): ?object
    {
        $answered = [];
        foreach ($received as $contribution) {
            $answered[$contribution->ownerKey] = true;
        }
        $isAnswered = static fn(object $file): bool => isset($answered[$scannerId . ':file:' . $file->relativePath]);
        $unanswered = array_values(array_filter($item['files'], static fn(object $file): bool => !$isAnswered($file)));
        $done = array_values(array_filter($item['files'], $isAnswered));
        if ($error->frameIsAFilesAnswer === false || $unanswered === []) {
            // No file's answer was too large, so neither splitting nor leaving
            // a file out can help, and the second would cache an innocent file
            // as too large.
            throw new WorkerException(
                $error->diagnosticCode,
                sprintf(
                    '%s The oversized frame belongs to no file (%s), so no file is left out over it.',
                    $error->getMessage(),
                    $error->frameIsAFilesAnswer === false
                        ? ($error->frameDescription ?? "it was not a file's contribution")
                        : 'the worker had already answered every file in the batch',
                ),
                $error,
            );
        }
        if (count($item['files']) === 1) {
            return $item['files'][0];
        }
        if (count($done) < count($unanswered)) {
            $this->chargeSearch($error);
        }
        // Halved only to isolate the file, so the language's budget is not
        // lowered: none of its batches settled on this one.
        $budget = self::halvedBudget($unanswered, $item['budget']);
        $this->pending = [
            ...self::queued(self::batches($unanswered, $this->halfCount($unanswered), $budget), $budget, $item['halvings'], $item['retries'] + 1),
            ...($done === [] ? [] : self::queued([$done], $item['budget'], $item['halvings'], $item['retries'] + 1)),
            ...$this->pending,
        ];

        return null;
    }

    /**
     * Re-split a batch a smaller one can get past, at half what it held.
     *
     * Only the batch that failed is re-split, and only its own descendants
     * inherit the reduced budget. Carrying the reduction across the rest of
     * the language would let one pathological directory pin a whole
     * repository at a fraction of its budget, which for TypeScript means
     * rebuilding the program per request. The cost of keeping the others at
     * full width is one doomed request each if they fail too: a tax, not a
     * cliff.
     *
     * @param array{files: list<object>, budget: int, halvings: int, retries: int} $item
     * @throws WorkerException once the batch's lineage has used its halvings
     */
    public function retry(array $item, WorkerException $error): void
    {
        if ($item['halvings'] >= WorkerExecutionPolicy::MAX_SCAN_BATCH_HALVINGS) {
            throw self::withRetries($error, $item['retries']);
        }
        $budget = self::halvedBudget($item['files'], $item['budget']);
        $this->sourceBytes = min($this->sourceBytes, $budget);
        $this->pending = [
            ...self::queued(self::batches($item['files'], $this->halfCount($item['files']), $budget), $budget, $item['halvings'] + 1, $item['retries'] + 1),
            ...$this->pending,
        ];
    }

    /**
     * The failure for a batch that cannot be retried, saying what was tried.
     *
     * @param array{files: list<object>, budget: int, halvings: int, retries: int} $item
     */
    public static function givenUp(array $item, WorkerException $error): WorkerException
    {
        return self::withRetries(self::forOneFile($error, count($item['files'])), $item['retries']);
    }

    /** Draw one blind frame-search split from the allowance, or give up when it is spent. */
    private function chargeSearch(WorkerException $error): void
    {
        if ($this->searchRetries >= $this->searchAllowance) {
            throw new WorkerException(
                $error->diagnosticCode,
                sprintf(
                    '%s Knossos gave up after %d frame-search retries, the most a language of %d files to scan is '
                    . 'allowed. This many files whose answers do not fit one frame suggests the limit is set too low '
                    . 'for this project, or a broken worker, rather than that many oversized files.',
                    $error->getMessage(),
                    $this->searchRetries,
                    count($this->files),
                ),
                $error,
            );
        }
        ++$this->searchRetries;
    }

    /**
     * Split the files a language must scan into scan requests.
     *
     * Bounded on two axes because protocol output has two known terms. A fixed
     * cost per file — around 800 B to 1.8 KB — dominates on a project of many
     * tiny files, which is why the file count is capped; the rest scales with
     * how much source the request covers, which is why the cumulative byte
     * total is capped too. Neither alone is sufficient: generated 130-byte
     * files expand 15x where the real sources they imitate expand under 2x.
     *
     * Both terms together are still only a lower bound. They predict real
     * corpora within a few percent but cannot explain a corpus dense in
     * declared symbols: 400 TypeScript files of 1.9 MB emitted 29.6 MB, where
     * the per-file term accounts for 2.5% and the source term would need a
     * coefficient of ~15x against a measured 1.85x. Symbol density is simply
     * not modelled here, and no cheap pre-scan measurement of it exists. That
     * is why the byte budget adapts on WORKER_OUTPUT_LIMIT rather than trying
     * to predict: these bounds make the common case one request, and the retry
     * covers the case they cannot see coming.
     *
     * Returns the file objects rather than their paths so a batch the worker
     * rejected as too large can be re-split at a smaller budget.
     *
     * A file bigger than the whole byte budget still gets a request of its own
     * rather than an empty one; nothing smaller can be sent.
     *
     * @param list<object> $files
     * @return list<list<object>>
     */
    private static function batches(array $files, int $maxFiles, int $maxSourceBytes): array
    {
        $batches = [];
        $current = [];
        $bytes = 0;
        foreach ($files as $file) {
            // Test fixtures and any non-DiscoveredFile input carry no size; a
            // missing size only relaxes the byte axis, never the count axis.
            $size = isset($file->size) && is_int($file->size) ? $file->size : 0;
            if ($current !== [] && (count($current) >= $maxFiles || $bytes + $size > $maxSourceBytes)) {
                $batches[] = $current;
                $current = [];
                $bytes = 0;
            }
            $current[] = $file;
            $bytes += $size;
        }
        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * Wrap batches as queue items carrying the budget they were split at.
     *
     * The budget travels with the batch rather than with the language so a
     * reduction stays scoped to the work that provoked it: a batch's own
     * descendants inherit it, the rest of the language does not.
     *
     * @param list<list<object>> $batches
     * @param int $budget the source-byte budget these batches were split at
     * @param int $halvings how many reductions this lineage was charged against the bound
     * @param int $retries how many failed requests this lineage has been retried after, charged or not
     * @return list<array{files: list<object>, budget: int, halvings: int, retries: int}>
     */
    private static function queued(array $batches, int $budget, int $halvings, int $retries): array
    {
        return array_map(
            static fn(array $files): array => ['files' => $files, 'budget' => $budget, 'halvings' => $halvings, 'retries' => $retries],
            $batches,
        );
    }

    /**
     * The most requests one language may send in a scan: two per file plus
     * four per initial batch.
     *
     * Each mechanism that re-sends work is bounded on its own, but together
     * they are not obviously linear; this makes them so. Two per file covers
     * a batch split down to single files (2k - 1 requests for k files), and
     * four per batch covers the ordinary retries each batch may take.
     */
    public static function requestCap(int $files, int $batches): int
    {
        return 2 * $files + 4 * $batches;
    }

    /**
     * Half of what a failed batch actually held, in bytes, floored at 1.
     *
     * Halved from the batch's own bytes, not its budget: a batch using a
     * tenth of its budget would otherwise be re-sent whole while counting as
     * a smaller retry. Files with no recorded size leave the budget as it was.
     *
     * @param list<object> $files
     */
    private static function halvedBudget(array $files, int $budget): int
    {
        $bytes = array_sum(array_map(
            static fn(object $file): int => isset($file->size) && is_int($file->size) ? $file->size : 0,
            $files,
        ));

        return max(1, intdiv($bytes > 0 ? min($budget, $bytes) : $budget, 2));
    }

    /**
     * Half the file count, rounded up, so a batch shrinks even when its files
     * carry no size.
     *
     * @param list<object> $files
     */
    private function halfCount(array $files): int
    {
        return max(1, min($this->descriptor->scanBatchFiles, intdiv(count($files) + 1, 2)));
    }

    /**
     * Say how many smaller batches were tried before a failure was given up on.
     *
     * Without it a degraded language reads as if one request failed once,
     * and the obvious next step, retrying smaller, looks untried.
     *
     * @param int $retries how many failed requests the batch's lineage was
     *        retried after before this one, charged against the allowance or not
     */
    private static function withRetries(WorkerException $error, int $retries): WorkerException
    {
        if ($retries === 0) {
            return $error;
        }

        return new WorkerException(
            $error->diagnosticCode,
            sprintf(
                '%s This was after %d %s in smaller batches with a fresh worker.',
                $error->getMessage(),
                $retries,
                $retries === 1 ? 'retry' : 'retries',
            ),
            $error,
            $error->terminatingSignal,
        );
    }

    /**
     * Add that a request too large for a single file cannot be made smaller.
     *
     * The request frame limit is otherwise met by splitting, so its message
     * alone would leave the reader expecting a retry that cannot come.
     */
    private static function forOneFile(WorkerException $error, int $files): WorkerException
    {
        if ($files !== 1 || $error->diagnosticCode !== 'WORKER_REQUEST_TOO_LARGE') {
            return $error;
        }

        return new WorkerException(
            $error->diagnosticCode,
            $error->getMessage() . ' The batch held a single file, so a smaller batch cannot help.',
            $error,
        );
    }
}
