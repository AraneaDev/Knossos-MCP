# Keep a graph current with watch mode

`knossos watch` scans a project once, then polls it and rescans when files
change. It reuses the contribution cache and the atomic snapshot activation of a
normal scan, so a rescan parses only what changed. It is never started
implicitly.

```sh
bin/knossos watch /absolute/project \
  --poll-ms=500 \
  --debounce-ms=300 \
  --max-queue=1000 \
  --db=/data/knossos.sqlite
```

| option          | default | range     | what it does                                                     |
| --------------- | ------: | --------- | ---------------------------------------------------------------- |
| `--poll-ms`     |   `500` | 50–60,000 | how often the tree is checked (`1000` with `--shared`)           |
| `--debounce-ms` |   `300` | 0–60,000  | how long a change waits for more before the scan starts          |
| `--max-queue`   |  `1000` | 1–10,000  | how many changed paths are queued before the next scan goes full |
| `--shared`      |     off |           | the watcher the Claude Code mod starts, see below                |

The path has to be inside an allowed root. SIGINT and SIGTERM stop the watcher
gracefully.

## What it watches

A poll fingerprints the tree the way the scanner sees it: every discovered
language file, package and compiler manifest, and checked-in Knossos
configuration, after the validated ignores apply. Between fingerprints a stat
pass checks what the last one saw, so an idle tree costs a few hundred `stat`
calls per poll instead of hashing every file. Ordinary batches request an
incremental scan, and the contribution cache decides which owners need parsing.

The watcher does not execute project code, does not follow symlinks and does not
bypass discovery limits. It polls instead of using filesystem notifications,
because those are unreliable inside containers and differ across platforms.

## Bursts and overflow

Changes are coalesced by project-relative path during the debounce window. If
the queue holds more than `--max-queue` paths, the individual paths are dropped
and the next scan is promoted to a full one. An event storm therefore costs one
full scan, not an unbounded backlog.

## Failures

A rescan that fails transiently (a worker timeout, a busy write lease, a
disappearing file, a temporary storage error) emits a retryable `error` event
and keeps the pending change set. The watcher retries with exponential backoff,
starting at `--poll-ms`, doubling per consecutive failure and capped at 30
seconds, so the failed batch is scanned again once the fault clears.

Only an engine-level fault, a defect that would recur identically, is terminal:
it emits a non-retryable `error` and then `stopped` with reason `error`.

## Events and the result

Lifecycle events are one JSON object per line on standard error, and the final
result is on standard output.

| event            | when                                                                 |
| ---------------- | -------------------------------------------------------------------- |
| `ready`          | the initial scan is done (or found the graph already current)        |
| `changes`        | a poll found changes and nothing was pending                         |
| `overflow`       | the queue passed `--max-queue`; the next scan is full                |
| `scan_started`   | a scan begins, with its mode and the number of changes               |
| `scan_completed` | a scan ended, with its snapshot and the files it parsed              |
| `error`          | a scan or a poll failed; `retryable` says whether it will be retried |
| `stopped`        | always last, with the reason: `cancelled`, `poll_limit` or `error`   |

The result reports the poll and scan counts, incremental and full scans,
coalesced changes, queue overflows, `scan_errors`, pending work, and up to 200
of the events.

## Shared mode

`--shared` is what the [Claude Code mod](../claude-code/plugin.md) starts, and
you rarely run it by hand. It differs from the plain watcher in a few ways:

- It watches only an existing, already scanned project in an allowed root. For
  anything else it emits `refused` and stops, and it never creates a database.
- Events go to standard output, and the exit code is always `0`: every failure
  is an event.
- There is one watcher per project across every session that shares a data
  directory. The first to ask takes an advisory lock under `watch/` beside the
  database and leads. Any other watcher follows: it polls the project's active
  snapshot, which is one row and never a scan, and reports it with `following`.
  It takes over with `leading` when the leader is gone. The kernel releases the
  lock when the process ends, so a crashed watcher leaves nothing to clean up.
- The leader runs each scan in a process of its own and records it in the scan
  ledger, so a turn brief that finds its edits already scanned can still say
  what they changed. A change another writer already scanned is taken in with
  `absorbed` and no scan, and another writer's snapshot is announced as
  `snapshot`.
- It prints a `heartbeat` every 15 seconds, and stops with reason `orphaned`
  once the process that started it is gone.
