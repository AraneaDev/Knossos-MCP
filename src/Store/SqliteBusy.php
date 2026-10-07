<?php

declare(strict_types=1);

namespace Knossos\Store;

use PDOException;

/**
 * Recognises the driver error for a contended SQLite write lock.
 */
final class SqliteBusy
{
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
}
