<?php

declare(strict_types=1);

namespace Knossos\Watch;

/**
 * One live watcher per project, across every session that shares a data
 * directory.
 *
 * The lock is an advisory `flock` on `<dir>/<key>.lock`, held for the
 * watcher's life. The kernel releases it when the process ends, however it
 * ends, so a crashed or killed watcher never leaves a lock behind: the next
 * session to try simply takes it over. Beside it, `<key>.json` says who holds
 * it (PID, project, the snapshot it last saw, its phase) and when it last
 * said so (`heartbeat`), rewritten whole through a rename so a reader never
 * sees half of it. The lock file itself is never rewritten: renaming over it
 * would hand the next locker a different file.
 */
final class WatchLock
{
    /** @param resource $handle the open lock file, holding the lock */
    private function __construct(
        private mixed $handle,
        private readonly string $statePath,
    ) {}

    /**
     * Takes the project's lock, or null when a live process holds it.
     *
     * @throws \RuntimeException when the lock directory or file cannot be made
     */
    public static function acquire(string $dir, string $projectId): ?self
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Cannot create the watch lock directory %s.', $dir));
        }
        $base = $dir . '/' . self::key($projectId);
        $handle = @fopen($base . '.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open the watch lock %s.lock.', $base));
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return null;
        }
        return new self($handle, $base . '.json');
    }

    /**
     * What the holder last said about itself, or null when nobody has (or it
     * cannot be read).
     *
     * @return array<string, mixed>|null
     */
    public static function owner(string $dir, string $projectId): ?array
    {
        $text = @file_get_contents($dir . '/' . self::key($projectId) . '.json');
        if (!is_string($text) || $text === '') {
            return null;
        }
        $state = json_decode($text, true);
        return is_array($state) ? $state : null;
    }

    /**
     * Says who holds the lock, now: the PID and heartbeat are filled in.
     *
     * @param array<string, mixed> $state
     */
    public function write(array $state): void
    {
        $text = json_encode(['pid' => getmypid(), 'heartbeat' => time()] + $state, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $temporary = $this->statePath . '.' . getmypid() . '.tmp';
        if ($text !== false && @file_put_contents($temporary, $text) !== false && !@rename($temporary, $this->statePath)) {
            @unlink($temporary);
        }
    }

    /** Lets go: the state file goes first, so nobody reads a holder that is gone. */
    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        @unlink($this->statePath);
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    /** A file name for the project: its id is free text, so it is hashed. */
    private static function key(string $projectId): string
    {
        return substr(hash('sha256', $projectId), 0, 24);
    }
}
