/**
 * Stands in for `react/jsx-runtime` in the mod's vitest suite. The mod is
 * compiled by Claude Code, which supplies its own element factory, so the
 * repository depends on no React package. A spec that only loads a module
 * containing JSX needs the compiled calls to resolve, and these return the
 * element description they were given.
 */

/** A JSX element: its type, its props and its key. */
export function jsx(type, props, key) {
    return { type, props, key: key ?? null };
}

export const jsxs = jsx;
export const jsxDEV = jsx;

/** The fragment marker. */
export const Fragment = Symbol.for("knossos.fragment");
