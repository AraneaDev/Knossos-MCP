/**
 * The byte cap on the files the worker reads, and the bounded read that
 * enforces it. A declaration file below node_modules gets a multiple of the
 * cap a project's own sources get, the default library is exempt, and a
 * bounded read never reads more than one byte past the cap.
 */

import fs from "node:fs";
import path from "node:path";
import { rethrowStackOverflow } from "./errors.js";
import {
    contains,
    defaultLibDirectory,
    normalize,
    realSourcePath,
} from "./project-paths.js";

/**
 * Read a file's bytes, or undefined when it holds more than `maxBytes`: at most
 * one byte past the cap is ever read.
 *
 * Throws for anything but a regular file. Opening a FIFO for reading blocks
 * until a writer appears, and a walk reports any non-directory as a file, so
 * the path is opened without blocking (which changes nothing for a regular
 * file) and the handle, not the path, is checked, so a path swapped for a FIFO
 * after a check of it cannot slip through.
 */
export function readBounded(file, requestedMaxBytes) {
    const maxBytes = byteCapFor(file, requestedMaxBytes);
    const handle = fs.openSync(
        file,
        fs.constants.O_RDONLY | fs.constants.O_NONBLOCK,
    );
    try {
        if (!fs.fstatSync(handle).isFile())
            throw new Error(`Not a regular file: ${file}`);
        const chunks = [];
        let total = 0;
        const limit = maxBytes + 1;
        while (total < limit) {
            const chunk = Buffer.alloc(Math.min(65_536, limit - total));
            const read = fs.readSync(handle, chunk, 0, chunk.length, null);
            if (read === 0) break;
            chunks.push(
                read === chunk.length ? chunk : chunk.subarray(0, read),
            );
            total += read;
        }
        return total > maxBytes ? undefined : Buffer.concat(chunks, total);
    } finally {
        fs.closeSync(handle);
    }
}

// A dependency's declaration file may hold a whole framework's type surface
// (Phaser ships 6 MB in one file), and refusing it cost every class extending
// one of its types all of its inherited members. Declarations below
// node_modules get this multiple of the cap a project's own sources get. The
// core's commit-time check applies the same rule (UndiscoveredInputVerifier).
const DEPENDENCY_DECLARATION_CAP_FACTOR = 16;

function byteCapFor(file, maxBytes) {
    return /(?:^|\/)node_modules\/.+\.d\.[cm]?ts$/.test(normalize(file))
        ? maxBytes * DEPENDENCY_DECLARATION_CAP_FACTOR
        : maxBytes;
}

// Default-library declaration files are exempt: skipping one would break type
// resolution for every file. Only project sources under the root are capped.
export function exceedsByteCap(fileName, maxFileBytes) {
    const normalized = realSourcePath(normalize(path.resolve(fileName)));
    if (contains(defaultLibDirectory(), normalized)) return false;
    try {
        return (
            fs.statSync(normalized).size > byteCapFor(normalized, maxFileBytes)
        );
    } catch (error) {
        rethrowStackOverflow(error);
        return false;
    }
}
