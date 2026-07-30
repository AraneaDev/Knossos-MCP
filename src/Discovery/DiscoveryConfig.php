<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use InvalidArgumentException;

/**
 * Limits and ignore rules for one discovery pass.
 *
 * The caps are a safety boundary, not tuning: discovery walks whatever tree it is
 * pointed at, and an unbounded walk of a huge or pathological directory is how a
 * local-first server becomes unusable.
 */
final readonly class DiscoveryConfig
{
    /**
     * @param list<string> $allowedRoots
     * @param list<string> $ignorePatterns
     */
    public function __construct(
        public array $allowedRoots,
        public array $ignorePatterns = [],
        public int $maxFiles = 100_000,
        public int $maxFileBytes = 2_000_000,
    ) {
        if ($allowedRoots === []) {
            throw new InvalidArgumentException('At least one allowed root is required.');
        }
        if ($maxFiles < 1 || $maxFileBytes < 1) {
            throw new InvalidArgumentException('Discovery limits must be positive.');
        }
    }
}
