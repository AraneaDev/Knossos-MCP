# Claude Code mod

The Knossos plugin for Claude Code carries a mod: a module of function hooks
that puts the graph on screen while a session works. It shows you what the
current turn did to the architecture, tells the model when it edits a file
that many others depend on, and reports boundary-policy violations a turn
introduced so the model fixes them. An architecture pane gives the
project-wide picture on demand, and a live watcher keeps that picture current
while you work.

The mod never blocks an edit, never starts a turn on its own and never scans
inside a hook. When anything it needs is missing, it stays quiet.

## The band

After a turn that edited files, a single line above the prompt sums up the
blast radius:

```text
knossos · 2 files → 37 dependents · 4 tests · as of 12s ago · reaching Core, Http
```

That is the files the turn changed, how many other files depend on them, any
policy violations it introduced, the tests that reach the change, how old the
figures are, and the boundaries those dependents sit in (by their short
names, the two holding the most dependents, then a count of the rest). When
the project declares boundaries, only declared ones are named. The figures
come first and the boundaries last, so a narrow terminal cuts the boundaries
before anything else. The age keeps counting while the band is on screen, so
figures from an hour ago read as an hour old.

While a scan runs, the band says `scanning…` and keeps the previous figures
with their age. A scan that fails says `scan failed`, with the reason when
there is one. Red means the turn introduced policy violations.

`[ details ]` opens the architecture pane; `[ hide ]` hides the band for the
rest of the session.

When the project's root is not allowed, the band says so and names the root
(`knossos · not allowed: Knossos-MCP`). The command that allows it does
not fit a band, so `[ copy ]` copies it whole, and `[ details ]` opens
the pane, which offers to run it for you. Any band line that is still too
wide for the terminal is cut at its end, before its buttons.

## Notes for the model

The mod tells the model a few facts at the moment they help, so it keeps to
the architecture instead of being told afterwards that it broke it. Every
note is one short line, said once, and none costs a process: the figures
come from the last dashboard load.

**Before an edit.** When the model Reads a file with at least
`fanInThreshold` dependent files, or a file in a boundary a declared policy
binds, it reads one line after the file:

```text
knossos: src/Boundary/BoundaryInference.php (core) has 269 dependent files. Policy: core may not depend on php-worker, typescript-worker, python-worker, rust-worker, tooling, tests.
```

The rules of a boundary are stated once per session: the next file read in
`core` gets its count alone (`knossos: src/Bundle/GraphBundleDecoder.php
(core) has 244 dependent files.`), and a quiet file there gets nothing. A
quiet file in a policed boundary nobody read yet gets the boundary and its
rules (`knossos: workers/php/bin/worker is in php-worker. Policy: php-worker
may not depend on core.`). Only a declared boundary is named; a file in an
inferred one only, or in none, gets its count. A rule with an allow list
reads `edge may depend only on itself, core, unassigned code`, and one
limited to some dependency kinds names them in brackets. The rules come with
the dashboard (`policy.rules`), with each policed boundary
(`policy.boundaries`): a boundary declared by `path_prefix` binds every file
under that prefix, however large the project. Only the files of a boundary
placed by namespace are listed (`policy.files`, at most 2,000 files). When
that list stops at its cap before it reaches a file, the note does not read
as "no rules": it says `knossos: lib/Late.php: rules may bind this file; run
check_architecture.`

**On an edit.** An edit or write of a file with at least `fanInThreshold`
dependent files that the model was not told about on Read (Claude Code reads
a file before it edits it, so this is rare) adds one line, which you also see
as a toast:

```text
knossos: src/Router.php has 41 dependent files across 3 boundaries; run test_impact before finishing.
```

**After a turn.** When the turn's scan finds tests that reach the files it
changed, the model reads which, and the command that runs them, chosen as on
the Changes tab. A test the turn already ran after its last edit is left
out: a Bash command that runs its runner and names the test, a directory
holding it, or no test at all (the whole suite, such as `vendor/bin/phpunit`
or `npm run test:mod`). A test named in an earlier note is not named again.
Tests the command names itself (PHPUnit, Vitest, Jest, pytest) are not
listed twice. A JavaScript test runs with the runner its nearest
`package.json` names (Vitest or Jest, in its dependencies or its `test`
script); when that is ambiguous or unknown, the test is listed and no
command is given for it:

```text
knossos: 3 tests reach this turn's changes. Run: vendor/bin/phpunit --filter '(DashboardServiceTest|FileFanInQueryTest|TurnBriefServiceTest)'
```

This line and the policy note below arrive together, as one note.

**Limits.** Each file is noted once per session and per agent: a subagent
gets its own notes, since what the main loop read was never in its context.
At most three notes follow tool results in one turn; a note held back by
that cap is said at the next Read or edit of the file. A note that fails
leaves the tool result as it was and one line in the debug log. A turn ends with at most one
note. `agentNotes` turns them all off; the toast stays.

Approximate cost on this repository: 20 to 60 tokens for a Read note (60
only for the first file read in a policed boundary), about 35 for an edit
note, about 50 for a tests note naming three PHPUnit classes (up to about
150 at the brief's cap of 20 tests), about 55 for one policy violation.

## Policy violations

When the project declares boundary policies (in `knossos.json`) and
`enforcePolicies` is on, the post-turn brief reports only the violations the
turn introduced. Before the scan it evaluates the policies for violations
whose source component lives in a file the turn edited; after the scan it
does the same for those files again. A violation in the second set
and not the first is new. One that was already there, in an edited file or in
a file that merely depends on one, is not reported. New violations reach the
model as a note it reads before its next step, each once a session:

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

`/knossos` opens and closes the architecture pane. Right after a reload of
the plugin Claude Code can refuse to register the command until a session is
bound; the mod tries again on a timer (for about a minute) and then at the
end of each turn, and starts up meanwhile. The pane lays itself out to
the width it has, from about 40 columns up. Its tables are packed to the left:
names take what the longest one needs, the boundary follows the name, then the
bar and the numbers, and width a table does not need stays at the right edge.
As the pane narrows, bars shorten first, then long names are cut with `…`,
then the boundary column goes, then the bars. The numbers always stay.

The pane draws in your Claude Code theme's own colours (its theme keys), so it
follows a dark, light, daltonized or ANSI theme. Colour carries meaning only:
each boundary keeps one of seven subagent colours wherever it appears
(declared boundaries first, largest first). Red is not one of them: on this
pane red means an error or a policy violation, never a boundary. An inferred
boundary that got no colour of its own is drawn neutral rather than in a
colour it would share by chance. Two boundaries whose short names differ only
in case (an inferred `namespace:Knossos` beside a `composer:…/knossos`) keep
their source in the label, `namespace:Knossos` and `composer:knossos`, so they
never read as one. The status dot is `success` for a fresh or live snapshot,
`warning` for a stale one or a scan under way and `error` when a refresh or
rescan failed, and the accent marks the selection and the active tab. Everything
else is neutral: headings and numbers in the theme's text colour, notes in
its secondary grey, bar tracks and empty cells fainter still. Bars are thin
rules on a dotted track.

```text
Knossos-MCP                         ● stale · 11h  r: rescan
7,878 components · 7 boundaries · 46 drifted · PHP JS RS
1: Overview  2  3  4  5  6³
━━━━━━━━━━━─────────────────────────────────────────────────

Look at now                                     this session
›  TurnBriefService.php core · 21 dependents
   t: copy test command   3 tests reach these changes

Last turn               3 files → 29 dependents · 3 tests
   TurnBriefService.php core  ━━━━━━━━━━━━━━━━━━━━━━━━ 21
   register.tsx         hooks ━━━━━━━·················  6
   changes.ts           hooks ━━╸·····················  2

Health
   cycles         2
   max degree   161
   dead code     55
   policy       ✓ 0
   diagnostics  ✓ 0

Most depended on                                          in
   StableId                 core ━━━━━━━━━━━━━━━━━━━━━━━ 525
   ArchitectureQueryService core ━━━━━━━━━━━╸··········· 262
```

The line under the project counts its components, its declared boundaries
(every boundary when none is declared), the files drifted since the snapshot
and its languages. A narrow pane drops the languages first. When the dashboard
names the drifted files, their count is drawn in the accent and the word after
it is a button, the one thing on that quiet line you can press; `d` does the
same: it lists the drifted files right there,
under the header, each marked as Changes marks a file (`+` added, `−`
deleted) with its own boundary, the first twenty by path and how many more.
While they are listed the marker walks them: `o` opens a file's detail as the
snapshot holds it, `e` the file itself. `d` again, or a tab, hides them.

```text
Drifted since the snapshot                                 3
›  hooks/lib/changes.ts                            hooks
 + hooks/lib/files.ts
   src/Query/DashboardService.php                  core
```

- **Overview:** the project, the snapshot's state and age, "Look at now"
  once this session changed something (the touched file with the most
  dependents, and how many tests reach this session's changes, whose command
  `t` copies; a warning when none does), the last turn's impact (files,
  dependents, tests: the last turn's alone, where "Look at now" counts the
  whole session, and each section says which in its header), health (cycles, maximum degree, dead-code
  candidates, the project's policy violations and its diagnostic errors and
  warnings) and the five most depended-on components. One marker walks the
  three lists in the order they are drawn, and starts on the file "Look at
  now" points at: `o` opens the marked file's or component's detail, `e` the
  marked file in your editor (a hub opens at the line that declares it; one
  the graph places nowhere, such as a stand-in for a vendor symbol, offers no
  `e`). A trend line appears
  beside a figure only once there are five snapshots and the figure moved; a
  flat line says nothing.
- **Hubs:** hubs and hotspots as one list, one row per component, with its
  boundary and its in, out and cross-boundary degree. `◆` marks a hotspot
  that is not also a hub. Methods read `Class::method`. `f` opens a filter
  field: the list narrows as you type to the components whose name holds the
  text, Enter keeps the filter and `x` clears it. `s` sorts by in, out or
  cross-boundary degree in turn; the bar follows the sort, and the title says
  it (`Hubs and hotspots · sorted by in`). The sorted column's numbers are in
  the text colour and the other two are dimmed. When every listed row sits in
  one boundary, the boundary column goes and the header says it once
  (`all in core`); otherwise a row in the same boundary as the row above
  draws its label dimmed, so the column reads by where the boundary changes.
  The same holds for Overview's most depended on, and the last turn dims its
  repeats. `e` opens a hub's file at its declaration, as on Overview.
- **Cycles:** each dependency cycle, the largest first, as a chain of its
  members (`a → b → c ↺`) wrapped to the pane's width, under a line naming
  the boundary most of them are in. A member outside that boundary is drawn
  in its own boundary's colour, with a legend: that is where the cycle
  crosses. The ten largest are listed. `j` and `k` mark a cycle: `o` opens
  the member where it leaves its own boundary (its uses include the edge that
  closes the loop), `c` copies the whole chain, and `q` asks Claude how to
  break it.
- **Issues:** the declared policy violations (each with the offending file
  and line), the scan's errors and warnings, the first ten dead-code
  candidates (`◇` marks one only tests reach) and the five largest files.
  The tab label carries the count of violations, errors and warnings, as in
  `Issues ³`.
- **Changes:** everything this session's turns touched, added up from each
  turn's brief: every file (most dependents first, `+` added, `−` deleted)
  with its dependents and the boundary it sits in (blank when it sits in
  none, or only in one spanning the whole repository), the boundaries its
  dependents are in (where the changes reach), any policy violations they introduced, and the tests that
  reach them, nearest first, each once. Below, the command that runs those
  tests, chosen from their paths: `vendor/bin/phpunit` (the file, or
  `--filter` over the test classes), `npx vitest run` or `npx jest` (as the
  project's `package.json` says; a script whose runner is unknown is left
  out), `python -m pytest`, `go test` per package or `cargo test`, joined
  with `&&`. The paths are relative to the project root; when the session
  sits elsewhere (below an ancestor project's root), the command starts
  with `cd <project root> &&`, and so does the one in the model's note. `t`
  copies it, as on Overview; `c` copies the marked file's path, as on every
  list. `o` opens the marked file's detail and `e` the file in your editor.
  The tab label counts the files.
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
  theme's colours (it follows a theme picked in `/config`, daltonized and
  ANSI ones included, the ANSI colours as xterm paints them); elsewhere it is
  drawn as shades from `░` to `█`. Below it each boundary is listed with
  its components and its dependencies in from and out to other boundaries.
  A component counts in one boundary, the one the pane labels it with, and
  only boundaries that label something get a row. `j` and `k` mark a
  boundary, and the pane spells it out under the table: the boundaries it
  depends on and the ones that depend on it, each with its count (red where a
  policy forbids the pair), and the ones a policy forbids it to use. There is
  nothing to open; `c` copies the boundary's name and `q` asks Claude what
  its dependencies are for.

```text
G tooling
   depends on   core 18  hooks 4
   used by      nothing outside itself
```

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

Tests that reach these changes · 3              hops
   hooks/lib/changes.spec.ts                       1
   tests/phpunit/Query/TurnBriefServiceTest.php    1
   tests/phpunit/Store/StoreTest.php               2

   $ vendor/bin/phpunit --filter
     '(TurnBriefServiceTest|StoreTest)' && npx vitest run
     hooks/lib/changes.spec.ts
```

Every action is a button, so a click works as well as its key: `1` to `6`
switch tabs, `j` and `k` move the `›` marker (as does moving the focus with
Tab or the arrows onto a row), `o` or Enter opens the marked row, `e` opens
the marked row's file in your editor, `c` copies and `q` asks about it, `t`
copies the test command, `d` lists the drifted files, `b` goes back, `h`
lists the keys, one a line. The keys mean the same on every tab. A component
opens from the Overview, Hubs, Cycles and Issues tabs and from a detail; the
pane looks it up by its canonical name. A file opens from Overview ("Look at
now" and the last turn), Changes, the drifted files and a file's detail. Back
on the tab, the marker stands where the detail was opened from. Numbers of four digits or more are grouped
(`10,867`).
`/knossos inspect <component>` opens the pane on one component directly.

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
columns and one above the other below that. A side whose counts are all the
same draws no bars, since they would compare nothing. The marker walks both
sides, used by first, and every name there opens in turn.
Annotations recorded on the component follow.

A file's detail answers what depends on it. It heads with the file's name,
its own boundary, its path (a link), language and size. Below, the files that
depend on it, the ten most connected with their boundary and how many
relationships run from each, the boundaries a change here reaches, and how
many more there are; then the components it declares, the twelve most used
with how many components in other files use each. A dependent opens as its
own file detail, a component as a component's detail. The counts leave out
the stand-ins for symbols declared outside the project (`sprintf`, a vendor
class), which knossos files under the first file that names them, and the
module node that stands for the file itself; the fan-in figures in the band,
the notes and Changes leave out the same stand-ins.

```text
Assertions.php                                                tests
tests/phpunit/Support/Assertions.php · PHP · 91 lines

Depended on by 251 files                                      edges
   reaching tests core
›  tests/phpunit/Reconciliation/GraphReconcilerTest.php tests ━━━ 206
   tests/phpunit/Discovery/IgnoreMatcherTest.php        tests ━━╸ 167
   +241 more

Declares 7 components                     used by
   assertSame        ━━━━━━━━━━━━━━━━━━━━━━ 2,360
   captureThrows     ━━╸··················    313
```

Escape cannot be caught by a
pane (it hands the keyboard back), so `b` is the way back, and the filter
clears with `x` or an empty Enter.

Every `file:line` the pane shows is a link: the detail's place, the places on
the Issues tab, the largest files, a file detail's path and the Changes
tab's tests. It is a Markdown `file:` link (`#L<line>` names the
line), so a ctrl- or cmd-click opens it as a link in one of Claude's replies
would. A plain click, and `e` on the marked row (or on what the detail shows), runs `code -g <path>:<line>`, which VS Code
and its forks answer; where no such command answers, the pane copies
`path:line` to your clipboard instead and says so in a toast. A terminal
editor from `$EDITOR` cannot be started this way: it needs a terminal of its
own.

Two more keys act on the marked row, or on what the detail shows:

- `c` copies its canonical name (a file's path) to the clipboard of the surface you pressed
  it on, and says so in a toast.
- `q` asks Claude about it. Your press submits the one prompt below (on
  Cycles, how to break the marked cycle; on Boundaries, what the marked
  boundary's dependencies are for). It is the only prompt the mod ever
  submits, and only on that press; the mod never starts a turn on its own.

```text
Using the Knossos graph, what depends on <canonical name> and what would break if I changed it?
```

With no graph for the project yet, the pane says so under a heading
(`No architecture graph yet`) and offers one thing to do: `q: ask Claude to scan
it`, which submits a prompt asking Claude to scan the project through the
Knossos server, into the same graph the pane reads. The pane looks for the
graph again at the end of every turn until it finds one. When the root is
refused, the allow-root offer below is that one thing instead.

When the project's root is not allowed, the pane says so under the header and
offers `a: allow root`. That only asks: the pane shows which root it would
allow and which roots file it would add it to, with `y: allow` and
`n: cancel`. Only `y` runs `knossos allow-root <root> --execute`, against the
roots file the installation names, and then scans the edits the refusal held
back. A grant that does not land says why and can be asked again.

A count that hit a search limit reads `50+`, never `50`. When the walk that
ranks hubs and hotspots stops at its limit (five seconds on a cold, large
graph), the lists say `partial`.

Every time the pane opens, by `/knossos`, `[ details ]` or on start, it
reloads the dashboard. The header gives the snapshot's age, which keeps
counting while the pane is open. While the live watcher keeps a fresh graph
current, the header says `● live` instead (`● live · watched by another
session` when another session's watcher leads), and `● scanning… · fresh · 4s`
while the watcher scans. When a reload fails, the pane keeps the
figures it had and the header says `refresh failed · 2m`.

When the snapshot is stale or files drifted since it, the header offers
`r: rescan`. It runs an incremental scan (`knossos rescan`) under the same
rules as the turn's scan, shows `scanning…` meanwhile with the state and age
of the figures still on show (`scanning… · stale · 2h`), and reloads the pane
when it is done. A rescan that does not land says why in the header and
leaves the figures as they were.

`openPaneOnStart` opens it when a session starts. A pane opened that way,
without you asking, only takes its place at 144 terminal columns or more; on
a narrower terminal it waits until there is room. One you open yourself
seats at any width.

## The live watcher

Once a session has the dashboard of an allowed, scanned project, the mod
starts `knossos watch <project> --shared` through the wrapper and keeps it for
the session's life. The watcher polls the project and rescans what changed,
whoever changed it: Claude's edits, your editor, a checkout. Each scan it
finishes reloads the dashboard (one load at a time), so the pane and its
header follow the code as it changes.

It watches only what a turn's brief may scan: an existing project inside an
allowed root. It never creates a database or a project; asked about anything
else it says `refused`, and the mod does not ask again until you allow a root
from the pane.

**One watcher per project.** Sessions that share a data directory share one
watcher per project. The first to start leads; it holds an advisory lock on
`watch/<key>.lock` beside the database, with `watch/<key>.json` saying who
holds it (its PID, the snapshot it last saw, whether it is scanning) and when
it last said so (a heartbeat every 15 seconds). Any other session follows: it
scans nothing, reads the project's active snapshot once a poll (one database
row) and reloads its pane when another session's scan moves it. The kernel
releases the lock when the leader's process ends, however it ends, so a
crashed leader never blocks the project: the next follower to look takes the
lead over.

**Stopping.** The watcher ends with the session (`session.end`) and with a
reload of the mod, and Claude Code ends a module's children when it unloads
it. The watcher also stops itself once the process that started it is gone,
so a watcher outliving its session never keeps polling. A watcher that ends
on its own after coming up is started again after 30 seconds, at most three
times; one that never says a word (as with a container installation, which
offers no watcher) is not started again.

**The turn's brief.** The turn-end brief still runs: it computes what the
turn did, its notes and its policy verdict. With a watcher running it waits
for the watcher to take the turn's last edits in, then passes `--reuse-scan`,
so it reads them from the graph instead of scanning again. It also passes
`--since=<snapshot>`, the snapshot the graph was at before the turn's first
edit. Every scan the watcher, the pane's rescan or a turn's brief runs is
recorded in the `scan_ledger` table: the snapshot it started from and the one
it made, each changed file's content hash before it and, for up to 20 changed
files, the policy violations each held before it. Read in order from the
turn's snapshot, the first entry that changed a file says what that file was
before the turn, so the brief's changed, added and deleted files and its
before-and-after policy check stay the turn's own, whoever scanned the edits.
When a scan since the turn began was not recorded (a `knossos scan` from a
shell, say), the brief still names the files but leaves the policy
unevaluated rather than guess.

**What it costs.** Between scans the watcher stats the files it last saw and
their directories instead of hashing every file, and fingerprints the whole
tree only when a stat moved, a file was written within the last second, or a
minute has passed. Each scan runs in a process of its own, so the memory a
scan peaks at (about 200 MB on this repository) goes back when it ends.
Measured on this repository (about 660 files) with an up-to-date graph:

| Poll interval     | Idle CPU (one core) | Memory |
| ----------------- | ------------------- | ------ |
| 500 ms            | 0.95%               | 41 MB  |
| 1000 ms (default) | 0.48%               | 41 MB  |
| 2000 ms           | 0.33%               | 40 MB  |
| follower, 1000 ms | 0.03%               | 38 MB  |

After a scan the leader holds about 47 MB. The default of one second puts a
change on the pane about a second and a half after it is saved, at half a
percent of one core.

## Settings

| Field             | Default | What it does                                                       |
| ----------------- | ------- | ------------------------------------------------------------------ |
| `enabled`         | `true`  | Turns the band, notes and pane on or off.                          |
| `fanInThreshold`  | `20`    | Dependent files at which a Read or edit gets a note (1 to 100000). |
| `agentNotes`      | `true`  | Gives the model its notes (Read, edit, turn end); off, none.       |
| `enforcePolicies` | `true`  | Tells the model about violations a turn introduced.                |
| `openPaneOnStart` | `false` | Opens the pane when a session starts, on a wide enough terminal.   |
| `watch`           | `true`  | Runs the live watcher for an allowed, scanned project.             |
| `watchPollMs`     | `1000`  | How often the watcher looks for changes (250 to 60000 ms).         |

They appear in Claude Code's config menu under the plugin's name.

## What it writes

After a turn that edited files, the mod runs `knossos turn-brief`, which
runs an incremental scan into the project's existing database. The pane's
rescan runs `knossos rescan`, the same incremental scan without the brief.
The live watcher runs incremental scans as files change, each a `knossos scan`
process of its own. Those are the only writes to the graph, and each of them
records what it changed in the `scan_ledger` table (migration 019, applied the
first time an updated knossos opens the database; the newest 200 entries per
project are kept). The watcher also writes its lock and state files under
`watch/` beside the database. They happen only for a project that
is already scanned and inside an allowed root, never inside a hook dispatch,
and never two at a time within a session. The pane's allow-root action writes
the roots file, and only after you confirmed it. `knossos dashboard` and
`knossos component-detail` and `knossos file-detail` only read the graph, though like the scans they
bring a database with an older schema up to date before they read it. One
small exception: the dashboard writes trend cache rows. It keeps the figures
it computed for a retained snapshot in the `snapshot_metrics` table
(migration 018, applied the first time an updated knossos opens the
database), since an archived snapshot never
changes, so the next dashboard reads them instead of decoding the archive
again. The cache belongs to the trend query, not to the mod: every caller of
it writes those rows, the MCP tool `architecture_trends` and the CLI
`knossos architecture-trends` too. A row answers only for the archive and the code it came from, goes
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
- The project is not inside an allowed root: the band names the root
  instead of figures, and `[ copy ]` copies the exact command, naming
  the roots file the brief read and the root to allow (the ancestor project
  root when that is what would be scanned):
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
whole graph), 15 for `component-detail`, `file-detail` and `allow-root`.
`watch` is not bounded: the wrapper replaces itself with the watcher, so
stopping the process the session started stops the watcher. It runs
`allow-root` only with a roots file the installation (or the environment)
names, never one it would guess from the working directory.

Paths are compared after resolving symbolic links, so a checkout you reach
through a linked directory is still recognised as the project.
