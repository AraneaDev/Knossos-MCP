<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Discovery;

use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\IgnoreMatcher;
use Knossos\Discovery\RootGuard;
use PHPUnit\Framework\Attributes\Group;

/**
 * Direct tests for the 2 Discovery guard files:
 *
 *   - src/Discovery/IgnoreMatcher.php  (mut-active, EXCLUDED_SEGMENTS +
 *                                      EXCLUDED_PREFIXES const lists
 *                                      + custom pattern matching with
 *                                      '/**' suffix glob + fnmatch
 *                                      fallback).
 *   - src/Discovery/RootGuard.php      (mut-active, realpath +
 *                                      canonical-prefix containment
 *                                      + DiscoveryException throws).
 *
 * Per the close-out doc § 9 plan: Batch 11c covers these 2 guards
 * before the ProjectDiscoverer anchor (Batch 11d) which depends on
 * both. ProjectDiscoverer is scheduled as Batch 11d.
 *
 * Conventions match batches 1-11b: bare global helpers from
 * `tests/phpunit/Support/Assertions.php`; class-level
 * `#[Group('discovery-guards')]`. NO `#[CoversClass]`. NO `assertTrue`.
 */
#[Group('discovery-guards')]
final class DiscoveryGuardsTest extends \Knossos\Tests\Phpunit\KnossosTestCase
{
    /**
     * Create a temporary directory under sys_get_temp_dir and return
     * its absolute path. The directory is realpath-resolvable. Used
     * for RootGuard tests that need real filesystem + realpath.
     */
    private function tempDir(string $leaf = 'root'): string
    {
        $base = sys_get_temp_dir() . '/knossos-guard-' . bin2hex(random_bytes(6));
        mkdir($base, 0700, true);
        $absolute = $base . '/' . $leaf;
        mkdir($absolute, 0700, true);
        return $absolute;
    }

    // ===== IgnoreMatcher ==================================================

    public function testIgnoreMatcherPatternListPropagatesThroughMatches(): void
    {
        $empty = new IgnoreMatcher([]);
        $withPattern = new IgnoreMatcher(['*.tmp']);

        assertSame(false, $empty->matches('src/Foo.php'));
        assertSame(false, $withPattern->matches('src/Foo.php'));
        assertSame(true, $withPattern->matches('foo.tmp'));
    }

    public function testIgnoreMatcherMatchesExcludedSegment(): void
    {
        // EXCLUDED_SEGMENTS arm: any segment in the const list
        // triggers true (e.g., 'node_modules').
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('node_modules/lodash/index.js'));
        assertSame(true, $matcher->matches('a/b/c/.git/HEAD'));
        assertSame(true, $matcher->matches('vendor/autoload.php'));
    }

    public function testIgnoreMatcherMatchesExcludedPrefix(): void
    {
        // EXCLUDED_PREFIXES arm: path starting with 'storage/framework/'
        // or 'public/build/' triggers true.
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('storage/framework/cache/data.php'));
        assertSame(true, $matcher->matches('public/build/manifest.json'));
        // Sibling paths not matching the prefix are not excluded.
        assertSame(false, $matcher->matches('storage/other/file.php'));
        assertSame(false, $matcher->matches('public/asset/image.png'));
    }

    public function testIgnoreMatcherNormalizesBackslashSeparators(): void
    {
        // Backslash separators are converted to forward slash before
        // segment-matching. Windows-style input paths go through the
        // same EXCLUDED_SEGMENTS check after normalisation.
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('src\node_modules\foo.js'));
        assertSame(true, $matcher->matches('a\b\.git\HEAD'));
        assertSame(false, $matcher->matches('src\Foo.php'));
    }

    public function testIgnoreMatcherTrimsLeadingAndTrailingSlashes(): void
    {
        // Leading / trailing slashes / backslashes in the input are
        // trimmed before matching. The backslash arm also exercises
        // the str_replace -> trim order.
        $matcher = new IgnoreMatcher([]);
        assertSame(true, $matcher->matches('/node_modules/foo/'));
        assertSame(true, $matcher->matches('\\node_modules\foo\\'));
        assertSame(false, $matcher->matches('/src/Foo.php/'));
    }

    public function testIgnoreMatcherCustomPatternWithDoubleStarGlob(): void
    {
        // '/**' suffix arm: a pattern ending in '/**' matches the
        // base directory itself (path === base) and any subpath
        // (path starts with base + '/').
        $matcher = new IgnoreMatcher(['docs/**']);
        assertSame(true, $matcher->matches('docs'));
        assertSame(true, $matcher->matches('docs/index.md'));
        assertSame(true, $matcher->matches('docs/sub/page.md'));
        assertSame(false, $matcher->matches('src/docs/index.md'));
    }

    public function testIgnoreMatcherCustomPatternWithFnmatchGlob(): void
    {
        // fnmatch fallback arm: FNM_PATHNAME treats '/' as a path
        // separator; '*' does NOT cross '/' on its own. So a
        // single-segment pattern like `*.tmp` only matches
        // single-segment paths. Multi-segment patterns must include
        // the literal '/' if they want to cross segments.
        $matcher = new IgnoreMatcher(['*.tmp', 'test_*.php', 'sub/test_*.php']);
        assertSame(true, $matcher->matches('foo.tmp'));
        assertSame(true, $matcher->matches('test_user.php'));
        // Multi-segment pattern `sub/test_*.php` matches
        // `sub/test_inner.php` because the explicit '/' lets the
        // glob cross segments.
        assertSame(true, $matcher->matches('sub/test_inner.php'));
        // Single-segment patterns do NOT match multi-segment paths.
        assertSame(false, $matcher->matches('sub/foo.tmp'));
        assertSame(false, $matcher->matches('keep.php'));
    }

    public function testIgnoreMatcherSkipsEmptyCustomPatterns(): void
    {
        // Empty-pattern skip arm: a pattern of '' or just whitespace
        // normalises to '' and is skipped via `continue`.
        $matcher = new IgnoreMatcher(['', '   ']);
        assertSame(false, $matcher->matches('src/Foo.php'));
    }

    public function testIgnoreMatcherReturnsFalseForNonExcludedCleanPath(): void
    {
        // Negative arm: clean path with no excluded segments, no
        // excluded prefix, no matching custom pattern returns false.
        $matcher = new IgnoreMatcher([]);
        assertSame(false, $matcher->matches('src/Foo.php'));
        assertSame(false, $matcher->matches('packages/frontend/src/main.ts'));
        assertSame(false, $matcher->matches('composer.json'));
    }

    public function testIgnoreMatcherHandlesBothSegmentsAndPatternsCorrectly(): void
    {
        // Composition: a path that has BOTH an excluded segment AND
        // a matching custom pattern returns true (segment check
        // first). Also exercises pattern-with-backslash glob
        // normalisation in the pattern itself.
        $matcher = new IgnoreMatcher(['docs\**']);
        assertSame(true, $matcher->matches('docs/index.md'));
        assertSame(true, $matcher->matches('docs/'));
    }

    // ===== RootGuard =======================================================

    public function testRootGuardResolveReturnsCanonicalRealpathInsideAllowedRoot(): void
    {
        // Success arm: requested root that realpath-resolves AND is
        // inside one of the allowedRoots returns the canonical
        // realpath. The containment check uses the static contains()
        // with normalised forward-slash paths.
        $allowed = $this->tempDir('allowed');
        $project = $allowed . '/subproject';
        mkdir($project, 0700, true);

        $guard = new RootGuard([$allowed]);
        $resolved = $guard->resolve($project);

        assertSame(realpath($project), $resolved);
    }

    public function testRootGuardResolveThrowsWhenRequestedRootDoesNotExist(): void
    {
        // Throw arm 1: realpath() on a missing path returns false ->
        // throw DiscoveryException ('Project root does not exist...').
        $allowed = $this->tempDir('allowed');
        $guard = new RootGuard([$allowed]);
        assertThrows(
            fn() => $guard->resolve('/nonexistent-' . bin2hex(random_bytes(4))),
            DiscoveryException::class,
        );
    }

    public function testRootGuardResolveThrowsWhenAllowedRootDoesNotExist(): void
    {
        // Throw arm 2: realpath() on a configured allowedRoot
        // returns false -> throw DiscoveryException ('Configured
        // allowed root does not exist...').
        $project = $this->tempDir('project');
        $guard = new RootGuard(['/nonexistent-allowed-' . bin2hex(random_bytes(4))]);
        assertThrows(
            fn() => $guard->resolve($project),
            DiscoveryException::class,
        );
    }

    public function testRootGuardResolveThrowsWhenProjectOutsideAllowed(): void
    {
        // Throw arm 3: requested realpath does exist but is NOT
        // inside any allowedRoot -> 'Project root is outside the
        // configured allowed roots.'
        $allowed = $this->tempDir('allowed');
        $disallowed = $this->tempDir('disallowed');

        $guard = new RootGuard([$allowed]);
        assertThrows(
            fn() => $guard->resolve($disallowed),
            DiscoveryException::class,
        );
    }

    public function testRootGuardResolveAllowsProjectExactlyEqualToAllowedRoot(): void
    {
        // Boundary case: requested = allowed root (after realpath).
        // The contains() check returns true for candidate === root.
        $allowed = $this->tempDir('exact-allowed');
        $guard = new RootGuard([$allowed]);
        $resolved = $guard->resolve($allowed);
        assertSame(realpath($allowed), $resolved);
    }

    public function testRootGuardResolveAllowsClosestMatchingAllowedWhenMultipleConfigured(): void
    {
        // Multi-allowed arm: multiple allowedRoots configured; the
        // requested is inside one of them; the first matching
        // allowed returns the canonical resolution (the loop
        // short-circuits on first matches()=true).
        $first = $this->tempDir('first');
        $second = $this->tempDir('second');
        $project = $second . '/sub';
        mkdir($project, 0700, true);

        $guard = new RootGuard([$first, $second]);
        $resolved = $guard->resolve($project);
        assertSame(realpath($project), $resolved);
    }

    public function testRootGuardStaticContainsExactMatch(): void
    {
        // Success: candidate === root (exact match) returns true
        // (after rtrim + normalise). The trailing-slash variant
        // exercises the rtrim arm on line 36.
        assertSame(true, RootGuard::contains('/repo', '/repo'));
        assertSame(true, RootGuard::contains('/repo/', '/repo'));
        assertSame(true, RootGuard::contains('/repo', '/repo/'));
    }

    public function testRootGuardStaticContainsChildPath(): void
    {
        // Success: candidate is a child (deeper) of root -> candidate
        // starts with root + '/' -> true.
        assertSame(true, RootGuard::contains('/repo', '/repo/sub'));
        assertSame(true, RootGuard::contains('/repo', '/repo/sub/inner'));
        assertSame(true, RootGuard::contains('/repo/', '/repo/sub'));
    }

    public function testRootGuardStaticContainsReturnsFalseForUnrelated(): void
    {
        // Negative: candidate is unrelated to root.
        assertSame(false, RootGuard::contains('/repo', '/elsewhere'));
        assertSame(false, RootGuard::contains('/repo', '/repo-other'));
    }

    public function testRootGuardStaticContainsNormalizesBackslashes(): void
    {
        // Normalisation arm: backslash separators are converted to
        // forward slashes before the containment check.
        assertSame(true, RootGuard::contains('C:\repo', 'C:\repo\sub'));
    }

    public function testRootGuardStaticContainsDoesNotMatchPrefixWithoutSeparator(): void
    {
        // Safety arm: a candidate that shares a string prefix but
        // differs at the separator boundary does NOT match. This is
        // the protection against str_starts_with($root . '/')
        // security bypass via shared names like '/repo' vs '/repos'.
        assertSame(false, RootGuard::contains('/repo', '/repos'));
        assertSame(false, RootGuard::contains('/repo', '/repository'));
    }
}
