<?php

declare(strict_types=1);

namespace Knossos\Discovery;

use Closure;

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
        '.next',
        '.nuxt',
        '.venv',
        'venv',
        '__pycache__',
        '.tox',
        '.mypy_cache',
        '.pytest_cache',
        // Git worktree checkouts are separate copies of a project, not source
        // belonging to the checkout that contains the .worktrees directory.
        // Walking them also creates duplicate manifest boundaries and can make
        // a scan grow by several complete repositories.
        '.worktrees',
        // Generated build output and mutation-testing sandboxes are not source.
        // '.stryker-tmp' in particular holds one full project copy per sandbox
        // (each with its own tsconfig), which would otherwise multiply the
        // TypeScript program count and make scans slow or time out.
        '.stryker-tmp',
        // Package-manager stores: dependency code, like node_modules.
        '.pnpm-store',
        '.yarn',
    ];

    /**
     * Build output names, excluded only directly under the project root or
     * under a manifest root (a directory holding a package or build manifest).
     *
     * Excluded at any depth they took real source with them: `src/build`,
     * `apps/site`. Where a build tool writes is beside the manifest that
     * configures it, so that is the only place they are taken as output, and a
     * `!` pattern re-includes them there.
     */
    public const ANCHORED_SEGMENTS = ['build', 'coverage', 'dist', 'site'];

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

    /**
     * Consecutive segments excluded wherever they appear: VitePress writes its
     * prebundled dependencies and its build below the site's own directory,
     * and `cache` or `dist` alone would say too little to exclude on.
     */
    private const EXCLUDED_SEGMENT_SEQUENCES = [
        ['.vitepress', 'cache'],
        ['.vitepress', 'dist'],
    ];

    /**
     * File-name suffixes of minified bundles: vendored libraries and build
     * output, with no architecture of their own to read.
     */
    private const EXCLUDED_FILE_SUFFIXES = ['.min.js', '.min.mjs', '.min.cjs'];

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
     * POSIX bracket classes as the ASCII ranges PCRE reads them as without the
     * `u` modifier, for regex engines that do not know the POSIX names.
     */
    private const PORTABLE_POSIX_CLASSES = [
        '[:alnum:]' => 'a-zA-Z0-9',
        '[:alpha:]' => 'a-zA-Z',
        '[:blank:]' => ' \t',
        '[:cntrl:]' => '\x00-\x1f\x7f',
        '[:digit:]' => '0-9',
        '[:graph:]' => '\x21-\x7e',
        '[:lower:]' => 'a-z',
        '[:print:]' => '\x20-\x7e',
        '[:punct:]' => '\x21-\x2f\x3a-\x40\x5b-\x60\x7b-\x7e',
        '[:space:]' => ' \t\n\r\x0b\x0c',
        '[:upper:]' => 'A-Z',
        '[:word:]' => 'a-zA-Z0-9_',
        '[:xdigit:]' => '0-9A-Fa-f',
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
     * @param ?Closure(string): bool $isManifestRoot whether a project-relative
     *        directory is a manifest root, so build output directly below it is
     *        excluded; null means only the project root anchors build output
     * @throws DiscoveryException when a pattern does not compile to a valid regex
     */
    public function __construct(array $patterns, private ?Closure $isManifestRoot = null)
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

    /**
     * The rules {@see matches()} applies, in a form a worker in any language
     * applies to the paths it reads, so it leaves out what discovery leaves out.
     *
     * A path is excluded when it, or a directory above it, matches: discovery
     * never descends into a directory that matches. `segments`, `prefixes`,
     * `sequences`, `suffixes` and `path_prefixes` exclude outright. A segment in
     * `anchored_segments` whose parent directory is one of `anchor_roots` (`''`
     * for the project root, which is always one) marks the path ignored before
     * the patterns are applied, so a later `!` pattern can re-include it. A
     * pattern's `regex` is a body to anchor as `^body$` (followed by `(?:/.*)?`
     * when `anchored`, and matched against each segment when not), with POSIX
     * bracket classes spelled out, since JavaScript and Python do not read
     * them. The last pattern that matches decides, and a `negated` one
     * re-includes.
     *
     * @param list<string> $anchorRoots the manifest roots discovery saw, the
     *        directories the matcher's predicate answers true for
     * @return array{segments: list<string>, anchored_segments: list<string>, anchor_roots: list<string>, prefixes: list<string>, sequences: list<array{0: string, 1: string}>, suffixes: list<string>, path_prefixes: list<string>, patterns: list<array{regex: string, anchored: bool, negated: bool}>}
     */
    public function workerRules(array $anchorRoots = ['']): array
    {
        $roots = array_values(array_unique(['', ...$anchorRoots]));
        sort($roots);

        return [
            'segments' => self::EXCLUDED_SEGMENTS,
            'anchored_segments' => self::ANCHORED_SEGMENTS,
            'anchor_roots' => $roots,
            'prefixes' => self::EXCLUDED_SEGMENT_PREFIXES,
            'sequences' => self::EXCLUDED_SEGMENT_SEQUENCES,
            'suffixes' => self::EXCLUDED_FILE_SUFFIXES,
            'path_prefixes' => self::EXCLUDED_PREFIXES,
            'patterns' => array_map(
                static fn(array $pattern): array => [
                    'regex' => strtr($pattern[2], self::PORTABLE_POSIX_CLASSES),
                    'anchored' => $pattern[1],
                    'negated' => $pattern[3],
                ],
                $this->compiled,
            ),
        ];
    }

    /**
     * Whether the walk leaves a path out: the path or a directory above it
     * matches. The walk never enters an excluded directory, so a later `!`
     * pattern naming something below one re-includes nothing, as in git.
     */
    public function matchesWithAncestors(string $relativePath): bool
    {
        $segments = self::segments($relativePath);
        for ($end = 1, $count = count($segments); $end <= $count; ++$end) {
            if ($this->matches(implode('/', array_slice($segments, 0, $end)))) {
                return true;
            }
        }

        return false;
    }

    /** Whether a path is ignored, applying built-in exclusions then the user patterns. */
    public function matches(string $relativePath): bool
    {
        $segments = self::segments($relativePath);
        if (self::absoluteBuiltIn($segments)) {
            return true;
        }

        return $this->userDecision($segments) ?? $this->anchored($segments);
    }

    /**
     * Whether the anchored build-output rule, and nothing else, is what
     * excludes this path: no absolute built-in covers it and no user pattern
     * matches it, either to ignore it (the user asked for that) or to re-include
     * it. Discovery reports exactly these directories as skipped build output.
     */
    public function anchoredBuiltIn(string $relativePath): bool
    {
        $segments = self::segments($relativePath);

        return !self::absoluteBuiltIn($segments)
            && $this->userDecision($segments) === null
            && $this->anchored($segments);
    }

    /** @return list<string> */
    private static function segments(string $relativePath): array
    {
        $path = trim(str_replace('\\', '/', $relativePath), '/');

        return $path === '' ? [] : explode('/', $path);
    }

    /**
     * The built-ins no pattern overrides: dependency and tool directories at
     * any depth, fixed path prefixes, minified bundles, segment pairs.
     *
     * @param list<string> $segments
     */
    private static function absoluteBuiltIn(array $segments): bool
    {
        $path = implode('/', $segments);
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
        $basename = $segments === [] ? '' : $segments[count($segments) - 1];
        foreach (self::EXCLUDED_FILE_SUFFIXES as $suffix) {
            if (str_ends_with($basename, $suffix)) {
                return true;
            }
        }
        foreach (self::EXCLUDED_SEGMENT_SEQUENCES as [$first, $second]) {
            for ($i = 0, $last = count($segments) - 1; $i < $last; ++$i) {
                if ($segments[$i] === $first && $segments[$i + 1] === $second) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether a build-output segment sits directly under the project root or a
     * manifest root.
     *
     * @param list<string> $segments
     */
    private function anchored(array $segments): bool
    {
        foreach ($segments as $index => $segment) {
            if (!in_array($segment, self::ANCHORED_SEGMENTS, true)) {
                continue;
            }
            if ($index === 0) {
                return true;
            }
            if ($this->isManifestRoot !== null && ($this->isManifestRoot)(implode('/', array_slice($segments, 0, $index)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The verdict of the last user pattern that matches, or null when none does.
     *
     * User patterns follow gitignore semantics: last matching pattern wins, a
     * leading '!' re-includes, a slash-free pattern matches a basename at any
     * depth, and '**' spans directory segments. They decide after the anchored
     * build-output rule, so a '!' pattern re-includes build output; the
     * absolute built-ins are decided before them and cannot be negated.
     *
     * @param list<string> $segments
     */
    private function userDecision(array $segments): ?bool
    {
        $path = implode('/', $segments);
        $ignored = null;
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
            // A range hyphen is the one metacharacter a gitignore class may
            // carry; everything else is quoted. It is passed through wherever
            // it stands: PCRE reads a hyphen at either end of a class as a
            // literal, exactly as fnmatch does, so its position needs no test.
            // An invalid range (for example a descending one) still fails to
            // compile, and compile() turns that into a loud
            // PROJECT_CONFIG_INVALID rather than a silently-empty match.
            $escaped .= $char === '-' ? '-' : preg_quote($char, '#');
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

        return $close === false ? null : $close + 2;
    }
}
