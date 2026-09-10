<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use RuntimeException;

/**
 * Discovery cannot proceed: a missing root, or one outside the allow-list.
 *
 * Deliberately not final, and the only class in Discovery that is not. It is
 * the type every caller catches, so a narrower refusal has to be reachable
 * through it: {@see RootNotFoundException} says which of those two failures
 * happened without asking a single one of those catch sites to change.
 * Subclasses stay inside this namespace; nothing outside it may widen what
 * `catch (DiscoveryException)` means.
 */
class DiscoveryException extends RuntimeException {}
