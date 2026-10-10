/**
 * The record of every input a request read, and which file each read is
 * attributed to.
 *
 * `InputReadRecorder` holds the hash of every project file the request read,
 * for the result's `input_hashes`. `ReadAttribution` decides which of
 * those reads belongs to the `reads` of each requested file and which the
 * whole request shares. The record functions below write one walk, link or
 * probe into the recorder.
 */

import { createHash } from "node:crypto";
import path from "node:path";
import ts from "typescript";
import { readBounded } from "./byte-caps.js";
import { rethrowStackOverflow } from "./errors.js";
import {
    belowNodeModules,
    contains,
    defaultLibDirectory,
    excludedByProjectLayoutPath,
    isProjectSource,
    normalize,
    realSourcePath,
    relativeInside,
    walkPath,
} from "./project-paths.js";
import { parsedContentHashes } from "./source-caches.js";

/**
 * Every project file one scan request read to derive facts, for the result's
 * `input_hashes`: project-relative path to the SHA-256 of the bytes read, or
 * null when a read was attempted and failed.
 *
 * The checker resolves a requested file against every source file in its
 * program, so the map covers each file each program read, not only the requested
 * ones. One request can build several programs (one per tsconfig, plus the
 * fallback) and each reads its files afresh, so a path can be read more than
 * once. When those reads disagree, whether two different hashes or a hash and a
 * failure in either order, no single hash describes what the request's facts
 * came from, and the entry becomes null for good.
 *
 * One recorder per request, handed to each program's host: nothing about a read
 * outlives the request that made it.
 */
export class InputReadRecorder {
    /**
     * @param {(stage: "load"|"read", absolute: string) => void} observePath
     *   Told each path the host is about to examine: "load" before a requested
     *   SourceFile's admission checks, "read" before the read resolves it. A
     *   test seam, so a test can change the tree at exactly that point.
     */
    constructor(observePath = () => {}) {
        this.hashes = new Map();
        this.sourceFiles = new WeakSet();
        this.observe = observePath;
    }

    /** Record one read; a read disagreeing with an earlier one records null. */
    record(relative, contentHash) {
        if (
            this.hashes.has(relative) &&
            this.hashes.get(relative) !== contentHash
        ) {
            contentHash = null;
        }
        this.hashes.set(relative, contentHash);
    }

    /** Whether this request recorded a read or probe under a key. */
    has(relative) {
        return this.hashes.has(relative);
    }

    /** The value a key carries in `input_hashes`, as recorded so far. */
    value(relative) {
        return this.hashes.get(relative);
    }

    /** Every key recorded so far. */
    keys() {
        return this.hashes.keys();
    }

    /** Remember a SourceFile this request created from its own read. */
    created(sourceFile) {
        this.sourceFiles.add(sourceFile);
    }

    /**
     * Whether this request created the SourceFile a program holds. A redirect
     * SourceFile (a duplicate package resolved to an already loaded copy) is a
     * view of its target, so its target is what must have been read.
     */
    createdThisRequest(sourceFile) {
        return this.sourceFiles.has(
            sourceFile.redirectInfo?.redirectTarget ?? sourceFile,
        );
    }

    /** The map as the result field, keys sorted for deterministic output. */
    toResult() {
        return this.select(this.hashes.keys());
    }

    /** The given keys with their recorded values, sorted, as a `reads` map. */
    select(keys) {
        return Object.fromEntries(
            [...keys]
                .sort((left, right) =>
                    left < right ? -1 : left > right ? 1 : 0,
                )
                .map((key) => [key, this.hashes.get(key)]),
        );
    }
}

/**
 * Which files each requested file's facts came from, for its contribution's
 * `reads`, and which reads every file of the request shares, for the result's.
 *
 * A requested file is attributed its direct reads: every module its imports,
 * exports, `import()`, `require` calls and import types resolved to, with
 * every candidate the resolution probed on the way there; the targets of its
 * `/// <reference>` directives; the package.json files that decided its module
 * format; and the file of every declaration the checker answered with while
 * its facts were collected. The core closes over those itself: a file it
 * rescans counts as a change to every file that read it.
 *
 * That closure runs only through files the core scans, and the core refuses a
 * read no `reads` names. So a read a project file of the program made, one
 * the core holds a contribution for but this request did not name, is
 * reported as unattributed: confirmed against `input_hashes`, owned by
 * nobody, since that file's own contribution names it and the closure runs
 * from there. Every other read is shared by the whole request: a config, a
 * bundler alias config, a probe for a type library, and what a dependency's
 * declarations read. So is a global script and a module that augments the
 * global scope or another module, whoever read it: every file of its program
 * sees those declarations without an import saying so.
 *
 * Values are taken from the recorder once the request has finished, so a path
 * two programs read differently carries the same null here as in
 * `input_hashes`.
 */
export class ReadAttribution {
    /**
     * @param {InputReadRecorder} reads the request's recorder
     * @param {Set<string>} sourceFiles every project file of the language the
     *   core discovered, whose own contribution reports its reads
     */
    constructor(root, reads, maxFileBytes, sourceFiles = new Set()) {
        this.root = root;
        this.reads = reads;
        this.maxFileBytes = maxFileBytes;
        this.sourceFiles = sourceFiles;
        // Requested path to the keys its contribution read.
        this.byFile = new Map();
        // Requested path to the key of the program its contribution came from.
        this.programOf = new Map();
        // Keys every file of the request shares, whoever else read them.
        this.shared = new Set();
        // Keys read only for project files whose contributions this request
        // did not produce.
        this.unattributed = new Set();
        // Program key to the keys of its files that declare globally.
        this.globalsByProgram = new Map();
        // Program key to its digest, computed once the request has finished.
        this.environmentCache = new Map();
    }

    /**
     * Attribute a requested file's reads.
     *
     * @param {ts.SourceFile[]|Set<ts.SourceFile>} declarationFiles the files
     *   holding a declaration the checker answered with for this file
     * @param {string[]} probes paths the file's facts asked about directly
     */
    requested(
        program,
        sourceFile,
        relative,
        declarationFiles,
        probes,
        programKey,
    ) {
        const keys = this.#directReads(program, sourceFile, true);
        for (const file of declarationFiles)
            this.#addRecorded(keys, file.fileName);
        for (const probe of probes) this.#addProbed(keys, probe);
        keys.delete(relative);
        this.byFile.set(relative, keys);
        this.programOf.set(relative, programKey);
    }

    /**
     * Sort the reads of a program's other files: the declarations of every
     * file that declares globally are shared, a dependency's global script as
     * much as a project's own, and so is whatever a dependency's file read,
     * since no contribution of the core's reports it. What a project file the
     * core discovered read, when this request produced no contribution for it
     * and a config lists it in this program, is unattributed: its own
     * contribution names those reads. The global files are also the program's
     * environment (see environmentOf).
     *
     * @param {string} programKey the program's key: a config's path for its
     *   program, or the key fallbackProgramKey gives a fallback program
     * @param {(relative: string) => boolean} ownedByProgram whether a config
     *   lists the file in this program (see programOwnership)
     */
    program(program, programKey, ownedByProgram = () => true) {
        const globals = new Set();
        this.globalsByProgram.set(programKey, globals);
        for (const sourceFile of program.getSourceFiles()) {
            const relative = relativeInside(this.root, sourceFile.fileName);
            if (relative === null) continue;
            for (const key of this.#globalKeys(sourceFile, relative)) {
                this.shared.add(key);
                globals.add(key);
            }
            if (this.programOf.get(relative) === programKey) continue;
            if (isProjectSource(relative)) {
                // Its own contribution names its reads only as its owning
                // program made them: under another program's paths, aliases
                // and manifests the same import can land elsewhere, and that
                // read is the request's to keep.
                if (
                    !this.sourceFiles.has(relative) ||
                    !ownedByProgram(relative)
                )
                    continue;
                this.#addRecorded(this.unattributed, sourceFile.fileName);
                for (const key of this.#directReads(program, sourceFile, false))
                    this.unattributed.add(key);
                continue;
            }
            for (const key of this.#directReads(program, sourceFile, false))
                this.shared.add(key);
        }
    }

    /**
     * A digest of the global declarations a program's files saw: one line,
     * `path`, NUL and the hash `input_hashes` carries (empty for null), per
     * file of the program that declares globally, sorted. Two scans that agree
     * on it agree on every name a file of the program can use without an
     * import. Computed once per program, after the request's last read.
     */
    environmentOf(programKey) {
        let digest = this.environmentCache.get(programKey);
        if (digest === undefined) {
            const lines = [...(this.globalsByProgram.get(programKey) ?? [])]
                .map((key) => `${key}\0${this.reads.value(key) ?? ""}`)
                .sort((left, right) =>
                    left < right ? -1 : left > right ? 1 : 0,
                );
            digest = createHash("sha256")
                .update(lines.join("\n"))
                .digest("hex");
            this.environmentCache.set(programKey, digest);
        }
        return digest;
    }

    /**
     * The result's `environments`: the digest of every program this request
     * built, keyed by program, whether or not it emitted a contribution. A
     * program built for a requested file another program emitted still tells
     * the core what its own cached contributions now see.
     *
     * @returns {Record<string, string>}
     */
    environments() {
        return Object.fromEntries(
            [...this.globalsByProgram.keys()]
                .sort((left, right) =>
                    left < right ? -1 : left > right ? 1 : 0,
                )
                .map((key) => [key, this.environmentOf(key)]),
        );
    }

    /** The `reads` of a requested file's contribution; `{}` when it read nothing. */
    readsOf(relative) {
        return this.reads.select(this.byFile.get(relative) ?? []);
    }

    /**
     * The result's `reads`: every read shared whoever named it, and every
     * read neither a contribution's `reads` nor the unattributed reads name,
     * other than a requested file's own.
     *
     * @param {Iterable<string>} requested the paths the request named
     */
    sharedReads(requested) {
        const named = this.#named(requested);
        const keys = new Set(this.shared);
        for (const key of this.reads.keys()) {
            if (!named.has(key) && !this.unattributed.has(key)) keys.add(key);
        }
        return this.reads.select(keys);
    }

    /**
     * The result's `unattributed_reads`: what the program's other discovered
     * project files read, less anything a contribution or the shared reads
     * name. The core confirms them against `input_hashes` and stores them for
     * nobody.
     *
     * @param {Iterable<string>} requested the paths the request named
     */
    unattributedReads(requested) {
        const named = this.#named(requested);
        return this.reads.select(
            [...this.unattributed].filter(
                (key) => !named.has(key) && !this.shared.has(key),
            ),
        );
    }

    /** The requested paths and every key a contribution's `reads` names. */
    #named(requested) {
        const named = new Set(requested);
        for (const keys of this.byFile.values())
            for (const key of keys) named.add(key);
        return named;
    }

    /** The recorded keys of a file that declares globally; none otherwise. */
    #globalKeys(sourceFile, relative) {
        if (!declaresGlobally(sourceFile)) return [];
        const { key } = this.#keyOf(sourceFile.fileName);
        return [...new Set([relative, key])].filter(
            (candidate) => candidate !== null && this.reads.has(candidate),
        );
    }

    /**
     * The keys of what one file of a program read directly. With `probe`, a
     * location this request has not recorded yet (a resolution answered from
     * the cache another file filled, or a candidate the compiler skipped
     * because its directory is missing) is probed now and recorded; without
     * it, only recorded keys count.
     */
    #directReads(program, sourceFile, probe) {
        const keys = new Set();
        const add = probe
            ? (location) => this.#addProbed(keys, location)
            : (location) => this.#addRecorded(keys, location);
        const addResolution = (resolution, resolvedFileName) => {
            if (resolvedFileName !== undefined) add(resolvedFileName);
            for (const location of resolution.failedLookupLocations ?? [])
                add(location);
            for (const location of resolution.affectingLocations ?? [])
                add(location);
        };
        program.forEachResolvedModule(
            (resolution) =>
                addResolution(
                    resolution,
                    resolution.resolvedModule?.resolvedFileName,
                ),
            sourceFile,
        );
        program.forEachResolvedTypeReferenceDirective(
            (resolution) =>
                addResolution(
                    resolution,
                    resolution.resolvedTypeReferenceDirective?.resolvedFileName,
                ),
            sourceFile,
        );
        // A `/// <reference path>` is loaded as written, or with each source
        // extension when it has none; every attempt went through the host.
        for (const reference of sourceFile.referencedFiles) {
            const target = normalize(
                path.resolve(
                    path.dirname(sourceFile.fileName),
                    reference.fileName,
                ),
            );
            this.#addRecorded(keys, target);
            for (const extension of REFERENCE_EXTENSIONS)
                this.#addRecorded(keys, `${target}${extension}`);
        }
        for (const location of sourceFile.packageJsonLocations ?? [])
            add(location);
        return keys;
    }

    #keyOf(location) {
        const absolute = realSourcePath(normalize(path.resolve(location)));
        return { absolute, key: inputHashKey(this.root, absolute) };
    }

    #addRecorded(keys, location) {
        const { key } = this.#keyOf(location);
        if (key !== null && this.reads.has(key)) keys.add(key);
    }

    /**
     * Add a location's keys, probing it first when this request has not
     * recorded it. A path the project's layout refuses is never read, so it
     * has nothing to report. A file found present is hashed as a read of it
     * would be, so its key carries the value any other read gives it.
     *
     * A dependency candidate the compiler did not probe is left out: it skips
     * the candidates below a node_modules directory that does not exist, a
     * dozen per package import and ancestor directory, and installing a
     * package changes a package.json that resolution did read.
     */
    #addProbed(keys, location) {
        const { absolute, key } = this.#keyOf(location);
        if (key === null) return;
        if (this.reads.has(key)) {
            keys.add(key);
            return;
        }
        if (
            belowNodeModules(key) ||
            excludedByProjectLayoutPath(this.root, absolute)
        )
            return;
        const walked = walkPath(absolute);
        const present =
            walked.kind === "file" && contains(this.root, walked.location);
        const walkedKeys = walkKeys(this.root, walked);
        if (
            present &&
            walkedKeys.final !== null &&
            !this.reads.has(walkedKeys.final)
        )
            this.reads.record(
                walkedKeys.final,
                boundedHash(walked.location, this.maxFileBytes),
            );
        recordProbe(this.reads, this.root, walked, present, this.maxFileBytes);
        for (const candidate of [
            walkedKeys.final,
            ...walkedKeys.through,
            ...walkedKeys.directories,
        ]) {
            if (candidate !== null && this.reads.has(candidate))
                keys.add(candidate);
        }
    }
}

// What the compiler appends to a `/// <reference path>` written without one.
const REFERENCE_EXTENSIONS = [".ts", ".tsx", ".d.ts", ".js", ".jsx"];

/**
 * Whether every file of a program sees a file's declarations without importing
 * it: a script, a module that augments the global scope or another module, or
 * one that exports a UMD global (`export as namespace X`).
 */
function declaresGlobally(sourceFile) {
    try {
        return (
            !ts.isExternalOrCommonJsModule(sourceFile) ||
            (sourceFile.moduleAugmentations?.length ?? 0) > 0 ||
            sourceFile.statements.some((statement) =>
                ts.isNamespaceExportDeclaration(statement),
            )
        );
    } catch (error) {
        // A file that cannot be inspected might declare anything: shared.
        rethrowStackOverflow(error);
        return true;
    }
}

/**
 * The key a read of `absolute` goes under in `input_hashes`, or null for a read
 * the core cannot track: the root itself, anything outside it, or the default
 * library, which is the worker's own and never the project's, even under a
 * root that happens to contain the worker.
 *
 * A path below node_modules is keyed like any other. Discovery never hashes
 * one, so the core re-reads it when the scan commits: declarations resolved
 * through a dependency decide the facts of every file importing it.
 */
function inputHashKey(root, absolute) {
    const relative = relativeInside(root, absolute);
    if (
        relative === null ||
        relative === "" ||
        contains(defaultLibDirectory(), absolute)
    ) {
        return null;
    }
    return relative;
}

/**
 * The keys a walk goes under in `input_hashes`.
 *
 * `final` is the key of the location the walk reached (inputKeyLocation).
 * `linked` holds every other in-root key the walk passed through a link: each
 * link followed, and each link with the components that were still to walk
 * below it. The first of those is the path as written, which the host always
 * receives normalised, without a `..`. A `..` among the components below a
 * later link could only be applied by the walk, so such a path is left out, as
 * is anything inputHashKey refuses: the root itself, anything outside it, and
 * the default library.
 *
 * Discovery never reports a link and never descends into a linked directory,
 * so on a stable tree every linked key names a path discovery did not hash.
 * Mid-scan, a discovered file swapped for a link (to another file, to a
 * directory, or on a package's resolved path) is keyed where discovery saw it,
 * so the read or probe that went through it is checked against discovery's
 * hash.
 *
 * The core re-reads every key discovery did not hash when the scan commits,
 * and fails the scan when two requests, or two programs, report one key with
 * two values. So a key's value must be what that path itself names on a stable
 * tree, whichever read or probe reported it. Linked keys come in two kinds:
 *
 * - `through`: the path as written, and a link with the components below it.
 *   Each resolves exactly as the walk does, so it takes the walk's value.
 * - `directories`: a link the walk followed with components still to apply
 *   below it. It never names the file the walk reached: a hash of that file
 *   would differ from one read below it to the next. Its value is the link's
 *   own (see recordLinked): null for a directory or nothing, and the hash of
 *   the file it leads to when the walk failed below it because it is a file.
 *
 * A link followed as the last component is a `through` key even when the walk
 * ends at a directory or at nothing; a read of either records null anyway.
 *
 * @returns {{final: string|null, through: string[], directories: string[]}}
 */
function walkKeys(root, walked) {
    const location = inputKeyLocation(root, walked);
    const final = location === null ? null : inputHashKey(root, location);
    const through = new Set();
    const directories = new Set();
    const add = (set, candidate) => {
        const key = inputHashKey(root, candidate);
        if (key !== null && key !== final) set.add(key);
    };
    for (const link of walked.links) {
        if (!contains(root, link.location)) continue;
        const rest = link.rest.filter((name) => name !== "" && name !== ".");
        if (rest.length === 0) {
            add(through, link.location);
            continue;
        }
        add(directories, link.location);
        if (!rest.includes(".."))
            add(through, `${link.location}/${rest.join("/")}`);
    }
    return { final, through: [...through], directories: [...directories] };
}

/** Record a read, hashed or failed, under every key its walk gives. */
export function recordWalked(reads, root, walked, contentHash, maxFileBytes) {
    const keys = walkKeys(root, walked);
    if (keys.final !== null) reads.record(keys.final, contentHash);
    recordLinked(reads, root, walked, keys, contentHash, maxFileBytes);
}

/**
 * Record a walk's linked keys: its `through` keys with the value given, and its
 * `directories` keys with the value the link itself names, whatever was read
 * below it (see walkKeys).
 *
 * A walk that reached a file or a directory went through every such link as a
 * directory, so each is null. A walk that failed may have failed at the link's
 * own target, a file with more still to walk below it (ENOTDIR), and a
 * `/// <reference path="./lnk.ts/x.d.ts" />` does exactly that to a link a
 * read in another request opens as a file. So the link is walked again as
 * itself and keyed as a read of it would be: the hash of the in-root file it
 * leads to within the byte cap, or null.
 */
function recordLinked(
    reads,
    root,
    walked,
    { through, directories },
    throughValue,
    maxFileBytes,
) {
    for (const key of through) reads.record(key, throughValue);
    const traversed = walked.kind === "file" || walked.kind === "directory";
    for (const key of directories) {
        reads.record(
            key,
            traversed ? null : linkedFileHash(root, key, maxFileBytes),
        );
    }
}

/** The hash of the in-root file a project-relative path leads to, or null. */
function linkedFileHash(root, relative, maxFileBytes) {
    const walked = walkPath(`${root}/${relative}`);
    return walked.kind === "file" && contains(root, walked.location)
        ? boundedHash(walked.location, maxFileBytes)
        : null;
}

/**
 * Whether a path is an in-root file, recorded as a probe whose answer feeds
 * facts (recordProbe). A default-library file is the worker's own and is
 * answered without a record; anything else outside the root is absent.
 */
export function probeRecorded(reads, root, file, maxFileBytes) {
    const absolute = normalize(path.resolve(file));
    if (contains(defaultLibDirectory(), absolute))
        return ts.sys.fileExists(absolute);
    if (!contains(root, absolute)) return false;
    const walked = walkPath(absolute);
    const present = walked.kind === "file" && contains(root, walked.location);
    // A path discovery leaves out is never read, whatever the answer: the
    // host refuses it, so no file there can change a fact.
    if (!excludedByProjectLayoutPath(root, absolute))
        recordProbe(reads, root, walked, present, maxFileBytes);
    return present;
}

/** Whether a path walks to a file inside the root, without recording it. */
export function inRootFile(root, file) {
    const walked = walkPath(normalize(path.resolve(file)));
    return walked.kind === "file" && contains(root, walked.location);
}

/** Record a path the host would not or could not read as a failed read. */
export function recordRefused(reads, root, absolute, maxFileBytes) {
    recordWalked(reads, root, walkPath(absolute), null, maxFileBytes);
}

/**
 * Record an existence probe whose answer feeds facts, such as a module
 * resolution candidate or a realpath.
 *
 * A probe answering absent, or not a file, records null under every key its
 * walk gives: the compiler goes on as if the file were not there, so a
 * discovered file missing for that moment must fail verification.
 *
 * A probe answering present vouches for nothing at the location it reached,
 * which a read will record. Its linked keys still need a value, so that a
 * discovered path that had become a link is caught. That value must be the one
 * a read through the same path records (see walkKeys): a module resolution
 * probes a package's files through its link in one request, while a
 * `/// <reference path>` reads them through it in another, and the core fails
 * a scan whose requests disagree on a key. So when the walk ended at a file and
 * has `through` keys, the file is read within the byte cap and those keys get
 * its hash, or null when that read fails or goes over the cap, as a read of it
 * would. Anything else a present probe reached, a directory, gives them null.
 */
export function recordProbe(reads, root, walked, present, maxFileBytes) {
    const keys = walkKeys(root, walked);
    if (!present && keys.final !== null) reads.record(keys.final, null);
    recordLinked(
        reads,
        root,
        walked,
        keys,
        present && walked.kind === "file" && keys.through.length > 0
            ? boundedHash(walked.location, maxFileBytes)
            : null,
        maxFileBytes,
    );
}

/** The hash of a file read within the byte cap, or null when that read fails. */
function boundedHash(file, maxFileBytes) {
    let buffer;
    try {
        buffer = readBounded(file, maxFileBytes);
    } catch (error) {
        rethrowStackOverflow(error);
        return null;
    }
    return buffer === undefined
        ? null
        : createHash("sha256").update(buffer).digest("hex");
}

/**
 * The absolute location a walked read goes under in `input_hashes`, or null
 * when no location the walk passed lies inside the root.
 *
 * - A walk that ended at a file, a missing location or a directory inside the
 *   root: that location, the path the read opened or would have opened. A
 *   directory read fails (EISDIR) and is recorded as null, which the core
 *   accepts at commit for a path that is not a regular file, while a
 *   discovered file replaced by one mid-scan fails verification.
 * - Otherwise the last link followed inside the root. A link followed as a
 *   directory component keys the path below it as it was about to be walked
 *   (a discovered `src/sub/c.ts` whose `src/sub` became a link out of the root
 *   keys `src/sub/c.ts`), unless that path holds a `..`, which only the walk
 *   could have applied; then the link itself.
 *
 * Every key a stable layout produces this way names either the file the
 * kernel reaches, or a link or a path through one, which discovery never
 * reports. The core verifies such an undiscovered key when the scan commits: a
 * hash must still match the in-root regular file within the cap that the path
 * names, and a null is valid only while the path is absent, not a regular
 * file, a link, or over the cap. A discovered file changed into one of those
 * mid-scan is keyed where discovery saw it, so it fails verification against
 * discovery's hash.
 */
function inputKeyLocation(root, walked) {
    if (walked.kind !== "unresolvable" && contains(root, walked.location)) {
        return walked.location;
    }
    const link = walked.links.findLast((entry) =>
        contains(root, entry.location),
    );
    if (link === undefined) return null;
    const rest = link.rest.filter((name) => name !== "" && name !== ".");
    if (rest.length === 0 || rest.includes("..")) return link.location;
    return `${link.location}/${rest.join("/")}`;
}

/**
 * Record null for every project file a program holds that this request did not
 * create from its own read.
 *
 * TypeScript 6.0 asks the host for every file again when it reuses an old
 * program's structure, and this host always returns a fresh SourceFile, so a
 * reused program holds only this request's reads and this finds nothing. It is
 * here so that a compiler that kept an earlier request's SourceFile would make
 * that file's facts unverifiable rather than vouched for by the earlier read.
 *
 * @param {InputReadRecorder} reads the request's recorder
 */
export function recordUnreadSourceFiles(root, program, reads, maxFileBytes) {
    for (const sourceFile of program.getSourceFiles()) {
        if (reads.createdThisRequest(sourceFile)) continue;
        const absolute = normalize(
            path.resolve(realSourcePath(sourceFile.fileName)),
        );
        // The hash its facts were parsed from, bound to the object, when this
        // worker created it at all; null only when nothing describes it.
        recordWalked(
            reads,
            root,
            walkPath(absolute),
            parsedContentHashes.get(sourceFile) ?? null,
            maxFileBytes,
        );
    }
}
