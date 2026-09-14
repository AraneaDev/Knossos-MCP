# Knossos scanner worker protocol v1

Status: Phase 1 foundation  
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
`shutdown`. `cancel` is advisory and best-effort: the workers are
single-threaded and blocked inside `scan` when it arrives, so the host
terminates the process rather than waiting. No worker advertises a `cancel`
capability.

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
        "input_hashes"
    ]
}
```

Version mismatch is fatal and occurs before project paths are sent.

### `scan`

Accepts a request ID, project context, project-relative added/changed/deleted
inputs, configuration hashes, and limits. A worker streams zero or more
`scan/contribution` notifications, and zero or more `scan/input_hashes`
notifications (below), followed by a final result containing counts.

**One language's files arrive over several `scan` requests on the same
session.** The line, total-output, and time limits are enforced per request, so
the core splits a language's work into batches bounded by both a file count and
a cumulative source-byte budget; the batch bounds differ per language. A worker
must therefore treat every `scan` as covering only the files that request named,
and must not assume the first `scan` sees the whole project or that any request
is the last one.

Two consequences for a worker author:

- **Every integer in the result is a per-request count, and the core sums it
  across a language's requests.** `files_scanned`, and any counter of its own a
  worker adds, must report what THIS request did, not a running total: a worker
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
  batches optimistically and halves the budget when a worker overflows. A worker
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
This covers files the worker read for another file's sake, not only the file a
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
and Rust workers report little more than the files they were asked for, but a
batch of 400 files with long enough paths outgrows one line, so they split
their maps the same way.

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
stringified. It is a notification with no reply: a single-threaded worker is
blocked inside `scan` and will not read the frame until that scan has finished,
so the core discards uncommitted output and terminates the process rather than
waiting for cooperation.

### `shutdown`

Requests orderly worker termination. No new requests are accepted afterward.

## Trust boundary

Scanner output is untrusted until schema and limit validation succeeds. Workers
must not execute scanned source, invoke package lifecycle scripts, install
dependencies, access paths outside the supplied root, or write into the scanned
project.
