# Fault recovery matrix

Knossos treats the active scan as the last known-good architecture graph.
Failed work is never activated, derived caches are disposable, and worker
processes are supervised within explicit protocol and resource limits.

| Fault                          | Observable diagnostic                                             | Recovery and preserved state                                                                                                                                             |
| ------------------------------ | ----------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| Worker crash or broken pipe    | `WORKER_EXITED` or `WORKER_PIPE_BROKEN`                           | Worker and Linux descendants are terminated; that language degrades to an `error` diagnostic and the rest of the scan proceeds.                                          |
| Worker timeout or flood        | `WORKER_TIMEOUT` or `WORKER_OUTPUT_LIMIT`                         | Request is aborted and the process tree terminated; that language degrades to a diagnostic, and the limits in force are reported in the scan's `worker_execution` block. |
| Cancellation or signal         | `KNOSSOS_SCAN_CANCELLED` / watch `stopped` event                  | Worker cleanup and transaction rollback run; lease is released.                                                                                                          |
| Tree changed under a scan      | `KNOSSOS_SCAN_SNAPSHOT_CHANGED`                                   | No graph row is written and the previous active graph stays queryable; a `failed` scan row is recorded for the reaper. Rerun once writes to the tree have stopped.       |
| File unreadable under a scan   | `KNOSSOS_SCAN_SNAPSHOT_CHANGED`                                   | Same preserved state, different remedy: waiting does not restore readability, so make the named path a readable file again before rerunning.                             |
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
several requests.

The bounds are reported per language, under `worker_execution.scan_batches`,
because they differ per language and the byte budget can differ per scan:

- `files`: most files sent per worker request. This bound guards the deadline.
- `source_bytes`: most source bytes sent per worker request. This bound guards
  `max_output_bytes`, because protocol output has a large per-file constant
  (roughly 0.8-1.8 KB) plus a term that scales with how much source the request
  covers. Neither axis alone is sufficient, and neither is both together, since
  how densely a file declares symbols also drives output and is not modelled at
  all. That unmodelled term is why the budget adapts.
- `source_bytes_used`: the narrowest budget any of that language's requests ran
  at. Lower than `source_bytes` means at least one batch overflowed and was
  re-split; see below.

The default is 400 files and 4 MB per request. TypeScript uses a much larger
file cap (2,000), because it rebuilds and re-checks a whole `ts.Program` on every
request (a cost set by the program, however many files the request named), so
splitting its work repeats the expensive part. Its byte budget is 3 MB, and so is
Rust's.

### Adaptive budgets

Measured expansion from source bytes to protocol output varies far too much for
any fixed budget to be both safe and fast: 1.68x for KaTeX's TypeScript sources,
2.24x for this repository's PHP, but 15x for corpora of very small generated
files and for code unusually dense in declared symbols. The byte budget is
therefore set optimistically, and corrected when it is wrong.

When a scan request fails with a size signal, the budget is halved, that
language's worker is restarted, and **the failing batch** is re-split and
retried. The reduction applies only to that batch and its descendants: later
batches start again at the configured budget, so one pathological directory
costs that batch its own retries, and the rest of the language keeps its budget
for the rest of the scan. A batch and its descendants may be halved up to
`max_scan_batch_halvings` times (4), however many other batches also needed
retries, after which the failure falls through to the ordinary degrade path
below.

Three kinds of failure are retried this way: a size signal
(`WORKER_OUTPUT_LIMIT`, `WORKER_FRAME_TOO_LARGE`, `WORKER_REQUEST_TOO_LARGE`), a
TypeScript worker that exited from V8 heap exhaustion on a request that names
no `tsconfig`, and a worker of any language killed by SIGTERM or SIGKILL, which
Knossos never sends to a worker that still owes a response. Those two are what
a host memory guard such as earlyoom or systemd-oomd, or the kernel's OOM
killer, send to the largest process when memory runs low, and a smaller request
on a fresh worker needs less memory at its peak. A worker killed by hand with
`kill` is retried the same way; to stop a scan, cancel it instead. A worker
that crashed with its own signal (SIGSEGV, SIGABRT, SIGBUS and the like), one
stopped by SIGHUP or SIGINT, a crash for any other reason, a timeout, or a
cancellation is never retried.

Each retry halves both the file count and the bytes the batch actually held.
`source_bytes_used` reports the narrowest budget these ordinary retries settled
on.

`WORKER_FRAME_TOO_LARGE` is searched for differently, because it is one file's
answer that did not fit one frame. The files the worker had already answered are
not the cause, so they go back as one batch and only the rest is searched: its
first file alone, since a worker that answers in order failed on exactly that
file, then the others in two halves. A split
that confirmed at least as many files as it still suspects halves the search and
is free. Every other split draws on a search allowance per language of
`max_scan_batch_halvings` plus two binary searches over the files it scans (16
for 64 files, 26 for 2,000). A worker that fails before answering anything, or
answers one file before each failure, is therefore not searched file by file;
when the allowance runs out the language degrades with a diagnostic saying the
line limit is probably too low for the project. These splits do not lower
`source_bytes_used`. An oversized frame that is not a file's contribution (the
worker's response, another notification, or anything after every file in the
batch was answered) belongs to no file, and degrades the language instead of
leaving a file out.

On top of both, a language may send at most two requests per file plus four per
batch in one scan. Reaching that cap degrades the language with
`WORKER_REQUEST_CAP`, naming the cap and the last failure.

A batch of one file cannot be split any further, so it is never retried. When
that one file's own frame or output is what outgrew a limit, as a generated
bundle can, the file is left out with a diagnostic naming the limit and the rest
of the language is kept. The scan names it in its summary line and in a
`SCANNER_FILES_LEFT_OUT` warning, and counts it in `left_out_files` and nowhere
else among the processing counts: not in `parsed_files`, and not in
`unchanged_files` when a rescan reuses it. Its content still counts in
`added_files` or `changed_files` like any other file's. The left-out result is
cached against the file's content and the limits that excluded it, so an
unchanged file stays out without being sent again. When every file of a
language (two or more) is left out, the language is reported as failed with
`WORKER_EVERY_FILE_LEFT_OUT`, since that points to a limit set too low or a
broken worker. Any other failure of a one-file batch degrades the language at
once. When retries run out, the diagnostic says how many retries were made.

Sending a whole project in one request made `max_output_bytes` a project-wide
ceiling: at roughly 14.9 KB of protocol output per PHP file, a scan of more than
about 1,340 files aborted with `WORKER_OUTPUT_LIMIT` even though `max_files`
permits far more. Batching keeps the reported budgets meaningful at any project
size.

## Degraded scans

A worker that fails or times out costs its own language.
The remaining languages are still analysed and reconciled, so a dead TypeScript
worker leaves a usable PHP and Python graph.

- `degraded_languages`: owner keys of scanners that failed during this scan.
  Non-empty means the graph is partial: the listed languages contributed no
  facts, and the corresponding failure is also persisted as an `error`
  diagnostic against the scan.
- Every failure is repeated in the envelope's `warnings` as `CODE: message`, so
  a caller reading only the warnings still learns the answer is incomplete.
- Cancellation is not a degradation. `KNOSSOS_SCAN_CANCELLED` still aborts the
  entire scan, because the caller chose to stop, which is no fault of the scan.
- A tree that changes mid-scan is not a degradation either. Discovery hashes
  every file and the language workers read those same paths again for
  themselves, so a write landing between the two reads leaves graph facts that
  no recorded hash describes, which every later drift check would then report
  as `fresh`. The scan re-hashes what it discovered before it writes anything
  to the graph and aborts on the first file that no longer matches, was
  removed, or can no longer be read. The message names that file, and its
  wording says which of the three happened, because the last one is not a race
  to wait out: a rewritten or removed file is resolved by rerunning once the
  tree has settled, while an unreadable path stays unreadable and fails the
  next scan in the same place until it is corrected. As with any terminal
  attempt, the aborted scan is recorded as a `failed` scan row so stale-scan
  cleanup can reap it; no graph row is touched.

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
processes. The bundled PHP, TypeScript, Python, and Rust scanners do not spawn
analysis children.

For database damage, use `maintain-database integrity` and restore a backup as
described in [maintenance](maintenance.md). Cache corruption never requires a
backup because contribution payloads are derived and validated before replay.
