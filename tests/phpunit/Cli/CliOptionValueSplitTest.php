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

    /** An option with no value at all is a flag. */
    #[Group('cli')]
    public function testAnOptionWithNoValueIsAFlag(): void
    {
        [, $options] = (new CliOptionParser())->parse(['--json', '--filter=']);

        assertSame(['true'], $options['json']);
        assertSame([''], $options['filter'], 'An explicit empty value is empty, not absent.');
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
