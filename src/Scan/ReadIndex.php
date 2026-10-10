<?php

declare(strict_types=1);

namespace Knossos\Scan;

/**
 * The cached rows of a project indexed for an invalidation walk, and the
 * paths found changed before it starts.
 *
 * A path is changed when an owner's own file has different bytes or is gone,
 * when any read no longer matches the stored hash, when an owner's shared
 * read group is no longer stored, or when discovery hashes a path for the
 * first time that some owner read while it was left out.
 */
final readonly class ReadIndex
{
    /**
     * @param array<string, true> $changed paths whose change the walk starts from
     * @param array<string, array<string, true>> $readersOf path to the owners that read it themselves
     * @param array<string, array<string, true>> $groupsOf path to the read groups that hold it
     * @param array<string, array<string, true>> $ownersOfGroup read group to its owners
     * @param array<string, array<string, true>> $ownersOfFile path to the owners whose own file it is
     * @param array<string, list<string>> $rowsOfScanner scanner id to its owners, in row order
     * @param array<string, true> $unattributed scanner ids with a row that does not attribute its reads
     * @param array<string, list<string>> $incompleteOf scanner id to its owners whose reads are incomplete
     * @param array<string, true> $deletedFrom scanner ids rebuilt whole because a file of theirs is gone
     */
    private function __construct(
        public array $changed,
        public array $readersOf,
        public array $groupsOf,
        public array $ownersOfGroup,
        public array $ownersOfFile,
        public array $rowsOfScanner,
        public array $unattributed,
        public array $incompleteOf,
        public array $deletedFrom,
    ) {}

    /**
     * Index the cached rows and their reads against what discovery hashed.
     *
     * @param array<string, string> $discovered every path discovery hashed, to its content hash
     * @param callable(string, ?string): bool $stillMatches whether an undiscovered path still matches a stored read
     * @param array<string, list<string>> $addedByScanner scanner id to the discovered paths of its languages it has no row for
     * @param array<string, true> $addedFilesAffectAll scanner ids a deleted file of whose rebuilds all of their rows
     * @param array<string, string> $layoutMarkers scanner id to the pattern of the paths whose deletion rebuilds all of its rows
     */
    public static function build(CachedReads $cached, array $discovered, callable $stillMatches, array $addedByScanner, array $addedFilesAffectAll, array $layoutMarkers): self
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

        // A file discovery hashes for the first time was, to every owner
        // that read it, a file discovery left out: read by its bytes, but
        // never one with a contribution of its own, as the Rust index never
        // holds one. Those bytes may be the same now, and the reader is
        // still stale.
        foreach ($addedByScanner as $paths) {
            foreach ($paths as $path) {
                if (isset($readersOf[$path]) || isset($groupsOf[$path])) {
                    $changed[$path] = true;
                }
            }
        }

        return new self($changed, $readersOf, $groupsOf, $ownersOfGroup, $ownersOfFile, $rowsOfScanner, $unattributed, $incompleteOf, $deletedFrom);
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
