<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Classification;

use Knossos\Classification\TestModuleRule;
use Knossos\Scanner\Protocol\Confidence;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\Origin;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('test-module-rule')]
final class TestModuleRuleTest extends TestCase
{
    public function testIdReturnsConstant(): void
    {
        assertSame('core.test.modules.v1', (new TestModuleRule())->id());
    }

    public function testClassifyReturnsEmptyForNonTestPath(): void
    {
        $node = $this->makeNode('src/Service/Foo.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyDetectsUnderscoreTestsDirectory(): void
    {
        $node = $this->makeNode('__tests__/users.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('quality.test_module', $facts[0]->role);
    }

    public function testClassifyDetectsUnderscoreTestDirectory(): void
    {
        $node = $this->makeNode('__test__/foo.py');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('quality.test_module', $facts[0]->role);
    }

    public function testClassifyDetectsTestsDirectory(): void
    {
        $node = $this->makeNode('tests/unit/users.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsTestDirectory(): void
    {
        $node = $this->makeNode('test/integration/foo.py');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsSpecDirectory(): void
    {
        $node = $this->makeNode('spec/components/Bar.tsx');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDirectoryMatchIsCaseInsensitive(): void
    {
        // 'TESTS' lowercased at compare time matches 'tests' in DIRECTORY_SEGMENTS.
        $node = $this->makeNode('TESTS/unit/foo.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsDotTestFilenameSuffix(): void
    {
        $node = $this->makeNode('src/widget.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsDotSpecFilenameSuffix(): void
    {
        $node = $this->makeNode('src/widget.spec.js');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsTestUnderscorePrefix(): void
    {
        $node = $this->makeNode('src/test_foo.py');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsUnderscoreTestSuffix(): void
    {
        $node = $this->makeNode('src/foo_test.py');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsPascalCaseTestSuffix(): void
    {
        // PascalCase 'FooTest' matches via [a-z0-9]Test$ regex (the 'o' before 'Test').
        $node = $this->makeNode('src/FooTest.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsCamelCaseTestSuffix(): void
    {
        // 'fooTest' also matches via the same regex.
        $node = $this->makeNode('src/fooTest.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsStandaloneTestCasing(): void
    {
        // 'Test' (no preceding letter) matches via ^Test$ alternative.
        $node = $this->makeNode('src/Test.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyRejectsLowercaseOnlyTestSuffix(): void
    {
        // 'contest' has no uppercase 'T' suffix — lowercase 't' at end does NOT match.
        $node = $this->makeNode('src/contest.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyRejectsPluralTestsInFilename(): void
    {
        // 'fooTests' has uppercase 'T' but the 's' after 'Test' prevents the $ anchor.
        $node = $this->makeNode('src/fooTests.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyRejectsUppercaseLetterBeforeTest(): void
    {
        // 'ATest' starts with uppercase 'A' — neither regex alternative matches.
        $node = $this->makeNode('src/ATest.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame([], $facts);
    }

    public function testClassifyMatchedPathAttributeIsAlwaysForwardSlash(): void
    {
        $node = $this->makeNode('src/__tests__/Foo.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame('src/__tests__/Foo.ts', $facts[0]->attributes['matched_path']);
    }

    public function testClassifyPropagatesNodeEvidence(): void
    {
        $node = $this->makeNode('tests/unit/WidgetTest.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame('tests/unit/WidgetTest.php', $facts[0]->evidence->relativePath);
        assertSame(1, $facts[0]->evidence->startLine);
    }

    public function testClassifyUsesDerivedOriginAndProbableConfidence(): void
    {
        $node = $this->makeNode('tests/foo.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(Origin::Derived, $facts[0]->origin);
        assertSame(Confidence::Probable, $facts[0]->confidence);
    }

    public function testClassifyEmitsExactlyOneFactWhenMatch(): void
    {
        // Even when filename AND directory both match (e.g. tests/foo.test.ts), only 1 fact.
        $node = $this->makeNode('tests/foo.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsDirectoryButSkipsNonTestFilename(): void
    {
        // Path inside __tests__/ whose filename 'Foo.php' has no test suffix.
        // The directory match is the ONLY path that returns true — falling through
        // to the file check returns false. This kills mutations that remove the
        // foreach (e.g. Foreach_ → foreach([])) because then the file check alone
        // fails and isTestPath returns false.
        $node = $this->makeNode('src/__tests__/Foo.php');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
        assertSame('quality.test_module', $facts[0]->role);
    }

    public function testClassifyDetectsUppercaseDirectoryViaLowercaseMatch(): void
    {
        // 'TEST' (uppercase) is lowercased at compare time to match 'test' in DIRECTORY_SEGMENTS.
        // Filename 'foo.py' has no test suffix. This kills UnwrapStrToLower mutations on the
        // segment because without strtolower the literal 'TEST' would not match the
        // lowercase 'test' constant.
        $node = $this->makeNode('TEST/foo.py');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsUppercaseSpecDirectoryViaLowercaseMatch(): void
    {
        // 'SPEC' (uppercase) lowercased at compare time matches 'spec' in DIRECTORY_SEGMENTS.
        $node = $this->makeNode('SPEC/foo.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsUppercaseTestsDirectoryViaLowercaseMatch(): void
    {
        // 'TESTS' (uppercase) lowercased at compare time matches 'tests' in DIRECTORY_SEGMENTS.
        $node = $this->makeNode('TESTS/unit/foo.py');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyDetectsUppercaseUnderscoreTestsDirectoryViaLowercaseMatch(): void
    {
        // '__TESTS__' lowercased matches '__tests__' in DIRECTORY_SEGMENTS.
        $node = $this->makeNode('__TESTS__/foo.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    public function testClassifyStringStemsWithUppercaseTestSuffixStillMatch(): void
    {
        // File stem 'FOO.test' has uppercase chars; preg_replace leaves them as-is.
        // Mutation UnwrapStrToLower on $stem → $lower becomes 'FOO.test' instead of 'foo.test'.
        // str_ends_with('FOO.test', '.test') still passes (literal match) — this test
        // confirms the path stays identical for case-preserved stems.
        $node = $this->makeNode('src/FOO.test.ts');

        $facts = (new TestModuleRule())->classify($node);

        assertSame(1, count($facts));
    }

    // ----- helpers -----

    private function makeNode(string $relativePath): NodeFact
    {
        return new NodeFact(
            'file:' . $relativePath,
            'file',
            $relativePath,
            basename($relativePath),
            Origin::Ast,
            Confidence::Certain,
            new Evidence($relativePath, 1, 10),
            [],
        );
    }
}
