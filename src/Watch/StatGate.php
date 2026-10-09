<?php

declare(strict_types=1);

namespace Knossos\Watch;

use Closure;

/**
 * Whether a tree may have changed since it was last fingerprinted, from
 * `stat` alone.
 *
 * A fingerprint hashes every file, which is most of what an idle watcher
 * spends. Between two fingerprints this gate stats what the last one saw:
 * every file (an edit moves its mtime, ctime or size), every directory
 * holding one up to the root, and every other directory the walk opened
 * (adding, removing or renaming an entry moves the directory's mtime, so a
 * file created in a directory that held no discovered file is seen too). When
 * none of them moved, nothing the scanner would see can have changed; a full
 * fingerprint at least once a minute covers whatever stat cannot see.
 *
 * The stats are taken after the walk, so a path that moved while the tree was
 * being walked, or in the same second (stat times have a resolution of one
 * second), could carry a change the fingerprint did not see. As git does with
 * its index, such a "racy" snapshot makes the next check fingerprint anyway.
 */
final class StatGate
{
    /** How long, at most, between two fingerprints taken regardless of the stats. */
    public const FULL_EVERY_MS = 60_000;

    /** @var array<string, string> absolute path => its stat signature */
    private array $signatures = [];

    /** When the last fingerprint was remembered (hrtime, ns). */
    private int $rememberedAt = 0;

    /** Whether something the last fingerprint saw moved within the second it was taken. */
    private bool $racy = false;

    /**
     * @param int $fullEveryMs how long, at most, between two fingerprints taken regardless of the stats
     * @param ?Closure(): int $clock the wall clock in seconds, `time()` when null; it dates
     *        "now" when a snapshot is remembered without the time its walk started
     */
    public function __construct(
        private readonly string $root,
        private readonly int $fullEveryMs = self::FULL_EVERY_MS,
        private readonly ?Closure $clock = null,
    ) {}

    /**
     * Remembers what the fingerprint saw: its files, their directories, and
     * the other directories its walk opened.
     *
     * @param array<string, string> $fingerprint relative path => content hash
     * @param list<string> $directories every directory the walk opened, relative to the root
     * @param ?int $walkStartedAt when the walk began (`time()`); a path whose mtime or
     *        ctime is at or after one second before it moved during the walk. Null
     *        means "now", so only a change within the last second is racy.
     */
    public function remember(array $fingerprint, array $directories = [], ?int $walkStartedAt = null): void
    {
        $root = rtrim($this->root, '/');
        $paths = [$root => true];
        foreach (array_keys($fingerprint) as $relative) {
            $path = $root . '/' . $relative;
            $paths[$path] = true;
            for ($dir = dirname($path); strlen($dir) > strlen($root); $dir = dirname($dir)) {
                if (isset($paths[$dir])) {
                    break;
                }
                $paths[$dir] = true;
            }
        }
        // After the files, so the ancestor walk above is not cut short by a
        // directory that is already listed.
        foreach ($directories as $directory) {
            $paths[$root . '/' . $directory] = true;
        }
        clearstatcache();
        $this->signatures = [];
        $this->racy = false;
        $recent = ($walkStartedAt ?? ($this->clock === null ? time() : ($this->clock)())) - 1;
        foreach (array_keys($paths) as $path) {
            $stat = @stat((string) $path);
            $this->signatures[(string) $path] = self::signature($stat);
            $this->racy = $this->racy || ($stat !== false && max($stat['mtime'], $stat['ctime']) >= $recent);
        }
        $this->rememberedAt = hrtime(true);
    }

    /** Whether a fingerprint is worth taking: something it saw moved, or the periodic full check is due. */
    public function mayHaveChanged(): bool
    {
        if ($this->signatures === [] || $this->racy || hrtime(true) - $this->rememberedAt >= $this->fullEveryMs * 1_000_000) {
            return true;
        }
        clearstatcache();
        foreach ($this->signatures as $path => $signature) {
            if (self::signature(@stat($path)) !== $signature) {
                return true;
            }
        }
        return false;
    }

    /**
     * A path's mtime, ctime, size and inode, or `-` when it is gone.
     *
     * @param array<int|string, int>|false $stat
     */
    private static function signature(array|false $stat): string
    {
        return $stat === false ? '-' : $stat['mtime'] . ':' . $stat['ctime'] . ':' . $stat['size'] . ':' . $stat['ino'];
    }
}
