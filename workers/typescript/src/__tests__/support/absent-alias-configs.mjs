/**
 * The bundler configs a program's directory is probed for, whose absence each
 * request records as a null in `input_hashes`: a config that appears later
 * declares aliases that change what imports resolve to.
 *
 * Tests about other reads leave those nulls out with `withoutAbsentAliasConfigs`;
 * read-attribution.test.js asserts them.
 */
export const ALIAS_CONFIG_NAMES = [
    "svelte.config.js",
    "svelte.config.mjs",
    "svelte.config.ts",
    "vite.config.js",
    "vite.config.mjs",
    "vite.config.ts",
    "vite.config.mts",
    "webpack.config.js",
    "webpack.config.cjs",
    "webpack.config.mjs",
    "webpack.mix.js",
    "vue.config.js",
];

/** The map without the null probes for absent bundler configs. */
export function withoutAbsentAliasConfigs(hashes) {
    return Object.fromEntries(
        Object.entries(hashes).filter(
            ([key, value]) =>
                value !== null ||
                !ALIAS_CONFIG_NAMES.includes(key.split("/").at(-1)),
        ),
    );
}
