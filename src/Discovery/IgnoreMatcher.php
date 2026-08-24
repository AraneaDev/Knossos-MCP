<?php

declare(strict_types=1);

namespace Knossos\Discovery;

/**
 * Decides which paths discovery skips.
 *
 * Dependency and build directories are excluded by default — scanning
 * `node_modules` produces a graph about someone else's code — on top of whatever
 * the project's own configuration adds.
 */
final readonly class IgnoreMatcher
{
    private const EXCLUDED_SEGMENTS = [
        '.git',
        '.idea',
        '.knossos',
        'vendor',
        'node_modules',
        'coverage',
        '.next',
        '.nuxt',
        '.venv',
        'venv',
        '__pycache__',
        '.tox',
        '.mypy_cache',
        '.pytest_cache',
        // Generated build output and mutation-testing sandboxes are not source.
        // '.stryker-tmp' in particular holds one full project copy per sandbox
        // (each with its own tsconfig), which would otherwise multiply the
        // TypeScript program count and make scans slow or time out.
        '.stryker-tmp',
        'build',
        'dist',
    ];

    /**
     * Segment prefixes, for directories this tool and its wrappers own the
     * naming of. '.knossos' alone is an exact segment above; a CI job that needs
     * somewhere to put a checkout of the analyzer or its snapshot database names
     * it '.knossos-src' or '.knossos-ci' by the same convention, and those were
     * scanned as if they were the project's own source.
     */
    private const EXCLUDED_SEGMENT_PREFIXES = [
        '.knossos-',
        // Laravel IDE Helper generated stubs have no architectural signal — they
        // are enumerations of every class, method, and docblock in the project —
        // and scanning them produces NDJSON frames large enough to overflow the
        // worker's line limit on any non-trivial project.
        '_ide_helper',
    ];

    private const EXCLUDED_PREFIXES = [
        'public/build',
        'storage/framework',
        // Laravel writable directories that hold uploaded assets, debug dumps,
        // and logs — never source code. Including them in discovery wastes
        // worker time on binary/multi-MB files and can overflow frame limits.
        'storage/attachments',
        'storage/debugbar',
        'storage/logs',
    ];

    /**
     * Patterns pre-compiled to `[normalized, anchored, regex, negated]`, so a
     * pattern that cannot compile is rejected at construction rather than
     * silently matching nothing on every path, and the regex is built once per
     * pattern instead of once per pattern per discovered file.
     *
     * @var list<array{0: string, 1: bool, 2: string, 3: bool}>
     */
    private array $compiled;

    /**
     * @param list<string> $patterns
     * @throws DiscoveryException when a pattern does not compile to a valid regex
     */
    public function __construct(array $patterns)
    {
        $compiled = [];
        foreach ($patterns as $pattern) {
            // Trim before reading the negation marker, not after. Testing the raw
            // pattern meant a single leading space turned "!keep.js" into a literal
            // pattern matching nothing, silently discarding the re-include while the
            // trim two lines later made the same whitespace irrelevant everywhere
            // else. Whitespace is either significant here or it is not.
            $normalized = trim(str_replace('\\', '/', $pattern));
            $negated = str_starts_with($normalized, '!');
            if ($negated) {
                $normalized = trim(substr($normalized, 1));
            }
            $anchored = str_starts_with($normalized, '/') || str_contains(trim($normalized, '/'), '/');
            $normalized = trim($normalized, '/');
            if ($normalized === '') {
                continue;
            }
            // Trailing '/**' ignores the directory itself and everything under it, so
            // reduce it to its base and let the descendant suffix in patternMatches()
            // cover contents.
            if (str_ends_with($normalized, '/**')) {
                $normalized = substr($normalized, 0, -3);
                $anchored = true;
            }
            $regex = self::compile($normalized, $pattern);
            $compiled[] = [$normalized, $anchored, $regex, $negated];
        }
        $this->compiled = $compiled;
    }

    /** Whether a path is ignored, applying built-in exclusions then the user patterns. */
    public function matches(string $relativePath): bool
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        foreach ($segments as $segment) {
            if (in_array($segment, self::EXCLUDED_SEGMENTS, true)) {
                return true;
            }
            foreach (self::EXCLUDED_SEGMENT_PREFIXES as $prefix) {
                if (str_starts_with($segment, $prefix)) {
                    return true;
                }
            }
        }

        foreach (self::EXCLUDED_PREFIXES as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        // User patterns follow gitignore semantics: last matching pattern wins, a
        // leading '!' re-includes, a slash-free pattern matches a basename at any
        // depth, and '**' spans directory segments. Built-in excludes above are
        // absolute and cannot be negated.
        $ignored = false;
        foreach ($this->compiled as [, $anchored, $regex, $negated]) {
            if (self::patternMatches($regex, $anchored, $path, $segments)) {
                $ignored = !$negated;
            }
        }

        return $ignored;
    }

    /**
     * Whether one compiled pattern matches, honouring anchoring and descendants.
     *
     * @param list<string> $segments
     */
    private static function patternMatches(string $regex, bool $anchored, string $path, array $segments): bool
    {
        if ($anchored) {
            // Anchor to the project root; the '(?:/.*)?' suffix ignores descendants
            // when the pattern names a directory (gitignore directory semantics).
            return preg_match('#^' . $regex . '(?:/.*)?$#', $path) === 1;
        }

        // A slash-free pattern matches a file or directory of that name at any depth;
        // matching any path segment covers both the file itself and ignored contents.
        foreach ($segments as $segment) {
            if (preg_match('#^' . $regex . '$#', $segment) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Translate a normalised glob fragment into a PCRE body (delimiter '#') and
     * confirm the result actually compiles.
     *
     * `preg_match()`'s `false` return used to go unchecked: a class containing the
     * '#' delimiter or one left unterminated compiled to garbage that silently
     * matched nothing on every path, so a project believed a directory was
     * excluded while it was still being scanned. Checking here turns that into a
     * loud, attributable configuration error instead.
     *
     * @param string|null $original the as-written pattern to name in the error, when it
     *     differs from $pattern (the constructor normalises before compiling — stripping
     *     whitespace, the negation marker, and anchoring slashes — and reporting that
     *     stripped-down form back to the user would weaken the attribution this exists for)
     * @throws DiscoveryException when the pattern does not compile to a valid regex
     */
    private static function compile(string $pattern, ?string $original = null): string
    {
        $regex = self::toRegex($pattern);
        if (@preg_match('#^' . $regex . '$#', '') === false) {
            $shown = $original ?? $pattern;
            // json_encode() itself returns false on invalid UTF-8, which would
            // otherwise render as an empty slot in the message; var_export() has
            // no such failure mode.
            $encoded = json_encode($shown);
            throw new DiscoveryException(sprintf(
                'PROJECT_CONFIG_INVALID: ignore pattern %s is not a valid glob.',
                $encoded === false ? var_export($shown, true) : $encoded,
            ));
        }

        return $regex;
    }

    /** Translate a gitignore glob fragment into a PCRE body (delimiter '#'). */
    private static function toRegex(string $pattern): string
    {
        $out = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $char = $pattern[$i];
            if ($char === '*') {
                if ($i + 1 < $length && $pattern[$i + 1] === '*') {
                    ++$i;
                    if ($i + 1 < $length && $pattern[$i + 1] === '/') {
                        ++$i;
                        $out .= '(?:.*/)?';
                    } else {
                        $out .= '.*';
                    }
                } else {
                    $out .= '[^/]*';
                }
            } elseif ($char === '?') {
                $out .= '[^/]';
            } elseif ($char === '[') {
                $close = self::characterClassEnd($pattern, $i, $length);
                if ($close === null) {
                    $out .= '\\[';
                } else {
                    $out .= self::characterClass(substr($pattern, $i, $close - $i + 1));
                    $i = $close;
                }
            } else {
                $out .= preg_quote($char, '#');
            }
        }

        return $out;
    }

    /**
     * Translate one bracket class, escaping its body.
     *
     * The body used to be copied verbatim into a '#'-delimited pattern, so a
     * class containing '#' terminated the delimiter and one ending in '\]' left
     * the class unterminated — both compiled to nothing, preg_match returned
     * false, and the pattern silently excluded nothing at all. Only the
     * gitignore-to-PCRE negation ('!' becomes '^') and ranges are meaningful
     * here; everything else is a literal.
     *
     * $class always has a non-empty body once the negation marker is stripped:
     * characterClassEnd() only reports a class as terminated after it has
     * consumed at least one body character following the optional negation
     * marker (either a literal-first ']' or whatever the scan for the real
     * terminator found), so there is no bracket-class fallback here — an
     * unterminated class never reaches this method at all.
     *
     * A POSIX class ('[:alpha:]' and friends) is the exception to "everything
     * else is a literal": PCRE spells it the same way fnmatch does, so it is
     * copied through untouched. Quoting it produced '[\[\:alpha\:\]]', which
     * does not match 'a' but does match 'a]' — a quiet wrong answer about which
     * files a project scans, not a loud one.
     */
    private static function characterClass(string $class): string
    {
        $body = substr($class, 1, -1);
        $negated = str_starts_with($body, '!') || str_starts_with($body, '^');
        if ($negated) {
            $body = substr($body, 1);
        }
        $escaped = '';
        $length = strlen($body);
        for ($i = 0; $i < $length; ++$i) {
            $posix = self::posixClassEnd($body, $i, $length);
            if ($posix !== null) {
                $escaped .= substr($body, $i, $posix - $i);
                $i = $posix - 1;
                continue;
            }
            $char = $body[$i];
            // A range hyphen between two literals is the one metacharacter a
            // gitignore class may carry; everything else is quoted. An invalid
            // range (for example a descending one) still fails to compile —
            // compile() turns that into a loud PROJECT_CONFIG_INVALID rather than
            // a silently-empty match.
            $escaped .= $char === '-' && $i > 0 && $i < $length - 1 ? '-' : preg_quote($char, '#');
        }

        return '[' . ($negated ? '^' : '') . $escaped . ']';
    }

    /** The index closing a bracket class, or null when it is unterminated. */
    private static function characterClassEnd(string $pattern, int $start, int $length): ?int
    {
        $j = $start + 1;
        // Both negation spellings ('!' and '^') are followed by the same
        // literal-first-']' allowance below; skipping only '!' left '[^]x]' — a
        // valid gitignore/POSIX class — closing two characters early, at the
        // ']' that is actually the class's first (negated-away) member.
        if ($j < $length && ($pattern[$j] === '!' || $pattern[$j] === '^')) {
            ++$j;
        }
        if ($j < $length && $pattern[$j] === ']') {
            ++$j;
        }
        while ($j < $length && $pattern[$j] !== ']') {
            // The ']' inside '[:alpha:]' belongs to the POSIX class, not to the
            // bracket class around it. Stopping there cut '[[:alpha:]]' short at
            // '[[:alpha:]' and left the trailing ']' to be read as a literal.
            $j = self::posixClassEnd($pattern, $j, $length) ?? $j + 1;
        }

        return $j < $length ? $j : null;
    }

    /**
     * The index just past the POSIX class starting at $start, or null when none
     * starts there.
     *
     * Only the '[:' … ':]' shape is recognised. An unterminated '[:' is not a
     * class at all and falls back to the literal '[' the surrounding scan
     * already handled.
     */
    private static function posixClassEnd(string $subject, int $start, int $length): ?int
    {
        if ($start + 1 >= $length || $subject[$start] !== '[' || $subject[$start + 1] !== ':') {
            return null;
        }
        $close = strpos($subject, ':]', $start + 2);

        return $close === false || $close + 2 > $length ? null : $close + 2;
    }
}
