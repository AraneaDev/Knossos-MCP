<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use InvalidArgumentException;

/** Defines the finite execution budget used by production scanner workers. */
final readonly class WorkerExecutionPolicy
{
    public const DEFAULT_REQUEST_TIMEOUT_MS = 30_000;
    public const MIN_REQUEST_TIMEOUT_MS = 1_000;
    public const MAX_REQUEST_TIMEOUT_MS = 120_000;

    /**
     * Most files sent in one scan request.
     *
     * Every request gets its own byte budget and deadline, so a batch is what
     * keeps both proportional to a unit of work rather than to the project.
     * This cap guards the deadline: PHP parses at ~4.2 ms/file, so 400 files is
     * ~1.7 s against the 30 s default. It is a ceiling, not a target — the
     * source-byte budget below usually binds first, and a language whose cost
     * is dominated by whole-program work rather than by file count raises it
     * (see LanguageDescriptor::defaults()).
     */
    public const SCAN_BATCH_FILES = 400;

    /**
     * Most source bytes sent in one scan request, before any adaptation.
     *
     * This is the second guard on `max_output_bytes`, and it is deliberately
     * OPTIMISTIC rather than worst-case, because no static estimate of protocol
     * output survives every codebase. Measured expansion (output bytes / source
     * bytes) over real hand-written code:
     *
     * - KaTeX `src/*.ts`, 38 files / 392 KB — 1.68x
     * - KaTeX + this repository's own worker sources, 366 files / 2.7 MB — 1.81x
     * - this repository's PHP `src/`, 154 files / 1.0 MB — 2.24x
     * - this repository's Python worker and tests, 5 files / 71 KB — 1.88x
     *
     * and over generated corpora of very small files:
     *
     * - benchmark corpus Python, 1,001 files averaging 132 B — 15.69x
     * - benchmark corpus PHP, 1,000 files averaging 175 B — 9.13x
     * - a dense generated TypeScript corpus, 400 files / 1.9 MB — 15.30x
     *
     * The first group is what real projects look like; the second is what a
     * generator produces. The spread is not language-specific — it is dominated
     * by a fixed ~1.8 KB of protocol output per file, which swamps the source
     * term when files are tiny, and by declared-symbol density, which the dense
     * corpus maximises. That is why batches are bounded on BOTH axes: the file
     * cap covers the many-tiny-files regime and this budget covers the
     * large-source regime.
     *
     * Neither axis can be sized for the worst case without making the common
     * case slow, so overflow is handled rather than prevented: see
     * MAX_SCAN_BATCH_HALVINGS.
     */
    public const SCAN_BATCH_SOURCE_BYTES = 4_000_000;

    /**
     * How many times a language's source-byte budget may be halved and the
     * batch retried after `WORKER_OUTPUT_LIMIT`.
     *
     * The budget above is optimistic on purpose, so it will occasionally be
     * wrong — a codebase dense enough in declared symbols emits far more per
     * source byte than any measurement predicted. Rather than sizing every scan
     * for that case, the runner halves the language's budget and re-splits the
     * offending batch, which corrects against the only authority that matters:
     * the cap actually being hit. Four halvings take TypeScript's 3 MB to
     * 187 KB, below even the worst measured density, after which the failure
     * falls through to the per-language degrade path unchanged.
     */
    public const MAX_SCAN_BATCH_HALVINGS = 4;

    public function __construct(
        public int $requestTimeoutMs = self::DEFAULT_REQUEST_TIMEOUT_MS,
        public ?int $workerMemoryMb = null,
    ) {
        if ($requestTimeoutMs < self::MIN_REQUEST_TIMEOUT_MS || $requestTimeoutMs > self::MAX_REQUEST_TIMEOUT_MS) {
            throw new InvalidArgumentException(sprintf(
                'worker_timeout_ms must be between %d and %d.',
                self::MIN_REQUEST_TIMEOUT_MS,
                self::MAX_REQUEST_TIMEOUT_MS,
            ));
        }
        if ($workerMemoryMb !== null && $workerMemoryMb < 64) {
            throw new InvalidArgumentException('worker_memory_mb must be at least 64.');
        }
    }
    /** The resource caps applied to a worker process. */

    public function limits(): WorkerLimits
    {
        return new WorkerLimits(requestTimeoutMs: $this->requestTimeoutMs);
    }

    /**
     * The policy as reported in the scan result, so a timeout is explicable after the fact.
     *
     * Deliberately carries no batch bounds: those are per language and, for the
     * source-byte budget, per scan, so a single pair of numbers here would
     * misreport whichever language actually differed. The scan result reports
     * them per language under `scan_batches` instead.
     *
     * @return array<string, int>
     */
    public function metadata(): array
    {
        $limits = $this->limits();

        return [
            'request_timeout_ms' => $limits->requestTimeoutMs,
            'maximum_request_timeout_ms' => self::MAX_REQUEST_TIMEOUT_MS,
            'max_line_bytes' => $limits->maxLineBytes,
            'max_output_bytes' => $limits->maxOutputBytes,
            'max_stderr_bytes' => $limits->maxStderrBytes,
            'max_scan_batch_halvings' => self::MAX_SCAN_BATCH_HALVINGS,
        ];
    }
}
