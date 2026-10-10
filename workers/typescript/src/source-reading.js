/**
 * Reading config and source files the way the compiler would.
 *
 * Every read goes through the walk and the byte cap, decodes the bytes as
 * TypeScript does and is recorded as an input of the request. A component's
 * source is read once, turned into virtual TypeScript and cached on the
 * SourceFile so its diagnostics can be reported against the real file.
 */

import { createHash } from "node:crypto";
import path from "node:path";
import ts from "typescript";
import { readBounded } from "./byte-caps.js";
import {
    blankSource,
    componentDialect,
    toVirtualSource,
} from "./component-source.js";
import { errorMessage, rethrowStackOverflow } from "./errors.js";
import { recordWalked } from "./input-reads.js";
import {
    contains,
    defaultLibDirectory,
    normalize,
    realSourcePath,
    walkPath,
} from "./project-paths.js";
import { componentSources, parsedContentHashes } from "./source-caches.js";

/**
 * Read a file for the compiler within the byte cap, decoded as TypeScript
 * decodes it, recording the read under its walk's keys: the hash of the raw
 * bytes read, or null when the read failed or went over the cap. A default
 * library file is exempt from the cap and never recorded, since discovery
 * never reports one.
 */
export function readRecorded(root, file, reads, maxFileBytes) {
    const normalized = realSourcePath(normalize(path.resolve(file)));
    const library = contains(defaultLibDirectory(), normalized);
    let buffer;
    try {
        buffer = readBounded(
            normalized,
            library ? Number.MAX_SAFE_INTEGER : maxFileBytes,
        );
    } catch (error) {
        rethrowStackOverflow(error);
        buffer = undefined;
    }
    if (!library)
        recordWalked(
            reads,
            root,
            walkPath(normalized),
            buffer === undefined
                ? null
                : createHash("sha256").update(buffer).digest("hex"),
            maxFileBytes,
        );
    return buffer === undefined ? undefined : decodeLikeTypeScript(buffer);
}

/**
 * Decode a file's bytes into the string ts.sys.readFile would return, so
 * reading the buffer ourselves (to hash it) changes nothing the compiler sees.
 * Mirrors TypeScript 6.0's node `readFile`: UTF-16 BE and LE byte-order marks
 * decode as UTF-16, a UTF-8 BOM is dropped, anything else is UTF-8.
 */
function decodeLikeTypeScript(buffer) {
    if (buffer.length >= 2 && buffer[0] === 0xfe && buffer[1] === 0xff) {
        // Copied before swapping: the caller's buffer is what was hashed.
        const swapped = Buffer.from(buffer.subarray(0, buffer.length & ~1));
        swapped.swap16();
        return swapped.toString("utf16le", 2);
    }
    if (buffer.length >= 2 && buffer[0] === 0xff && buffer[1] === 0xfe) {
        return buffer.toString("utf16le", 2);
    }
    if (
        buffer.length >= 3 &&
        buffer[0] === 0xef &&
        buffer[1] === 0xbb &&
        buffer[2] === 0xbf
    ) {
        return buffer.toString("utf8", 3);
    }
    return buffer.toString("utf8");
}

/**
 * Read, hash and parse one file in a single read, so the hash is over exactly
 * the bytes the SourceFile was built from, and record the read for the request.
 * Returns undefined when the file cannot be read, the same "skip this input"
 * signal ts.sys.readFile gives.
 *
 * The path is resolved once, by walkPath, and that one walk decides everything:
 * whether the file may be read (it must end at a file inside the root, or be a
 * default-library file), the path the bytes are read from (the file the walk
 * ended at), and the key the read goes under (inputKeyLocation). Discovery
 * never follows a symlink, so a linked name is not a path the core tracks,
 * while the file its bytes came from is. A tsconfig `include` walks through
 * links (and `preserveSymlinks` keeps linked import paths), so both names do
 * reach this host. A path that is refused here is not read at all; the compiler
 * goes on as if the file did not exist, which changes the facts of every file
 * importing it, so it is recorded as a failed read under the same key.
 *
 * The tree can still change between the walk and the read, and the read is
 * still verified. A read that fails is recorded as null. A read that opens a
 * file other than the one the walk reached, because a component became a link
 * in between, hashes the bytes it actually got and records that hash under the
 * walk's keys, where it disagrees with what discovery hashed for the keyed path
 * unless the bytes are the same, in which case so are the facts. The read is
 * bounded to one byte past the cap for the same reason: whatever it opens, it
 * never reads more than a file the host would accept.
 *
 * @param {import("./input-reads.js").InputReadRecorder} reads the request's recorder
 */
export function readHashedSourceFile(
    root,
    readPath,
    fileName,
    languageVersion,
    scriptKind,
    reads,
    maxFileBytes,
) {
    const absolute = normalize(path.resolve(readPath));
    reads.observe("read", absolute);
    const walked = walkPath(absolute);
    const library = contains(defaultLibDirectory(), absolute);
    if (
        walked.kind !== "file" ||
        !(contains(root, walked.location) || library)
    ) {
        recordWalked(reads, root, walked, null, maxFileBytes);
        return undefined;
    }
    let buffer;
    try {
        // Default-library declaration files are exempt from the cap, as in
        // exceedsByteCap.
        buffer = readBounded(
            walked.location,
            library ? Number.MAX_SAFE_INTEGER : maxFileBytes,
        );
    } catch (error) {
        rethrowStackOverflow(error);
        buffer = undefined;
    }
    if (buffer === undefined) {
        recordWalked(reads, root, walked, null, maxFileBytes);
        return undefined;
    }
    const contentHash = createHash("sha256").update(buffer).digest("hex");
    const decoded = decodeLikeTypeScript(buffer);
    const component = componentSource(readPath, decoded);
    const sourceFile = ts.createSourceFile(
        fileName,
        component?.text ?? decoded,
        component === undefined
            ? languageVersion
            : asComponentModule(languageVersion),
        true,
        scriptKind,
    );
    if (component !== undefined) componentSources.set(sourceFile, component);
    parsedContentHashes.set(sourceFile, contentHash);
    reads.created(sourceFile);
    recordWalked(reads, root, walked, contentHash, maxFileBytes);
    return sourceFile;
}

/**
 * The virtual source a component is parsed from, or undefined for any other
 * file. One that cannot be delimited is parsed as blank text, so it keeps its
 * module node, and says why.
 */
function componentSource(readPath, decoded) {
    const dialect = componentDialect(readPath);
    if (dialect === null) return undefined;
    try {
        return { dialect, ...toVirtualSource(decoded, dialect, readPath) };
    } catch (error) {
        rethrowStackOverflow(error);
        return {
            dialect,
            text: blankSource(decoded),
            scriptRanges: [],
            templateRanges: [],
            typed: false,
            unparsed: errorMessage(error),
        };
    }
}

/**
 * Parse options that make a component a module whatever its script says.
 *
 * A `<script setup>` that neither imports nor exports reads to the compiler as
 * a global script, and an import of the component then resolves to nothing.
 * Every component is a module to its bundler, with its compiled component as
 * the default export.
 */
function asComponentModule(languageVersion) {
    const options =
        typeof languageVersion === "object"
            ? languageVersion
            : { languageVersion };
    return {
        ...options,
        setExternalModuleIndicator: (file) => {
            file.externalModuleIndicator = true;
        },
    };
}
