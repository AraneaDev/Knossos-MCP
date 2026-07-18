<?php

declare(strict_types=1);

namespace Knossos\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;

interface ScannerClient
{
    /** Negotiate the worker contract before any project input is sent. */
    public function initialize(): ScannerManifest;

    /**
     * Discover language configuration within one validated project root.
     *
     * @param array<string, mixed> $project
     * @return array<string, mixed>
     */
    public function discover(array $project): array;

    /**
     * Stream owned facts for a bounded, validated scan request.
     *
     * @param array<string, mixed> $request
     * @return iterable<ScanContribution>
     */
    public function scan(array $request): iterable;

    /** Request cooperative cancellation of an in-flight worker operation. */
    public function cancel(string $requestId): void;

    /** Shut down the worker and release its complete process tree. */
    public function shutdown(): void;
}
