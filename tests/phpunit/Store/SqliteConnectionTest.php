<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use InvalidArgumentException;
use Knossos\Store\SqliteConnection;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('sqlite-connection')]
final class SqliteConnectionTest extends TestCase
{
    private string $tempPath;

    protected function tearDown(): void
    {
        if (isset($this->tempPath) && is_file($this->tempPath)) {
            @unlink($this->tempPath);
        }
    }

    // ----- helpers -----

    private function tempSqliteFile(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-sqlite-');
        $this->tempPath = $path;

        return $path;
    }

    private function pragmaValue(PDO $pdo, string $pragma): mixed
    {
        $row = $pdo->query('PRAGMA ' . $pragma)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : reset($row);
    }

    // ----- validation -----

    public function testOpenInMemoryPathReturnsConfiguredPdo(): void
    {
        $pdo = SqliteConnection::open(':memory:');

        assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        assertSame(PDO::FETCH_ASSOC, $pdo->getAttribute(PDO::ATTR_DEFAULT_FETCH_MODE));
        assertSame(1, $this->pragmaValue($pdo, 'foreign_keys'));
        assertSame(5000, $this->pragmaValue($pdo, 'busy_timeout'));
    }

    public function testOpenInMemoryDoesNotEnableWalJournalMode(): void
    {
        // WAL is only activated for file-backed dbs.
        $pdo = SqliteConnection::open(':memory:');

        $mode = $this->pragmaValue($pdo, 'journal_mode');
        $this->assertNotSame('wal', strtolower((string) $mode));
    }

    public function testOpenExistingFileAppliesAllPragmas(): void
    {
        $path = $this->tempSqliteFile();

        $pdo = SqliteConnection::open($path);

        assertSame(1, $this->pragmaValue($pdo, 'foreign_keys'));
        assertSame(5000, $this->pragmaValue($pdo, 'busy_timeout'));
        assertSame('wal', strtolower((string) $this->pragmaValue($pdo, 'journal_mode')));
    }

    public function testOpenEmptyPathThrows(): void
    {
        $error = captureThrows(
            static fn () => SqliteConnection::open(''),
            InvalidArgumentException::class,
        );

        assertSame('SQLite path must not be empty.', $error->getMessage());
    }

    public function testOpenNonExistentDirectoryThrows(): void
    {
        $missingDir = sys_get_temp_dir() . '/knossos-missing-' . uniqid('', true);

        $error = captureThrows(
            static fn () => SqliteConnection::open($missingDir . '/db.sqlite'),
            InvalidArgumentException::class,
        );

        $this->assertStringContainsString('SQLite directory does not exist', $error->getMessage());
        $this->assertStringContainsString($missingDir, $error->getMessage());
    }

    public function testOpenConfiguredPdoRaisesExceptionsOnBadSql(): void
    {
        $path = $this->tempSqliteFile();
        $pdo = SqliteConnection::open($path);

        $error = captureThrows(
            static fn () => $pdo->exec('CREATE TABLE no_such_thing_then_select'),
            \PDOException::class,
        );

        assertSame('PDOException', $error::class);
    }

    public function testConstructorIsPrivate(): void
    {
        $reflection = new \ReflectionClass(SqliteConnection::class);

        $constructor = $reflection->getConstructor();
        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());
    }

    public function testClassIsFinal(): void
    {
        $reflection = new \ReflectionClass(SqliteConnection::class);

        $this->assertTrue($reflection->isFinal());
    }
}