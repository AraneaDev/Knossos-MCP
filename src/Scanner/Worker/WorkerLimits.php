<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use InvalidArgumentException;

final readonly class WorkerLimits
{
    public function __construct(
        public int $requestTimeoutMs = WorkerExecutionPolicy::DEFAULT_REQUEST_TIMEOUT_MS,
        public int $maxLineBytes = 1_000_000,
        public int $maxOutputBytes = 20_000_000,
        public int $maxStderrBytes = 100_000,
    ) {
        if ($requestTimeoutMs < 1 || $maxLineBytes < 128 || $maxOutputBytes < $maxLineBytes || $maxStderrBytes < 0) {
            throw new InvalidArgumentException('Worker limits are invalid.');
        }
    }
}
