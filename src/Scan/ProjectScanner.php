<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Query\ResultEnvelope;

/**
 * Scan entry point consumed by orchestrators such as watch mode. Extracted so
 * long-running callers can be exercised against controlled scan outcomes.
 */
interface ProjectScanner
{
    /** @param list<array<string, mixed>>|null $explicitBoundaries */
    public function scan(
        string $root,
        ?string $name = null,
        ?int $maxFiles = null,
        ?int $maxFileBytes = null,
        ?array $explicitBoundaries = null,
        ?string $mode = null,
        ?CancellationToken $cancellation = null,
        ?int $snapshotRetention = null,
        ?int $workerTimeoutMs = null,
    ): ResultEnvelope;
}
