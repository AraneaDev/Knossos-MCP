/**
 * Per-SourceFile caches shared by the host that reads a file and the code that
 * later reports on it. Both are keyed weakly by the SourceFile, so an entry
 * lives exactly as long as the program that holds the file.
 */

// The hash of the raw bytes each SourceFile was created from, keyed by the
// SourceFile object itself. Keyed by object rather than by path because
// programs are cached across requests and several programs can read the same
// path: a path-keyed map could pair one read's facts with another read's hash,
// which is precisely the false match the hash exists to prevent.
export const parsedContentHashes = new WeakMap();

/**
 * A component's virtual source, by the source file made from it: its dialect,
 * the script and template ranges, whether its script is TypeScript, and, for
 * one that could not be read, why.
 */
export const componentSources = new WeakMap();
