/**
 * Records the moment the TypeScript compiler is resolved, into the file named by
 * `KNOSSOS_TS_LOAD_MARKER`.
 *
 * Registered with `--import` by the worker-startup test, so "did the handshake
 * load the compiler?" is answered by observing the module graph rather than by
 * timing, which would be flaky on a loaded machine and would prove nothing about
 * why the worker was fast. A file rather than a stream because resolve hooks run
 * off the main thread, where a write racing process exit can be lost.
 */
import { register } from "node:module";

register(
    "data:text/javascript," +
        encodeURIComponent(`
            import { appendFileSync } from "node:fs";

            export async function resolve(specifier, context, nextResolve) {
                if (specifier === "typescript" && process.env.KNOSSOS_TS_LOAD_MARKER) {
                    appendFileSync(process.env.KNOSSOS_TS_LOAD_MARKER, "loaded\\n");
                }
                return nextResolve(specifier, context);
            }
        `),
    import.meta.url,
);
