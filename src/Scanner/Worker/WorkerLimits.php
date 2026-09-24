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
     *     Since the TypeScript worker reports `node_modules` reads, those
     *     resolution candidates, not discovered files, dominate the map on a
     *     real project: about 23,000 keys and 1.7 MB per request against an
     *     869 MB `node_modules`, still far under 64 MB.
     */
    public function __construct(
        public int $requestTimeoutMs = WorkerExecutionPolicy::DEFAULT_REQUEST_TIMEOUT_MS,
        // A single source file can legitimately produce a dense contribution
        // just over one megabyte (large generated type declarations are a
        // common example). Keep the frame finite, but leave enough headroom for
        // those contributions without degrading an otherwise healthy language.
        public int $maxLineBytes = 2_000_000,
        public int $maxOutputBytes = 20_000_000,
        public int $maxStderrBytes = 100_000,
        public int $maxInputHashesBytes = 64_000_000,
        // How long a request may run however busy the worker says it is.
        // `requestTimeoutMs` is how long it may stay silent: every message it
        // sends during a scan restarts that wait, up to this cap.
        public int $maxRequestMs = WorkerExecutionPolicy::MAX_REQUEST_TIMEOUT_MS,
    ) {
        if ($requestTimeoutMs < 1 || $maxLineBytes < 128 || $maxOutputBytes < $maxLineBytes || $maxStderrBytes < 0 || $maxInputHashesBytes < 0 || $maxRequestMs < 1) {
            throw new InvalidArgumentException('Worker limits are invalid.');
        }
    }
}
