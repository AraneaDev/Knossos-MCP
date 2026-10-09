<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery\Manifest;

use Knossos\Discovery\Manifest\Toml;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The targeted TOML reads the `pyproject.toml` and `Cargo.toml` readers rely
 * on, asked directly so a regression names the read that broke rather than a
 * manifest field that came back empty.
 */
#[Group('discovery')]
final class TomlTest extends TestCase
{
    private const CARGO = "[package] # the crate\nname = \"crate\"\n"
        . "[[bin]]\nname = \"a\"\npath = \"src/a.rs\"\n"
        . "[[bin]]\nname = \"b\"\n"
        . "[dependencies]\nserde = \"1\"\n\"quoted-dep\" = \"2\"\nweb = { package = \"Actix-Web\", version = \"4\" }\n";

    /** The name comes from the named table only, and a table without one answers null. */
    public function testTableStringReadsTheNameOfOneTable(): void
    {
        assertSame('crate', Toml::tableString(self::CARGO, '[package]'));
        self::assertNull(Toml::tableString(self::CARGO, '[workspace]'));
        self::assertNull(Toml::tableString("[package]\nversion = \"1\"\n", '[package]'));
        // A `name` in a later table is not the package's.
        self::assertNull(Toml::tableString("[package]\nedition = \"2021\"\n[lib]\nname = \"other\"\n", '[package]'));
    }

    /** Every `[[bin]]` block is returned in order, with an empty string for a missing key. */
    public function testArrayTablesReadsEveryRepeatedTable(): void
    {
        assertSame(
            [['name' => 'a', 'path' => 'src/a.rs'], ['name' => 'b', 'path' => '']],
            Toml::arrayTables(self::CARGO, '[[bin]]'),
        );
        assertSame([], Toml::arrayTables(self::CARGO, '[[test]]'));
    }

    /** Single-bracket headers are listed trimmed; array-of-table headers are not. */
    public function testHeadersListsTheSingleBracketTables(): void
    {
        assertSame(['package', 'dependencies'], Toml::headers(self::CARGO));
        assertSame(['a.b'], Toml::headers("[ a.b ]\n"));
        assertSame([], Toml::headers("key = 1\n"));
    }

    /** Keys are lowercased and may be quoted; renamed inline packages are read from their `package` key. */
    public function testTableKeysAndInlinePackageNames(): void
    {
        $block = Toml::tableBlock(self::CARGO, '[dependencies]');

        self::assertNotNull($block);
        assertSame(['serde', 'quoted-dep', 'web'], Toml::tableKeys($block));
        assertSame(['actix-web'], Toml::inlinePackageNames($block));
        assertSame(['upper'], Toml::tableKeys("UPPER = 1\n"));
        assertSame([], Toml::tableKeys("# nothing\n"));
        assertSame([], Toml::inlinePackageNames("serde = { version = \"1\" }\n"));
    }

    /** A block runs to the next header or to the end, and ends with a newline. */
    public function testTableBlockStopsAtTheNextHeader(): void
    {
        assertSame("\nname = \"crate\"\n\n", Toml::tableBlock(self::CARGO, '[package]'));
        assertSame("\na=1\n", Toml::tableBlock("[last]\na=1", '[last]'));
        self::assertNull(Toml::tableBlock(self::CARGO, '[features]'));
    }

    /** Lists are read across lines, brackets inside strings do not close them, and keys can be filtered. */
    public function testStringListsReadsQuotedListItems(): void
    {
        $block = "dependencies = [\"fastapi[all]>=1\", 'httpx']\nother = [\"skip\"]\n";

        assertSame(['fastapi[all]>=1', 'httpx'], Toml::stringLists($block, ['dependencies']));
        assertSame(['fastapi[all]>=1', 'httpx', 'skip'], Toml::stringLists($block, null));
        assertSame(['x', 'y', 'z'], Toml::stringLists("a = [\"x\"]\nb = [\n  \"y\",\n  \"z\",\n]\n", null));
        assertSame([], Toml::stringLists("a = 1\n", null));
    }
}
