<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliOptionParser;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Where an option value stops being split.
 *
 * CliOptionParser scored 98% with two survivors, and both are the limit on a
 * split: one more field and the value is silently cut at its first separator.
 * Neither is exotic. An option value carrying an `=` is ordinary (a filter
 * expression, a query), and a boundary prefix carrying a `:` is ordinary on any
 * path that names a scheme or a drive.
 */
final class CliOptionValueSplitTest extends KnossosTestCase
{
    /** Only the first equals sign separates an option from its value. */
    #[Group('cli')]
    public function testOnlyTheFirstEqualsSignSeparatesTheValue(): void
    {
        [$positionals, $options] = (new CliOptionParser())->parse(['--filter=kind=class', 'path']);

        assertSame(['kind=class'], $options['filter'], 'The rest of the value is the value, equals signs included.');
        assertSame(['path'], $positionals);
    }

    /**
     * An option with no value at all is a switch, and on.
     *
     * The parser used to store a bare `--name` as 'true', which a value option
     * then took as its value (`--db /x.sqlite` opened a database named
     * 'true'). It stores '' now, which flag() reads as on and single() refuses.
     */
    #[Group('cli')]
    public function testAnOptionWithNoValueIsAFlag(): void
    {
        $parser = new CliOptionParser();
        [, $options] = $parser->parse(['--json', '--filter=']);

        assertSame([''], $options['json']);
        assertSame(true, $parser->flag($options, 'json'));
        assertSame([''], $options['filter'], 'An explicit empty value is empty, not absent.');
    }

    /** --db /x.sqlite became db = 'true' plus a stray positional, and the command opened a database file named 'true'. */
    #[Group('cli')]
    public function testAValueOptionWithoutEqualsIsRefused(): void
    {
        $parser = new CliOptionParser();
        [$positionals, $options] = $parser->parse(['--db', '/x.sqlite']);

        assertSame(['/x.sqlite'], $positionals);
        $error = captureThrows(static fn() => $parser->single($options, 'db'), \InvalidArgumentException::class);
        assertSame('--db takes a value; write --db=VALUE.', $error->getMessage());
    }

    /** A repeatable value option has the same rule, for every occurrence. */
    #[Group('cli')]
    public function testARepeatableValueOptionWithoutEqualsIsRefused(): void
    {
        $parser = new CliOptionParser();

        $error = captureThrows(static fn() => $parser->values($parser->parse(['--edge-kind=calls', '--edge-kind', 'calls'])[1], 'edge-kind'), \InvalidArgumentException::class);

        assertSame('--edge-kind takes a value; write --edge-kind=VALUE.', $error->getMessage());
        assertSame(['calls', 'imports'], $parser->values($parser->parse(['--edge-kind=calls', '--edge-kind=imports'])[1], 'edge-kind'));
        assertSame([], $parser->values([], 'edge-kind'));
    }

    /** A bare switch is on, and an explicit false turns it off. */
    #[Group('cli')]
    public function testABareSwitchIsStillOn(): void
    {
        $parser = new CliOptionParser();

        assertSame(true, $parser->flag($parser->parse(['--json'])[1], 'json'));
        assertSame(true, $parser->flag($parser->parse(['--json='])[1], 'json'));
        assertSame(false, $parser->flag($parser->parse(['--json=false'])[1], 'json'));
    }

    /** A boundary prefix may itself contain a colon. */
    #[Group('cli')]
    public function testABoundaryPrefixMayContainAColon(): void
    {
        $boundaries = (new CliOptionParser())->boundaries(['Api:path:src/a:b', 'Core:namespace:App\\Core']);

        assertSame(
            [
                ['name' => 'Api', 'path_prefix' => 'src/a:b'],
                ['name' => 'Core', 'namespace_prefix' => 'App\\Core'],
            ],
            $boundaries,
        );
    }

    /** A boundary still needs all three fields, and a known kind. */
    #[Group('cli')]
    public function testABoundaryNeedsAllThreeFieldsAndAKnownKind(): void
    {
        $parser = new CliOptionParser();

        foreach (['Api:path', 'Api:path:', ':path:src', 'Api:folder:src'] as $malformed) {
            $error = captureThrows(
                static fn() => $parser->boundaries([$malformed]),
                \InvalidArgumentException::class,
            );
            assertSame('--boundary uses NAME:path:PREFIX or NAME:namespace:PREFIX.', $error->getMessage(), $malformed);
        }
    }
}
