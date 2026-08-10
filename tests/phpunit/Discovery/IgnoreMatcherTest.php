<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\IgnoreMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('ignore-matcher')]
final class IgnoreMatcherTest extends TestCase
{
    /**
     * '[#]' used to terminate the '#'-delimited compiled pattern early:
     * preg_match warned "Unknown modifier ']'" and returned false, so the
     * pattern silently excluded nothing. Escaping the class body fixes the
     * delimiter injection outright — the pattern now compiles and matches the
     * literal '#' it names, rather than either throwing or vanishing.
     */
    public function testADelimiterCharacterInsideAClassMatchesLiterally(): void
    {
        $matcher = new IgnoreMatcher(['file[#]name']);

        assertSame(true, $matcher->matches('file#name'));
        assertSame(false, $matcher->matches('fileXname'));
    }

    /**
     * '[a\]' used to leave the compiled character class unterminated: the
     * scanner (unlike PCRE) does not treat '\' as an escape character, so it
     * closed the class one character later than PCRE would once the body was
     * copied verbatim, and PCRE saw the trailing '\]' as an escaped literal
     * bracket rather than the terminator. Escaping the recovered body produces
     * a class that compiles instead of silently matching nothing. (The class
     * also names a literal backslash, but a backslash cannot appear in a
     * segment to test that with: matches() normalises '\' to '/' as a path
     * separator before segmenting, same as everywhere else in this class.)
     */
    public function testATrailingBackslashInAClassMatchesLiterally(): void
    {
        $matcher = new IgnoreMatcher(['[a\\]']);

        assertSame(true, $matcher->matches('a'));
        assertSame(false, $matcher->matches('b'));
    }

    public function testValidCharacterClassStillMatches(): void
    {
        $matcher = new IgnoreMatcher(['*.[oa]']);
        self::assertTrue($matcher->matches('build/thing.o'));
        self::assertFalse($matcher->matches('src/thing.php'));
    }

    /**
     * Escaping the class body does not make every pattern compile: a
     * descending range (start byte greater than end byte) has no valid PCRE
     * interpretation and preg_match rejects it. The constructor now checks the
     * compile result instead of silently matching nothing on every path, so
     * this surfaces as a loud, attributable configuration error.
     */
    public function testAPatternWithAnInvalidCharacterRangeIsRejected(): void
    {
        $this->expectException(DiscoveryException::class);
        new IgnoreMatcher(['[z-a]']);
    }

    /**
     * characterClassEnd() skipped a leading '!' but not a leading '^' — the
     * other gitignore/POSIX negation spelling — so '[^]x]' (a negated class
     * whose first member is the literal ']') closed two characters early, at
     * the ']' that is actually the class's own first member. Pre-fix that
     * malformed regex failed to compile — loud, if unhelpful. Post one-line-fix
     * but pre this round's correction it compiled to the wrong thing (`x]`
     * taken as two trailing literals) and matched silently and incorrectly:
     * a loud failure turned into a quiet wrong answer, which is worse than
     * what was there before. fnmatch() is the oracle throughout, exactly as
     * the reviewer verified it against a reflection-based call into the real
     * toRegex().
     *
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function characterClassAgreesWithFnmatchProvider(): iterable
    {
        $cases = [
            '[^]x]' => ['a', ']', 'x', '^', 'b'],
            // Bare '[^]' has no member after the negation marker is stripped
            // (the literal-first-']' special case consumes the only ']'
            // available), so it is unterminated and falls back to matching its
            // own literal text — same as '[!]' already did.
            '[^]' => ['a', 'b', '^', ']', '[^]'],
            // Embedded in a longer, slash-free pattern: the missing terminator
            // makes the whole pattern literal, not just the bracket portion.
            'x[^]y' => ['x[^]y', 'xay', 'x^y'],
            '[!]x]' => ['a', ']', 'x', '!'],
            '[]]' => [']', 'a'],
            '[^x]' => ['a', 'x', '^'],
            '[!x]' => ['a', 'x', '!'],
            '[a-z]' => ['a', 'm', 'z', 'A'],
            '*.[oa]' => ['thing.o', 'thing.a', 'thing.c'],
            // POSIX classes. preg_quote()ing the body turned '[[:alpha:]]' into
            // '[\[\:alpha\:\]]' — a class of the literal characters '[', ':',
            // 'a', 'l', 'p', 'h' and ']' — which does not match 'a' but does
            // match 'a]', so the wrong files were scanned and nothing said so.
            '[[:alpha:]]' => ['a', 'Z', '1', '_', 'a]', 'ab'],
            '[[:digit:]x]' => ['1', 'x', 'a', '1]', 'x]'],
            '[^[:space:]]' => ['a', ' ', "\t", '^', ' ]'],
            '[a-z[:digit:]]' => ['a', 'z', '5', 'A', '-', 'a]'],
            // The class terminator has to be found past ':]', not at it: the
            // scan used to stop inside the POSIX class and leave the real ']'
            // to be read as a trailing literal.
            '[[:upper:]]x' => ['Ax', 'ax', '1x', 'A]x'],
        ];
        foreach ($cases as $pattern => $candidates) {
            foreach ($candidates as $candidate) {
                yield "$pattern vs '$candidate'" => [$pattern, $candidate, (bool) fnmatch($pattern, $candidate)];
            }
        }
    }

    #[DataProvider('characterClassAgreesWithFnmatchProvider')]
    public function testCharacterClassAgreesWithFnmatch(string $pattern, string $candidate, bool $expected): void
    {
        $matcher = new IgnoreMatcher([$pattern]);

        assertSame($expected, $matcher->matches($candidate));
    }

    public function testClassIsFinalAndReadonly(): void
    {
        $reflection = new \ReflectionClass(IgnoreMatcher::class);

        assertSame(true, $reflection->isFinal());
        assertSame(true, $reflection->isReadOnly());
    }

    /**
     * The pattern-normalisation steps each have an observable effect, and mutation
     * testing showed none of them were pinned: trim(), the anchored-detection
     * trim, and the `continue` that skips a blank pattern all survived being
     * removed or altered. IgnoreMatcher decides what gets scanned at all, so a
     * silent change here distorts every graph the project produces.
     *
     * Note the directory names: `dist` and `build` are built-in exclusions and
     * cannot be negated, so user-pattern behaviour has to be exercised through
     * names the built-in list does not already cover.
     */
    public function testSurroundingWhitespaceInAPatternIsIgnored(): void
    {
        // A hand-edited knossos.json routinely has a stray space. Without the trim
        // the pattern becomes " out", which matches nothing, and the directory is
        // silently scanned.
        $padded = new IgnoreMatcher(['  out  ']);

        assertSame(true, $padded->matches('out/x.js'));
        assertSame(true, $padded->matches('nested/out/x.js'));
        assertSame(false, $padded->matches('src/x.php'));
    }

    public function testATrailingSlashDoesNotAnchorAPattern(): void
    {
        // Anchored detection asks whether a separator appears *inside* the pattern,
        // so a trailing slash must be stripped before the check. Reading "logs/" as
        // anchored would stop it matching a nested directory of the same name.
        $matcher = new IgnoreMatcher(['logs/']);

        assertSame(true, $matcher->matches('logs/a.log'));
        assertSame(true, $matcher->matches('deep/logs/a.log'));
    }

    public function testALeadingSlashAnchorsToTheProjectRoot(): void
    {
        $matcher = new IgnoreMatcher(['/logs']);

        assertSame(true, $matcher->matches('logs/a.log'));
        assertSame(false, $matcher->matches('deep/logs/a.log'));
    }

    public function testAnInnerSlashAnchorsToTheProjectRoot(): void
    {
        $matcher = new IgnoreMatcher(['src/generated']);

        assertSame(true, $matcher->matches('src/generated/api.php'));
        assertSame(false, $matcher->matches('lib/src/generated/api.php'));
    }

    public function testABlankPatternIsSkippedWithoutAbandoningTheRest(): void
    {
        // Ignore lists contain blank lines. Breaking out of the loop instead of
        // continuing would silently drop every pattern after the first blank one —
        // which surfaces as "why is my build output suddenly in the graph".
        $matcher = new IgnoreMatcher(['', 'out', '   ', 'logs']);

        assertSame(true, $matcher->matches('out/b.js'));
        assertSame(true, $matcher->matches('logs/a.log'));
        assertSame(false, $matcher->matches('src/app.php'));
    }

    public function testALaterNegationReIncludesAPreviouslyIgnoredPath(): void
    {
        // Last match wins, so pattern order is behaviour rather than preference.
        $matcher = new IgnoreMatcher(['out', '!out/keep.js']);

        assertSame(false, $matcher->matches('out/keep.js'));
        assertSame(true, $matcher->matches('out/other.js'));
    }

    public function testAPaddedNegationIsStillANegation(): void
    {
        // Regression: the negation marker used to be read from the untrimmed
        // pattern, so one leading space turned "!out/keep.js" into a literal
        // pattern matching nothing — silently discarding the re-include while the
        // trim that followed made the same whitespace irrelevant everywhere else.
        assertSame(false, (new IgnoreMatcher(['out', ' !out/keep.js ']))->matches('out/keep.js'));
        assertSame(false, (new IgnoreMatcher(['out', '! out/keep.js']))->matches('out/keep.js'));
        // The non-negated sibling is unaffected, so the fix did not turn every
        // pattern into a negation.
        assertSame(true, (new IgnoreMatcher(['out', ' !out/keep.js ']))->matches('out/other.js'));
    }

    /**
     * The glob-to-regex translator was the weakest part of this class under
     * mutation testing: `**` spanning, the preg_quote of literal characters, and
     * the character-class scanner were all unexercised. Every expectation below
     * was probed against the implementation first, so these pin current behaviour
     * rather than an assumption about it.
     */
    public function testDoubleStarSpansAnyNumberOfDirectorySegments(): void
    {
        $matcher = new IgnoreMatcher(['**/tmp']);

        assertSame(true, $matcher->matches('tmp/a'));
        assertSame(true, $matcher->matches('x/tmp/a'));
        assertSame(true, $matcher->matches('x/y/tmp/a'));
    }

    public function testATrailingDoubleStarMatchesTheDirectoryContentsOnly(): void
    {
        $matcher = new IgnoreMatcher(['logs/**']);

        assertSame(true, $matcher->matches('logs/a.log'));
        assertSame(true, $matcher->matches('logs/x/y.log'));
        // Not a prefix match: a sibling whose name merely starts the same is kept.
        assertSame(false, $matcher->matches('logsx/a'));
    }

    public function testAnInnerDoubleStarMatchesZeroOrMoreSegments(): void
    {
        $matcher = new IgnoreMatcher(['a/**/b']);

        assertSame(true, $matcher->matches('a/b/f'));
        assertSame(true, $matcher->matches('a/x/b/f'));
        assertSame(true, $matcher->matches('a/x/y/b/f'));
    }

    public function testASingleStarStopsAtASegmentBoundaryForAnAnchoredPattern(): void
    {
        // Unanchored, so it matches a basename at any depth; the point is that `*`
        // itself does not cross a separator.
        $matcher = new IgnoreMatcher(['*.log']);

        assertSame(true, $matcher->matches('a.log'));
        assertSame(true, $matcher->matches('d/a.log'));
        assertSame(false, $matcher->matches('a.txt'));
    }

    public function testACharacterClassMatchesAnyMemberAndNothingElse(): void
    {
        $matcher = new IgnoreMatcher(['file.[co]']);

        assertSame(true, $matcher->matches('file.c'));
        assertSame(true, $matcher->matches('file.o'));
        assertSame(false, $matcher->matches('file.z'));
    }

    public function testANegatedCharacterClassExcludesItsMembers(): void
    {
        $matcher = new IgnoreMatcher(['f[!o]o.txt']);

        assertSame(true, $matcher->matches('fao.txt'));
        assertSame(false, $matcher->matches('foo.txt'));
    }

    public function testAnUnterminatedCharacterClassIsTreatedLiterally(): void
    {
        // A malformed pattern must not become a regex that matches everything, nor
        // throw: it degrades to matching the literal text the user typed.
        $matcher = new IgnoreMatcher(['[abc']);

        assertSame(true, $matcher->matches('[abc'));
        assertSame(false, $matcher->matches('a'));
    }

    public function testRegexMetacharactersInAPatternAreLiteral(): void
    {
        // Without preg_quote, "." would match any character and "+" would repeat
        // the previous one, so a pattern would silently ignore far more than the
        // user named.
        $dot = new IgnoreMatcher(['v1.2']);
        assertSame(true, $dot->matches('v1.2'));
        assertSame(false, $dot->matches('v1x2'));

        $plus = new IgnoreMatcher(['a+b']);
        assertSame(true, $plus->matches('a+b'));
        assertSame(false, $plus->matches('aab'));
    }


    /**
     * Boundary conditions in the pattern scanner: a wildcard or bracket sitting at
     * the very end of the pattern, where the lookahead index reaches the string
     * length. Mutation testing left every one of these index comparisons alive,
     * which is precisely where an off-by-one in a path matcher hides — it would
     * quietly include or exclude a whole directory.
     */
    public function testATrailingWildcardMatchesWithNothingAfterIt(): void
    {
        $bare = new IgnoreMatcher(['*']);
        assertSame(true, $bare->matches('a'));
        assertSame(true, $bare->matches('a/b'));

        // The lookahead for a second '*' must not read past the end.
        $doubled = new IgnoreMatcher(['**']);
        assertSame(true, $doubled->matches('a'));
        assertSame(true, $doubled->matches('a/b'));
    }

    public function testAWildcardAtTheEndOfALiteralPrefixMatchesTheBareStem(): void
    {
        $matcher = new IgnoreMatcher(['a*']);

        assertSame(true, $matcher->matches('ab'));
        // '*' matches zero characters, so the stem alone matches.
        assertSame(true, $matcher->matches('a'));
        assertSame(false, $matcher->matches('ba'));
    }

    public function testADoubleStarAtTheEndOfALiteralPrefixSpansSegments(): void
    {
        $matcher = new IgnoreMatcher(['a**']);

        assertSame(true, $matcher->matches('ab'));
        assertSame(true, $matcher->matches('a/b'));
    }

    public function testAnAnchoredTrailingDoubleStarKeepsItsLiteralPrefix(): void
    {
        // Anchored deliberately: unanchored patterns fall back to matching a
        // basename at any depth, which masks whether the trailing '**' was
        // translated as a segment-spanning wildcard and whether the literal prefix
        // survived into the regex at all. Both mutations were invisible until the
        // pattern was anchored.
        $matcher = new IgnoreMatcher(['x/a**']);

        assertSame(true, $matcher->matches('x/ab'));
        assertSame(true, $matcher->matches('x/a/b'));
        // The prefix still constrains: dropping it would ignore the whole tree.
        assertSame(false, $matcher->matches('x/b/c'));
        assertSame(false, $matcher->matches('y/a/b'));
    }

    public function testABracketAtTheEndOfAPatternIsLiteral(): void
    {
        // The class scanner starts looking one past the '[' and must cope with that
        // index already being the end of the string.
        $matcher = new IgnoreMatcher(['a[']);

        assertSame(true, $matcher->matches('a['));
        assertSame(false, $matcher->matches('a'));
    }

    public function testAClassWhoseFirstMemberIsTheClosingBracket(): void
    {
        // "[]]" is a class containing ']': the scanner has to skip a ']' in first
        // position rather than treating it as the terminator.
        $matcher = new IgnoreMatcher(['[]]']);

        assertSame(true, $matcher->matches(']'));
        assertSame(false, $matcher->matches('a'));
    }

    public function testANegationMarkerWithNoMembersIsNotAClass(): void
    {
        // "[!]" has no terminator after the '!', so it degrades to a literal rather
        // than producing an empty — and therefore always-failing — character class.
        $matcher = new IgnoreMatcher(['[!]']);

        assertSame(true, $matcher->matches('[!]'));
        assertSame(false, $matcher->matches('a'));
    }

    public function testACharacterRangeInsideAClassIsHonoured(): void
    {
        $matcher = new IgnoreMatcher(['[a-c]x']);

        assertSame(true, $matcher->matches('ax'));
        assertSame(true, $matcher->matches('bx'));
        assertSame(false, $matcher->matches('dx'));
    }


    public function testAWindowsStylePatternIsNormalisedToForwardSlashes(): void
    {
        // knossos.json is edited on Windows too, and a pattern written with
        // backslashes must mean the same thing. Without the normalisation the
        // pattern becomes a literal that matches nothing, so the directory is
        // silently scanned — the failure is invisible rather than loud.
        $matcher = new IgnoreMatcher(['src\\generated']);

        assertSame(true, $matcher->matches('src/generated/api.php'));
        // Still anchored: the inner separator survives normalisation.
        assertSame(false, $matcher->matches('lib/src/generated/api.php'));
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

    /**
     * '.knossos' was matched as an exact segment, so anything the tool or a CI
     * wrapper parked beside it under the same namespace was scanned as project
     * source. A workflow that checks this analyzer out to '.knossos-src' to
     * build its image, and then scans the directory containing it, produced a
     * graph four fifths of which was the analyzer; archiving a snapshot that
     * size exhausted the memory limit. The namespace is ours, so everything in
     * it is excluded.
     */
    public function testMatchesPathInsideANamespacedKnossosSegment(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('.knossos-src/src/Store/SqliteGraphRepository.php'));
    }

    public function testMatchesANamespacedKnossosSegmentAtAnyDepth(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(true, $matcher->matches('tools/.knossos-ci/knossos.sqlite'));
    }

    /**
     * The hyphen is the namespace boundary. Without it the rule is a bare string
     * prefix, and a project directory that merely starts with the same letters
     * would vanish from its own graph.
     */
    public function testDoesNotMatchASegmentThatMerelyStartsWithKnossos(): void
    {
        $matcher = new IgnoreMatcher([]);

        assertSame(false, $matcher->matches('.knossosaurus/src/main.ts'));
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
