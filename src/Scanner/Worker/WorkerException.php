<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use RuntimeException;
use Throwable;

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
