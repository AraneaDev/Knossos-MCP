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
    /** The two object formats Git names blobs by, and the only values $gitObjectHash may take. */
    private const OBJECT_FORMATS = ['sha1', 'sha256'];

    /**
     * @param list<string> $allowedRoots
     * @param list<string> $ignorePatterns
     * @param string $gitObjectHash the scanned repository's object format,
     *        which decides how discovery names the blob id it records for each
     *        file. A repository created with `--object-format=sha256` names
     *        every object by SHA-256, and a blob id computed the other way
     *        matches nothing in its trees, so the whole drift comparison it
     *        feeds silently reports every tracked file as dirty. Defaulted
     *        rather than required because a caller with no repository to match
     *        never compares the value against anything.
     */
    public function __construct(
        public array $allowedRoots,
        public array $ignorePatterns = [],
        public int $maxFiles = 100_000,
        public int $maxFileBytes = 2_000_000,
        public string $gitObjectHash = 'sha1',
    ) {
        if ($allowedRoots === []) {
            throw new InvalidArgumentException('At least one allowed root is required.');
        }
        if ($maxFiles < 1 || $maxFileBytes < 1) {
            throw new InvalidArgumentException('Discovery limits must be positive.');
        }
        // Rejected here rather than at the hash call: an unknown algorithm
        // would throw from inside the per-file loop, failing a scan halfway
        // through for a value the caller chose once.
        if (!in_array($gitObjectHash, self::OBJECT_FORMATS, true)) {
            throw new InvalidArgumentException('Git object hash must be sha1 or sha256.');
        }
    }
}
