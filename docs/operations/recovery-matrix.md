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

The bounds are reported per language, under `worker_execution.scan_batches`,
because they differ per language and the byte budget can differ per scan:

- `files` — most files sent per worker request. This bound guards the deadline.
- `source_bytes` — most source bytes sent per worker request. This bound guards
  `max_output_bytes`, because protocol output has a large per-file constant
  (roughly 0.8-1.8 KB) plus a term that scales with how much source the request
  covers. Neither axis alone is sufficient — and neither is both together, since
  how densely a file declares symbols also drives output and is not modelled at
  all. That unmodelled term is why the budget adapts rather than predicts.
- `source_bytes_used` — the narrowest budget any of that language's requests ran
  at. Lower than `source_bytes` means at least one batch overflowed and was
  re-split; see below.

TypeScript uses a much larger file cap (2,000) than the default (400), and a
3 MB byte budget against the 4 MB default, because it rebuilds and re-checks a
whole `ts.Program` on every request — a cost set by the program, not by how many
files the request named — so splitting its work repeats the expensive part.

### Adaptive budgets

Measured expansion from source bytes to protocol output varies far too much for
any fixed budget to be both safe and fast: 1.68x for KaTeX's TypeScript sources,
2.24x for this repository's PHP, but 15x for corpora of very small generated
files and for code unusually dense in declared symbols. The byte budget is
therefore set optimistically, and corrected when it is wrong.

When a scan request fails with `WORKER_OUTPUT_LIMIT`, the budget is halved, that
language's worker is restarted, and **the failing batch** is re-split and
retried. The reduction applies only to that batch and its descendants: later
batches start again at the configured budget, so one pathological directory
costs a single wasted request rather than pinning the whole language at a
fraction of its budget for the rest of the scan. This repeats up to
`max_scan_batch_halvings` times (4) per batch, after which the failure falls
through to the ordinary degrade path below.

A batch of one file is never retried — there is nothing left to split — so it
degrades immediately instead of burning the remaining attempts.
`WORKER_OUTPUT_LIMIT` is the only retryable failure: a crash, a timeout, or a
cancellation is never retried.

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
