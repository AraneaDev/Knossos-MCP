<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use RuntimeException;
use Throwable;

/**
 * A worker failed, carrying a stable diagnostic code.
 *
 * The code is what the scan report surfaces, so an operator can tell a timeout from
 * a crash from a protocol mismatch without reading a stack trace.
 */
final class WorkerException extends RuntimeException
{
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
