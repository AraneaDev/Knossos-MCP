<?php

declare(strict_types=1);

namespace Knossos\Scan;

/**
 * Decides which cached contributions an incremental scan must rebuild.
 *
 * An owner is stale when its own file changed or when any file it read now has
 * different bytes, including a file that did not exist when it was read and a
 * file that has since been deleted. A rebuilt owner counts as a change to its
 * own file, so its readers are rebuilt too, transitively. A scanner that does
 * not say which file read what is treated as one unit: any change it could see,
 * or a file added in one of its languages, rebuilds all of its files, and each
 * of those counts as a change to its readers in other scanners. A scanner whose
 * added files can affect every file it scanned, as a global script can, is
 * rebuilt the same way when a file of its languages is added or one of its
 * files is deleted: the program that held a deleted global may not be built
 * again in this scan to say so. A scanner whose files resolve in a layout
 * that marker files decide is rebuilt the same way when a cached marker is
 * deleted, since none of the files it renames need have read it. A row
 * whose reads cannot cover what its file stands for (a file left out, or one
 * the worker failed on) is rebuilt on any change its scanner sees, since its
 * readers named it instead of what it re-exports. A file discovery hashes for
 * the first time reaches every owner that read it while it was left out,
 * whatever bytes that read saw. A scanner whose reads name
 * every file its facts came from is the exception to the transitive rule: a
 * rebuilt owner of it reaches its readers only when its own bytes changed.
 */
final class ReadSetInvalidator
{
    private function __construct() {}

    /**
     * Every cached owner that must be rescanned.
     *
     * A discovered path that no row owns enters the changed set only through a
     * stored read that disagrees with it (a probed miss that now exists). An
     * unconditional entry would rebuild every reader of an unchanged manifest on
     * every scan.
     *
     * A scanner that does not attribute its reads cannot say whether a file
     * it has never seen would change what its other files produce, so a file
     * of its languages that is new, or changed while it had no cache row,
     * rebuilds all of it.
     *
     * @param array<string, string> $discovered every path discovery hashed, to its content hash
     * @param callable(string, ?string): bool $stillMatches whether an undiscovered path still matches a stored read
     *        (production: {@see UndiscoveredInputVerifier::stillMatches()}, the rule the commit check applies)
     * @param array<string, list<string>> $addedByScanner scanner id to the discovered paths of its languages it has no row for
     *        that the active scan did not record with the same bytes
     * @param array<string, true> $addedFilesAffectAll scanner ids an added file of whose languages, or a deleted
     *        one of its files, rebuilds all of their rows ({@see LanguageDescriptor::$addedFilesAffectAll})
     * @param array<string, true> $forced owners the caller already knows are stale, rebuilt with their readers
     * @param array<string, string> $layoutMarkers scanner id to the pattern of the paths whose deletion rebuilds all of
     *        its rows ({@see LanguageDescriptor::$layoutMarkers})
     * @param array<string, true> $directReads scanner ids whose rebuilt owners count as a change to their
     *        readers only when their own bytes changed ({@see LanguageDescriptor::$directReads})
     * @return array<string, true> keyed by owner key
     */
    public static function invalidated(CachedReads $cached, array $discovered, callable $stillMatches, array $addedByScanner = [], array $addedFilesAffectAll = [], array $forced = [], array $layoutMarkers = [], array $directReads = []): array
    {
        $index = ReadIndex::build($cached, $discovered, $stillMatches, $addedByScanner, $addedFilesAffectAll, $layoutMarkers);
        $walk = new InvalidationWalk($cached, $index, $directReads);
        foreach ($addedByScanner as $scanner => $paths) {
            if ($paths !== [] && (isset($index->unattributed[(string) $scanner]) || isset($addedFilesAffectAll[(string) $scanner]))) {
                $walk->rebuildScanner((string) $scanner);
            }
            if ($paths !== []) {
                $walk->dueIncomplete((string) $scanner);
            }
        }
        foreach ($index->deletedFrom as $scanner => $true) {
            $walk->rebuildScanner((string) $scanner);
        }
        foreach ($forced as $owner => $true) {
            if (isset($cached->rows[(string) $owner])) {
                $walk->invalidate((string) $owner);
            }
        }
        $walk->drain();

        return $walk->invalidated();
    }
}
