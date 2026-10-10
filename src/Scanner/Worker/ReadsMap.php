<?php

declare(strict_types=1);

namespace Knossos\Scanner\Worker;

use InvalidArgumentException;
use Knossos\Scanner\Protocol\RelativePath;

/**
 * The shape of a `reads` map, on a contribution or on a scan result: the
 * files a worker that attributes its reads says a contribution, or every
 * contribution of the request, was derived from.
 */
final class ReadsMap
{
    private function __construct() {}

    /**
     * Validate a `reads` object: project-relative paths to a SHA-256 or null.
     *
     * A key discovery cannot carry is dropped, as it is from `input_hashes`, so
     * the two stay comparable entry for entry.
     *
     * @return array<string, ?string>
     * @throws WorkerException WORKER_CONTRIBUTION_INVALID for a map that is not an object of paths to a SHA-256 or null
     */
    public static function decode(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new WorkerException('WORKER_CONTRIBUTION_INVALID', 'reads must be an object keyed by path.');
        }
        $reads = [];
        foreach ($value as $key => $hash) {
            $path = (string) $key;
            if (!RelativePath::isSupported($path)) {
                continue;
            }
            try {
                RelativePath::assertValid($path, 'reads key');
            } catch (InvalidArgumentException $error) {
                throw new WorkerException('WORKER_CONTRIBUTION_INVALID', $error->getMessage(), $error);
            }
            if ($hash !== null && (!is_string($hash) || preg_match('/\A[0-9a-f]{64}\z/', $hash) !== 1)) {
                throw new WorkerException('WORKER_CONTRIBUTION_INVALID', sprintf('reads for %s must be null or a lowercase SHA-256 hex digest.', $path));
            }
            $reads[$path] = $hash;
        }

        return $reads;
    }
}
