# Session brief

`session-brief` renders the short orientation text a Claude Code session sees
before it does anything else. It is addressed by filesystem path rather than
by project id, because the hook that calls it runs at session start, before
anyone has a project id to hand it. Its own CLI command exists rather than
another arm of the query command for the same reason its failure contract is
inverted: every other query command signals a bad invocation by throwing,
which is right for a person at a terminal and wrong for a hook that runs
before a session starts. `session-brief` never throws. It exits 0 and prints
nothing on any failure, because a broken brief that breaks a session start
costs more than the brief was ever worth.

The brief reflects the last scan, not the working tree. For anything current,
call `scan_project`, then the live query tools the pointer at the end of the
brief names.

## The five states

| State        | Meaning                                                                                                        |
| ------------ | -------------------------------------------------------------------------------------------------------------- |
| `fresh`      | Scanned, and a drift probe found nothing changed since.                                                        |
| `stale`      | Scanned, but files have changed since the last scan.                                                           |
| `unverified` | Scanned, but drift probing was skipped: over 500 tracked files, or the root is currently unavailable to check. |
| `missing`    | The path belongs to a known project, but that project has no graph at all.                                     |
| `unscanned`  | The path belongs to no project.                                                                                |

Each state renders exactly one verdict line, verbatim (the placeholders shown
are substituted at render time):

- `fresh`: `FRESH (scanned {age} ago).`
- `stale`: `STALE ({n} files, {age}). Run scan_project path={path} first.`
- `unverified`: `UNVERIFIED ({n} files, over probe limit; scanned {age} ago). Rescan if exactness matters.`
- `missing`, path allowed: `NO GRAPH. Run scan_project path={path} first.`
- `missing`, path not allowed: `NO GRAPH, and {path} is not an allowed root. Add it: knossos allow-root {path} --execute`
- `unscanned`, path allowed: `NOT SCANNED. Run scan_project path={path} to map this repository.`
- `unscanned`, path not allowed: `NOT SCANNED, and {path} is not an allowed root. Add it: knossos allow-root {path} --execute`

`age` is a coarse, single-token duration (`17d`, `3h`, `9m`); minute precision
on a seventeen-day-old scan is noise, not accuracy.

## Naming `allow-root` instead of a scan that would be rejected

The `missing` and `unscanned` verdicts each carry two forms, chosen by
whether the path lies inside a root the server can currently see. When it
does not, the verdict does not tell the agent to run `scan_project`, because
that call would only fail against the allow-list `RootGuard` enforces. It
names the actual fix instead: `knossos allow-root {path} --execute`.

This check reuses `RootGuard::resolve()`, the same containment logic a real
`scan_project` call would run, so the warning can never drift from what a
scan attempt would actually decide. It has a deliberate blind spot, though: a
server started with `--allow-root` flags on its own command line has roots
this check has no way to see, since those never touch `roots.json` or
`KNOSSOS_ALLOWED_ROOTS`. A path permitted only through such a flag is
reported here as not allowed, which is a false positive.

That blind spot is acceptable, and not merely tolerated, because the three
root sources (`KNOSSOS_ALLOWED_ROOTS`, the roots file, and `--allow-root`
flags) are unioned wherever access is actually decided. Advice that is wrong
in this specific direction is still safe to act on: appending an
already-allowed root to `roots.json` is a no-op, not a widening of anything.
The check is not authoritative and the docs and the verdict text both treat
it that way; it is a best-effort warning that only ever fires in the safe
direction.

Fresh, stale, and unverified states skip this check entirely. All three imply
a project that was already scanned, which means its root was necessarily
accepted once already, so there is nothing the check could add.

## Granting a root: `allow-root`

`knossos allow-root <path> [--execute] [--db=FILE] [--json]` appends a path
to `roots.json` (found beside the database) without hand-editing the file.
Like `annotate-component` and `install-agent-plugin`, it previews by default:
without `--execute` it reports what it would add and changes nothing. With
`--execute`, it writes the file, atomically (write to a temporary name
beside the target, then `rename`, preserving the target's existing file
mode), and reports that the addition takes effect immediately.

That immediacy is not incidental. `roots.json` is re-read on every request a
running server handles, not cached at startup, so a newly-granted root needs
no restart and no re-registration. A second `allow-root` run for a path
already present reports it as already there and writes nothing.

The path must be absolute and must already exist as a directory: roots are
compared as literal strings against the path a scan request names, so a
relative path would never match anything and would silently grant nothing,
and a root that does not exist looks identical to a working one until
something tries to scan it.

## Budgets: bounding the optional sections, not the whole output

Each state has a character budget on its _optional_ sections:

| State                            | Budget          |
| -------------------------------- | --------------- |
| `unscanned`                      | 200 characters  |
| `stale`, `unverified`, `missing` | 500 characters  |
| `fresh`                          | 1200 characters |

Sections are appended and kept only while the running total (including the
closing pointer) still fits; a section that would not fit is dropped whole,
never truncated mid-list. A list cut off partway through reads as a complete
list that happens to be wrong, which is worse than a shorter list.

The verdict line and the closing skill pointer (`Ask before grepping for
structure: the knossos skill.`) sit **beneath** this budget as an
irreducible floor, not subject to it. Both are always emitted, even when the
verdict line alone would already exceed the budget for that state. The
verdict line embeds the project path, which has no upper bound, so "never
exceed the budget" and "never drop the verdict or the pointer" cannot both
hold for every possible path; the floor wins. Dropping or truncating the
verdict would hand back a `scan_project path=...` that nobody could actually
run, and dropping the pointer would mean the `knossos` skill is never armed
for that session. An unusually long path can therefore push the rendered
output past its nominal budget; that is accepted as the price of never
emitting a broken command.

## What survives a stale verdict, and what does not

The brief draws a hard line between two kinds of material:

- **Config-derived**: boundary rules, read from `knossos.json` on disk (not
  from the config snapshot stored at scan time, so a policy edited since the
  last scan is still the policy CI will enforce), and notes, read from the
  `note`-kind rows in the annotations table.
- **Graph-derived**: entry points (routes, commands, and endpoints) and hubs
  (the most-depended-on components by inbound edge count).

Rules and notes are rendered for every state that has a project at all
(fresh, stale, unverified, missing), because neither a declaration in
`knossos.json` nor an annotation written in an earlier session is
invalidated by the current scan being out of date. A stale scan means the
_graph_ might not match the working tree; it says nothing about whether a
boundary rule still applies or a note is still true.

Entry points and hubs, by contrast, are omitted for every state except
`fresh`. Both are read straight out of the graph tables (`nodes`, `edges`),
so they can describe a codebase that no longer exists once the tree has
drifted from the scan that produced them. Showing them under `stale` or
`unverified` would present stale structural claims with the same confidence
as current ones.

## Why node counts and language mix are missing

`export_agent_brief` (see [agent brief](agent-brief.md)) leads with file,
component, and relationship counts and the language mix, because it is read
once by a person settling into a codebase. `session-brief` deliberately
drops all of that. It is billed on every session start, resume, and compact,
not read once, and counts that read impressively change no decision an agent
is about to make. What remains is action-shaped: a verdict, rules, notes,
and (when fresh) where to look next.

## Binary discovery and the timeout chain

The `SessionStart` hook that calls `session-brief` looks for the `knossos`
binary in a fixed, short order, because a long search is a slow session
start:

1. `KNOSSOS_BIN`, if set and executable.
2. `knossos` on `PATH`.
3. Conventional locations: `$CLAUDE_PROJECT_DIR/bin/knossos`,
   `$HOME/.local/bin/knossos`, `/usr/local/bin/knossos`.

Nothing found at any of those means the hook exits 0 with no output.

The call itself is bounded by `timeout`, then `gtimeout`, in that order. A
plain macOS install ships neither GNU coreutils' `timeout` nor, by extension,
`gtimeout`; Homebrew's coreutils installs the GNU tool under the `gtimeout`
name specifically so it never shadows a BSD tool of the same name, which is
why the hook tries that name second rather than assuming it is absent. If
neither binary exists, the call runs unbounded at the shell level, and the
backstop becomes the harness itself: `hooks/hooks.json` sets this hook's own
`timeout` to 15 seconds, which Claude Code enforces on the whole process
regardless of what runs inside it.

## Every failure path is silent and exits 0

The hook script, the container variant of it, and the `session-brief`
command itself share one contract: nothing they do can cost a session
anything beyond a bounded amount of time. No binary found, the command
exits non-zero, the output is empty, the timeout fires: every one of these
exits 0 with nothing printed. A brief that fails to help is acceptable. A
brief that fails to be harmless is not.

## Invocation

```sh
knossos session-brief [path] [--db=FILE] [--json]
```

`path` defaults to the current working directory. `--json` prints the full
result envelope instead of the plain text a hook injects directly. The
command always exits 0.
