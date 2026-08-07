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
3. Core may call `discover` and then `scan` one or more times.
4. Core may send `cancel` for an active request.
5. Core calls `shutdown`; it terminates an unresponsive worker after a grace
   period.

## Methods

### `initialize`

Request parameters contain the core protocol/output versions. The result is a
scanner manifest:

```json
{
    "id": "knossos.typescript",
    "version": "0.1.0",
    "protocol_version": "1.0",
    "output_schema_version": "1.0",
    "languages": ["typescript", "javascript"],
    "file_extensions": ["ts", "tsx", "mts", "cts"],
    "capabilities": ["discover", "incremental", "cancel"]
}
```

Version mismatch is fatal and occurs before project paths are sent.

### `discover`

Accepts an allowed canonical project root, project-relative configuration
paths, ignore rules, and resource limits. It returns recognized project units,
configuration inputs, and eligible project-relative files. All returned paths
must remain relative to the supplied root.

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
  worker adds, must report what THIS request did, not a running total — a worker
  that returns a cumulative figure will be double-counted. Non-integer result
  fields are not summed; the last request's value is the one reported.
- **Work that can be amortised across requests should be cached on the session.**
  The packaged TypeScript worker keeps its `ts.Program` cache on the scanner
  instance for exactly this reason, and the core in turn gives TypeScript a much
  larger file batch than PHP or Python so a normal project is still one request.
- **A request that exceeds the output limit may be re-sent as smaller batches.**
  The core cannot predict how much output a request will produce — measured
  expansion from source bytes to protocol output ranges from under 2x for real
  hand-written sources to 15x for code dense in declared symbols — so it sizes
  batches optimistically and halves the budget when a worker overflows. A worker
  must therefore be safe to re-ask for files it has already partially reported
  on; the core discards the partial output of a failed request. Ordinary worker
  failures are not retried.

Each contribution has one stable owner key and lists node facts, unresolved edge
facts, and diagnostics. Re-emitting an owner replaces that owner's previous
facts atomically during reconciliation.

Required fact properties are defined by the DTOs under
`src/Scanner/Protocol`. Paths are project-relative, source lines are one-based,
and confidence is `certain`, `probable`, or `possible`.

### `cancel`

Accepts the active scan request ID. A worker stops producing contributions and
returns a cancellation error/result. The core discards uncommitted output.

### `shutdown`

Requests orderly worker termination. No new requests are accepted afterward.

## Trust boundary

Scanner output is untrusted until schema and limit validation succeeds. Workers
must not execute scanned source, invoke package lifecycle scripts, install
dependencies, access paths outside the supplied root, or write into the scanned
project.
