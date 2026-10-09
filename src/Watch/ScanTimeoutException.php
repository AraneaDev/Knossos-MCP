<?php

declare(strict_types=1);

namespace Knossos\Watch;

use RuntimeException;

/**
 * A watch scan ran past its time limit and was stopped.
 *
 * Its own class so the watcher can tell a timeout from any other transient
 * failure: a scan that outlived its limit once may have met a load spike,
 * but the same timeout again and again means the scan is too large for its
 * limit, and retrying it forever never converges.
 */
final class ScanTimeoutException extends RuntimeException {}
