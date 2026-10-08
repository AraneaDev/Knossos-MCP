/**
 * The candidates a relative import or `require` is tried at, in Node's order
 * and then a TypeScript loader's, when the checker could not bind it to a file
 * of the program. Each one the worker finds absent is a probe its facts
 * depended on, so `input_hashes` reports it as null.
 */
export const REQUIRE_SUFFIXES = [
    "",
    ".js",
    "/index.js",
    ".ts",
    ".tsx",
    ".cts",
    ".mts",
    ".jsx",
    ".cjs",
    ".mjs",
    ".d.ts",
    "/index.ts",
    "/index.tsx",
    "/index.jsx",
    "/index.d.ts",
];

/**
 * Null for each candidate of `base` tried before the one found, `until`
 * (excluded), or for all of them when nothing was found.
 */
export function absentRequireCandidates(base, until) {
    const tried =
        until === undefined
            ? REQUIRE_SUFFIXES
            : REQUIRE_SUFFIXES.slice(0, REQUIRE_SUFFIXES.indexOf(until));
    return Object.fromEntries(
        tried.map((suffix) => [`${base}${suffix}`, null]),
    );
}
