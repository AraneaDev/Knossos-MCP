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
 * of those counts as a change to its readers in other scanners.
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
     * @return array<string, true> keyed by owner key
     */
    public static function invalidated(CachedReads $cached, array $discovered, callable $stillMatches, array $addedByScanner = []): array
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
        foreach ($cached->rows as $owner => $row) {
            $owner = (string) $owner;
            if (($discovered[$row['file_path']] ?? null) !== $row['content_hash']) {
                $changed[$row['file_path']] = true;
            }
            $ownersOfFile[$row['file_path']][$owner] = true;
            $rowsOfScanner[$row['scanner_id']][] = $owner;
            if (!$row['read_attribution']) {
                $unattributed[$row['scanner_id']] = true;
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
        $invalidate = static function (string $owner) use ($cached, &$invalidated, &$changed, &$queue): bool {
            if (isset($invalidated[$owner])) {
                return false;
            }
            $invalidated[$owner] = true;
            $ownPath = $cached->rows[$owner]['file_path'];
            if (!isset($changed[$ownPath])) {
                $changed[$ownPath] = true;
                $queue[] = $ownPath;
            }

            return true;
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
            if ($paths !== [] && isset($unattributed[(string) $scanner])) {
                $rebuildScanner((string) $scanner);
            }
        }
        while ($queue !== []) {
            $path = array_pop($queue);
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
        }

        return $invalidated;
    }

    /**
     * The owners to rebuild for a scanner whose added files affect every file
     * it scanned ({@see \Knossos\Scanner\Protocol\Protocol::CAPABILITY_ADDED_FILES_AFFECT_ALL}):
     * all of its cached owners once a file of its languages is added, and
     * otherwise those the reads reached.
     *
     * Decided once the worker's manifest is known, which is after planning.
     *
     * @param array<string, true> $invalidated what the reads reached
     * @param array<string, list<string>> $addedByScanner scanner id to its added files
     * @return array<string, true>
     */
    public static function withAddedFilesAffectingAll(?CachedReads $cached, array $invalidated, array $addedByScanner, string $scanner): array
    {
        if ($cached === null || ($addedByScanner[$scanner] ?? []) === []) {
            return $invalidated;
        }
        foreach ($cached->rows as $owner => $row) {
            if ($row['scanner_id'] === $scanner) {
                $invalidated[(string) $owner] = true;
            }
        }

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
