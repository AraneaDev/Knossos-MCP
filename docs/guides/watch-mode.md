# Opt-in watch mode

`knossos watch` performs an initial scan, polls only validated discovery inputs,
and reuses the same contribution cache and atomic snapshot activation as normal
scans.

```sh
bin/knossos watch /absolute/project \
  --poll-ms=500 \
  --debounce-ms=300 \
  --max-queue=1000 \
  --db=/data/knossos.sqlite
```

Watch mode is never enabled implicitly. Structured lifecycle events are written
to standard error (`ready`, `scan_started`, `scan_completed`, `overflow`,
`error`, and `stopped`), leaving the final result available on standard output.
SIGINT and SIGTERM request graceful cancellation.

A rescan that fails transiently — a worker timeout, a busy write lease, a
disappearing file, or a temporary storage error — emits a retryable `error`
event and keeps the pending change set instead of dropping it. The watcher then
retries with bounded exponential backoff, so the failed batch is scanned again
once the underlying fault clears. Only an engine-level fault (a programming
defect that would recur identically) is treated as terminal: it emits a
non-retryable `error` and then `stopped` with reason `error`. The watcher always
emits `stopped` before returning, whether it ends by cancellation, the poll
limit, or a terminal fault. Watch results additionally report `scan_errors`.

Changes are coalesced by project-relative path during the debounce window.
Ordinary batches request an incremental scan; the scanner contribution cache
then decides exactly which owners require parsing. If the bounded queue exceeds
`max-queue`, individual paths are discarded and the next scan is safely promoted
to full mode. This prevents an event storm from creating an unbounded backlog.

Polling observes language files, package/compiler manifests, and checked-in
Knossos configuration after applying validated ignores. Configuration files
always remain fingerprinted. Watch results report poll/scan counts, incremental
and overflow full scans, coalesced changes, queue overflows, pending work, and up
to 200 lifecycle events.

The watcher does not execute project code, follow symlinks, or bypass discovery
limits. Filesystem notification backends may be added later behind the same
bounded orchestration contract; polling is the portable baseline.
