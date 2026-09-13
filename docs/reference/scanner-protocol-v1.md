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
    "version": "0.5.0",
    "protocol_version": "1.0",
    "output_schema_version": "1.0",
    "languages": ["typescript", "javascript"],
    "file_extensions": ["ts", "tsx", "mts", "cts", "js", "jsx", "mjs", "cjs"],
    "capabilities": ["project_program", "partial_ast", "content_hash", "input_hashes"]
}
```

Version mismatch is fatal and occurs before project paths are sent.

### `scan`

Accepts a request ID, project context, project-relative added/changed/deleted
inputs, configuration hashes, and limits. A worker streams zero or more
`scan/contribution` notifications followed by a final result containing counts.

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
hex of the raw bytes read, or to `null` when a read was attempted and failed.
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
worker that never sends the field is untouched by any of this, same as a
worker that never sends `content_hash`. Declaring the capability changes only
one thing: an absent or malformed field then becomes a violation the core
would otherwise say nothing about, per the next paragraph.

The core compares every path the result names against what discovery recorded,
the same as it does for `content_hash`. A path discovery does not track, such
as a dependency outside the scanned tree, is ignored: freshness never covered
it. A hash that differs from discovery's, or a `null` for a path discovery
does track, fails the scan with `KNOSSOS_SCAN_SNAPSHOT_CHANGED`, the same as a
`content_hash` mismatch, regardless of declaration. A worker that declares the
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

`input_hashes` is evidence for this check alone. The core strips it from the
result before folding the rest into `scanner_metadata`, so it never reaches a
scan report and is never summed or otherwise merged across a language's
requests.

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
