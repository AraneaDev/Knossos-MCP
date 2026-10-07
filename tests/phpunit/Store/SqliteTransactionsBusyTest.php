<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Scan\ScanBusyException;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteTransactions;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A write lock held past the busy timeout is waited out, then reported as busy
 * rather than surfacing as a raw driver error.
 */
final class SqliteTransactionsBusyTest extends KnossosTestCase
{
    #[Group('store')]
    public function testALockHeldForGoodIsReportedAsBusy(): void
    {
        $path = self::databasePath();
        try {
            $holder = SqliteConnection::open($path);
            $waiter = SqliteConnection::open($path);
            $waiter->exec('PRAGMA busy_timeout = 50');
            $holder->exec('BEGIN IMMEDIATE');

            assertThrows(static fn() => (new SqliteTransactions($waiter))->run(static fn() => null), ScanBusyException::class);
        } finally {
            self::removeDatabase($path);
        }
    }

    #[Group('store')]
    public function testALockReleasedWhileRetryingLetsTheTransactionRun(): void
    {
        $path = self::databasePath();
        $process = null;
        try {
            $waiter = SqliteConnection::open($path);
            $waiter->exec('CREATE TABLE t(v TEXT)');
            $waiter->exec('PRAGMA busy_timeout = 50');

            $script = '$p = new PDO("sqlite:" . $argv[1]); $p->exec("BEGIN IMMEDIATE"); fwrite(STDOUT, "held\n"); fflush(STDOUT); usleep(300000); $p->exec("COMMIT");';
            $process = proc_open([PHP_BINARY, '-r', $script, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            assertSame(true, is_resource($process));
            assertSame("held\n", fgets($pipes[1]));

            $result = (new SqliteTransactions($waiter))->run(static function () use ($waiter): string {
                $waiter->exec("INSERT INTO t(v) VALUES ('kept')");

                return 'done';
            });

            assertSame('done', $result);
            assertSame('1', (string) $waiter->query('SELECT COUNT(*) FROM t')->fetchColumn());
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $process = null;
        } finally {
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            self::removeDatabase($path);
        }
    }

    private static function databasePath(): string
    {
        return sys_get_temp_dir() . '/knossos-busy-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    private static function removeDatabase(string $path): void
    {
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }
}
