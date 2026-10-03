# Claude Code mod

The Knossos plugin for Claude Code carries a mod: a module of function hooks
that puts the graph on screen while a session works. It shows you what the
current turn did to the architecture, tells the model when it edits a file
that many others depend on, and reports boundary-policy violations a turn
introduced so the model fixes them. An architecture pane gives the
project-wide picture on demand.

The mod never blocks an edit, never starts a turn on its own and never scans
inside a hook. When anything it needs is missing, it stays quiet.

## The band

After a turn that edited files, a single line above the prompt sums up the
blast radius:

```text
knossos · 2 files → 37 dependents · Core, Http · 4 tests · as of 12s ago
```

That is the files the turn changed, how many other files depend on them, the
boundaries those dependents sit in, the tests that reach the change, and how
old the figures are. The age keeps counting while the band is on screen, so
figures from an hour ago read as an hour old.

While a scan runs, the band says `scanning…` and keeps the previous figures
with their age. A scan that fails says `scan failed`, with the reason when
there is one. Red means the turn introduced policy violations.

`[ details ]` opens the architecture pane; `[ hide ]` hides the band for the
rest of the session.

## The edit note

When the model edits or writes a file with at least `fanInThreshold`
dependent files, it reads one extra line after the tool result, and you see
the same line as a toast:

```text
knossos: src/Router.php has 41 dependent files across 3 boundaries (Http, Core, Cli); run test_impact before finishing.
```

The figures come from the last dashboard load, so the note costs no process
at edit time.

## Policy violations

When the project declares boundary policies (in `knossos.json`) and
`enforcePolicies` is on, the post-turn brief reports only the violations the
turn introduced. Before the scan it evaluates the policies for violations
whose source component lives in a file the turn edited; after the scan it
does the same for those files again. A violation in the second set
and not the first is new. One that was already there, in an edited file or in
a file that merely depends on one, is not reported. New violations reach the
model as a note it reads before its next step:

```text
knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:
- domain-isolation: App\Domain\Order → App\Infra\Db
```

The count is per violating dependency, so one call can count twice (once for
the class, once for the method). The underlying check stops at 100 violations
across the whole project and at its time limit. When it stops early the brief
says so (`policy.truncated`), the count is a bound rather than exact, and the
note adds `(check was truncated; run check_architecture)`. A truncated check
that found nothing new still sends a note, because the turn's violations may
be past the cap: it tells the model to run `check_architecture` on the files
it edited.

Only files the turn edited with Edit, Write or NotebookEdit count. A file
changed by other means (a branch checkout, a formatter, a shell command) never
contributes to the policy verdict, so switching branches does not hand the
model a whole branch's worth of violations to fix.

## The pane

`/knossos-pane` opens and closes the architecture pane. It lays itself out to
the width it has, from about 40 columns up: as the pane narrows, bars shorten
first, then long names are cut with `…`, then the boundary column goes, then
the bars. The numbers always stay. Each boundary keeps one colour wherever it
appears; the status dot is green for a fresh snapshot, yellow for a stale one
and red when a refresh or rescan failed.

```text
Knossos-MCP                         ● stale · 11h  r: rescan
10 hubs · 2 cycles · 55 dead code · 46 drifted
1: Overview  2: Hubs  3: Boundaries  4: Cycles  5: Issues
━━━━━━━━━━━─────────────────────────────────────────────────

LAST TURN                         2 files → 27 deps · 4 tests
   TurnBriefService.php core  ███████████████████████████ 21
   register.tsx         hooks ███████▊                     6

HEALTH
   cycles       2
   max degree 161
   dead code   55   policy ✓ 0

MOST DEPENDED ON                                          in
›  StableId                 core  ██████████████████████ 525
   ArchitectureQueryService core  ███████████            262
```

- **Overview:** the project, the snapshot's state and age, the last turn's
  impact (files, dependents, tests), health (cycles, maximum degree, dead-code
  candidates, the last turn's policy verdict) and the five most depended-on
  components. A trend line appears beside a figure only once there are five
  snapshots and the figure moved; a flat line says nothing.
- **Hubs:** hubs and hotspots as one list, one row per component, with its
  boundary and its in, out and cross-boundary degree. `◆` marks a hotspot
  that is not also a hub. Methods read `Class::method`.
- **Boundaries, Cycles, Issues:** coming in the next builds.

Every action is a button, so a click works as well as its key: `1` to `5`
switch tabs, `j` and `k` move the `›` marker (as does moving the focus with
Tab or the arrows onto a row), `o` or Enter opens the marked component, `b`
goes back, `h` shows the keys. Opening a component shows its kind, location,
boundaries and who uses it; the pane looks it up by its canonical name.
`/knossos-pane inspect <component>` opens the pane on one component directly.

A count that hit a search limit reads `50+`, never `50`. When the walk that
ranks hubs and hotspots stops at its limit (five seconds on a cold, large
graph), the lists say `partial`.

Every time the pane opens, by `/knossos-pane`, `[ details ]` or on start, it
reloads the dashboard. The header gives the snapshot's age, which keeps
counting while the pane is open. When a reload fails, the pane keeps the
figures it had and the header says `refresh failed · 2m`.

When the snapshot is stale or files drifted since it, the header offers
`r: rescan`. It runs an incremental scan (`knossos rescan`) under the same
rules as the turn's scan, shows `scanning…` meanwhile, and reloads the pane
when it is done. A rescan that does not land says why in the header and
leaves the figures as they were.

`openPaneOnStart` opens it when a session starts. A pane opened that way,
without you asking, only takes its place at 144 terminal columns or more; on
a narrower terminal it waits until there is room. One you open yourself
seats at any width.

## Settings

| Field             | Default | What it does                                                     |
| ----------------- | ------- | ---------------------------------------------------------------- |
| `enabled`         | `true`  | Turns the band, notes and pane on or off.                        |
| `fanInThreshold`  | `20`    | Dependent files at which an edit gets a note (1 to 100000).      |
| `enforcePolicies` | `true`  | Tells the model about violations a turn introduced.              |
| `openPaneOnStart` | `false` | Opens the pane when a session starts, on a wide enough terminal. |

They appear in Claude Code's config menu under the plugin's name.

## What it writes

After a turn that edited files, the mod runs `knossos turn-brief`, which
runs an incremental scan into the project's existing database. The pane's
rescan runs `knossos rescan`, the same incremental scan without the brief.
Those are the only writes. They happen only for a project that is already
scanned and inside an allowed root, never inside a hook dispatch, and never
two at a time within a session. `knossos dashboard` and
`knossos component-detail` only read the graph, though like the scans they
bring a database with an older schema up to date before they read it. None
of them ever creates a database.

The scan only counts changes Claude made with Edit, Write or NotebookEdit as
a reason to run. A turn that only ran shell commands does not trigger one,
because a scan that finds nothing still costs a few seconds; the next edit's
scan picks those changes up.

## Which graph it reads

The MCP server and the hooks must read the same database. The MCP server
gets its data directory from its own registration; hooks do not see that
environment. `install-agent-plugin` therefore writes the data directory into
the installed scripts: `--data-dir=DIR` when given, otherwise the
`KNOSSOS_DATA_DIR` of the shell you install from. Without either, the hooks
fall back to the `.knossos/` directory nearest the project, which is right
only when the server uses that too.

```sh
knossos install-agent-plugin --data-dir="$HOME/.knossos" --execute
```

## When it stays silent

- No `knossos` binary on the path or in the usual locations.
- The project is not inside an allowed root: the band shows the exact
  command instead of figures, naming the roots file the brief read and the
  root to allow (the ancestor project root when that is what would be
  scanned):
  `KNOSSOS_ROOTS_FILE='<roots file>' knossos allow-root '<root>' --execute`.
- The project has never been scanned.
- An older Claude Code without function hooks: the module is ignored and the
  session-start brief keeps working as before.

Failures in the wrapper are silent by design: it exits 0 with no output, and
the band keeps its last figures with their real age. A timeout or empty answer
is asked again at the next turn that edited files; it never turns the mod off.

The one exception is a missing binary (or, for a container install, a missing
`docker`). The wrapper then prints `{"status":"no-binary"}`, and the mod writes
one log line and turns the band and pane off for the session.

The wrapper bounds each call: 60 seconds for `turn-brief` and the pane's
rescan, 30 for `dashboard` (a first dashboard of a large project walks the
whole graph), 15 for `component-detail`.

Paths are compared after resolving symbolic links, so a checkout you reach
through a linked directory is still recognised as the project.
