/**
 * Validating a scan request before any program is built.
 *
 * The limits a request sets, and whether each requested file can be scanned
 * at all: inside the root and still resolving to itself, a regular file
 * within the byte cap, with a source or component extension or, when it has
 * no extension, a shebang that names JavaScript. A file refused here is
 * reported per file; only a request that cannot be interpreted at all is
 * fatal.
 */

import { createHash } from "node:crypto";
import fs from "node:fs";
import path from "node:path";
import { readBounded } from "./byte-caps.js";
import { componentDialect } from "./component-source.js";
import { errorMessage } from "./errors.js";
import {
    assertScannablePath,
    normalize,
    SOURCE_EXTENSIONS,
    validatedInside,
    walkPath,
} from "./project-paths.js";

// Bytes read when probing an extensionless file's shebang; one short line is enough.
const SHEBANG_PROBE_BYTES = 256;

export function maxFileBytesFrom(limits) {
    return Number.isInteger(limits?.max_file_bytes)
        ? limits.max_file_bytes
        : 2_000_000;
}

/**
 * Whether a requested path is still an in-root regular file within the byte
 * cap, reached without following a link.
 */
export function readableAsItself(root, relative, maxFileBytes) {
    const absolute = `${root}/${relative}`;
    const walked = walkPath(absolute);
    if (walked.kind !== "file" || walked.location !== absolute) return false;
    try {
        return fs.statSync(absolute).size <= maxFileBytes;
    } catch {
        return false;
    }
}

/**
 * A requested file the filesystem would not let the worker read as that file.
 * See validateRequestedFiles.
 */
class UnreadableInput extends Error {}

// A requested file refused because of what was read in it: an extensionless
// script whose shebang does not name JavaScript. Discovery routed it here
// because its reading of those bytes did, so a script swapped for another one
// and restored around the probe would otherwise lose its facts from a graph
// reported fresh. The refusal carries evidence for `input_hashes`: the hash of
// the whole file from one bounded read that reached the same verdict, which a
// stable tree matches, or null when that read failed, was over the cap, or
// found JavaScript after all.
class RefusedAfterRead extends Error {
    constructor(message, contentHash) {
        super(message);
        this.contentHash = contentHash;
    }
}

export function validateRequestedFiles(root, files, limits = {}) {
    if (
        !Array.isArray(files) ||
        files.some((file) => typeof file !== "string")
    ) {
        throw new Error(
            "TypeScript scan files must be a list of project-relative paths.",
        );
    }
    const maxFiles = Number.isInteger(limits?.max_files)
        ? limits.max_files
        : 100_000;
    const maxFileBytes = maxFileBytesFrom(limits);
    if (maxFiles < 1 || maxFileBytes < 1 || files.length > maxFiles)
        throw new Error("TypeScript scan limits are invalid or exceeded.");

    // A path this worker refuses, or a file that vanished between discovery and
    // scan, is reported per file rather than raised: only a request that cannot
    // be interpreted at all (checked above) is fatal.
    //
    // A rejection is either a refusal by policy (an extension or shebang this
    // worker does not scan), which says nothing about the tree, or a read the
    // filesystem refused: the path is gone, is not a regular file, is over the
    // byte cap, or resolves somewhere other than where it was requested. The
    // second kind is marked `failedRead`, because the facts-free contribution
    // standing in for the file must not pass verification as if it had been
    // read.
    const accepted = [];
    const rejected = [];
    for (const relative of files) {
        // A malformed path stays fatal: it names no file, so there is nothing to
        // attribute a diagnostic to, and echoing it into a contribution would
        // emit an owner key the graph rejects anyway.
        assertScannablePath(relative);
        const requested = normalize(relative);
        try {
            let absolute;
            try {
                absolute = validatedInside(root, relative);
            } catch (error) {
                throw new UnreadableInput(errorMessage(error));
            }
            // Discovery never follows a link, so a requested path always names
            // its own file. One that now resolves elsewhere is read as another
            // file, whose facts must not stand in for this one's, nor be
            // emitted under the other file's key.
            if (normalize(path.relative(root, absolute)) !== requested)
                throw new UnreadableInput(
                    `TypeScript input no longer resolves to itself: ${relative}`,
                );
            if (
                !SOURCE_EXTENSIONS.has(path.extname(absolute).toLowerCase()) &&
                componentDialect(absolute) === null
            ) {
                if (path.extname(absolute) !== "")
                    throw new Error(
                        `Unsupported TypeScript input: ${relative}`,
                    );
                if (!namesJavaScriptInShebang(absolute))
                    throw new RefusedAfterRead(
                        `Unsupported TypeScript input: ${relative}`,
                        shebangRefusalEvidence(absolute, maxFileBytes),
                    );
            }
            let stat;
            try {
                stat = fs.statSync(absolute);
            } catch (error) {
                throw new UnreadableInput(errorMessage(error));
            }
            if (!stat.isFile() || stat.size > maxFileBytes)
                throw new UnreadableInput(
                    `TypeScript input exceeds limits: ${relative}`,
                );
            accepted.push(requested);
        } catch (error) {
            rejected.push({
                relative: requested,
                message: errorMessage(error),
                failedRead: error instanceof UnreadableInput,
                ...(error instanceof RefusedAfterRead
                    ? { refusalHash: error.contentHash }
                    : {}),
            });
        }
    }

    return { accepted, rejected };
}

// Discovery classifies an extensionless script by its shebang and routes it
// here, so gating on the extension alone rejected exactly the files discovery
// had just resolved. Mirrors the discoverer's rule: match `#!/usr/bin/node` and
// `#!/usr/bin/env node`, tolerate a version suffix, and anchor to a word
// boundary so a path merely containing an interpreter name is not matched. Only
// the first line is read, and only for a file that has no known extension.
function namesJavaScriptInShebang(absolute) {
    if (path.extname(absolute) !== "") return false;
    let buffer;
    let read;
    try {
        // Non-blocking, and checked on the handle, as readBounded does: the
        // path may have been swapped for a FIFO, whose open would otherwise
        // block until a writer appears.
        const handle = fs.openSync(
            absolute,
            fs.constants.O_RDONLY | fs.constants.O_NONBLOCK,
        );
        try {
            if (!fs.fstatSync(handle).isFile())
                throw new Error(`Not a regular file: ${absolute}`);
            buffer = Buffer.alloc(SHEBANG_PROBE_BYTES);
            read = fs.readSync(handle, buffer, 0, SHEBANG_PROBE_BYTES, 0);
        } finally {
            fs.closeSync(handle);
        }
    } catch (error) {
        // Only ever asked of a path that just resolved, so a failed read is the
        // filesystem's answer, not this script's interpreter.
        throw new UnreadableInput(errorMessage(error));
    }

    return probeNamesJavaScript(buffer.subarray(0, read));
}

// Whether the probe's bytes, the file's first SHEBANG_PROBE_BYTES at most, name
// JavaScript on their first line.
function probeNamesJavaScript(bytes) {
    const first = bytes
        .toString("utf8", 0, Math.min(bytes.length, SHEBANG_PROBE_BYTES))
        .split("\n", 1)[0];
    return (
        first.startsWith("#!") &&
        /\b(node|nodejs|bun|deno)[0-9.]*\b/i.test(first)
    );
}

// What a shebang refusal reports in `input_hashes` for the refused file. The
// probe read only a line, which no discovery hash can be compared with, so the
// whole file is read once more, bounded, and judged again on those same bytes.
// A file that still does not name JavaScript is reported by that read's hash:
// a tree that is not changing matches what discovery hashed, so a script this
// rule and discovery's happen to judge apart costs only its diagnostic, while a
// script swapped for another one does not match. A read that fails, is over the
// cap, or now names JavaScript saw a file that changed between the two reads,
// and is reported as null.
function shebangRefusalEvidence(absolute, maxFileBytes) {
    let buffer;
    try {
        buffer = readBounded(absolute, maxFileBytes);
    } catch {
        return null;
    }
    if (buffer === undefined || probeNamesJavaScript(buffer)) return null;
    return createHash("sha256").update(buffer).digest("hex");
}
