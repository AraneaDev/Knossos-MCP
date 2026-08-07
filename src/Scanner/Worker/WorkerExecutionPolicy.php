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
     * Most source bytes sent in one scan request.
     *
     * This is what actually guards `max_output_bytes`. Protocol output tracks
     * source SIZE, not file count: measured over three generated TypeScript
     * corpora of very different symbol density (6.3, 22.6 and 74.1 KB of NDJSON
     * per file) the ratio of output bytes to source bytes stayed at 15.2-15.9x,
     * while this repository's own PHP source expands only ~2.2x. Batching by
     * file count alone is therefore unsafe: 400 files of dense TypeScript
     * emitted 29.6 MB against a 20 MB cap, and 400 files of sparse TypeScript
     * emitted 2.5 MB — the same count, a 12x spread.
     *
     * 4 MB at PHP's ~2.2x expansion is ~9 MB of output, comfortably under half
     * the 20 MB cap. Each language sets its own value from its own measured
     * expansion so every batch targets that same output share.
     */
    public const SCAN_BATCH_SOURCE_BYTES = 4_000_000;

    public function __construct(
        public int $requestTimeoutMs = self::DEFAULT_REQUEST_TIMEOUT_MS,
    ) {
        if ($requestTimeoutMs < self::MIN_REQUEST_TIMEOUT_MS || $requestTimeoutMs > self::MAX_REQUEST_TIMEOUT_MS) {
            throw new InvalidArgumentException(sprintf(
                'worker_timeout_ms must be between %d and %d.',
                self::MIN_REQUEST_TIMEOUT_MS,
                self::MAX_REQUEST_TIMEOUT_MS,
            ));
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
            'scan_batch_files' => self::SCAN_BATCH_FILES,
            'scan_batch_source_bytes' => self::SCAN_BATCH_SOURCE_BYTES,
        ];
    }
}
