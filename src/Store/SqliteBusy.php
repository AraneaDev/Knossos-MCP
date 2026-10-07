<?php

declare(strict_types=1);

namespace Knossos\Store;

use Knossos\Scan\ScanBusyException;
use PDO;
use PDOException;

/**
 * Recognises the driver error for a contended SQLite write lock, and takes the
 * lock with retries.
 */
final class SqliteBusy
{
    /** Pauses between the retries of BEGIN IMMEDIATE: 200, 400 and 800 ms. */
    private const BACKOFF_MICROSECONDS = [200_000, 400_000, 800_000];

    /**
     * SQLite reports a contended WAL write lock as SQLSTATE HY000 with a
     * "database is locked"/"busy" message (distinct from the 23000 UNIQUE
     * collision on a lock row). Both mean "come back later", so callers
     * surface them as a busy error rather than a raw driver error.
     */
    public static function is(PDOException $error): bool
    {
        if ((string) $error->getCode() !== 'HY000') {
            return false;
        }
        $message = strtolower($error->getMessage());

        return str_contains($message, 'database is locked') || str_contains($message, 'database table is locked') || str_contains($message, 'busy');
    }

    /**
     * Take the write lock, waiting out a holder that outlasts the busy timeout.
     *
     * The connection's busy timeout covers a short hold. A longer one raised a
     * raw driver error after the scan's worker work was already done, or at
     * startup when two processes migrated at once, so the lock is retried with
     * a growing pause and only then reported as busy.
     */
    public static function beginImmediate(PDO $pdo): void
    {
        foreach (self::BACKOFF_MICROSECONDS as $pause) {
            try {
                $pdo->exec('BEGIN IMMEDIATE');

                return;
            } catch (PDOException $error) {
                if (!self::is($error)) {
                    throw $error;
                }
            }
            usleep($pause);
        }
        try {
            $pdo->exec('BEGIN IMMEDIATE');
        } catch (PDOException $error) {
            if (!self::is($error)) {
                throw $error;
            }
            throw new ScanBusyException('The graph database is busy; another writer holds the lock.', 0, $error);
        }
    }
}
