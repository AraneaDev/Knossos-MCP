/**
 * The diagnostics a built program contributes: each file's own syntactic and
 * semantic diagnostics, the program-wide ones anchored to a carrier file,
 * component parse failures and declaration files over the byte cap.
 */

import ts from "typescript";
import { exceedsByteCap } from "./byte-caps.js";
import {
    componentDiagnosticKept,
    componentDialect,
} from "./component-source.js";
import { rethrowStackOverflow } from "./errors.js";
import { belowNodeModules, relativeInside } from "./project-paths.js";
import { componentSources } from "./source-caches.js";

/**
 * The errors that say a global name or a type library is missing: "Cannot
 * find name", the hints to install `@types/node` or a test runner's types or
 * to change `lib`, and a `types` entry that is not installed. Under a
 * tsconfig they are real; in a fallback program they describe the `types`
 * and `lib` it does not inherit. A missing module stays: the fallback does
 * inherit how modules resolve.
 */
const UNKNOWN_GLOBAL_CODES = new Set(
    [
        2304, 2503, 2552, 2580, 2581, 2582, 2583, 2584, 2591, 2592, 2593, 2688,
    ].map((code) => `TS${code}`),
);

/**
 * A program's compiler diagnostics, by file and program-wide.
 *
 * Compiler diagnostics are best-effort: failing to compute them must not cost
 * the facts. A stack overflow still reaches the program-level backstop, which
 * reports the files as too deep.
 *
 * No tsconfig includes a fallback program's files, and the fallback inherits
 * no `types` or `lib`: a missing global or type library is its gap. Its
 * options are partly made up by the scanner rather than read from the user's
 * config, so an option error that names no file describes those made-up
 * options, not anything the user can fix, and is dropped.
 */
export function programDiagnostics(program, root, maxFileBytes, fallback) {
    let byFile = new Map();
    let programLevel = [];
    try {
        ({ byFile, programLevel } = diagnosticsForProgram(
            program,
            root,
            maxFileBytes,
        ));
    } catch (error) {
        rethrowStackOverflow(error);
    }
    if (!fallback) return { byFile, programLevel };
    for (const [relative, items] of byFile) {
        byFile.set(
            relative,
            items.filter((item) => !UNKNOWN_GLOBAL_CODES.has(item.code)),
        );
    }
    return { byFile, programLevel: [] };
}

/** Program-wide diagnostics, each anchored at the first line of one file. */
export function anchoredAt(programLevel, relative) {
    return programLevel.map((item) => ({
        ...item,
        evidence: { path: relative, start_line: 1, end_line: 1 },
    }));
}

/**
 * The one file of a program that carries its program-wide diagnostics.
 *
 * The sorted first of the program's own root files that a contribution can be
 * made for: inside the root, outside node_modules, owned by this config rather
 * than another that includes it, nameable by the core, loaded, and not a
 * redirected duplicate of another package copy. It depends only on the
 * program, so every request agrees on it however the files are batched.
 */
export function programWideCarrier(program, root, owner, owners) {
    const candidates = [];
    for (const fileName of program.getRootFileNames()) {
        const relative = relativeInside(root, fileName);
        if (
            relative === null ||
            belowNodeModules(relative) ||
            // Discovery skips a name with a control character, so such a file
            // is never requested and would carry the diagnostics nowhere.
            hasControlCharacter(relative) ||
            (owners.has(relative) && owners.get(relative) !== owner)
        )
            continue;
        const sourceFile = program.getSourceFile(fileName);
        if (sourceFile === undefined || sourceFile.redirectInfo !== undefined)
            continue;
        candidates.push(relative);
    }
    return candidates.sort()[0];
}

/** Whether a name holds a C0 control character or DEL, which the core cannot carry. */
function hasControlCharacter(name) {
    for (let index = 0; index < name.length; index++) {
        const code = name.charCodeAt(index);
        if (code < 32 || code === 127) return true;
    }
    return false;
}

/**
 * A program's compiler diagnostics by file, and the ones that name no file.
 *
 * The whole program is checked, in program order, before any fact is
 * collected, whichever files the request names. Checking one file can change
 * what the checker has cached for the next: a type instantiated in one file
 * comes back whole in another where, checked alone, that file ran out of
 * instantiation depth and its facts differed. Checking every file keeps a
 * file's facts and diagnostics the same in every batch, at the cost of the
 * program's whole type check for a one-file request. A diagnostic that names
 * no file is taken before the check (see programLevelDiagnostics).
 */
function diagnosticsForProgram(program, root, maxFileBytes) {
    const result = new Map();
    const programLevel = programLevelDiagnostics(program);
    for (const diagnostic of ts.getPreEmitDiagnostics(program)) {
        if (diagnostic.file)
            addFileDiagnostic(
                { program, root, maxFileBytes },
                diagnostic,
                result,
            );
    }
    componentParseDiagnostics(program, root, result);
    return { byFile: result, programLevel };
}

/**
 * The diagnostics of a program that name no file: an option or configuration
 * error, or a global type the checker cannot find. Each applies to the whole
 * program and is reported once, on the program's carrier.
 *
 * Taken before any file is checked. Checking a file can add a global
 * diagnostic of its own (a global type only that file's code asks for), and
 * the carrier is not rebuilt when another file's edit adds or removes one,
 * so it would go stale after an incremental scan. What the checker reports
 * when it is created, and what the config and options report, depends only
 * on the program's configuration.
 */
function programLevelDiagnostics(program) {
    const programLevel = [];
    const diagnostics = ts.sortAndDeduplicateDiagnostics([
        ...program.getConfigFileParsingDiagnostics(),
        ...program.getOptionsDiagnostics(),
        ...program.getGlobalDiagnostics(),
    ]);
    for (const diagnostic of diagnostics) {
        if (diagnostic.file) continue;
        const message =
            ts.flattenDiagnosticMessageText(diagnostic.messageText, "\n") +
            " (applies to the whole program)";
        const code = `TS${diagnostic.code}`;
        if (
            programLevel.some(
                (item) => item.code === code && item.message === message,
            )
        )
            continue;
        programLevel.push({
            severity:
                diagnostic.category === ts.DiagnosticCategory.Error
                    ? "error"
                    : "warning",
            code,
            message,
        });
    }
    return programLevel;
}

/** One compiler diagnostic on a file, added to that file's list. */
function addFileDiagnostic(
    { program, root, maxFileBytes },
    diagnostic,
    result,
) {
    const component = componentSources.get(diagnostic.file);
    if (
        component !== undefined &&
        !componentDiagnosticKept(component, diagnostic)
    )
        return;
    if (diagnostic.code === 6059) return; // Analysis-only project-reference source merging triggers this.
    if (namesComponentDefaultExport(diagnostic)) return;
    const relative = relativeInside(root, diagnostic.file.fileName);
    if (relative === null || belowNodeModules(relative)) return;
    const overCap = declarationOverCap(program, diagnostic, root, maxFileBytes);
    if (overCap !== null) {
        const start = diagnostic.file.getLineAndCharacterOfPosition(
            diagnostic.start ?? 0,
        );
        const list = result.get(relative) ?? [];
        list.push({
            severity: "warning",
            code: "TS_DECLARATION_OVER_CAP",
            message: overCap,
            evidence: {
                path: relative,
                start_line: start.line + 1,
                end_line: start.line + 1,
            },
        });
        result.set(relative, list);
        return;
    }
    const start = diagnostic.start ?? 0;
    const startPosition = diagnostic.file.getLineAndCharacterOfPosition(start);
    const endPosition = diagnostic.file.getLineAndCharacterOfPosition(
        start + (diagnostic.length ?? 0),
    );
    const item = {
        severity:
            diagnostic.category === ts.DiagnosticCategory.Error
                ? "error"
                : "warning",
        code: `TS${diagnostic.code}`,
        message: ts.flattenDiagnosticMessageText(diagnostic.messageText, "\n"),
        evidence: {
            path: relative,
            start_line: startPosition.line + 1,
            end_line: Math.max(startPosition.line + 1, endPosition.line + 1),
        },
    };
    const list = result.get(relative) ?? [];
    list.push(item);
    result.set(relative, list);
}

/**
 * `Module "X.vue" has no default export`, `Module "X.svelte" has no exported
 * member 'default'` where `export { default as X }` names it, and `Property
 * 'default' does not exist on type 'typeof import("X.vue")'` where a dynamic
 * import destructures it: a component's default export is the component its
 * bundler compiles, which its virtual source never spells. Declaring one
 * instead, typed `any`, was worse: a test mounting it lost the component's
 * own typing and reported errors the framework's checker does not.
 */
function namesComponentDefaultExport(diagnostic) {
    const text = ts.flattenDiagnosticMessageText(diagnostic.messageText, "\n");
    if (
        diagnostic.code !== 1192 &&
        !(diagnostic.code === 2305 && /member 'default'/.test(text)) &&
        !(diagnostic.code === 2339 && /Property 'default'/.test(text))
    )
        return false;
    const module =
        /Module '"([^"]+)"'/.exec(text)?.[1] ??
        /typeof import\("([^"]+)"\)/.exec(text)?.[1];
    return module !== undefined && componentDialect(module) !== null;
}

/** A `COMPONENT_UNPARSED` warning for each component that could not be read. */
function componentParseDiagnostics(program, root, result) {
    for (const sourceFile of program.getSourceFiles()) {
        const component = componentSources.get(sourceFile);
        const relative = relativeInside(root, sourceFile.fileName);
        if (component?.unparsed === undefined || relative === null) continue;
        const list = result.get(relative) ?? [];
        list.push({
            severity: "warning",
            code: "COMPONENT_UNPARSED",
            message: `${relative} was not read as a ${component.dialect} component: ${component.unparsed}`,
            evidence: { path: relative, start_line: 1, end_line: 1 },
        });
        result.set(relative, list);
    }
}

/**
 * Why a `Cannot find module` is not what it says, or null when it is.
 *
 * A file over the byte cap is refused to bound memory, and a package whose
 * declaration file is that large resolves perfectly well: the compiler just
 * never receives it, and reports the import as missing. Read as a missing
 * dependency, that sends someone to install a package they already have.
 */
function declarationOverCap(program, diagnostic, root, maxFileBytes) {
    if (diagnostic.code !== 2307) return null;
    const text = ts.flattenDiagnosticMessageText(diagnostic.messageText, "\n");
    const name = /Cannot find module '([^']+)'/.exec(text)?.[1];
    if (name === undefined) return null;
    let resolved;
    program.forEachResolvedModule((resolution, moduleName) => {
        if (moduleName === name)
            resolved ??= resolution.resolvedModule?.resolvedFileName;
    }, diagnostic.file);
    if (resolved === undefined || !exceedsByteCap(resolved, maxFileBytes))
        return null;
    const shown = relativeInside(root, resolved) ?? resolved;
    return `Module '${name}' resolves to ${shown}, which is over the ${maxFileBytes}-byte per-file cap, so the scan did not read it and names imported from it are unresolved.`;
}
