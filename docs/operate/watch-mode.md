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
incremental scan, and the contribution cache decides which owners need parsing:
the changed files, and every cached file that read one of them. The graph it
leaves is the one a full scan would produce; see [what an incremental scan
reuses](troubleshooting-and-migrations.md#what-an-incremental-scan-reuses).

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

The initial scan follows the same rules. A transient failure there is retried
the same way, and `ready` follows the first scan that succeeds; a terminal one
emits `error` and `stopped` without a `ready`.

A shared watcher's scan that runs past its time limit (see
[shared mode](#shared-mode)) is retried too, and its `error` event carries
`code: "scan_timeout"`. After 3 timeouts in a row the watcher stops: the third
`error` is not retryable and `stopped` follows with reason `error`. A scan that
succeeds resets the count.

The plain watcher exits with `2` when it stopped with reason `error`, and with
`0` otherwise.

## Events and the result

Lifecycle events are one JSON object per line on standard error, and the final
result is on standard output.

| event            | when                                                                 |
| ---------------- | -------------------------------------------------------------------- |
| `ready`          | the initial scan is done, or found the graph already current         |
| `changes`        | a poll found changes and nothing was pending                         |
| `overflow`       | the queue passed `--max-queue`; the next scan is full                |
| `scan_started`   | a scan begins, with its mode and the number of changes               |
| `scan_completed` | a scan ended, with its snapshot and the files it parsed              |
| `error`          | a scan or a poll failed; `retryable` says whether it will be retried |
| `stopped`        | always last, with the reason: `cancelled`, `poll_limit` or `error`   |

The result reports the poll and scan counts, incremental and full scans,
coalesced changes, queue overflows, `scan_errors`, pending work, the
`stopped_reason` the `stopped` event carried, and up to 200 of the events.

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

### Shared events

A shared watcher prints these on standard output, the leader's own events from
the table above included. A process that takes the lead at once starts with
`ready`, and one that had to wait starts with `following`. `ready`, `leading` and
`following` carry the process's `pid`.

| event             | who      | when                                                                                                                          |
| ----------------- | -------- | ----------------------------------------------------------------------------------------------------------------------------- |
| `refused`         | either   | the project is not scanned or not in an allowed root; its `status` says why. Nothing follows it, not even `stopped`           |
| `leading`         | leader   | a follower took the lock after the leader before it went away                                                                 |
| `following`       | follower | another process leads; carries `owner_pid`, `snapshot_id`, `stale` and `same_process`, and is sent again when `stale` changes |
| `leader_scanning` | follower | the leader started a scan, seen within a poll                                                                                 |
| `snapshot`        | either   | another writer's scan moved the active snapshot                                                                               |
| `absorbed`        | leader   | changes another writer already scanned were taken in without a scan                                                           |
| `heartbeat`       | either   | every 15 seconds, so a reader that waits for a line knows the watcher is still there                                          |
| `stopped`         | either   | the last event of every run that was not refused; reason `cancelled`, `orphaned`, `poll_limit` or `error`                     |

A follower is `stale` when the leader's own heartbeat is more than 60 seconds
old: alive, but not polling.
