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
the class, once for the method). The check walks only the dependencies of the
components declared in the edited files, so its cost follows the edit rather
than the size of the graph, within a five-second budget. It stops at 100
violations in those files, at its edge budget and at that time limit. When it
stops early the brief says so (`policy.truncated`), the count is a bound
rather than exact, and the note adds `(check was truncated; run
check_architecture)`. A truncated check that found nothing new still sends a
note, because the turn's violations may be past the cap: it tells the model to
run `check_architecture` on the files it edited.

Only files the turn edited with Edit, Write or NotebookEdit count. A file
changed by other means (a branch checkout, a formatter, a shell command) never
contributes to the policy verdict, so switching branches does not hand the
model a whole branch's worth of violations to fix.

## The pane

`/knossos-pane` opens and closes the architecture pane. It lays itself out to
the width it has, from about 40 columns up. Its tables are packed to the left:
names take what the longest one needs, the boundary follows the name, then the
bar and the numbers, and width a table does not need stays at the right edge.
As the pane narrows, bars shorten first, then long names are cut with `…`,
then the boundary column goes, then the bars. The numbers always stay.

The pane draws in your Claude Code theme's own colours (its theme keys), so it
follows a dark, light, daltonized or ANSI theme. Colour carries meaning only:
each boundary keeps one of the eight subagent colours wherever it appears
(declared boundaries first, largest first), the status dot is `success` for a
fresh snapshot, `warning` for a stale one and `error` when a refresh or rescan
failed, and the accent marks the selection and the active tab. Everything
else is neutral: headings and numbers in the theme's text colour, notes in
its secondary grey, bar tracks and empty cells fainter still. Bars are thin
rules on a dotted track.

```text
Knossos-MCP                         ● stale · 11h  r: rescan
7,878 components · 7 boundaries · 46 drifted · PHP JS RS
1: Over  2: Hubs  3: Bound  4: Cyc  5: Iss  6: Chg ³
━━━━━━━─────────────────────────────────────────────────────

Look at now
   e: TurnBriefService.php core · 21 dependents
   t: copy test command · 3 tests reach the changes

Last turn                     3 files → 29 deps · 3 tests
   TurnBriefService.php core  ━━━━━━━━━━━━━━━━━━━━━━━━ 21
   register.tsx         hooks ━━━━━━━·················  6
   changes.ts           hooks ━━╸·····················  2

Health
   cycles       2
   max degree 161
   dead code   55   policy ✓ 0   diagnostics ✓ 0

Most depended on                                          in
›  StableId                 core ━━━━━━━━━━━━━━━━━━━━━━━ 525
   ArchitectureQueryService core ━━━━━━━━━━━╸··········· 262
```

The line under the project counts its components, its declared boundaries
(every boundary when none is declared), the files drifted since the snapshot
and its languages. A narrow pane drops the languages first.

- **Overview:** the project, the snapshot's state and age, "Look at now"
  once this session changed something (the touched file with the most
  dependents, which `e` opens in your editor, and how many tests reach the
  session's changes, whose command `t` copies; a warning when none does), the
  last turn's impact (files, dependents, tests), health (cycles, maximum degree, dead-code
  candidates, the project's policy violations and its diagnostic errors and
  warnings) and the five most depended-on components. A trend line appears
  beside a figure only once there are five snapshots and the figure moved; a
  flat line says nothing.
- **Hubs:** hubs and hotspots as one list, one row per component, with its
  boundary and its in, out and cross-boundary degree. `◆` marks a hotspot
  that is not also a hub. Methods read `Class::method`. `f` opens a filter
  field: the list narrows as you type to the components whose name holds the
  text, Enter keeps the filter and `x` clears it. `s` sorts by in, out or
  cross-boundary degree in turn; the bar follows the sort, and the title says
  it (`Hubs and hotspots · sorted by in`).
- **Cycles:** each dependency cycle, the largest first, as a chain of its
  members (`a → b → c ↺`) wrapped to the pane's width, under a line naming
  the boundary most of them are in. A member outside that boundary is drawn
  in its own boundary's colour, with a legend: that is where the cycle
  crosses. The ten largest are listed.
- **Issues:** the declared policy violations (each with the offending file
  and line), the scan's errors and warnings, the first ten dead-code
  candidates (`◇` marks one only tests reach) and the five largest files.
  The tab label carries the count of violations, errors and warnings, as in
  `Issues ³`.
- **Changes:** everything this session's turns touched, added up from each
  turn's brief: every file (most dependents first, `+` added, `−` deleted)
  with its dependents and the boundary they are in, the boundaries the
  changes reach, any policy violations they introduced, and the tests that
  reach them, nearest first, each once. Below, the command that runs those
  tests, chosen from their paths: `vendor/bin/phpunit` (the file, or
  `--filter` over the test classes), `npx vitest run`, `python -m pytest`,
  `go test` per package or `cargo test`, joined with `&&`. `c` copies it;
  `o` opens the marked file in your editor. The tab label counts the files.
  The list lives for the session and keeps at most 500 files and 500 tests
  (`partial` past that).
- **Boundaries:** a heat map of how much each boundary depends on each
  other one. Rows are where a dependency starts, columns where it lands; the
  axes are lettered (`A`, `B`, ...) and the row labels spell the letters out.
  The map is one hue, the theme's accent, in four steps on a log scale of
  the busiest cell, and a faint `·` where nothing crosses; only the axis
  letters carry the boundaries' colours. The `error` colour marks a pair a
  declared policy forbids: `×` while nothing crosses it, an error tile once
  something does. On the terminal the map is one grid of solid tiles in the
  theme's colours (it follows a theme picked in `/config`); elsewhere it is
  drawn as shades from `░` to `█`. Below it each boundary is listed with
  its components and its dependencies in from and out to other boundaries.
  A component counts in one boundary, the one the pane labels it with, and
  only boundaries that label something get a row.

```text
Boundaries                                  10 · 29,854 deps
   from→to             A  B  C  D  E  F  G  H  I  J
   A tests             ██ ██ ·  ·  ·  ▒▒ ·  ·  ·  ·
   B core              ×  ██ ×  ×  ×  ×  ×  ·  ·  ·
   C typescript-worker ·  ·  ▓▓ ·  ·  ·  ·  ·  ·  ·
```

```text
Changes this session                                 2 turns
   3 files → 29 dependents reaching core hooks
   file                                                 deps
›  src/Query/TurnBriefService.php core  ━━━━━━━━━━━━━━━   21
   hooks/register.tsx             hooks ━━━━╸··········    6
 + hooks/lib/changes.ts           hooks ━╸·············    2

Tests that reach them 3                         hops
   hooks/lib/changes.spec.ts                       1
   tests/phpunit/Query/TurnBriefServiceTest.php    1
   tests/phpunit/Store/StoreTest.php               2

   $ vendor/bin/phpunit --filter
     '(TurnBriefServiceTest|StoreTest)' && npx vitest run
     hooks/lib/changes.spec.ts
```

Every action is a button, so a click works as well as its key: `1` to `6`
switch tabs, `j` and `k` move the `›` marker (as does moving the focus with
Tab or the arrows onto a row), `o` or Enter opens the marked component, `b`
goes back, `h` shows the keys. A component opens from the Overview, Hubs and
Issues tabs; the pane looks it up by its canonical name.
`/knossos-pane inspect <component>` opens the pane on one component directly.

```text
StalenessProbe                                                class · core
src/Query/StalenessProbe.php:21

Used by 91                       edges  Uses 13                      edges
   RefreshIfStaleTest::test… tests ━━ 2     DriftOracle         core ━━━ 1
   McpServerAssembly::__con…  core ━· 1     StalenessProbe::age core ━━━ 1
```

The detail heads with the component's name, kind, boundary, file and line.
Below it, what uses it and what it uses, each the eight most connected with
their boundary and how many relationships run to each, side by side from 72
columns and one above the other below that. Every name there opens in turn.
Annotations recorded on the component follow. Escape cannot be caught by a
pane (it hands the keyboard back), so `b` is the way back, and the filter
clears with `x` or an empty Enter.

Every `file:line` the pane shows is a link: the detail's place, the places on
the Issues tab, the largest files, the last turn's files and the Changes
tab's files and tests. It is a Markdown `file:` link (`#L<line>` names the
line), so a ctrl- or cmd-click opens it as a link in one of Claude's replies
would. A plain click, and `e` on the marked row (or the shown component, or
on Overview the riskiest file), runs `code -g <path>:<line>`, which VS Code
and its forks answer; where no such command answers, the pane copies
`path:line` to your clipboard instead and says so in a toast. A terminal
editor from `$EDITOR` cannot be started this way: it needs a terminal of its
own.

Two more keys act on the marked component, or on the one the detail shows:

- `c` copies its canonical name to the clipboard of the surface you pressed
  it on, and says so in a toast.
- `q` asks Claude about it. Your press submits the one prompt below. It is
  the only prompt the mod ever submits, and only on that press; the mod never
  starts a turn on its own.

```text
Using the Knossos graph, what depends on <canonical name> and what would break if I changed it?
```

When the project's root is not allowed, the pane says so under the header and
offers `a: allow root`. That only asks: the pane shows which root it would
allow and which roots file it would add it to, with `y: allow` and
`n: cancel`. Only `y` runs `knossos allow-root <root> --execute`, against the
roots file the installation names, and then scans the edits the refusal held
back. A grant that does not land says why and can be asked again.

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
Those are the only writes to the graph. They happen only for a project that
is already scanned and inside an allowed root, never inside a hook dispatch,
and never two at a time within a session. The pane's allow-root action writes
the roots file, and only after you confirmed it. `knossos dashboard` and
`knossos component-detail` only read the graph, though like the scans they
bring a database with an older schema up to date before they read it. One
small exception: the dashboard's trend keeps the figures it computed for a
retained snapshot in `snapshot_metrics`, since an archived snapshot never
changes, so the next dashboard reads them instead of decoding the archive
again. A row answers only for the archive and the code it came from, goes
with the archive, and is never written while a scan holds the database: that
write is skipped, not waited for. None of them ever creates a database.

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
  The pane offers to run it for you, after asking.
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
whole graph), 15 for `component-detail` and `allow-root`. It runs
`allow-root` only with a roots file the installation (or the environment)
names, never one it would guess from the working directory.

Paths are compared after resolving symbolic links, so a checkout you reach
through a linked directory is still recognised as the project.
