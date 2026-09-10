<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * The requested path is not a directory this process can reach.
 *
 * A subclass rather than a flag on {@see DiscoveryException} so that every
 * existing `catch (DiscoveryException)` keeps catching it unchanged, while a
 * caller that needs to tell the two refusals apart can name this one. The
 * distinction is not pedantry: "outside the allowed roots" is fixed by adding
 * a root, and "no such directory" is not, so a caller that reads the second as
 * the first hands its user a remedy that cannot work.
 */
final class RootNotFoundException extends DiscoveryException {}
