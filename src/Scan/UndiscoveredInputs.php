<?php

declare(strict_types=1);

namespace Knossos\Scan;

/**
 * Worker reads of files discovery never hashed, accumulated across a scan.
 *
 * {@see ScanInputHashes::verify()} checks a read of a discovered file against
 * discovery's hash on the spot. A read of anything else, such as a
 * `node_modules` declaration, a module under an ignored path, a file over the
 * size cap or one that existed only for a moment, has no recorded hash to
 * disagree with, so it is collected here instead and re-read by
 * {@see UndiscoveredInputVerifier} just before the scan commits.
 *
 * The conflict rule is the one {@see \Knossos\Scanner\Worker\InputHashesMap::merge()}
 * applies inside one request, applied across requests: one path reported with
 * two different values, two hashes or a hash and a null, means at least one
 * read disagrees with the file as it is now. Inside a request that merge turns
 * the path null, which a discovered path fails on; across requests there is no
 * later check a null would fail against a file that happens to be absent at
 * commit, so the conflict fails the scan here, with
 * {@see ScanSnapshotChangedException::inputReadInconsistently()}, as soon as the
 * second value arrives.
 */
final class UndiscoveredInputs
{
    /** @var array<string, string|null> */
    private array $inputs = [];

    /**
     * Fold one map in, refusing it whole when any path conflicts.
     *
     * @param array<array-key, string|null> $entries path to SHA-256 hex, or null for a failed read
     * @throws ScanSnapshotChangedException when a path already holds a different value
     */
    public function add(array $entries): void
    {
        // Checked before anything is written, so a refused map leaves no half
        // merged state behind for a caller that inspects it afterwards.
        foreach ($entries as $path => $hash) {
            $path = (string) $path;
            if (array_key_exists($path, $this->inputs) && $this->inputs[$path] !== $hash) {
                throw ScanSnapshotChangedException::inputReadInconsistently($path);
            }
        }
        foreach ($entries as $path => $hash) {
            $this->inputs[(string) $path] = $hash;
        }
    }

    /**
     * Every path collected so far, each with the one value every request reported for it.
     *
     * @return array<string, string|null>
     */
    public function all(): array
    {
        return $this->inputs;
    }
}
