<?php

declare(strict_types=1);

namespace Knossos\Query;

/** Whether a rescan fits the latency budget, and if not, what to tell the caller. */
final readonly class RefreshDecision
{
    private function __construct(
        public bool $refresh,
        public ?string $reason,
    ) {}

    /** Named allow(), not refresh(), so it cannot be misread as the $refresh property beside it. */
    public static function allow(): self
    {
        return new self(true, null);
    }

    /** Declining is not a failure, so the reason is addressed to a caller who can still act on it. */
    public static function decline(string $reason): self
    {
        return new self(false, $reason);
    }
}
