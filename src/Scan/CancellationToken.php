<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Closure;

final class CancellationToken
{
    private bool $cancelled = false;

    public function __construct(private readonly ?Closure $poll = null) {}

    public function cancel(): void
    {
        $this->cancelled = true;
    }
    public function isCancelled(): bool
    {
        if (!$this->cancelled && $this->poll !== null && ($this->poll)()) {
            $this->cancelled = true;
        }
        return $this->cancelled;
    }
    public function throwIfCancelled(): void
    {
        if ($this->isCancelled()) {
            throw new ScanCancelledException('Scan was cancelled.');
        }
    }
}
