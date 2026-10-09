<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Boundary;

use InvalidArgumentException;
use Knossos\Boundary\BoundaryReferences;
use Knossos\Store\StableId;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The one way a policy's boundary reference is read: a stable id, an exact
 * name, or a name the boundary had before a manifest renamed it.
 */
#[Group('boundary-references')]
final class BoundaryReferencesTest extends TestCase
{
    private const PROJECT = 'project-1';

    /** @return list<array{id: string, name: string, source: string, matcher_json: string}> */
    private static function rows(): array
    {
        $row = static fn(string $id, string $name, string $source, array $aliases = []): array => [
            'id' => $id, 'name' => $name, 'source' => $source,
            'matcher_json' => (string) json_encode(['type' => 'path_prefix', 'value' => ''] + ($aliases === [] ? [] : ['aliases' => $aliases])),
        ];

        return [
            $row('b-merged', 'composer:acme/lib (+node:web)', 'inferred', ['composer:acme/lib', 'node:web']),
            $row('b-loc-a', 'node:loc (a)', 'inferred', ['node:loc']),
            $row('b-loc-b', 'node:loc (b)', 'inferred', ['node:loc']),
            $row('b-core-explicit', 'Core', 'explicit'),
            $row('b-core-inferred', 'Core', 'inferred'),
            $row('b-plain', 'Domain', 'explicit'),
            // A name another boundary once had: the exact name wins.
            $row('b-web', 'web', 'explicit'),
            $row('b-old-web', 'node:other', 'inferred', ['web']),
        ];
    }

    private static function references(): BoundaryReferences
    {
        return BoundaryReferences::fromRows(self::PROJECT, self::rows());
    }

    public function testAnIdAndAnExactNameResolve(): void
    {
        assertSame('b-plain', self::references()->resolve('b-plain'));
        assertSame('b-plain', self::references()->resolve('Domain'));
        assertSame('b-merged', self::references()->resolve('composer:acme/lib (+node:web)'));
        assertSame('b-web', self::references()->resolve('web'));
    }

    /** A boundary renamed by a merge or a twin still answers to the name it had. */
    public function testAFormerNameResolvesThroughTheAliases(): void
    {
        assertSame('b-merged', self::references()->resolve('node:web'));
        assertSame('b-merged', self::references()->resolve('composer:acme/lib'));
    }

    /** A policy written with the stable id a boundary had before its merge still resolves. */
    public function testTheStableIdAFormerNameHadResolves(): void
    {
        $oldId = StableId::boundary(self::PROJECT, 'node:web', 'inferred');

        assertSame('b-merged', self::references()->resolve($oldId));
        assertSame('composer:acme/lib (+node:web)', self::references()->nameOf($oldId));
    }

    public function testAnUnknownReferenceIsRefused(): void
    {
        $error = captureThrows(static fn() => self::references()->resolve('Nowhere'), InvalidArgumentException::class);

        assertSame('Unknown policy boundary: Nowhere', $error->getMessage());
        assertSame(null, self::references()->find('Nowhere'));
        assertSame(null, self::references()->nameOf('Nowhere'));
    }

    /** An alias two boundaries share picks neither, and says which ids to choose from. */
    public function testASharedAliasIsAmbiguousAndNamesTheCandidates(): void
    {
        $error = captureThrows(static fn() => self::references()->resolve('node:loc'), InvalidArgumentException::class);

        assertSame('Ambiguous policy boundary name; use its stable ID: node:loc (candidates: b-loc-a, b-loc-b)', $error->getMessage());
        assertSame(null, self::references()->find('node:loc'));
        assertSame(null, self::references()->nameOf('node:loc'));
    }

    /** A name an explicit and an inferred boundary share is ambiguous the same way. */
    public function testASharedNameIsAmbiguousAndNamesTheCandidates(): void
    {
        $error = captureThrows(static fn() => self::references()->resolve('Core'), InvalidArgumentException::class);

        assertSame('Ambiguous policy boundary name; use its stable ID: Core (candidates: b-core-explicit, b-core-inferred)', $error->getMessage());
        assertSame('b-core-inferred', self::references()->resolve('b-core-inferred'));
    }

    public function testFindAndNameOfAnswerForTheBoundaryAReferenceResolvesTo(): void
    {
        assertSame('b-merged', self::references()->find('node:web'));
        assertSame('composer:acme/lib (+node:web)', self::references()->nameOf('node:web'));
        assertSame('Domain', self::references()->nameOf('b-plain'));
    }

    /** An explicit boundary's alias-shaped id is not derived: only inferred boundaries were ever renamed. */
    public function testOnlyAnInferredBoundaryAnswersToAFormerStableId(): void
    {
        $rows = [[
            'id' => 'b-x', 'name' => 'X', 'source' => 'explicit',
            'matcher_json' => (string) json_encode(['type' => 'path_prefix', 'value' => '', 'aliases' => ['Y']]),
        ]];

        $references = BoundaryReferences::fromRows(self::PROJECT, $rows);

        assertSame('b-x', $references->resolve('Y'));
        assertSame(null, $references->find(StableId::boundary(self::PROJECT, 'Y', 'inferred')));
    }

    /** A former name stored twice is still one boundary's, and candidates are listed in id order whatever order the rows came in. */
    public function testARepeatedAliasIsOneCandidateAndCandidatesAreSorted(): void
    {
        $row = static fn(string $id, string $name, array $aliases): array => [
            'id' => $id, 'name' => $name, 'source' => 'inferred',
            'matcher_json' => (string) json_encode(['type' => 'path_prefix', 'value' => '', 'aliases' => $aliases]),
        ];
        $references = BoundaryReferences::fromRows(self::PROJECT, [$row('b-2', 'two', ['x', 'x', 'y']), $row('b-1', 'one', ['y'])]);

        assertSame('b-2', $references->resolve('x'));
        $error = captureThrows(static fn() => $references->resolve('y'), InvalidArgumentException::class);
        assertSame('Ambiguous policy boundary name; use its stable ID: y (candidates: b-1, b-2)', $error->getMessage());
    }
}
