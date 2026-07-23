<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Error;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scanner\Worker\WorkerException;
use Throwable;

/**
 * Runs a single watch-mode rescan and classifies the result into a typed
 * outcome so the watcher can stay alive through transient scanner or storage
 * faults instead of unwinding out of its poll loop and dropping pending work.
 */
final readonly class WatchScanAttempt
{
    public const SUCCESS = 'success';
    public const CANCELLED = 'cancelled';
    public const RETRYABLE = 'retryable';
    public const TERMINAL = 'terminal';

    /**
     * Worker diagnostic codes that reflect a transient fault (a crash, timeout,
     * or broken pipe) which may clear on the next attempt. Every other worker
     * failure — version/capability/schema mismatches, an oversized request, a
     * failed spawn — is a permanent misconfiguration that would recur
     * identically, so retrying it only floods diagnostics for the life of the
     * watch.
     *
     * @var list<string>
     */
    private const TRANSIENT_WORKER_CODES = [
        'WORKER_TIMEOUT',
        'WORKER_PIPE_BROKEN',
        'WORKER_IO_FAILED',
        'WORKER_EXITED',
    ];

    private function __construct(
        public string $outcome,
        public ?ResultEnvelope $result,
        public ?string $errorMessage,
    ) {}

    public static function run(
        ProjectScanner $scanner,
        string $root,
        string $mode,
        CancellationToken $cancellation,
    ): self {
        if ($cancellation->isCancelled()) {
            return new self(self::CANCELLED, null, null);
        }
        try {
            $result = $scanner->scan($root, mode: $mode, cancellation: $cancellation);
            return new self(self::SUCCESS, $result, null);
        } catch (ScanCancelledException) {
            return new self(self::CANCELLED, null, null);
        } catch (WorkerException $error) {
            // Classify worker faults by diagnostic code: a small allowlist of
            // transient codes stays retryable, permanent misconfigurations
            // become terminal so the watch does not retry them forever.
            $outcome = in_array($error->diagnosticCode, self::TRANSIENT_WORKER_CODES, true)
                ? self::RETRYABLE
                : self::TERMINAL;
            return new self($outcome, null, $error->getMessage());
        } catch (Error $error) {
            // Engine-level faults (type errors, undefined symbols) are programming
            // defects that will recur identically; retrying only floods diagnostics.
            return new self(self::TERMINAL, null, $error->getMessage());
        } catch (Throwable $error) {
            // Worker timeouts, busy write leases, disappearing files, and transient
            // storage failures all surface as exceptions and may clear on retry.
            return new self(self::RETRYABLE, null, $error->getMessage());
        }
    }

    public function isSuccess(): bool
    {
        return $this->outcome === self::SUCCESS;
    }

    public function isCancelled(): bool
    {
        return $this->outcome === self::CANCELLED;
    }

    public function isRetryable(): bool
    {
        return $this->outcome === self::RETRYABLE;
    }

    public function isTerminal(): bool
    {
        return $this->outcome === self::TERMINAL;
    }
}
