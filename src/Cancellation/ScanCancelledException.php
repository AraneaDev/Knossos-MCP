<?php

declare(strict_types=1);

namespace Knossos\Cancellation;

use RuntimeException;

/** The scan stopped because cancellation was requested, not because anything failed. */
final class ScanCancelledException extends RuntimeException {}
