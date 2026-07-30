<?php

declare(strict_types=1);

namespace Knossos\Scanner\Protocol;

use InvalidArgumentException;
use JsonSerializable;

/**
 * Something a scanner could not analyse, reported rather than thrown.
 *
 * A file it cannot parse must not fail the scan: the rest of the graph is still
 * worth having, and the caller needs to know the answer is incomplete and where.
 */
final readonly class Diagnostic implements JsonSerializable
{
    public function __construct(
        public string $severity,
        public string $code,
        public string $message,
        public ?Evidence $evidence = null,
    ) {
        if (!in_array($severity, ['info', 'warning', 'error'], true)) {
            throw new InvalidArgumentException('Diagnostic severity is invalid.');
        }

        if ($code === '' || $message === '') {
            throw new InvalidArgumentException('Diagnostic code and message must not be empty.');
        }
    }

    /**
     * The wire shape of the diagnostic.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return array_filter([
            'severity' => $this->severity,
            'code' => $this->code,
            'message' => $this->message,
            'evidence' => $this->evidence,
        ], static fn(mixed $value): bool => $value !== null);
    }
}
