<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use RuntimeException;

/** Discovery cannot proceed: a missing root, or one outside the allow-list. */
final class DiscoveryException extends RuntimeException {}
