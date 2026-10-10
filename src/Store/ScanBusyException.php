<?php

declare(strict_types=1);

namespace Knossos\Store;

use RuntimeException;

/** Another scan holds this project's write lease; the caller should retry rather than wait. */
final class ScanBusyException extends RuntimeException {}
