<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\RelativePath;

/**
 * The shape and merge rules of an `input_hashes` map, wherever it arrives.
 *
 * A worker reports what it read in the final scan result's `input_hashes`
 * field and, when the map would not fit on one frame, in any number of
 * `scan/input_hashes` notifications before it. Every one of those maps is held
 * to the same shape, and they are merged into one map per request before the
 * core compares it with discovery.
 *
 * The channel decodes JSON into PHP arrays, which turns a numeric-string object
 * key into an int, and an object whose keys are exactly "0", "1", ... into a
 * list that cannot be told apart from a JSON array. Int keys are read back as
 * the path they spelled. A non-empty list is refused as malformed; see
 * {@see \Knossos\Scan\ScanInputHashes} for what that costs.
 */
final class InputHashesMap
{
    public const FIELD = 'input_hashes';

    private function __construct() {}

    /**
     * Validate one map and return it keyed by path.
     *
     * The whole map is checked before it is returned, so a malformed entry is
     * reported as such even when an earlier entry would have failed the scan.
     *
     * @return array<string, string|null> path to lowercase SHA-256 hex, or null for a failed read
     * @throws WorkerException WORKER_RESPONSE_INVALID naming the worker, for any map that is not the protocol's object
     */
    public static function decode(mixed $inputHashes, string $workerId): array
    {
        // `{}` decodes to an empty array, as does `[]`; both say nothing was
        // read. Any other list is not the object the protocol defines.
        if (!is_array($inputHashes) || ($inputHashes !== [] && array_is_list($inputHashes))) {
            throw self::invalid($workerId, sprintf('sent %s that is not an object; it must be an object keyed by path', self::FIELD));
        }
        $reads = [];
        foreach ($inputHashes as $key => $hash) {
            // A key like "123" arrives as the int 123; it is still that path.
            $path = (string) $key;
            try {
                RelativePath::assertValid($path, self::FIELD . ' key ' . $path);
            } catch (InvalidArgumentException $error) {
                throw self::invalid($workerId, 'sent ' . lcfirst(rtrim($error->getMessage(), '.')));
            }
            if ($hash !== null && (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1)) {
                throw self::invalid($workerId, sprintf('sent %s for %s that is neither null nor a lowercase SHA-256 hex digest', self::FIELD, $path));
            }
            $reads[$path] = $hash;
        }

        return $reads;
    }

    /**
     * Union two decoded maps of the same request.
     *
     * A path both maps name with different values was read more than once with
     * differing results, so at least one of those reads disagrees with
     * discovery or failed; it becomes null, the value that fails the scan for a
     * discovered path. The same value twice is simply that value.
     *
     * @param array<array-key, string|null> $into
     * @param array<array-key, string|null> $more
     * @return array<string, string|null>
     */
    public static function merge(array $into, array $more): array
    {
        $merged = [];
        foreach ($into as $path => $hash) {
            $merged[(string) $path] = $hash;
        }
        foreach ($more as $path => $hash) {
            $path = (string) $path;
            $merged[$path] = array_key_exists($path, $merged) && $merged[$path] !== $hash ? null : $hash;
        }

        return $merged;
    }

    /** A malformed map, which costs the worker's language rather than the scan. */
    public static function invalid(string $workerId, string $detail): WorkerException
    {
        return new WorkerException('WORKER_RESPONSE_INVALID', sprintf('%s %s.', $workerId, $detail));
    }
}
