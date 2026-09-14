<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\InputHashesMap;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * `InputHashesMap::merge` folds one part into a running accumulator by
 * reference, in place, rather than rebuilding a fresh copy of everything
 * merged so far. These tests pin the conflict rule across many parts and the
 * by-reference contract that makes that cheap, without asserting on
 * wall-clock time.
 */
#[Group('scanner-worker')]
final class InputHashesMapTest extends TestCase
{
    /** Merging many parts one at a time matches a naive full-rebuild merge. */
    public function testMergingManyPartsMatchesANaiveRebuild(): void
    {
        $parts = [];
        for ($i = 0; $i < 500; $i++) {
            $parts[] = ['src/file' . $i . '.ts' => str_repeat((string) ($i % 10), 64)];
        }
        // A couple of paths disagree across parts, and must end up null.
        $parts[] = ['src/file3.ts' => str_repeat('f', 64)];
        $parts[] = ['src/file7.ts' => null];

        $accumulated = [];
        foreach ($parts as $part) {
            InputHashesMap::merge($accumulated, $part);
        }

        $naive = [];
        foreach ($parts as $part) {
            foreach ($part as $path => $hash) {
                $naive[$path] = array_key_exists($path, $naive) && $naive[$path] !== $hash ? null : $hash;
            }
        }

        assertSame($naive, $accumulated);
        assertSame(null, $accumulated['src/file3.ts']);
        assertSame(null, $accumulated['src/file7.ts']);
        assertSame(500, count($accumulated));
    }

    /**
     * `merge` mutates its first argument by reference: calling it and
     * discarding the return value still updates the caller's accumulator. The
     * quadratic version this replaces took `$into` by value and rebuilt a
     * fresh array, so it never touched the caller's variable directly; only a
     * by-reference merge changes `$accumulator` here without reassigning it.
     */
    public function testMergeMutatesTheAccumulatorByReference(): void
    {
        $accumulator = ['src/a.ts' => str_repeat('a', 64)];

        InputHashesMap::merge($accumulator, ['src/b.ts' => str_repeat('b', 64)]);

        assertSame([
            'src/a.ts' => str_repeat('a', 64),
            'src/b.ts' => str_repeat('b', 64),
        ], $accumulator);
    }

    /** The conflict rule: the same path with two different values becomes null. */
    public function testConflictingValuesForTheSamePathBecomeNull(): void
    {
        $accumulator = ['src/a.ts' => str_repeat('a', 64)];

        InputHashesMap::merge($accumulator, ['src/a.ts' => str_repeat('b', 64)]);

        assertSame(['src/a.ts' => null], $accumulator);
    }

    /** The same value reported twice for one path stays that value. */
    public function testTheSameValueTwiceStaysThatValue(): void
    {
        $accumulator = ['src/a.ts' => str_repeat('a', 64)];

        InputHashesMap::merge($accumulator, ['src/a.ts' => str_repeat('a', 64)]);

        assertSame(['src/a.ts' => str_repeat('a', 64)], $accumulator);
    }

    /** A numeric-string key from `$more` is folded in as the string it spells, like decode() would key it. */
    public function testIntegerLikeKeysFromMoreAreCastToStrings(): void
    {
        $accumulator = [];

        InputHashesMap::merge($accumulator, [7 => str_repeat('a', 64)]);

        assertSame(['7' => str_repeat('a', 64)], $accumulator);
    }

    /** The value merge() returns is the same accumulator it mutated, for chaining. */
    public function testMergeReturnsTheMutatedAccumulator(): void
    {
        $accumulator = ['src/a.ts' => str_repeat('a', 64)];

        $returned = InputHashesMap::merge($accumulator, ['src/b.ts' => str_repeat('b', 64)]);

        assertSame($accumulator, $returned);
    }
}
