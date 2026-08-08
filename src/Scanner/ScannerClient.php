<?php

declare(strict_types=1);

namespace Knossos\Scanner;

use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;

/**
 * Contract for talking to a language scanner.
 *
 * Abstracted so the scan pipeline does not care that workers are separate
 * processes, and so tests can exercise protocol failures that a real worker will
 * not produce on request.
 */
interface ScannerClient
{
    /** Negotiate the worker contract before any project input is sent. */
    public function initialize(): ScannerManifest;

    /**
     * Stream owned facts for a bounded, validated scan request.
     *
     * @param array<string, mixed> $request
     * @return iterable<ScanContribution>
     */
    public function scan(array $request): iterable;

    /**
     * Request cooperative cancellation of an in-flight worker operation.
     *
     * @param int|string $requestId Widened to match the id the session sends
     * verbatim: an int scan id must not be stringified, or a type-strict worker
     * will never match the in-flight request.
     */
    public function cancel(int|string $requestId): void;

    /** Shut down the worker and release its complete process tree. */
    public function shutdown(): void;
}
