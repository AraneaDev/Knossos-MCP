/**
 * The exclusion rules that keep paths out of discovery.
 *
 * The core sends its rules with each request; until one arrives the built-in
 * defaults apply. The rules of the request in progress are held here and read
 * by every discovery walk, so `TypeScriptScanner.scan` sets them through
 * `setActiveExclusions` before it reads anything.
 */

// What discovery leaves out, for a request that does not carry the core's own
// rules: the directories a project vendors under or keeps tool state in, the
// namespace this tool owns (`.knossos-ci` beside a project), and build output
// directly under the project root. A request that carries `exclusions`, the
// rules the core's IgnoreMatcher applies, uses those instead for its duration.
export const BUILT_IN_EXCLUSIONS = Object.freeze({
    segments: [
        ".git",
        ".knossos",
        "node_modules",
        "vendor",
        ".next",
        ".nuxt",
        ".stryker-tmp",
        ".pnpm-store",
        ".yarn",
        ".worktrees",
    ],
    anchored_segments: ["build", "coverage", "dist", "site"],
    anchor_roots: [""],
    prefixes: [".knossos-"],
    sequences: [
        [".vitepress", "cache"],
        [".vitepress", "dist"],
    ],
    suffixes: [],
    path_prefixes: [],
    patterns: [],
});

/** The exclusion rules of the request in progress (see exclusionRules). */
let activeExclusions = exclusionRules(BUILT_IN_EXCLUSIONS);

/** Makes `rules` (from exclusionRules) the rules of the request in progress. */
export function setActiveExclusions(rules) {
    activeExclusions = rules;
}

/**
 * Exclusion rules ready to apply, from the core's `exclusions` object (see
 * IgnoreMatcher::workerRules in the core), each pattern compiled once.
 * `anchored_segments` and `anchor_roots` are optional: without them no
 * segment is anchored.
 *
 * @throws {Error} for anything but that object
 */
export function exclusionRules(input) {
    const strings = (value) =>
        Array.isArray(value) && value.every((item) => typeof item === "string");
    const valid =
        input !== null &&
        typeof input === "object" &&
        ["segments", "prefixes", "suffixes", "path_prefixes"].every((field) =>
            strings(input[field]),
        ) &&
        ["anchored_segments", "anchor_roots"].every(
            (field) => input[field] === undefined || strings(input[field]),
        ) &&
        Array.isArray(input.sequences) &&
        input.sequences.every((pair) => strings(pair) && pair.length === 2) &&
        Array.isArray(input.patterns) &&
        input.patterns.every(
            (pattern) =>
                typeof pattern?.regex === "string" &&
                typeof pattern.anchored === "boolean" &&
                typeof pattern.negated === "boolean",
        );
    if (!valid)
        throw new Error(
            "TypeScript exclusions must be an object of segments, anchored_segments, anchor_roots, prefixes, sequences, suffixes, path_prefixes and patterns.",
        );
    return {
        segments: new Set(input.segments),
        anchoredSegments: new Set(input.anchored_segments ?? []),
        anchorRoots: new Set(input.anchor_roots ?? []),
        prefixes: input.prefixes,
        sequences: input.sequences,
        suffixes: input.suffixes,
        pathPrefixes: input.path_prefixes,
        patterns: input.patterns.map(({ regex, anchored, negated }) => ({
            expression: new RegExp(
                anchored ? `^(?:${regex})(?:/.*)?$` : `^(?:${regex})$`,
            ),
            anchored,
            negated,
        })),
    };
}

/**
 * Whether the given `exclusions` rules leave a project-relative path out, as
 * a scan applies them. Exported so the rules can be checked against the
 * core's shared case list without a scan; only that test calls it, which is
 * why `knossos.json` lists it under `dead_code_suppressions`.
 *
 * @throws {Error} when `exclusions` is not the rules object
 */
export function excludedBy(exclusions, relative) {
    return excludedByRules(exclusionRules(exclusions), relative);
}

/** Whether discovery leaves a project-relative path out, under the request's rules. */
export function excludedFromDiscovery(relative) {
    return excludedByRules(activeExclusions, relative);
}

/**
 * Whether discovery leaves a project-relative path out: the path, or a
 * directory above it, matches the rules, since discovery never descends into
 * a directory that matches. A segment rule, a file-name suffix and a path
 * prefix that match a directory match everything below it. Each directory in
 * turn is then marked ignored when it lies in an anchored segment directly
 * under an anchor root (build output beside a manifest), and the patterns are
 * asked of it, the last match deciding, so a negated pattern re-includes
 * build output.
 */
function excludedByRules(rules, relative) {
    const segments = relative.split("/");
    const bySegment = segments.some(
        (segment, index) =>
            rules.segments.has(segment) ||
            rules.prefixes.some((prefix) => segment.startsWith(prefix)) ||
            rules.suffixes.some((suffix) => segment.endsWith(suffix)) ||
            rules.sequences.some(
                ([first, second]) =>
                    segment === first && segments[index + 1] === second,
            ),
    );
    if (
        bySegment ||
        rules.pathPrefixes.some(
            (prefix) =>
                relative === prefix || relative.startsWith(`${prefix}/`),
        )
    )
        return true;
    let anchored = false;
    for (let end = 1; end <= segments.length; ++end) {
        anchored ||=
            rules.anchoredSegments.has(segments[end - 1]) &&
            rules.anchorRoots.has(segments.slice(0, end - 1).join("/"));
        if (patternsIgnore(rules.patterns, segments.slice(0, end), anchored))
            return true;
    }
    return false;
}

/**
 * Whether a path is ignored: the last pattern that matches decides, and with
 * none matching, whether an anchored segment already marked it.
 */
function patternsIgnore(patterns, segments, ignoredBefore) {
    const joined = segments.join("/");
    let ignored = ignoredBefore;
    for (const pattern of patterns) {
        const matched = pattern.anchored
            ? pattern.expression.test(joined)
            : segments.some((segment) => pattern.expression.test(segment));
        if (matched) ignored = !pattern.negated;
    }
    return ignored;
}
