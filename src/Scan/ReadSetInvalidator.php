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
 * readers named it instead of what it re-exports.
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
     * @return array<string, true> keyed by owner key
     */
    public static function invalidated(CachedReads $cached, array $discovered, callable $stillMatches, array $addedByScanner = [], array $addedFilesAffectAll = [], array $forced = [], array $layoutMarkers = []): array
    {
        $memo = [];
        $unchanged = static function (string $path, ?string $stored) use ($discovered, $stillMatches, &$memo): bool {
            if (array_key_exists($path, $discovered)) {
                return $discovered[$path] === $stored;
            }
            $key = $path . "\0" . ($stored ?? '');

            return $memo[$key] ??= (bool) $stillMatches($path, $stored);
        };

        $changed = [];
        // Path to the owners that read it themselves, path to the groups that
        // hold it, group to its owners. A group is expanded only when the walk
        // reaches it: copying its owners onto every path it holds made memory
        // grow with owners times reads.
        $readersOf = [];
        $groupsOf = [];
        $ownersOfGroup = [];
        $ownersOfFile = [];
        $rowsOfScanner = [];
        $unattributed = [];
        $incompleteOf = [];
        $deletedFrom = [];
        foreach ($cached->rows as $owner => $row) {
            $owner = (string) $owner;
            $marker = $layoutMarkers[$row['scanner_id']] ?? null;
            if (!array_key_exists($row['file_path'], $discovered) && (isset($addedFilesAffectAll[$row['scanner_id']]) || ($marker !== null && preg_match($marker, $row['file_path']) === 1))) {
                $deletedFrom[$row['scanner_id']] = true;
            }
            if (($discovered[$row['file_path']] ?? null) !== $row['content_hash']) {
                $changed[$row['file_path']] = true;
            }
            $ownersOfFile[$row['file_path']][$owner] = true;
            $rowsOfScanner[$row['scanner_id']][] = $owner;
            if (!$row['read_attribution']) {
                $unattributed[$row['scanner_id']] = true;
            } elseif ($row['reads_incomplete'] ?? false) {
                $incompleteOf[$row['scanner_id']][] = $owner;
            }
            $reads = $cached->ownerReads[$owner] ?? [];
            self::collectChanged($reads, $unchanged, $changed);
            foreach ($reads as $path => $hash) {
                $readersOf[(string) $path][$owner] = true;
            }
            if ($row['read_group'] !== null) {
                $ownersOfGroup[$row['read_group']][$owner] = true;
                // A group the store no longer holds leaves nothing to compare
                // the owner's shared reads against, so it cannot be current.
                if (!isset($cached->groupReads[$row['read_group']])) {
                    $changed[$row['file_path']] = true;
                }
            }
        }
        foreach ($cached->groupReads as $group => $reads) {
            $group = (string) $group;
            if (!isset($ownersOfGroup[$group])) {
                continue;
            }
            self::collectChanged($reads, $unchanged, $changed);
            foreach ($reads as $path => $hash) {
                $groupsOf[(string) $path][$group] = true;
            }
        }

        $invalidated = [];
        $expandedGroups = [];
        $rebuiltScanners = [];
        $queue = array_map('strval', array_keys($changed));
        // The first owner a scanner rebuilds also rebuilds its rows whose
        // reads are incomplete, once per scanner, so their readers are
        // reached in turn.
        $incompleteDue = [];
        $invalidate = static function (string $owner) use ($cached, $incompleteOf, &$invalidated, &$changed, &$queue, &$incompleteDue): bool {
            if (isset($invalidated[$owner])) {
                return false;
            }
            $invalidated[$owner] = true;
            $ownPath = $cached->rows[$owner]['file_path'];
            if (!isset($changed[$ownPath])) {
                $changed[$ownPath] = true;
                $queue[] = $ownPath;
            }
            $scanner = $cached->rows[$owner]['scanner_id'];
            if (isset($incompleteOf[$scanner]) && !array_key_exists($scanner, $incompleteDue)) {
                $incompleteDue[$scanner] = false;
            }

            return true;
        };
        // Rebuilds the incomplete rows of every scanner that has rebuilt an
        // owner since the last call, each scanner once.
        $fanOut = static function () use ($incompleteOf, $invalidate, &$incompleteDue): void {
            while (($scanner = array_search(false, $incompleteDue, true)) !== false) {
                $incompleteDue[$scanner] = true;
                foreach ($incompleteOf[$scanner] as $incomplete) {
                    $invalidate($incomplete);
                }
            }
        };
        // A scanner that does not attribute reads is rebuilt whole as soon as
        // any of its files is, and every file it rebuilds is a change its
        // readers in other scanners see.
        $rebuildScanner = static function (string $scanner) use ($rowsOfScanner, $invalidate, &$rebuiltScanners): void {
            if (isset($rebuiltScanners[$scanner])) {
                return;
            }
            $rebuiltScanners[$scanner] = true;
            foreach ($rowsOfScanner[$scanner] ?? [] as $owner) {
                $invalidate($owner);
            }
        };
        foreach ($addedByScanner as $scanner => $paths) {
            if ($paths !== [] && (isset($unattributed[(string) $scanner]) || isset($addedFilesAffectAll[(string) $scanner]))) {
                $rebuildScanner((string) $scanner);
            }
            if ($paths !== [] && isset($incompleteOf[(string) $scanner])) {
                $incompleteDue[(string) $scanner] ??= false;
            }
        }
        foreach ($deletedFrom as $scanner => $true) {
            $rebuildScanner((string) $scanner);
        }
        foreach ($forced as $owner => $true) {
            if (isset($cached->rows[(string) $owner])) {
                $invalidate((string) $owner);
            }
        }
        do {
            $fanOut();
            $path = array_pop($queue);
            if ($path === null) {
                break;
            }
            $owners = ($ownersOfFile[$path] ?? []) + ($readersOf[$path] ?? []);
            foreach ($groupsOf[$path] ?? [] as $group => $true) {
                if (!isset($expandedGroups[$group])) {
                    $expandedGroups[$group] = true;
                    $owners += $ownersOfGroup[(string) $group];
                }
            }
            foreach ($owners as $owner => $true) {
                $owner = (string) $owner;
                if ($invalidate($owner) && isset($unattributed[$cached->rows[$owner]['scanner_id']])) {
                    $rebuildScanner($cached->rows[$owner]['scanner_id']);
                }
            }
        } while (true);

        return $invalidated;
    }

    /**
     * Add every read that no longer matches the stored one.
     *
     * @param array<string, ?string> $reads
     * @param callable(string, ?string): bool $unchanged
     * @param array<string, true> $changed
     */
    private static function collectChanged(array $reads, callable $unchanged, array &$changed): void
    {
        foreach ($reads as $path => $hash) {
            $path = (string) $path;
            if (!isset($changed[$path]) && !$unchanged($path, $hash)) {
                $changed[$path] = true;
            }
        }
    }
}
