<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\IgnoreMatcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ignore-matcher')]
final class IgnoreMatcherTest extends TestCase
{
    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(IgnoreMatcher::class);

        assertSame(true, $reflection->isFinal());
        assertSame(true, $reflection->isReadOnly());
    }

    public function testMatchesPathInsideVendorSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('vendor/foo/bar.php'));
    }

    public function testMatchesPathInsideNodeModulesSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('node_modules/lodash/index.js'));
    }

    public function testMatchesPathInsideGitSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('.git/HEAD'));
    }

    public function testMatchesPathInsideKnossosSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('.knossos/cache.json'));
    }

    public function testMatchesPathInsideCoverageSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('coverage/clover.xml'));
    }

    public function testMatchesPathInsidePycacheSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('src/__pycache__/foo.cpython-310.pyc'));
    }

    public function testMatchesPathInsideBuildSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('build/output.bin'));
    }

    public function testMatchesPathInsideDistSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('dist/bundle.js'));
    }

    public function testMatchesPathInsideStrykerTmpSegment(): void
    {
        // Stryker mutation sandboxes must be excluded to avoid scanning copies.
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('.stryker-tmp/sandbox-1/tsconfig.json'));
    }

    public function testMatchesPathInsideExcludedPrefixPublicBuildExactMatch(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('public/build'));
    }

    public function testMatchesPathInsideExcludedPrefixPublicBuildSubdirectory(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('public/build/assets/bundle.js'));
    }

    public function testMatchesPathInsideExcludedPrefixStorageFramework(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('storage/framework/cache/data.bin'));
    }

    public function testDoesNotMatchPathWithExcludedPrefixAsSubstringOnly(): void
    {
        // 'storage/foo' should NOT match — 'storage/framework' is the prefix, not 'storage'.
        $matcher = new IgnoreMatcher([]);

        assertSame(false, $matcher->matches('storage/foo'));
    }

    public function testDoesNotMatchRegularSourcePath(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(false, $matcher->matches('src/Services/Foo.php'));
    }

    public function testNormalizesBackslashPathsToForwardSlashes(): void
    {
        $matcher = new IgnoreMatcher([]);

        // 'vendor\\foo' → 'vendor/foo' after normalization → vendor segment matched.
        $windowsPath = str_replace('/', chr(92), 'vendor/foo/bar.php');

        assertSame(true, $matcher->matches($windowsPath));
    }

    public function testTrimsLeadingAndTrailingSlashes(): void
    {
        $matcher = new IgnoreMatcher([]);

        // Leading/trailing slashes shouldn't fool the segment loop.
        assertSame(true, $matcher->matches('/vendor/foo/'));
        assertSame(true, $matcher->matches('vendor/foo/'));
    }

    public function testEmptyPathReturnsFalseWhenNoPatternsMatch(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(false, $matcher->matches(''));
    }

    public function testCustomGlobPatternStarDotTmpMatchesFile(): void
    {
        $matcher = new IgnoreMatcher(['*.tmp']);

        assertSame(true, $matcher->matches('debug.tmp'));
    }

    public function testCustomGlobPatternDoesNotMatchUnrelatedExtension(): void
    {
        $matcher = new IgnoreMatcher(['*.tmp']);

        assertSame(false, $matcher->matches('src/Foo.php'));
    }

    public function testCustomGlobPatternDirectoryWildcardSingleStarMatchesImmediateChild(): void
    {
        // FNM_PATHNAME: 'src/*.php' should match 'src/Foo.php' but NOT 'src/sub/Foo.php'
        // (because the * must consume a single segment, not cross /).
        $matcher = new IgnoreMatcher(['src/*.php']);

        assertSame(true, $matcher->matches('src/Foo.php'));
        assertSame(false, $matcher->matches('src/sub/Foo.php'));
    }

    public function testCustomGlobPatternQuestionMarkMatchesSingleCharacter(): void
    {
        $matcher = new IgnoreMatcher(['src/???.php']);

        assertSame(true, $matcher->matches('src/Foo.php'));
        assertSame(false, $matcher->matches('src/Foobar.php'));
        assertSame(false, $matcher->matches('src/Fo.php'));
    }

    public function testCustomGlobPatternCharClassMatchesAnyOfListed(): void
    {
        $matcher = new IgnoreMatcher(['src/[Ff]oo.php']);

        assertSame(true, $matcher->matches('src/Foo.php'));
        assertSame(true, $matcher->matches('src/foo.php'));
        assertSame(false, $matcher->matches('src/Bar.php'));
    }

    public function testDoubleStarGlobPatternMatchesAnySubdirectoryAtBase(): void
    {
        // Pattern ending in '/**' is rewritten to match either the exact base
        // OR any descendant of the base.
        $matcher = new IgnoreMatcher(['temp/**']);

        assertSame(true, $matcher->matches('temp'));
        assertSame(true, $matcher->matches('temp/log.txt'));
        assertSame(true, $matcher->matches('temp/a/b/c.txt'));
        assertSame(false, $matcher->matches('template/x'));
    }

    public function testDoubleStarGlobDoesNotMatchUnrelatedPath(): void
    {
        $matcher = new IgnoreMatcher(['temp/**']);

        assertSame(false, $matcher->matches('src/temp/foo'));
    }

    public function testEmptyPatternIsSkippedNotMatched(): void
    {
        $matcher = new IgnoreMatcher(['']);

        // An empty pattern shouldn't cause fnmatch errors; it's filtered out.
        assertSame(false, $matcher->matches('src/Foo.php'));
        assertSame(true, $matcher->matches('vendor/foo'));
    }

    public function testWhitespaceOnlyPatternIsTrimmedAndSkipped(): void
    {
        $matcher = new IgnoreMatcher(['   ']);

        assertSame(false, $matcher->matches('src/Foo.php'));
        assertSame(true, $matcher->matches('vendor/foo.php'));
    }

    public function testCustomPatternMatchesExactPath(): void
    {
        $matcher = new IgnoreMatcher(['secrets/credentials.json']);

        assertSame(true, $matcher->matches('secrets/credentials.json'));
        assertSame(false, $matcher->matches('secrets/credentials.txt'));
    }

    public function testBackslashInPatternIsNormalizedToForwardSlash(): void
    {
        $windowsPattern = str_replace('/', chr(92), 'temp/**');
        $matcher = new IgnoreMatcher([$windowsPattern]);

        assertSame(true, $matcher->matches('temp/foo'));
    }

    public function testMultiplePatternsMatchIndependently(): void
    {
        $matcher = new IgnoreMatcher(['*.bak', '*.tmp']);

        assertSame(true, $matcher->matches('foo.bak'));
        assertSame(true, $matcher->matches('foo.tmp'));
        assertSame(false, $matcher->matches('foo.php'));
    }

    public function testPatternsListCanBeEmptyAndStillBuiltinExcludesApply(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('vendor/foo'));
        assertSame(true, $matcher->matches('.git/x'));
        assertSame(true, $matcher->matches('build/x'));
        assertSame(false, $matcher->matches('src/foo'));
    }

    public function testCustomGlobQuestionMarkDoesNotCrossSlashes(): void
    {
        // '?' matches exactly one char, never a slash.
        $matcher = new IgnoreMatcher(['src/?.php']);

        assertSame(true, $matcher->matches('src/A.php'));
        assertSame(false, $matcher->matches('src/sub/A.php'));
        assertSame(false, $matcher->matches('src/AB.php'));
    }

    // ── Gitignore semantics ──────────────────────────────────────────

    public function testSlashFreePatternMatchesBasenameAtAnyDepth(): void
    {
        // The gitignore fix: a slash-free glob matches its basename anywhere,
        // not just at the top level (the old FNM_PATHNAME limitation).
        $matcher = new IgnoreMatcher(['*.log']);

        assertSame(true, $matcher->matches('error.log'));
        assertSame(true, $matcher->matches('var/logs/error.log'));
        assertSame(true, $matcher->matches('a/b/c/deep.log'));
        assertSame(false, $matcher->matches('src/error.txt'));
    }

    public function testSlashFreeLiteralMatchesDirectoryAtAnyDepthAndItsContents(): void
    {
        $matcher = new IgnoreMatcher(['generated']);

        assertSame(true, $matcher->matches('generated'));
        assertSame(true, $matcher->matches('src/generated'));
        assertSame(true, $matcher->matches('src/generated/output.php'));
        assertSame(false, $matcher->matches('src/generators/output.php'));
    }

    public function testLeadingDoubleStarMatchesAtAnyDepth(): void
    {
        $matcher = new IgnoreMatcher(['**/fixtures']);

        assertSame(true, $matcher->matches('fixtures'));
        assertSame(true, $matcher->matches('tests/fixtures'));
        assertSame(true, $matcher->matches('a/b/fixtures/data.json'));
        assertSame(false, $matcher->matches('tests/fixture'));
    }

    public function testMiddleDoubleStarSpansDirectorySegments(): void
    {
        $matcher = new IgnoreMatcher(['app/**/cache']);

        assertSame(true, $matcher->matches('app/cache'));
        assertSame(true, $matcher->matches('app/var/cache'));
        assertSame(true, $matcher->matches('app/a/b/cache/file'));
        assertSame(false, $matcher->matches('lib/app/cache'));
    }

    public function testNegationReincludesPreviouslyIgnoredPath(): void
    {
        // Last matching pattern wins; '!' re-includes.
        $matcher = new IgnoreMatcher(['*.php', '!keep.php']);

        assertSame(true, $matcher->matches('src/drop.php'));
        assertSame(false, $matcher->matches('src/keep.php'));
        assertSame(false, $matcher->matches('keep.php'));
    }

    public function testLaterIgnoreOverridesEarlierNegation(): void
    {
        // Order matters: a later ignore re-ignores what an earlier '!' allowed.
        $matcher = new IgnoreMatcher(['!keep.php', '*.php']);

        assertSame(true, $matcher->matches('keep.php'));
    }

    public function testBuiltinExcludesCannotBeNegated(): void
    {
        // Built-in hard excludes are absolute and win over user negation.
        $matcher = new IgnoreMatcher(['!vendor/keep.php']);

        assertSame(true, $matcher->matches('vendor/keep.php'));
    }

    public function testAnchoredPatternDoesNotMatchAtDepth(): void
    {
        // A pattern containing a slash is anchored to the project root.
        // (Uses a non-builtin directory name so only the anchored pattern is at play.)
        $matcher = new IgnoreMatcher(['assets/output']);

        assertSame(true, $matcher->matches('assets/output'));
        assertSame(true, $matcher->matches('assets/output/app.js'));
        assertSame(false, $matcher->matches('packages/assets/output'));
    }
}
