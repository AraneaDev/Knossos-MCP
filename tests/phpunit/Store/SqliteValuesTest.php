<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use JsonException;
use Knossos\Store\SqliteValues;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The one JSON encoding and timestamp format every stored row uses.
 *
 * Both used to be private helpers copied into the repository. They are shared
 * now, so a flag dropped from one copy can no longer drift unnoticed from the
 * rest: a stored attribute and a stored snapshot must encode the same way.
 */
final class SqliteValuesTest extends KnossosTestCase
{
    #[Group('store')]
    public function testJsonKeepsSlashesAndUnicodeUnescaped(): void
    {
        assertSame('{"path":"src/Café.php"}', SqliteValues::json(['path' => 'src/Café.php']));
    }

    #[Group('store')]
    public function testJsonThrowsRatherThanStoringMalformedOutput(): void
    {
        assertThrows(static fn(): string => SqliteValues::json(['value' => NAN]), JsonException::class);
    }

    #[Group('store')]
    public function testNowIsAUtcTimestampInTheStoredFormat(): void
    {
        assertSame(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', SqliteValues::now()));
    }
}
