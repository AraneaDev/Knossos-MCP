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

    public function limits(): WorkerLimits
    {
        return new WorkerLimits(requestTimeoutMs: $this->requestTimeoutMs);
    }

    /** @return array<string, int> */
    public function metadata(): array
    {
        $limits = $this->limits();

        return [
            'request_timeout_ms' => $limits->requestTimeoutMs,
            'maximum_request_timeout_ms' => self::MAX_REQUEST_TIMEOUT_MS,
            'max_line_bytes' => $limits->maxLineBytes,
            'max_output_bytes' => $limits->maxOutputBytes,
            'max_stderr_bytes' => $limits->maxStderrBytes,
        ];
    }
}
