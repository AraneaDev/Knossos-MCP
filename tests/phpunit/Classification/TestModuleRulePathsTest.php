<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\TestModuleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Which paths count as test code, at the edges of the convention.
 *
 * TestModuleRule scored 90% under mutation testing with four survivors, and they
 * are the parts of the path handling nothing exercised: separators that are not
 * forward slashes, the flag that stops an ambiguous directory name counting once
 * it sits under a source root, and the case folding of the filename.
 *
 * The stakes are concrete. This role is what keeps test scaffolding out of hub
 * rankings, entry points and dead-code candidates, so a path it fails to
 * recognise puts a test double in front of an agent as production structure, and
 * one it recognises wrongly hides real code from every report.
 */
final class TestModuleRulePathsTest extends KnossosTestCase
{
    /**
     * The rule never sees a separator other than a forward slash, because
     * evidence cannot carry one.
     *
     * This is why the rule does not normalise the path itself. The guarantee
     * lives one layer up, and asserting it here is what makes removing the
     * second normalisation safe.
     */
    #[Group('classification')]
    public function testEvidenceRefusesAPathThatIsNotAlreadyNormalised(): void
    {
        assertSame(true, self::isTestCode('tests/Foo.php'));

        foreach (['tests\\Foo.php', '/tests/Foo.php', 'tests/../Foo.php'] as $rejected) {
            assertThrows(static fn() => new Evidence($rejected, 1, 2), \InvalidArgumentException::class, $rejected);
        }
    }

    /**
     * An ambiguous directory name counts at the root of a package and not
     * underneath a source root.
     *
     * `spec/` at the top is a test directory; `src/spec/` is the OpenAPI
     * specification of an application, and tagging it would hide it from every
     * report that excludes test code.
     */
    #[Group('classification')]
    public function testAnAmbiguousDirectoryCountsOnlyOutsideASourceRoot(): void
    {
        assertSame(true, self::isTestCode('spec/Foo.php'));
        assertSame(false, self::isTestCode('src/spec/Foo.php'), 'Under a source root it is ordinary source.');
        assertSame(false, self::isTestCode('lib/tests/Foo.php'));

        // An unambiguous directory counts wherever it sits.
        assertSame(true, self::isTestCode('src/__tests__/Foo.php'));
    }

    /** The filename convention is recognised whatever case it is written in. */
    #[Group('classification')]
    public function testTheFilenameConventionIsRecognisedInAnyCase(): void
    {
        foreach (['FOO_TEST.php', 'foo_test.php', 'Foo.Test.ts', 'TEST_foo.py', 'foo.SPEC.js'] as $file) {
            assertSame(true, self::isTestCode('pkg/' . $file), $file);
        }
    }

    /**
     * A `Test` suffix counts at a word boundary, and a bare lowercase one does
     * not, so ordinary words ending in "test" stay production code.
     */
    #[Group('classification')]
    public function testABareLowercaseTestSuffixIsNotAConvention(): void
    {
        assertSame(true, self::isTestCode('pkg/ThingTest.php'));
        assertSame(false, self::isTestCode('pkg/contest.php'));
        assertSame(false, self::isTestCode('pkg/latest.php'));
    }

    /** A scanner's own mark outranks the path convention. */
    #[Group('classification')]
    public function testAScannerMarkOutranksThePathConvention(): void
    {
        assertSame(true, self::isTestCode('src/lib.rs', ['test' => true]));
        assertSame(false, self::isTestCode('src/lib.rs'));
    }

    /** @param array<string, mixed> $attributes */
    private static function isTestCode(string $relativePath, array $attributes = []): bool
    {
        $node = new NodeFact(
            'php:class:App\\Thing',
            'class',
            'App\\Thing',
            'Thing',
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 2),
            $attributes,
        );

        return (new TestModuleRule())->classify($node) !== [];
    }
}
