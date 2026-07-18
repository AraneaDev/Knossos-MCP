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

final class CliErrorRenderer
{
    public function render(Throwable $error): int
    {
        $code = match (true) {
            $error instanceof WorkerException => $error->diagnosticCode,
            $error instanceof ScanBusyException => 'KNOSSOS_SCAN_BUSY',
            $error instanceof ScanCancelledException => 'KNOSSOS_SCAN_CANCELLED',
            $error instanceof DiscoveryException => 'KNOSSOS_DISCOVERY_ERROR',
            $error instanceof PDOException => 'KNOSSOS_STORAGE_ERROR',
            $error instanceof InvalidArgumentException => 'KNOSSOS_INVALID_ARGUMENT',
            default => 'KNOSSOS_RUNTIME_ERROR',
        };
        fwrite(STDERR, $code . ': ' . $error->getMessage() . PHP_EOL);
        return 2;
    }
}
