<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use InvalidArgumentException;

/** Resource caps applied to a worker process, so an analyser cannot exhaust the host. */
final readonly class WorkerLimits
{
    /**
     * @param int $maxInputHashesBytes Bytes of `scan/input_hashes` frames one
     *     scan request may send. Counted apart from $maxOutputBytes because the
     *     map describes the files a request read, which for a TypeScript
     *     program is the whole program whatever the batch size: counting it as
     *     output would make splitting a batch look like a remedy when it cannot
     *     be one. The default is sized for the largest tree a scan accepts:
     *     100,000 discovered files (the `max_files` ceiling) at roughly 110
     *     bytes per entry is 11 MB, and 64 MB leaves room for long paths and
     *     for the null entries of import candidates probed and found absent,
     *     while still bounding what a runaway worker can make the core hold.
     */
    public function __construct(
        public int $requestTimeoutMs = WorkerExecutionPolicy::DEFAULT_REQUEST_TIMEOUT_MS,
        public int $maxLineBytes = 1_000_000,
        public int $maxOutputBytes = 20_000_000,
        public int $maxStderrBytes = 100_000,
        public int $maxInputHashesBytes = 64_000_000,
    ) {
        if ($requestTimeoutMs < 1 || $maxLineBytes < 128 || $maxOutputBytes < $maxLineBytes || $maxStderrBytes < 0 || $maxInputHashesBytes < 0) {
            throw new InvalidArgumentException('Worker limits are invalid.');
        }
    }
}
