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
        // FNM_PATHNAME: '?' matches exactly one char, never a slash.
        $matcher = new IgnoreMatcher(['src/?.php']);

        assertSame(true, $matcher->matches('src/A.php'));
        assertSame(false, $matcher->matches('src/sub/A.php'));
        assertSame(false, $matcher->matches('src/AB.php'));
    }
}
