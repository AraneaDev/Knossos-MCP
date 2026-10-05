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
    /**
     * @param ?int $terminatingSignal the signal that killed the worker while it
     *        still owed a response, when one did. Knossos never sends that: it
     *        closes a worker only after a request has failed for a reason of its
     *        own, so a signal here came from outside the scan.
     */
    public function __construct(
        public readonly string $diagnosticCode,
        string $message,
        ?Throwable $previous = null,
        public readonly ?int $terminatingSignal = null,
    ) {
        parent::__construct($message, previous: $previous);
    }
}
