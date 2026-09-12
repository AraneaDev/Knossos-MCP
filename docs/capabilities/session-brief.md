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

| State        | Meaning                                                                                                           |
| ------------ | ----------------------------------------------------------------------------------------------------------------- |
| `fresh`      | Scanned, and a drift probe found nothing changed since.                                                           |
| `stale`      | Scanned, but files have changed since the last scan.                                                              |
| `unverified` | Scanned, but drift probing was skipped: over 20,000 tracked files, or the root is currently unavailable to check. |
| `missing`    | The path belongs to a known project, but that project has no graph at all.                                        |
| `unscanned`  | The path belongs to no project.                                                                                   |

Each state renders exactly one verdict line, verbatim (the placeholders shown
are substituted at render time):

- `fresh`: `FRESH (scanned {age} ago).`
- `stale`, path allowed: `STALE ({n} files, {age}). Run scan_project path={path} first.`
- `stale`, path not allowed: `STALE ({n} files, {age}), and {path} is not an allowed root in {roots_file}. Add it: knossos allow-root {path} --execute`
- `unverified`, path allowed: `UNVERIFIED ({n} files, over probe limit; scanned {age} ago). Rescan if exactness matters.`
- `unverified`, path not allowed: `UNVERIFIED ({n} files, over probe limit; scanned {age} ago), and {path} is not an allowed root in {roots_file}. Add it: knossos allow-root {path} --execute`
- `missing`, path allowed: `NO GRAPH. Run scan_project path={path} first.`
- `missing`, path not allowed: `NO GRAPH, and {path} is not an allowed root in {roots_file}. Add it: knossos allow-root {path} --execute`
- `unscanned`, path allowed: `NOT SCANNED. Run scan_project path={path} to map this repository.`
- `unscanned`, path not allowed: `NOT SCANNED, and {path} is not an allowed root in {roots_file}. Add it: knossos allow-root {path} --execute`

`age` is a coarse, single-token duration (`17d`, `3h`, `9m`); minute precision
on a seventeen-day-old scan is noise, not accuracy.

`roots_file` is the file this brief actually read, following the same
precedence `AllowedRoots` uses: `KNOSSOS_ROOTS_FILE`, else `roots.json` beside
the database. It is named because a machine can have more than one, and the
one a running server reads is not necessarily the one derived here. Compare it
against `server_info` when a scan is refused anyway. The clause is dropped when
there was no file to consult, which is the in-memory and no-database case.

## Naming `allow-root` instead of a scan that would be rejected

Four of the five verdicts carry two forms, chosen by whether the path lies
inside a root the server can currently see. When it does not, the verdict
does not tell the agent to run `scan_project`, because that call would only
fail against the allow-list `RootGuard` enforces. It names the actual fix
instead: `knossos allow-root {path} --execute`.

This check reuses `RootGuard::resolve()`, the same containment logic a real
`scan_project` call would run, so the warning can never drift from what a
scan attempt would actually decide. It has a deliberate blind spot, though: a
server started with `--allow-root` flags on its own command line has roots
this check has no way to see, since those never touch `roots.json` or
`KNOSSOS_ALLOWED_ROOTS`. A path permitted only through such a flag is
reported here as not allowed, which is a false positive.

That blind spot is acceptable, and not merely tolerated, because of how
`serve` actually combines its sources. `--allow-root` flags and
`KNOSSOS_ALLOWED_ROOTS` are not both consulted: the environment variable is
read only when no `--allow-root` flag was passed, so exactly one of the two
supplies the static roots for a given run. Whichever one that is, it is then
unioned with the roots file, so `roots.json` always adds to whatever static
roots are active rather than replacing them. That is what keeps the advice
safe to act on even where the check is wrong: appending an already-permitted
root to `roots.json` is a no-op, not a widening of anything, because the
file was already being unioned with the active flags or environment
variable before the addition. The check is not authoritative and the docs
and the verdict text both treat it that way; it is a best-effort warning
that only ever fires in the safe direction.

The check runs for every state. An earlier version skipped it for `fresh`,
`stale` and `unverified`, reasoning that a scanned project must have had its
root accepted at some point, and that reasoning is wrong: `knossos scan`
passes the root it was handed to the guard as its own allow-list, so a scan
from the CLI self-authorises any path and leaves a project no `roots.json`
covers. A `stale` verdict on such a project used to end in
`Run scan_project path=... first.`, which is the exact call the server would
refuse, so the brief walked the agent into the dead end it exists to prevent.

Only `fresh` ignores the result. It asks for nothing, so it has nothing to
redirect, and a root warning on a graph that is currently correct would be
noise on the one verdict that needs none.

## Granting a root: `allow-root`

`knossos allow-root <path> [--execute] [--db=FILE] [--json]` appends a path
to `roots.json` (found beside the database) without hand-editing the file.
Like `annotate-component` and `install-agent-plugin`, it previews by default:
without `--execute` it reports what it would add and changes nothing. With
`--execute`, it writes the file, atomically (write to a temporary name
beside the target, then `rename`, preserving the target's existing file
mode).

`roots.json` is re-read on every request a running server handles, not cached
at startup, so a newly-granted root needs no restart and no re-registration.
A second `allow-root` run for a path already present reports it as already
there and writes nothing.

What the command says about that depends on how it found the file, because
only one of the two answers is worth relying on:

- **Named**, by `KNOSSOS_ROOTS_FILE`, `KNOSSOS_DATA_DIR`, or `--db`: the file
  is the one the caller meant, so the command reports that a server configured
  with it picks the addition up with no restart.
- **Derived** from the working directory, when none of those is set: the file
  is wherever the shell happened to be, which is rarely the file a running
  server reads. The command says so and points at `server_info` rather than
  promising an effect the caller cannot rely on. Under `--json` the same fact
  is `roots_file_source`, either `named` or `working-directory`.

A registration written by `tools/install` pins `KNOSSOS_DATA_DIR`, so a shell
that exports the same value is in the first case and agrees with the server.

The path must be absolute and must already exist as a directory: roots are
compared as literal strings against the path a scan request names, so a
relative path would never match anything and would silently grant nothing,
and a root that does not exist looks identical to a working one until
something tries to scan it.

## Which database it reads, and why it never creates one

Every other CLI command opens the graph through the shared runtime, which
creates the data directory and applies every migration before handing back a
connection. That is right for a command that is about to write and wrong
here: a `session-brief` in a directory nobody ever scanned would leave a
migrated SQLite file behind, from a hook nobody asked to run. So the command
locates the database itself, asks whether that file already exists, and opens
it only then. An absent database renders the `unscanned` verdict and touches
nothing.

Where it looks is derived from the path argument, not from the process's
working directory:

1. `--db=FILE`, when given.
2. `KNOSSOS_DATA_DIR`, which a containerised installation depends on.
3. `.knossos/knossos.sqlite` under the path argument, then under each of its
   parents, taking the nearest one that exists.

The parent walk mirrors the one that resolves a path to its project: a
session started in `src/` of a scanned repository has to reach that
repository's graph, and a lookup that stopped at the argument would find no
database there and report a fully scanned project as `NOT SCANNED`. It also
kept dropping an untracked `.knossos/` into whichever subdirectory the
session happened to start in.

The hook completes the pair from the other side: it `cd`s into the project
directory before invoking the binary, so the working directory and the
argument name the same place even for a caller that passes no path at all. A
directory it cannot enter is silent and exits 0, like every other failure
path in that script.

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
- **Graph-derived**: entry points and hubs, both filtered (see below).

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

## What the two graph sections show, and what they leave out

Both sections rank over the graph, and both have to leave most of it out. A
section an agent cannot trust at a glance is worse than an absent one,
because it is read anyway.

**Hubs** are the top five components by degree, counted the way
`architecture_health` counts: inbound plus outbound over dependency
relationships, so `contains` (which every declaration has with its own
members, and which therefore ranks nothing) is not in the tally. Vendor code,
unresolved references and test code are excluded, on exactly the terms
`architecture_health` applies them; the predicates are shared rather than
restated. Each hub is rendered with its
kind and degree, one line each, because a bare name says nothing about why
it is on the list.

Ranking on raw edge counts instead is what this section used to do, and
against this repository's own graph it produced `assertSame`,
`InvalidArgumentException`, `count`, `StableId` and `sprintf`: a test
assertion helper, an SPL class, two PHP built-ins, and one real component.

The full `architecture_health` report is not what produces this. That call
also computes hotspots, detects cycles and reconciles dead-code candidates
before it can hand back a hub list, which on this repository's graph costs
about 0.4s against 0.03s for the ranking alone. This runs on every session
start behind a hook that bounds itself at three seconds, so the brief asks
for the ranking and nothing else.

**Entry points** are the first four ways into the system, matched either by
node kind (`route`, `command`, `endpoint`) or by a classification role
(`application.controller`, `application.command`, `application.entry_point`,
`laravel.controller`, `laravel.command`), and never when the component is
classified as test code. Kind-declared entry points lead, because a scanner
read those off a route or command declaration rather than inferring them
from shape; the rest are ordered by kind and name, which is arbitrary but
stable across sessions on an unchanged graph.

Matching on kind alone, as this section used to, found nothing at all in a
repository whose ways in are classified rather than kind-tagged: this one
holds 11 `application.command` and 32 `application.entry_point`
classifications and not a single `route`, `command` or `endpoint` node, so
the section was simply never rendered. Nothing failed, which is why it went
unnoticed. The predicate now lives in one place and is shared with the
[agent brief](agent-integration.md#agent-brief).

## Why node counts and language mix are missing

`export_agent_brief` (see [agent brief](agent-integration.md#agent-brief)) leads with file,
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
