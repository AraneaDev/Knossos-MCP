# Fault recovery matrix

Knossos treats the active scan as the last known-good architecture graph.
Failed work is never activated, derived caches are disposable, and worker
processes are supervised within explicit protocol and resource limits.

| Fault                          | Observable diagnostic                                             | Recovery and preserved state                                                                                                    |
| ------------------------------ | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Worker crash or broken pipe    | `WORKER_EXITED` or `WORKER_PIPE_BROKEN`                           | Worker and Linux descendants are terminated; active graph remains unchanged.                                                    |
| Worker timeout or flood        | `WORKER_TIMEOUT`, `WORKER_OUTPUT_LIMIT`, or `WORKER_STDERR_LIMIT` | Request is aborted, process tree is terminated, incomplete scan stays inactive; effective limits are reported in scan metadata. |
| Cancellation or signal         | `KNOSSOS_SCAN_CANCELLED` / watch `stopped` event                  | Worker cleanup and transaction rollback run; lease is released.                                                                 |
| Concurrent writer              | `KNOSSOS_SCAN_BUSY`                                               | Current active graph remains queryable; retry after the writer finishes or stale lease recovery.                                |
| SQLite locked/full/I/O failure | `KNOSSOS_STORAGE_ERROR`                                           | Transaction fails closed; free capacity or release the lock, then retry.                                                        |
| Partial reconciliation write   | `KNOSSOS_STORAGE_ERROR` or runtime error                          | The graph transaction rolls back and prior active scan remains selected.                                                        |
| Corrupt contribution cache     | no user-visible error; affected file is reparsed                  | Invalid derived payload is discarded and rebuilt from read-only source.                                                         |
| Corrupt database               | failing `doctor` integrity check                                  | Stop writers and restore a verified atomic backup; do not continue scanning the corrupt file.                                   |
| Stale writer lease             | `KNOSSOS_SCAN_BUSY` until lease expiry                            | A later writer atomically removes an expired lease and proceeds.                                                                |

CLI execution failures use exit code `2` and a stable diagnostic prefix. MCP
tool errors carry the same family in structured content. Details after the
prefix are explanatory and may vary by operating system or SQLite version.

## Fault-injection coverage

The standard suite exercises malformed/crashed/slow/flooding workers,
cancellation, stale and competing leases, reconciliation rollback, malformed
frames, corrupt cache rebuilds, SQLite page exhaustion, database locks, and a
worker that spawns a long-running child. The Linux supervisor test verifies
both worker and descendant disappear after cancellation.

```sh
php tests/run.php --group=worker
php tests/run.php --group=concurrency
php tests/run.php --group=fault-injection
```

On Linux, Knossos enumerates the supervised worker's `/proc` descendants before
termination and sends graceful then forced signals. Other operating systems
still terminate the direct worker; third-party scanners must not detach child
processes. The bundled PHP, TypeScript, and Python scanners do not spawn
analysis children.

For database damage, use `maintain-database integrity` and restore a backup as
described in [maintenance](maintenance.md). Cache corruption never requires a
backup because contribution payloads are derived and validated before replay.
