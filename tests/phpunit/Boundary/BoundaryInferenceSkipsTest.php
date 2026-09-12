<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Boundary;

use Knossos\Boundary\BoundaryInference;
use Knossos\Discovery\ProjectUnit;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * What inference skips, and that skipping one thing does not stop the rest.
 *
 * BoundaryInference scored 84% under mutation testing. Several survivors are
 * `continue` statements in loops over units and nodes: turning one into a
 * `break` stops at the first thing worth skipping and silently drops every
 * manifest or node behind it. A fixture with one item to skip cannot tell the
 * two apart, so every list here puts the skipped item first.
 */
final class BoundaryInferenceSkipsTest extends KnossosTestCase
{
    /** Every manifest kind seeds a boundary, and an unknown kind does not stop the rest. */
    #[Group('boundary')]
    public function testEveryManifestKindSeedsABoundaryAndAnUnknownKindDoesNotStopTheRest(): void
    {
        $units = [
            // Skipped, and first on purpose: a break here would drop all five below.
            new ProjectUnit('yaml', 'deploy/compose.yaml', 'h', []),
            new ProjectUnit('composer', 'packages/php/composer.json', 'h', ['name' => 'acme/php']),
            new ProjectUnit('cargo', 'packages/rust/Cargo.toml', 'h', ['name' => 'acme-rust']),
            new ProjectUnit('node', 'packages/js/package.json', 'h', ['name' => '@acme/js']),
            new ProjectUnit('python', 'packages/py/pyproject.toml', 'h', ['name' => 'acme-py']),
            new ProjectUnit('typescript', 'packages/ts/tsconfig.json', 'h', []),
        ];

        $names = self::namesOf((new BoundaryInference())->infer($units, []));

        assertSame(
            ['cargo:acme-rust', 'composer:acme/php', 'node:@acme/js', 'python:acme-py', 'typescript:packages/ts/tsconfig.json'],
            $names,
            'One boundary per manifest kind, and none for the kind that is not a manifest.',
        );
    }

    /**
     * A synthetic node is skipped without stopping the walk, and a namespace
     * with a leading separator still names its first segment.
     */
    #[Group('boundary')]
    public function testASyntheticNodeIsSkippedWithoutStoppingTheWalk(): void
    {
        $contribution = new ScanContribution('php:file:src/A.php', [
            // Skipped, and first on purpose.
            self::node('php:route:GET /x', 'route', 'GET /x => App\\C::m', 'src/A.php'),
            self::node('php:class:App\\Thing', 'class', '\\App\\Thing', 'src/A.php'),
            self::node('ts:module:/src/app.ts', 'module', '/src/app.ts#Widget', 'src/app.ts'),
        ], [], []);

        $names = self::namesOf((new BoundaryInference())->infer([], [$contribution]));

        assertSame(['module:src', 'namespace:App'], $names, 'The nodes after the synthetic one still seed their boundaries.');
    }

    /** A leading separator is trimmed before the first segment is read. */
    #[Group('boundary')]
    public function testALeadingSeparatorIsTrimmedBeforeTheFirstSegmentIsRead(): void
    {
        $withSeparators = new ScanContribution('php:file:src/A.php', [
            self::node('php:class:App\\Thing', 'class', '\\App\\Thing', 'src/A.php'),
            self::node('ts:module:/src/app.ts', 'module', '/src/app.ts#Widget', 'src/app.ts'),
        ], [], []);
        $without = new ScanContribution('php:file:src/A.php', [
            self::node('php:class:App\\Thing', 'class', 'App\\Thing', 'src/A.php'),
            self::node('ts:module:src/app.ts', 'module', 'src/app.ts#Widget', 'src/app.ts'),
        ], [], []);

        assertSame(
            self::namesOf((new BoundaryInference())->infer([], [$without])),
            self::namesOf((new BoundaryInference())->infer([], [$withSeparators])),
            'A leading backslash or slash names the same boundary as none at all.',
        );
    }

    /** @param list<\Knossos\Boundary\BoundaryFact> $facts @return list<string> */
    private static function namesOf(array $facts): array
    {
        $names = array_map(static fn(object $fact): string => $fact->name, $facts);
        sort($names, SORT_STRING);

        return $names;
    }

    private static function node(string $localId, string $kind, string $canonicalName, string $path): NodeFact
    {
        return new NodeFact(
            $localId,
            $kind,
            $canonicalName,
            'X',
            Origin::Ast,
            Confidence::Certain,
            new Evidence($path, 1, 2),
            [],
        );
    }
}
