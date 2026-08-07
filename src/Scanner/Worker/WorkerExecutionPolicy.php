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
     * Files sent per scan request.
     *
     * Every request gets its own byte budget and deadline, so this is what keeps
     * both proportional to a batch rather than to the project. Measured on this
     * repository: ~14.9 KB of protocol output per PHP file, so 400 files is
     * ~6 MB against the 20 MB cap, and ~1.7 s against the 30 s default.
     */
    public const SCAN_BATCH_FILES = 400;

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
        ];
    }
}
