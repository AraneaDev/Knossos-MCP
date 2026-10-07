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
 * not say which file read what is treated as one unit: any change it could see
 * rebuilds all of its files.
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
     * of its languages with no cache row (an added file) rebuilds all of it.
     *
     * @param array<string, string> $discovered every path discovery hashed, to its content hash
     * @param callable(string, ?string): bool $stillMatches whether an undiscovered path still matches a stored read
     *        (production: {@see UndiscoveredInputVerifier::stillMatches()}, the rule the commit check applies)
     * @param array<string, list<string>> $addedByScanner scanner id to the discovered paths of its languages it has no row for
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
        $readersOf = [];
        $ownersOfGroup = [];
        foreach ($cached->rows as $owner => $row) {
            $owner = (string) $owner;
            if (($discovered[$row['file_path']] ?? null) !== $row['content_hash']) {
                $changed[$row['file_path']] = true;
            }
            $reads = $cached->ownerReads[$owner] ?? [];
            self::collectChanged($reads, $unchanged, $changed);
            foreach ($reads as $path => $hash) {
                $readersOf[(string) $path][$owner] = true;
            }
            if ($row['read_group'] !== null) {
                $ownersOfGroup[$row['read_group']][$owner] = true;
            }
        }
        foreach ($cached->groupReads as $group => $reads) {
            if (!isset($ownersOfGroup[(string) $group])) {
                continue;
            }
            self::collectChanged($reads, $unchanged, $changed);
            foreach ($reads as $path => $hash) {
                $readersOf[(string) $path] = ($readersOf[(string) $path] ?? []) + $ownersOfGroup[(string) $group];
            }
        }

        $ownersOfFile = [];
        foreach ($cached->rows as $owner => $row) {
            $ownersOfFile[$row['file_path']][(string) $owner] = true;
        }

        $invalidated = [];
        $queue = array_map('strval', array_keys($changed));
        while ($queue !== []) {
            $path = array_pop($queue);
            foreach (($ownersOfFile[$path] ?? []) + ($readersOf[$path] ?? []) as $owner => $true) {
                $owner = (string) $owner;
                if (isset($invalidated[$owner])) {
                    continue;
                }
                $invalidated[$owner] = true;
                $ownPath = $cached->rows[$owner]['file_path'];
                if (!isset($changed[$ownPath])) {
                    $changed[$ownPath] = true;
                    $queue[] = $ownPath;
                }
            }
        }

        return self::withUnattributedScanners($cached, $changed, $readersOf, $invalidated, $addedByScanner);
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

    /**
     * Rebuild a whole scanner that does not attribute reads once any change touches it.
     *
     * @param array<string, true> $changed
     * @param array<string, array<string, true>> $readersOf
     * @param array<string, true> $invalidated
     * @param array<string, list<string>> $addedByScanner
     * @return array<string, true>
     */
    private static function withUnattributedScanners(CachedReads $cached, array $changed, array $readersOf, array $invalidated, array $addedByScanner): array
    {
        $unattributed = [];
        foreach ($cached->rows as $row) {
            if (!$row['read_attribution']) {
                $unattributed[$row['scanner_id']] = true;
            }
        }
        if ($unattributed === []) {
            return $invalidated;
        }
        $touched = [];
        foreach ($addedByScanner as $scanner => $paths) {
            if ($paths !== []) {
                $touched[(string) $scanner] = true;
            }
        }
        foreach ($cached->rows as $owner => $row) {
            $scanner = $row['scanner_id'];
            if (isset($unattributed[$scanner]) && !isset($touched[$scanner]) && isset($changed[$row['file_path']])) {
                $touched[$scanner] = true;
            }
        }
        foreach (array_keys($changed) as $path) {
            foreach ($readersOf[(string) $path] ?? [] as $owner => $true) {
                $touched[$cached->rows[(string) $owner]['scanner_id']] = true;
            }
        }
        foreach ($cached->rows as $owner => $row) {
            if (isset($unattributed[$row['scanner_id']], $touched[$row['scanner_id']])) {
                $invalidated[(string) $owner] = true;
            }
        }

        return $invalidated;
    }
}
