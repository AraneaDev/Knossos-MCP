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
