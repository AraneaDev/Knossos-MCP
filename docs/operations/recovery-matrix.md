# Fault recovery matrix

Knossos treats the active scan as the last known-good architecture graph.
Failed work is never activated, derived caches are disposable, and worker
processes are supervised within explicit protocol and resource limits.

| Fault                          | Observable diagnostic                                             | Recovery and preserved state                                                                                                                                             |
| ------------------------------ | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Worker crash or broken pipe    | `WORKER_EXITED` or `WORKER_PIPE_BROKEN`                           | Worker and Linux descendants are terminated; that language degrades to an `error` diagnostic and the rest of the scan proceeds.                                          |
| Worker timeout or flood        | `WORKER_TIMEOUT`, `WORKER_OUTPUT_LIMIT`, or `WORKER_STDERR_LIMIT` | Request is aborted and the process tree terminated; that language degrades to a diagnostic, and the limits in force are reported in the scan's `worker_execution` block. |
| Cancellation or signal         | `KNOSSOS_SCAN_CANCELLED` / watch `stopped` event                  | Worker cleanup and transaction rollback run; lease is released.                                                                                                          |
| Concurrent writer              | `KNOSSOS_SCAN_BUSY`                                               | Current active graph remains queryable; retry after the writer finishes or stale lease recovery.                                                                         |
| SQLite locked/full/I/O failure | `KNOSSOS_STORAGE_ERROR`                                           | Transaction fails closed; free capacity or release the lock, then retry.                                                                                                 |
| Partial reconciliation write   | `KNOSSOS_STORAGE_ERROR` or runtime error                          | The graph transaction rolls back and prior active scan remains selected.                                                                                                 |
| Corrupt contribution cache     | no user-visible error; affected file is reparsed                  | Invalid derived payload is discarded and rebuilt from read-only source.                                                                                                  |
| Corrupt database               | failing `doctor` integrity check                                  | Stop writers and restore a verified atomic backup; do not continue scanning the corrupt file.                                                                            |
| Stale writer lease             | `KNOSSOS_SCAN_BUSY` until lease expiry                            | A later writer atomically removes an expired lease and proceeds.                                                                                                         |

CLI execution failures use exit code `2` and a stable diagnostic prefix. MCP
tool errors carry the same family in structured content. Details after the
prefix are explanatory and may vary by operating system or SQLite version.

## Worker request batching

The limits in the `worker_execution` block are per request, not per project: a
worker request has its own cumulative output-byte budget and its own
`request_timeout_ms` deadline. A language's files are therefore split across
several requests rather than sent as one.

- `scan_batch_files` — most files sent per worker request. Each request carries
  its own output-byte budget and its own `request_timeout_ms`, so both scale
  with a batch rather than with the project. This bound guards the deadline.
- `scan_batch_source_bytes` — most source bytes sent per worker request. This
  bound guards `max_output_bytes`, because protocol output tracks how much
  source a request covers rather than how many files it named.

Both values are the defaults. A language may raise or lower either: TypeScript
uses a much larger file cap and a much smaller source-byte budget, because it
rebuilds and re-checks a whole `ts.Program` on every request while emitting
about 15.5x its source bytes, against PHP's roughly 2.2x.

Sending a whole project in one request made `max_output_bytes` a project-wide
ceiling: at roughly 14.9 KB of protocol output per PHP file, a scan of more than
about 1,340 files aborted with `WORKER_OUTPUT_LIMIT` even though `max_files`
permits far more. Batching keeps the reported budgets meaningful at any project
size.

## Degraded scans

A worker that fails or times out costs its own language, not the whole scan.
The remaining languages are still analysed and reconciled, so a dead TypeScript
worker leaves a usable PHP and Python graph rather than no graph at all.

- `degraded_languages` — owner keys of scanners that failed during this scan.
  Non-empty means the graph is partial: the listed languages contributed no
  facts, and the corresponding failure is also persisted as an `error`
  diagnostic against the scan.
- Every failure is repeated in the envelope's `warnings` as `CODE: message`, so
  a caller reading only the warnings still learns the answer is incomplete.
- Cancellation is not a degradation. `KNOSSOS_SCAN_CANCELLED` still aborts the
  entire scan, because stopping is the caller's decision rather than a fault.

## Fault-injection coverage

The standard suite exercises malformed/crashed/slow/flooding workers,
cancellation, stale and competing leases, reconciliation rollback, malformed
frames, corrupt cache rebuilds, SQLite page exhaustion, database locks, and a
worker that spawns a long-running child. The Linux supervisor test verifies
both worker and descendant disappear after cancellation.

```sh
vendor/bin/phpunit --group=worker
vendor/bin/phpunit --group=concurrency
vendor/bin/phpunit --group=fault-injection
```

On Linux, Knossos enumerates the supervised worker's `/proc` descendants before
termination and sends graceful then forced signals. Other operating systems
still terminate the direct worker; third-party scanners must not detach child
processes. The bundled PHP, TypeScript, and Python scanners do not spawn
analysis children.

For database damage, use `maintain-database integrity` and restore a backup as
described in [maintenance](maintenance.md). Cache corruption never requires a
backup because contribution payloads are derived and validated before replay.
