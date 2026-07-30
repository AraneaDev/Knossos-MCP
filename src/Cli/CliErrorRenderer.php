<?php

declare(strict_types=1);

namespace Knossos\Cli;

use InvalidArgumentException;
use Knossos\Discovery\DiscoveryException;
use Knossos\Scan\ScanBusyException;
use Knossos\Scan\ScanCancelledException;
use Knossos\Scanner\Worker\WorkerException;
use PDOException;
use Throwable;

/**
 * Renders failures as a stable diagnostic code plus a human message.
 *
 * The codes are the contract CI scripts match on, so they are deliberately more
 * durable than the prose beside them.
 */
final class CliErrorRenderer
{
    /** @var resource */
    private $stream;

    /**
     * @param resource|null $stream Destination for the diagnostic line; defaults
     *                              to the process STDERR.
     *
     * The stream is injectable so tests can render into an in-memory stream and
     * assert the emitted diagnostic code. That is not merely convenience: under
     * `infection --filter=<file>` Infection's InitialTestsRunner calls
     * `$process->stop()` on the FIRST byte the test process writes to STDERR
     * (Process::ERR), which SIGTERMs PHPUnit mid-suite ("PHPUnit reported an
     * exit code of 143") and aborts the whole mutation run before any mutant is
     * generated. A suite that writes real diagnostics to STDERR is therefore
     * un-mutation-testable, so tests must render into their own stream.
     */
    public function __construct($stream = null)
    {
        /** @var resource $target */
        $target = $stream ?? STDERR;
        $this->stream = $target;
    }

    /**
     * Stable diagnostic code for a throwable, independent of rendering. Exposed
     * so the classification can be asserted directly without a stream.
     */
    public static function diagnosticCode(Throwable $error): string
    {
        return match (true) {
            $error instanceof WorkerException => $error->diagnosticCode,
            $error instanceof ScanBusyException => 'KNOSSOS_SCAN_BUSY',
            $error instanceof ScanCancelledException => 'KNOSSOS_SCAN_CANCELLED',
            $error instanceof DiscoveryException => 'KNOSSOS_DISCOVERY_ERROR',
            $error instanceof PDOException => 'KNOSSOS_STORAGE_ERROR',
            $error instanceof InvalidArgumentException => 'KNOSSOS_INVALID_ARGUMENT',
            default => 'KNOSSOS_RUNTIME_ERROR',
        };
    }

    public function render(Throwable $error): int
    {
        fwrite($this->stream, self::diagnosticCode($error) . ': ' . $error->getMessage() . PHP_EOL);
        return 2;
    }
}
