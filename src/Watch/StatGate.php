<?php

declare(strict_types=1);

namespace Knossos\Watch;

/**
 * Whether a tree may have changed since it was last fingerprinted, from
 * `stat` alone.
 *
 * A fingerprint hashes every file, which is most of what an idle watcher
 * spends. Between two fingerprints this gate stats what the last one saw:
 * every file (an edit moves its mtime, ctime or size) and every directory
 * holding one, up to the root (adding, removing or renaming an entry moves
 * the directory's mtime). When none of them moved, nothing the scanner
 * would see can have changed, save a file created in a directory that held
 * no discovered file before; a full fingerprint at least once a minute
 * covers that case.
 *
 * Stat times have a resolution of one second, so a file written in the same
 * second it was fingerprinted could change again unseen. As git does with
 * its index, such a "racy" entry makes the next check fingerprint anyway.
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

    /** @param int $fullEveryMs how long, at most, between two fingerprints taken regardless of the stats */
    public function __construct(private readonly string $root, private readonly int $fullEveryMs = self::FULL_EVERY_MS) {}

    /**
     * Remembers what the fingerprint saw: its files and their directories.
     *
     * @param array<string, string> $fingerprint relative path => content hash
     */
    public function remember(array $fingerprint): void
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
        clearstatcache();
        $this->signatures = [];
        $this->racy = false;
        $recent = time() - 1;
        foreach (array_keys($paths) as $path) {
            $stat = @stat((string) $path);
            $this->signatures[(string) $path] = self::signature($stat);
            $this->racy = $this->racy || ($stat !== false && $stat['mtime'] >= $recent);
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
