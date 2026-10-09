# Knossos scanner worker protocol v1

Wire format: UTF-8 newline-delimited JSON-RPC 2.0  
Protocol version: `1.0`  
Output schema version: `1.0`

## Framing

Each line on standard input or output is exactly one JSON-RPC message. Scanner
workers write logs only to standard error. Empty lines and non-JSON stdout are
protocol violations. The core imposes line, message, total output, and time
limits before accepting contributions.

## Lifecycle

1. Core starts a worker with a clean argument list and controlled environment.
2. Core calls `initialize` and validates protocol and output schema versions.
3. Core may call `scan` one or more times.
4. Core may send `cancel` for an active request.
5. Core calls `shutdown`; it terminates an unresponsive worker after a grace
   period.

## Methods

The worker protocol has four methods: `initialize`, `scan`, `cancel`, and
`shutdown`. `cancel` is advisory and best-effort: a worker handles one request at a time and reads the next line only after the scan in progress has answered, so the host terminates the process rather than waiting. No worker advertises a `cancel` capability.

### `initialize`

Request parameters contain the core protocol/output versions. The result is a
scanner manifest:

```json
{
    "id": "knossos.typescript",
    "version": "0.6.0",
    "protocol_version": "1.0",
    "output_schema_version": "1.0",
    "languages": ["typescript", "javascript"],
    "file_extensions": ["ts", "tsx", "mts", "cts", "js", "jsx", "mjs", "cjs"],
    "capabilities": [
        "project_program",
        "partial_ast",
        "content_hash",
        "input_hashes",
        "read_attribution",
        "added_files_affect_all"
    ]
}
```

Version mismatch is fatal and occurs before project paths are sent.

A compiled worker may add `source_hash`: the lowercase hex SHA-256 of the
source it was built from. The Rust worker embeds it at build time, and `doctor`
recomputes it over `workers/rust` to warn when the binary is older than the
checkout. The definition (which files, in what order, hashed how) lives in
`workers/rust/src/source_hash.rs` and `src/Runtime/WorkerSourceHash.php`. Other
workers leave the field out.

### `scan`

Accepts a request ID and `params` that carry the project's real root (`root`), the project-relative paths this request must scan (`files`), and the bounds (`limits`: `max_files` and `max_file_bytes`). The core sends only the files that need scanning: a file whose cached contribution is still valid is not sent. The packaged workers receive extra fields for their own language: `frameworks` (PHP, Python and Rust), `config_files` (TypeScript and Rust), `exclusions` (TypeScript and Python), `source_files` (TypeScript, Python and Rust: every discovered file of the language, sorted), and several TypeScript project lists. A worker streams zero or more
`scan/contribution` notifications, and zero or more `scan/input_hashes`
notifications (below), followed by a final result containing counts.

`exclusions` holds the rules discovery leaves paths out by, so a worker that
resolves imports leaves out the same files instead of keeping its own copy of
the list: `segments` and segment `prefixes` excluded wherever they appear, pairs
of consecutive segments (`sequences`), file-name `suffixes`, `path_prefixes`
from the root, and the project's own `patterns`. A pattern is a regular
expression `regex`, matched as `^regex(?:/.*)?$` against the whole path when
it is `anchored` and as `^regex$` against each segment when it is not; the
last pattern that matches decides, and a `negated` one takes the path back. A
path is left out when it, or a directory above it, matches, since discovery never
descends into a directory that matches. Inside a `node_modules` or `vendor`
directory the dependency's own layout governs, so only the part of the path
above it is checked. A worker reads nothing of a file these rules leave out,
and reports no read of it. Discovery leaves out more than these rules say, such
as a file a `.gitignore` names: a worker may reach such an undiscovered file
through resolution, read it by its bytes and report that read, as the packaged
Rust worker does at a probed module path and the Python worker does for an
imported module.

The request's time limit is an inactivity limit: every notification the worker
sends restarts it, up to a hard cap per request (the maximum worker timeout,
120 seconds). A worker busy for longer than the limit with nothing to report
yet, such as while building a large program, sends `scan/heartbeat`
notifications (method `scan/heartbeat`, no params) to say so. A worker that
sends only heartbeats is still ended at the cap.

**One language's files arrive over several `scan` requests on the same
session.** The line, total-output, and time limits are enforced per request, so
the core splits a language's work into batches bounded by both a file count and
a cumulative source-byte budget; the batch bounds differ per language. A worker
must therefore treat every `scan` as covering only the files that request named,
and must not assume the first `scan` sees the whole project or that any request
is the last one.

Three consequences for a worker author:

- **Every integer in the result is a per-request count, and the core sums it
  across a language's requests.** `files_scanned`, and any counter of its own a
  worker adds, must report what THIS request did alone: a worker
  that returns a cumulative figure will be double-counted. Other non-integer
  result fields are not summed; the last request's value is the one reported.
  `input_hashes` (below) is the exception: the core verifies it against
  discovery on every request and then discards it, so it never reaches
  `scanner_metadata` at all, summed or otherwise.
- **Work that can be amortised across requests should be cached on the session.**
  The packaged TypeScript worker keeps its `ts.Program` cache on the scanner
  instance for exactly this reason, and the core in turn gives TypeScript a much
  larger file batch than PHP or Python so a normal project is still one request.
- **A request that exceeds the output limit may be re-sent as smaller batches.**
  The core cannot predict how much output a request will produce (measured
  expansion from source bytes to protocol output ranges from under 2x for real
  hand-written sources to 15x for code dense in declared symbols), so it sizes
  batches optimistically and halves the budget, up to four times, when a worker overflows. A worker
  must therefore be safe to re-ask for files it has already partially reported
  on; the core discards the partial output of a failed request. Ordinary worker
  failures are not retried.

Each contribution has one stable owner key and lists node facts, unresolved edge
facts, and diagnostics. Re-emitting an owner replaces that owner's previous
facts atomically during reconciliation.

A contribution may also carry `content_hash`: the lowercase SHA-256 hex of the
raw bytes the worker read for that contribution's file, exactly as they came
off disk, before any decoding, byte-order-mark stripping or newline
normalisation. A worker that sends it declares the `content_hash` capability.

The core compares it against the hash discovery recorded for the file and
fails the scan with `KNOSSOS_SCAN_SNAPSHOT_CHANGED` when they differ, because
the facts were then parsed from content no stored hash describes. That catches
a file that changes and changes back while the scan runs, which a later re-read
cannot see.

The hash covers the bytes a contribution's own file was parsed from, and only
those. Derive that file's nodes, edges and local name resolution from exactly
those bytes: if you read the same file a second time, for example to index its
declarations for another file's imports, never use that second read for the
file's own facts. Declarations read from other files to resolve an edge's
target are outside what the hash covers; the `input_hashes` field below closes
that gap for a worker that declares it.

A worker declaring the capability attaches the hash to every contribution for
which it read bytes, including one that only reports a syntax error. It omits
the hash only when the read itself failed, and such a contribution must carry
no nodes or edges. Facts without a hash from a declaring worker are refused as
`WORKER_CONTRIBUTION_INVALID`, and that refusal degrades the worker's whole
language for the scan: none of its contributions from that scan reach the
graph. A contribution with no hash, no nodes and no edges from a declaring
worker is kept, so its diagnostics still reach the graph, but it is never
cached, and the next scan asks for that file again.

Bump your worker's version when you start declaring `content_hash`. A cache hit
is served without being verified again, and cached contributions are keyed on
the worker version, so entries your worker wrote before it hashed are only
purged by a version change.

The byte-order mark is the usual way to get this wrong: a runtime that strips
it while reading text hashes different bytes than discovery did, and every
scan of such a file fails. Hash the buffer, then decode it.

A result may also carry `input_hashes`: an object mapping every project file
the worker read while deriving that request's facts to the lowercase SHA-256
hex of the raw bytes read, or to `null` when a read was attempted and failed,
or a lookup that decides facts found nothing there (see keying reads, below).
This covers files the worker read for another file's sake, as well as the file a
contribution describes: a module index built by reading every module to
resolve one file's imports, or a type checker that loads a whole program to
check one of its files. A worker that declares the `input_hashes` capability,
separate from `content_hash` so a third-party worker is unaffected, promises
to send the field on every result.

A worker can read one path more than once within a request, for example once
to resolve an importer and again for that file's own contribution. When those
reads disagree, whether two different hashes or one read that succeeded and
one that failed, the worker must report the path as `null`. At least one of
the reads then differs from what discovery hashed or failed, and either may
have fed facts, so keeping one value would leave the other read unverified.
Reads that all produced the same hash report that hash.

The core decodes and verifies whatever `input_hashes` contains whenever a
result carries it, whether or not the worker's manifest declares the
capability, because a hash is evidence of a changed tree whoever sends it. A
malformed field is refused whether or not the capability is declared. A worker
that never sends the field is untouched by any of this, same as a worker that
never sends `content_hash`. Declaring the capability changes only one thing: an
absent field then becomes a violation the core would otherwise say nothing
about, per the next paragraph.

The core compares every path the result names against what discovery recorded,
the same as it does for `content_hash`. A path discovery does not track is
not compared with discovery: it is re-read when the scan commits, as described
below, and a dependency outside the scanned tree has no key at all. A hash that
differs from discovery's, or a `null` for a path discovery does track, fails
the scan with `KNOSSOS_SCAN_SNAPSHOT_CHANGED`, the same as a `content_hash`
mismatch, regardless of declaration. A worker that declares the
capability but omits the field, or sends one that is not an object keyed by
path, is refused as `WORKER_RESPONSE_INVALID` and degrades its language for
the scan, whether or not the request read anything: an empty result still
carries `input_hashes` as `{}`. A worker that does not declare the capability
and simply omits the field triggers none of this.

#### Attributing reads to a contribution

`input_hashes` says what a request read as a whole. A worker that also says which
file each contribution came from lets the core invalidate only the contributions
that depended on a changed file. A worker that declares the `read_attribution`
capability sends `reads` on every contribution: an object mapping each file that
contribution's facts were derived from to the lowercase SHA-256 hex of the bytes
read, or to `null` for a path probed and not found. A file that reads nothing
beyond itself sends `{}`. It may also send `reads` on the result, for reads
shared by every file of the request, such as a configuration file or a global
declaration.

The result's `reads` can outgrow the one line a result travels on, as
`input_hashes` can: everything a type checker read for a dependency's
declarations is shared by every file of the request. A `scan/input_hashes`
part (below) may carry a `reads` object beside its `input_hashes`, holding
part of the result's `reads`, and its `input_hashes` may then be `{}`. The core
merges every such part with the result's own `reads` under the rule it applies
to `input_hashes` parts: a path two of them report with different values
becomes `null`. A part may carry an `unattributed_reads` object the same way,
merged into the result's field of that name and never into `reads`.

Every entry in any `reads` must also appear in the result's `input_hashes` with
the same value; a worker reporting one that `input_hashes` does not confirm, or
omitting `reads` from a contribution after declaring the capability, is refused
as `WORKER_CONTRIBUTION_INVALID`. A worker that does not declare the capability
is treated as if every file depended on every entry of `input_hashes`, so its
contributions are invalidated by a change to any of them.

Every entry of `input_hashes` must also be named by some `reads`, on a
contribution or on the result, or by the result's `unattributed_reads`, unless
it is a file the request named, whose own bytes its contribution's
`content_hash` covers. A read named nowhere would invalidate nothing when it
changes, so an attributing worker that leaves one out is refused as
`WORKER_CONTRIBUTION_INVALID`. Put a read no single file caused, such as a
dependency's declaration another one imports, on the result.

A worker whose programs load project files beyond the ones a request names
reads for those files too: an importer's import chain, a config's whole
`include`. Such a file has a contribution of its own in the core's cache, and
when a config lists the file, that contribution was derived in the config's
program and names what the file read there, so a request building that same
program owes nobody those reads. Report them as `unattributed_reads` on the
result, the same shape as `reads`. The core confirms each entry against
`input_hashes`, counts it as named, and stores it for no file and no group.
That is the only case. The same file loaded into another program resolves
under that program's paths, aliases and manifests, and the read that decides
where an import lands there is one its own contribution never names: keep it
on `reads`. A file no config lists is emitted by whichever config's program
reaches it first, or else by the fallback program of its own group, so no
program can claim its reads as that contribution's own: keep them on
`reads` in every program, the fallback program included. Keep on `reads` also
what every file of the request genuinely shares: a config and what it
extends, a manifest read to resolve, a global declaration, and the reads of a
file the core never discovered, since no contribution names them. The
`unattributed_reads` map can outgrow one line as `reads` can, and travels in
`scan/input_hashes` parts the same way, under its own field name.

A worker that fails on a file after naming only part of what that file read
marks the contribution `reads_partial: true`. Such a contribution, and a file
the core left out of the graph because its answer was too large, cannot name
what the file re-exports, though an importer may name only the file and rely
on it. The core therefore rebuilds such a contribution, and everything that
read it, on any change its worker's languages see. The packaged Python worker
marks a file whose collection failed. The packaged TypeScript worker does not
set it: what a failed program read that no contribution names goes on the
request's shared `reads`, and an importer names the declaration files the
checker resolved for it, not only the file it imports.

The packaged Python worker uses `unattributed_reads` for the reads a module
makes for itself. A module's declarations include what it re-exports, so an importer
that uses them depends on the re-exported modules too. When the module is one
of the request's `source_files` and its own scan declares it (the worker names
a module by the file an import finds, so every spelling of the import gives
the id that file's own scan gives it), its own contribution names those
modules, and the importer names only the module's file and the probes that found it: a change to a
re-exported module rebuilds the module, which rebuilds the importer. The
module's reads for the re-exports go on `unattributed_reads`. A module that
discovery left out, such as one under `vendor/`, has no contribution of its
own, so its importer names what it re-exports as well. The request's `reads`
hold the probe that found no `src/__init__.py`, which decides whether `src/`
is a source root and so every module id below it; a marker that is present is
not recorded, and deleting it is a layout change the core rebuilds every
Python file for.

The packaged Rust worker reads every file of the request's `source_files` to
build its declaration index, whichever files the request names. Each
contribution names the files of every module above each name its walk looked
up, found or not, and of every module above its own, with `null` for each path
such a file could have that does not exist, plus the crate roots of the package
whose `src/` holds it. The manifests are the request's `reads`; the files read
only for the index go on `unattributed_reads`. Those reads are direct: a Rust
file's facts depend on the bytes of the files it names and on nothing those
files read in turn. For the packaged Rust worker the core takes this from its
own worker descriptors, and an owner of that worker that a scan rebuilds
reaches its readers only when its own bytes changed, not transitively. Every
name is looked up below its crate root, so editing a crate's `src/lib.rs`, or
adding its `src/main.rs` or `src/lib.rs`, rescans the whole crate. A Rust file
that does not parse keeps an attributed row with no reads: it has no facts,
so nothing but its own bytes can change it.

A file discovery hashes for the first time reaches every owner that read it
while discovery left it out, whatever bytes that read saw: the Rust index
holds no file discovery left out, and a Python importer names what such a
module re-exports itself, so the file joining the project changes their
facts even when its bytes did not change.

No read can name a file that did not exist anywhere a worker looked, and for
some languages a new file still changes what other files mean: a script that
declares global names any file may use. A worker for such a language also
declares `added_files_affect_all`. Then a discovered file of its languages that
has no cached contribution and is new since the last scan rebuilds every
cached contribution of that worker; an edited or deleted file stays as precise
as the reads. The packaged TypeScript worker declares it. For the packaged
workers the core takes this from its own worker descriptors when it plans a
scan, before any worker has started, so that a rebuilt file still reaches its
readers in other languages; the manifest capability states the same thing to
anyone reading the handshake, and the core does not consult it.

A worker also says what each file could see without an import, whether or
not it declares `added_files_affect_all`. Each contribution then carries `program`, the key of the program its
facts were derived in (the packaged TypeScript worker uses the tsconfig path,
or `fallback:` and the directory for a file no config includes), and
`environment`, the lowercase SHA-256 hex of the global declarations that
program held: one line per file of the program that declares globally (a
script, a module that augments the global scope or another module, a UMD
global, a type library a config names), the path, a NUL and the hash
`input_hashes` carries for it (empty for `null`), sorted and joined with
newlines. Both are kept with the cached contribution. The result also carries
`environments`, an object mapping the key of every program the request built
to its digest, since a program built for a file another program emitted
labels no contribution of its own: the packaged TypeScript worker builds every
config's program whose own root files hold a requested file, whichever config
describes that file, so an edit that imports a global script into a file two
configs include reaches both. After a scan builds a program, the core
rebuilds every contribution it reused from an earlier build of that program
whose `environment` differs, or is missing, and everything that read it, in
other languages too, and repeats the comparison for every program a later
pass builds until a pass finds nothing new. A contribution derived in no
program, such as a file the worker could not scan, carries neither field. A
deleted file of such a worker rebuilds every one of its contributions, since
the program that held a deleted global may not be built again in that scan.

A file no config lists has no program of its own: whichever config's program
reaches it first through imports emits it, and a program built for the files
no config describes, whose key starts with `fallback:`, takes what none did.
Such a program emits only the files of its own group, the files that sit
under the same config or package directory: it may reach a file of another
group through an import, but that file is emitted by its own group's program,
which holds the whole group whatever the imports say, so neither the order
the groups are built in nor an import between two unlisted files decides
which program describes one. The facts of a file a config's program reached
still follow the imports of other files, which no read of its own records.
Mark such a contribution `listed: false`; leave the field out of every other
contribution, so a payload already cached keeps its bytes. Once a
scan rebuilds any contribution derived in a program a config describes, or
one derived in no program, the core rebuilds every reused contribution so
marked, and everything that read it, in other languages too. A change that
reaches only files a `fallback:` program emitted rebuilds nothing more: a
config's program is driven from its root files and their imports, which hold
no such file, and a fallback program holds its whole group whatever the
imports say. The packaged TypeScript worker marks a file no tsconfig lists
among its root files, whether a config's program reached it or the fallback
program of its group emitted it.

The packaged Python worker labels every contribution that holds facts with
the program `python` and the digest of its source roots: the bare root, then
`src/` when it holds no `__init__.py` (the src layout) and each directory the
root `pyproject.toml` says its packages live in, in the order imports search
them, one per line. A new `src/` is a source root no import could have probed,
and the digest is what makes it reach every file. A deleted `src/__init__.py`
turns `src/` into a source root, renaming every module below it, and none of
those files need have read it, so the core rebuilds every Python contribution
when it is deleted, from its own worker descriptors, as it does for an added
file of a worker that declares `added_files_affect_all`. An added one needs
no such rule: the worker read its absence, and every file of the request
shares that read. The root `pyproject.toml` is a shared read too, by its hash
or as `null` when it is absent: the core's configuration hash covers it only
while discovery records it, and a gitignored one decides the source roots all
the same, so an edit, creation or deletion of it rebuilds every Python file
either way. Any other top-level directory is no source
root, so creating or deleting one, or a package marker in it, reaches only
the files that probed it.

A program's environment must not follow the request: the packaged TypeScript
worker receives `source_files`, every discovered file of its language, and
roots a program for files no config includes on every such file of the same
package, not only the ones requested, so a test sees the globals its setup
declares whether or not the setup was requested with it, and an ordinary edit
beside such a file triggers no second pass.

One decoding limitation to know about: an object keyed only by consecutive
integers starting at `"0"` is indistinguishable on the wire from a JSON array,
and decodes to one, so it is refused as malformed rather than trusted
unverified. A worker that reads root-level project files literally named `0`,
`1`, and so on, and nothing else, in one request degrades its language for
that reason alone. In practice this only matters for a project with files
named that way.

The map can outgrow the one line a result travels on: a type checker reads its
whole program to check one file, so a request naming one file of a program of
ten thousand files reports ten thousand entries, about a megabyte, however
small the batch. A worker whose map may grow that large sends it in parts. Each
part is a `scan/input_hashes` notification sent during the request, before its
final result:

```json
{
    "jsonrpc": "2.0",
    "method": "scan/input_hashes",
    "params": {
        "input_hashes": {
            "src/a.ts": "9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08",
            "src/b.ts": null
        }
    }
}
```

A worker may send any number of parts, each an object of the same shape as the
result field and each well under the line limit; the packaged workers cap a
part at 256 KB of serialized entries. The final result
still carries `input_hashes`, holding the last part or `{}`, and for a worker
that declares the capability that field remains the marker that it finished
reporting: parts followed by a result without the field are refused as a
missing field. The core merges every part of a request with the result's field
under the same rule a worker applies to repeated reads: a path two parts, or a
part and the result, report with different values becomes `null`. Each part is
validated as the result field is, and a malformed one fails the request as
`WORKER_RESPONSE_INVALID`. Parts from a worker that does not declare the
capability are still verified.

Part frames are not counted against the request's output limit, because the
map's size follows the files a request read rather than the files it named, and
splitting the batch could not shrink it. They have a budget of their own,
64 MB per request by default (`WorkerLimits::$maxInputHashesBytes`), sized for
the largest tree a scan accepts: exceeding it fails the request as
`WORKER_RESPONSE_INVALID`, which degrades the language and is never retried as
a smaller batch. A map bounded by its batch still needs parts: the packaged PHP
worker reports little more than the files it was asked for, but a batch of 400
files with long enough paths outgrows one line, so it splits its map the same
way. The packaged Rust worker's maps follow the project's Rust files, and it
splits `input_hashes`, `reads` and `unattributed_reads` alike.

#### Keying reads in `input_hashes`

The core compares each entry with the file discovery hashed at that path, so an
entry only protects a scan when its key names the path discovery saw. Discovery
reports regular files only, never follows a symbolic link, and never descends
into a linked directory. Key your reads by these rules and a tree that is not
changing never fails a scan, while a file that changes and changes back during
one does.

- **A read that succeeded** goes under the location the kernel's lookup
  reached, as a path relative to the root, with the hash of the bytes read.
- **A read that followed links** also goes under every link it followed inside
  the root, and under each such link joined with the path components that were
  still to be walked below it. The first of those is the path as your worker
  wrote it. A joined path, and a link followed as the last component, take the
  read's value. A link followed with components still to walk below it takes
  the value the link names as itself, whichever file below it was read: `null`
  when it leads to a directory or to nothing, and the hash of the file within
  the byte cap when it leads to a file, so the walk failed below it. Leave
  out a joined path that still holds a `..`, because only the kernel's lookup
  could apply it, and leave out anything outside the root. A discovered file
  swapped for a link to another file, or a discovered directory swapped for a
  link to another directory, is then checked against its own hash. Resolve
  links component by component, the way the kernel does, applying a `..` after
  the link before it has been followed: collapsing `a/link/../b` as text names
  a different file.
- **One key, one value.** The core re-reads every key discovery did not hash
  when the scan commits, and fails a scan in which two requests report one key
  with two values. On a tree that is not changing, give each key the value its
  path has, whichever read or probe reached it: the hash of the in-root regular
  file it resolves to within the byte cap, or `null`. The packaged TypeScript
  worker reports `node_modules` reads this way. An existence probe that finds a
  file present through a link hashes that file for the link's keys, so a
  package probed through a pnpm-style link in one request and read through it
  in another reports the same values in both. A worker may instead report
  `null` under every link key, in every request, since a `null` is valid for a
  path reached through a link; the file the walk reached is still keyed and
  verified at its own location. The packaged Python worker does this.
- **A read your worker refused** (over the byte cap, or resolving outside the
  root) goes under the same keys as `null`: the facts that needed it were
  computed without it.
- **A requested file whose read failed** for a filesystem reason (it is gone,
  is not a regular file, or is over the byte cap) goes under the requested
  path as `null`, and its contribution under that path's own owner key carries
  no facts. A refusal by policy, such as an extension your worker does not
  scan, says nothing about the tree and goes unreported.
- **A requested file refused on its content**, such as an extensionless
  script whose shebang does not name your language, was routed to your worker
  because discovery read that content differently. Report it under the
  requested path with the hash of the whole file, read once more within the
  byte cap and judged again on those bytes, or as `null` when that read fails,
  is over the cap, or now names your language. A stable tree matches that
  hash, so a script your rule and discovery's happen to judge apart costs only
  its diagnostic, while a script swapped for another one and restored around
  your probe fails verification. The packaged PHP, Python and TypeScript
  workers do this.
- **A requested file that resolves to a path other than the one requested**
  (discovery never follows a link, so this can only happen when a component of
  the requested path became a link since discovery ran) may be reported either
  way: as `null`, the same as any other failed read of that path, or as the
  hash of the bytes your worker actually read from wherever the walk ended,
  keyed under the requested path. Both keep a stable tree safe. A `null` never
  matches a discovered file's hash by chance, so it always fails verification
  and the language degrades. A hash of the wrong file's bytes fails
  verification too, unless the file the link now points to holds the same
  bytes discovery hashed for the requested path, in which case the facts your
  worker computed from it are the correct facts anyway. Choose whichever your
  worker already has cheaply to hand: the packaged PHP, Python and Rust
  workers read through the link and report the hash of what they read; the
  packaged TypeScript worker does not follow it and reports `null`.
- **An existence check whose answer decides facts**, such as a module
  resolution candidate, a realpath, or a check for a package marker, goes under
  the keys of its walk as `null` when it finds nothing or finds something that
  is not a file. When it finds the file, leave the location itself to the read
  that follows, and give the walk's link keys the values a read through the
  same path would: the hash of the file within the byte cap under the joined
  paths and a link followed as the last component, and a link followed with
  components below it as above. A check that recorded `null` there while a read
  in another request recorded the hash would fail every scan of that tree. The
  alternative is `null` under the link keys for the check and for every read
  through the link, as the packaged Python worker records them: its index
  checks a module before each read, and the two disagree into `null`.
- **Reads that disagree** about one key within a request, in hash or in
  whether they succeeded, go under that key as `null`.

The core checks every path discovery hashed: the discovered source files, and
the project units beside them, the manifests and configuration files such as
`package.json`, `tsconfig.json`, `Cargo.toml`, `pyproject.toml` and
`composer.json` (a manifest discovery read but could not parse is checked too).
Record a read of one of those like any other read: the hash of its raw bytes
under the walk's keys, or `null` when the read failed or went over the cap. A
key that names a checked path with the wrong value fails every scan of that
tree. After every worker has returned, the core also re-hashes each of those
paths, units included, and fails the scan when one no longer matches.

Every other key, a path discovery did not hash, is verified when the scan
commits, after the discovered paths. A hash must still equal the SHA-256 of
the in-root file the path leads to, read within the byte cap. A `null` is valid
only while the path is absent, not a regular file, reached through a link, or
over the cap: a `null` for an in-root, readable regular file fails the scan.
So an extra `null` is not free. Report one only for a read or check that
really failed or found nothing.

#### Known limits

- A file discovery never hashed (below `node_modules`, in an ignored path, over
  the cap, or not a recognised manifest, such as an `extends` target not named
  `tsconfig*.json`) is verified at commit against a re-read, and successful
  worker reads are retained as dependency inputs for freshness. A later change
  to a recorded declaration marks the graph stale and forces cached importing
  files to be rebuilt. Failed or refused reads remain commit-only evidence,
  since they have no dependency bytes to compare.
- On a case-insensitive volume, a worker's key and discovery's path can spell
  the same file differently. The core compares paths exactly, so such a read
  is not checked against discovery's hash, only re-read at commit like any
  other undiscovered key.
- The tree can change between a worker's walk of a path and its read. The read
  is still verified: it hashes the bytes it actually got and records them under
  the walk's keys, where they disagree with discovery's hash unless they are
  the same bytes, in which case the facts are the same too. A read bounded to
  one byte past the cap never reads more than an accepted file, whatever it
  opens.

`input_hashes` is evidence for this check alone. The core strips it from the
result before folding the rest into `scanner_metadata`, so it never reaches a
scan report and is never summed into that metadata. It is compared across
requests instead: the same key reported with two values by two requests, of
one language or of different languages, fails the scan as a conflict.

Required fact properties are defined by the DTOs under
`src/Scanner/Protocol`. Paths are project-relative, source lines are one-based,
and confidence is `certain`, `probable`, or `possible`.

### `cancel`

Accepts the active scan request ID, sent verbatim so an integer id is never
stringified. It is a notification with no reply: a worker busy inside `scan` will not read the frame until that scan has finished,
so the core discards uncommitted output and terminates the process rather than
waiting for cooperation.

### `shutdown`

Requests orderly worker termination. No new requests are accepted afterward.

## Trust boundary

Scanner output is untrusted until schema and limit validation succeeds. Workers
must not execute scanned source, invoke package lifecycle scripts, install
dependencies, access paths outside the supplied root, or write into the scanned
project.
