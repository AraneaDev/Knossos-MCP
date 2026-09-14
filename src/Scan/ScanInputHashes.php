<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Scanner\Protocol\Protocol;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Scanner\Worker\InputHashesMap;
use Knossos\Scanner\Worker\WorkerException;

/**
 * Checks the files a worker read for another file's sake against what
 * discovery hashed.
 *
 * A contribution's own `content_hash` proves the file it describes was parsed
 * from the bytes discovery recorded. It proves nothing about the other files the
 * worker read to get there: Python resolves imports against a module index it
 * built by reading every module, and the TypeScript checker reads whole programs
 * to type one file. When one of those files is rewritten while the worker reads
 * it and restored before the scan re-checks it, every requested file still
 * hashes correctly and disk still matches the record, yet the edges resolved
 * against the rewritten bytes describe a tree that never existed. The graph is
 * then committed and reported `fresh`, which is the outcome this subsystem
 * exists to prevent.
 *
 * So each scan result may carry `input_hashes`, the hash of every such read, and
 * they are compared here before the request's contributions are kept. A map too
 * large for one frame arrives partly in `scan/input_hashes` notifications, which
 * {@see \Knossos\Scanner\Worker\ScannerProtocolSession} merges into the result's
 * field before this check sees it. The same
 * stance as {@see ContributionCacheService}'s per-contribution check holds: a
 * hash is evidence of a changed tree whoever sends it, and a hash that cannot be
 * verified is refused rather than trusted. A path discovery never hashed, such as
 * a dependency outside the scanned tree, has no recorded content to disagree with
 * and is ignored.
 *
 * One limitation comes from how frames are decoded. The channel decodes JSON
 * into PHP arrays, which turns a numeric-string object key into an int, and
 * an object whose keys are exactly "0", "1", ... into a list that cannot be told
 * apart from a JSON array. Int keys are read back as the path they spelled. A
 * non-empty list is refused as malformed, and so is a merged map that happens
 * to come out keyed that way, so a worker reporting a read of
 * root-level files named `0` (and `1`, ...) and nothing else degrades its
 * language. That costs a rerun on a tree nobody has; accepting lists instead
 * would let a worker that sends an array of hashes pass unverified.
 */
final class ScanInputHashes
{
    private const FIELD = 'input_hashes';

    private function __construct() {}

    /**
     * Verify one scan request's `input_hashes` against discovery.
     *
     * @param array<string, mixed> $result the request's final result
     * @param array<string, object> $discoveredByPath every file the scan discovered, keyed by relative path
     * @throws WorkerException when a declaring worker omitted the field, it is malformed, or a hash cannot be verified
     * @throws ScanSnapshotChangedException when a discovered file was read from other bytes, or could not be read
     */
    public static function verify(array $result, ScannerManifest $manifest, array $discoveredByPath): void
    {
        if (!array_key_exists(self::FIELD, $result)) {
            if (in_array(Protocol::CAPABILITY_INPUT_HASHES, $manifest->capabilities, true)) {
                throw self::invalid($manifest, sprintf('declares the %s capability but its scan result carries no %s', Protocol::CAPABILITY_INPUT_HASHES, self::FIELD));
            }

            return;
        }
        // Shape first, for the whole map, so a malformed entry is reported as
        // such even when an earlier one would have failed the scan.
        $reads = InputHashesMap::decode($result[self::FIELD], $manifest->id);
        foreach ($reads as $path => $hash) {
            $path = (string) $path;
            $file = $discoveredByPath[$path] ?? null;
            if ($file === null) {
                continue;
            }
            if ($hash === null) {
                throw ScanSnapshotChangedException::inputUnreadable($path);
            }
            $expected = $file->contentHash ?? null;
            if (!is_string($expected)) {
                throw self::invalid($manifest, sprintf('reported reading %s, but discovery recorded no hash for it, so the read cannot be verified', $path));
            }
            if (!hash_equals($expected, $hash)) {
                throw ScanSnapshotChangedException::inputReadDifferently($path);
            }
        }
    }

    /** A malformed or unverifiable result, which costs the worker's language rather than the scan. */
    private static function invalid(ScannerManifest $manifest, string $detail): WorkerException
    {
        return InputHashesMap::invalid($manifest->id, $detail);
    }
}
