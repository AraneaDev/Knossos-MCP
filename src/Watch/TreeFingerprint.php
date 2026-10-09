<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\AllowedRoots;
use Knossos\Discovery\DiscoveryConfig;
use Knossos\Discovery\ProjectDiscoverer;

/**
 * A project tree as the scanner would see it: every discovered file and
 * configuration unit by relative path, with its content hash.
 *
 * Shared by the watcher, which compares one fingerprint with the next, and
 * by the ledgered scanner, which compares it with the hashes the graph holds
 * to learn which files a scan is about to change.
 */
final readonly class TreeFingerprint
{
    /**
     * The tree's paths and content hashes, sorted by path: its files and,
     * unless `$withUnits` is false, its configuration units (which the graph
     * does not hold as files).
     *
     * @return array<string, string>
     */
    public static function of(string $root, AllowedRoots $roots, bool $withUnits = true): array
    {
        return self::observe($root, $roots, $withUnits)[0];
    }

    /**
     * One walk of the tree as a watcher needs it: the fingerprint {@see of()}
     * returns, every directory the walk opened, and the `time()` taken before
     * the walk began, so a stat gate can track directories that hold no file
     * yet and treat anything that moved during the walk as unseen.
     *
     * @param ?Closure(): int $clock the wall clock in seconds, `time()` when null
     * @return array{0: array<string, string>, 1: list<string>, 2: int}
     */
    public static function observe(string $root, AllowedRoots $roots, bool $withUnits = true, ?Closure $clock = null): array
    {
        $startedAt = $clock === null ? time() : $clock();
        $configuration = ProjectConfigurationLoader::load($root, $roots);
        $discovery = (new ProjectDiscoverer(new DiscoveryConfig(
            $roots->current(),
            $configuration->ignores,
            $configuration->maxFiles ?? 100_000,
            $configuration->maxFileBytes ?? 2_000_000,
        )))->discover($root);
        $result = [];
        foreach ($discovery->files as $file) {
            $result[$file->relativePath] = $file->contentHash;
        }
        foreach ($withUnits ? $discovery->units : [] as $unit) {
            $result[$unit->configPath] = $unit->contentHash;
        }
        ksort($result, SORT_STRING);
        return [$result, $discovery->directories, $startedAt];
    }

    /**
     * The differences between two fingerprints: `added`, `changed` or `deleted` by path, sorted.
     *
     * @param array<string, string> $before
     * @param array<string, string> $after
     * @return array<string, string>
     */
    public static function changes(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $path => $hash) {
            if (!isset($before[$path])) {
                $changes[(string) $path] = 'added';
            } elseif ($before[$path] !== $hash) {
                $changes[(string) $path] = 'changed';
            }
        }
        foreach (array_diff_key($before, $after) as $path => $_hash) {
            $changes[(string) $path] = 'deleted';
        }
        ksort($changes, SORT_STRING);
        return $changes;
    }
}
