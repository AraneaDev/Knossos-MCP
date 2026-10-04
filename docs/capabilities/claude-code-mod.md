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

**After a commit.** When a Bash call in any loop runs `git commit` (with
options before it, as in `git -C dir commit`, or anywhere in a chain), the
call's result carries one note of what the commit takes along, as far as the
graph knows: the boundary-policy violations the session's turns introduced,
the changed files no test reaches, and the cycles new since the session
began (against the cycles the graph held when the session began):

```text
knossos: this commit carries 1 changed file no test reaches (src/Kernel.php); 1 dependency cycle new since the session began (Router → Kernel). Check them before you push.
```

Nothing new, no note. The same note is said once per loop, counts against
the three notes a turn, and `agentNotes` turns it off.

**On request.** The mod registers a tool the model calls itself,
`knossos_context` (listed as `mcp__knossos__knossos_context`), with one
argument, `path` (relative to the project or absolute). One call answers in
a few lines, at most 2,000 characters: the file's boundary, language, lines
and components; how many files depend on it, in which boundaries, and the
closest five; the declared rules that bind it (or that a cap leaves that
open); the tests that reach it, nearest first; its three latest commits;
and whether this session changed it. It reads `knossos file-context <file>`
through the wrapper when it is called (never in a drawing), and answers a
path outside the project, or a project never scanned, with a sentence that
says so. It is registered at the session's start, with the same retries as
`/knossos`, whatever `agentNotes` says: the model asks for it.

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
end of each turn, and starts up meanwhile.

The pane uses all the room it has, in one of three layouts by the columns of
its body:

| Width                | Layout                                                                                                 |
| -------------------- | ------------------------------------------------------------------------------------------------------ |
| narrow (below 80)    | one column; each section a light card: a top rule with its title in it, no sides, so no column is lost |
| medium (80 to 130)   | one column of framed cards; tables add out, cross and the file each component is declared in           |
| wide (more than 130) | a grid of framed cards: rows of two columns at fixed ratios, the cards of a row drawn equally tall     |

A framed card is a thin rounded border in the theme's faintest colour, its
title in bold (and the section's note, such as `this session` or `all in
core`) set into the top edge, one cell of padding inside. A small dim glyph
before the title says what kind of card it is, the same on every tab: `±`
this session and changes, `▥` the composition and the per-boundary table,
`◎` the dependency concentration and the hubs, `∿` health over time, `⇄`
the cross-boundary flows, the marked heat map cell and a detail's
dependencies, `≡` the files most depended on, `▦` the boundary map, `◈` the
marked boundary, `↻` cycles, `!` the Issues cards, `✓` the tests that reach
the changes, `◇` a detail, `∴` the churn scatter, `◎` a blast radius, `⇢` a
route, `✎` the notes. A card with nothing to list is one line: its
title and its note (or `none`) set into a rule, at every width. Cards stand
one blank row apart; labels are dim, numbers right-aligned.

Wide, each tab sets its cards on a fixed grid: rows of two columns two cells
apart, at a fixed ratio per tab, the cards of a row drawn equally tall (a
shorter one is stretched inside its frame), so no column ends half way down.
Overview puts the stat tiles across the top, this session across the pane,
then the composition beside the dependency concentration and health over
time beside the cross-boundary flows, in two equal columns; Hubs sets the
components (three fifths) beside the files most depended on; Boundaries the
map beside the per-boundary table, then the marked boundary beside its cell;
Changes the files beside the tests; Issues the cards with nothing to list side
by side as one-line rules first, then the lists in pairs. Cycles and a
detail stand in one column at every width, since a diagram wants the room.

Past 130 columns, Hubs, Changes, Issues, Cycles, Branch and Churn are master and
detail: the tab on the left (55 percent, as a medium pane lays it out) and,
beside it, the detail of the marked row, a component's neighbourhood or a
file's dependents (with its change since the session began, drawn as text
lines in the added and removed colours, since the engine's diff element
spans the pane). Moving the marker looks the new row up after a short pause,
so a run of `j` presses is one lookup; a press in the panel opens its row as
the detail itself. A row with nothing to show (a boundary, a chart's bar)
shows the tab alone. Boundaries keeps its own grid, whose lower row already
spells out the marked boundary and its cell. Narrower panes open a row with
`o`, as before.

The cards stretch to the pane's foot: the last framed card of a column, or
the rows of framed cards side by side in a grid, take the rows left over,
so nothing ends above blank space. A card with nothing to list is one line
with no frame to stretch: it stands on a line of its own (just before the
cards it was beside) and never takes a share of the spare rows, so no gap
opens under it; when every list of a grid is empty, the card across the top
takes the height. On a tall Branch tab the spare rows go to the churn
hotspots (see below), each a link to its file. On a tall Overview the rows left after the charts go to
two secondary lists, the complexity hotspots and the files most depended
on, each a link to its file; on a shorter pane they are left out, and the
marker never walks them, so what it walks does not depend on the height.

Paths are drawn in two tones, the directory dim and the file name in the
text colour. A path too long for its column loses the middle of its
directory first, by whole folders (`src/…/Query/`, then `…/Query/`), then
characters; the file name is cut only when it alone is wider than the
column.

When a new snapshot lands, the rows whose figures it changed (a hub's
degrees, a file's dependents, a boundary's members, a stat tile's figure, a
changed file a newer scan took in again) are drawn on a cool ground for
about three seconds, then go back. The marked row keeps its own ground.

`f` opens the finder over any tab: a field and the components and files
whose name holds the typed letters in order (`dsvc` finds
`DashboardService`), a type before its members, the closest first, at most
twenty. The search (`knossos graph-search`) runs after a short pause in the
typing, never in a drawing; until its answer for what is typed lands the
card says `searching…` and keeps the last matches. Enter opens the first
match (or the marked one), a click any; a component opens as its detail, a
file as the file's. `x` or an empty Enter closes it, and the tab under it
comes back as it was.

The Branch tab (`7`) compares the checked-out branch with the snapshot taken
where it left its default branch. `knossos branch-diff` finds the merge base
through git, locally (the default branch is the one `origin/HEAD` names, else
`main` or `master`), and matches it to a retained snapshot by the commit each
scan was taken at: one at the merge base, else the nearest one before it
(the tab says how many commits earlier). Then it lists what is new since:
dependencies crossing from one boundary into another, cycles whose members
did not already form one, hubs at least ten components depend on that more
depend on now, newly unreferenced components, and policy violations whose
dependency is new. Each list counts all and names the first eight, and each
row opens its component. When no snapshot near the merge base is retained,
the tab says so in the warning colour: with a snapshot taken after it, it
compares against that one and says how many of the branch's commits it
already holds, so what they changed is not shown; with none, it compares
nothing. On the default branch itself, or without git, it says that too.
The comparison is read when the tab opens and again for each new snapshot,
never while another tab is open. The tab label counts what it found.

The Churn tab (`8`) ranks the files changed most often in the last thirty
days that much of the project depends on: commits in the window times the
files that depend on the file, counted as the fan-in map counts them.
`knossos churn` reads one `git log` (at most 500 commits, three seconds)
and the graph's dependents for the files it names; the pane keeps the
answer for the commit the checkout is at, so a new commit (not a new scan)
reads it again. A scatter draws every listed file, commits across and
dependents up on a log scale from the fewest listed to the most, the nine
highest scores as their rank digits and the marked file as the accent dot;
under it the ranked list, each file with its commits, its dependents and a
bar for its score, opening its detail. Without git the tab says so.

A component's detail draws its blast radius: what depends on it one hop
away, two hops away, and further (`knossos blast-radius`, at most six hops,
1,500 components and two seconds), as concentric frames with the furthest
ring outermost and the component in the middle. Each frame's top edge
carries its hop, how many components it holds and whether a test reaches
them all (`✓ all tested`) or how many no test reaches (`▲ 2 untested`, in
the warning colour). Inside it, before the next ring in, its components
(the untested first, each after a `▲`; a boundary named only where it is
not the component's own) and the test files that reach the ring, nearest
first. A test is no member of a ring; a component counts as tested when a
test depends on it, directly or through others the search found. Every
named component is a row the marker walks after the detail's own lists,
and opens as its detail. Below 44 columns the rings are plain lists.

`p` on a component's detail starts a route: the finder opens over the
pane, offering components only, and the one you open is where the route
ends. `knossos path-between` finds the routes between the two (at most
six hops and five routes, the strongest first); when only the other way
has one, the pane draws that and says so in the warning colour. The route
is drawn as boxes down the pane, each framed in its boundary's colour with
the boundary beside it, each hop an arrow labelled with its kind and the
file and line it is written at; every route found is listed above it, told
from the last component they all share, and a press draws another. Each
box opens its component; `b` goes back to the detail the route began on.

`m` on a component's detail adds a note to it (`knossos annotate`, the
`note` kind of the `annotate_component` tool). A field opens in the notes
card, holding the note there is; Enter has knossos check it (a preview,
nothing written) and the card asks `Record this note on StableId?`, saying
what it replaces. Only `y` (or the `record` button) writes it; `n` drops
it. A refusal (an ambiguous name, a note too long) is said in the card.
The detail is read again once the note is recorded, so it shows.

When a scan brings a dependency cycle or a policy violation the graph did
not hold before, the mod says so in a toast, once per cycle or violation a
session: `knossos: a new dependency cycle of 3: Router → Handler → Store →
Router`. Only between two snapshots of the same project, never for the
first graph a session sees; a scan that brings more than three says the
rest as one count. The `notifications` setting turns these off.

Nothing the pane draws can wrap or push its card wider, on a terminal that
counts cells as the layout does or not: every row is a box as wide as the
pane that clips what passes its edge, every text in it cuts at that edge
with an ellipsis, and a pressable name draws the name as the layout cut it,
so it takes exactly the cells it was given.

Claude Code refuses a drawing past its bounds (20,000 elements, 32 deep,
100,000 characters serialized) and draws its own, empty pane instead. The
pane keeps far inside them: each row's plain text is one text element with
its styled pieces nested in it, a list grows to at most 28 rows however tall
the pane (then `n more ↓`, scrolling to the marker), and at most eight hover
cards hang off the rows nearest the marker. Should a drawing still pass
70,000 characters, the pane drops its hover cards and every link but the
marked row's, then gives its lists fewer rows until it fits.

Lists size themselves to the rows the pane's body has: every list starts at
a few rows, then the rows left after the rest of the tab are shared out
between the lists in turn, up to ten each; then the charts take what they
can use (the heat map's cells grow from one row to two or three); then the
lists take the rest, up to 28 rows each. A chart in one column of
the wide grid stops growing once it would leave the other column more than
three rows shorter. The dashboard sends up to fifty hubs, hotspots and dead-code
candidates and thirty complexity hotspots and files over budget, enough for a tall pane. A list cut short says `n more ↓` (and `n above ↑`
once the marker has moved down it), and moving the marker scrolls it. A pane
too short for even those few rows scrolls as a whole.

Tables are packed to the left: names take what the longest one needs, the
kind and the boundary follow the name, then the bar, the numbers and, from
the medium layout on, the file. A bar takes the width left after the names
and numbers, but never more than a quarter of the table nor more than 16, 24
or 30 cells by layout: it is a gauge beside a name, not the widest thing on
the row. Width it may not take goes to more columns: a wide table of
components adds how many files depend on each (`files`) and, where it fits,
its kind; the file column (`where`) may cut the few longest names (the
longest tenth) rather than be lost. As the pane narrows, the kind goes first,
then the file column, bars shorten, then long names are cut with `…`, then the
boundary column goes, then the bars. The numbers always stay, right-aligned.
In the narrow layout a table says its figures compactly (`31.7k`, `1.2M`); the
stat tiles, the cards' notes and a detail keep them whole (`31,740`).

The pane draws in your Claude Code theme's own colours (its theme keys), so it
follows a dark, light, daltonized or ANSI theme. Colour carries meaning only:
each boundary keeps one of seven subagent colours wherever it appears
(declared boundaries first, largest first), and only on a mark: a boundary
is a chip, its colour on a small `■` swatch (and on its bar, its frame in a
diagram) and its name in a text tone. No word is ever drawn in a boundary's
colour, and status colours (`success`, `warning`, `error`) mean a status
only, always beside a glyph or a word that says it. Red is not one of them: on this
pane red means an error or a policy violation, never a boundary. An inferred
boundary that got no colour of its own is drawn neutral rather than in a
colour it would share by chance. Two boundaries whose short names differ only
in case (an inferred `namespace:Knossos` beside a `composer:…/knossos`) keep
their source in the label, `namespace:Knossos` and `composer:knossos`, so they
never read as one. The status is a pill on the status colour: `success` for a
fresh or live snapshot, `warning` for a stale one or a scan under way and
`error` when a refresh or rescan failed, its words in the theme's text for a
fill. The open tab stands on the theme's selection colour, the marked row of
a list on a faint tint (Claude Code's own prompt background) from one edge of
its card to the other, its `›` kept for a terminal that draws no
backgrounds, and the accent marks the `›` itself. Everything else is neutral: names, headings and figures in the theme's text colour,
units and notes in its secondary grey (a note's figures stay at full
contrast: `3 files → 594 dependents`), bar tracks and empty cells fainter
still. Bars are thin rules on a dotted track.

```text
Knossos-MCP                          ● stale 11h   r: rescan
 Overview   2   3   4   5   6³

9,186 components ▲12   7 boundaries   2 cycles ±0
165 max degree ±0   3 dead code   0 diagnostics ±0
0 policy   24 drifted

── ± This session ──────────────────────────────────────────
   scans ●●●●●●  6 this session
   8 files · 841 dependents · 4 tests reach them
›  Changes  every file, its dependents and its tests
   t: copy test command

── ◎ Dependency concentration ─────────── 3,642 components ──
   in-degree                                     components
   0        ━━━━━━━━━━━━━━━━········                    917
   1–5      ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━     2,130
   6–20     ━━━━━━━·································    386
 ◆ 101+     ╸·······································     26 hubs
```

That is the narrow layout (60 columns): the header is the project's name and
the status pill, then the tabs, the open one by its name on the selection
colour and the others by their digit; the stat tiles collapse to a line of
their figures. From 80 columns the header names where the checkout stands
and the project's languages, and the tiles
are a framed band across the Overview, a faint rule between them, spread
evenly; they wrap onto as few lines as hold them when they do not fit on one.
A figure is in the text colour unless it deviates: cycles and diagnostics
above zero in `warning`, policy violations in `error`, drifted files in the
accent. Beside each figure, how it moved since the snapshot retained before
this one (`▲12`, `▼1`, `±0`): the glyph says which way, and only a figure
that is a status (cycles, diagnostics) colours it, a rise in
`warning` and a fall in `success`. Dead code counts the candidates the Issues
tab lists, and has no delta or sparkline: the retained snapshots carry only
the quality gate's wider count of components nothing references, which the
health card draws as its own row, `gate unreferenced`. A tile draws its trend as a sparkline in
the accent when it has room and five snapshots or more move. The drifted
tile's label is the button that lists the drifted files. Each card is framed,
and its tables add columns:

```text
╭──────────────────────────────────────────────────────────────────────────────────────────────────╮
│ 9,026       │ 7           │ 2 ▁▅▁█▁ │ 165         │ 0          │ 12       │ 0      │ 0           │
│ components  │ boundaries  │ cycles  │ max degree  │ dead code  │ drifted  │ policy │ diagnostics │
╰──────────────────────────────────────────────────────────────────────────────────────────────────╯

╭─ ◎ Hubs and hotspots · sorted by in ───────────────────── partial ─╮
│   name                  boundary        in out cross where          │
│   StableId              ■ core   ━━━━━ 516   0     0 StableId.php:19 │
╰─────────────────────────────────────────────────────────────────────╯
```

```text

  Knossos-MCP  feat/claude-code-mod · 610c773  PHP  JS  TS  RS     ● stale 16s   r: rescan

   Overview    Hubs    Boundaries    Cycles    Issues¹    Changes⁸

────────────────────────────────────────────────────────────────────────────────────────────
```

The header has room around it: a blank row above the name, another between
the name and the tabs, both rows padded two cells in from the edges (one when
narrow), then a blank row and a rule in the faint ink before the first card.
Narrow, the row above the name goes; on a pane 24 rows tall or shorter every
blank row goes, never a line that says something. The first row names the project in bold, where its
checkout stands (the branch and the short commit, dim; the commit alone on a
detached head, nothing outside git), the languages as small chips, and
against the right edge the status pill, why it is so when the pill does not
say (a rescan's reason, a stuck leader) and `r: rescan` when a rescan would
change anything. Where the checkout stands is read through the wrapper's
`knossos session-head` (which names the branch beside the commit) at
start-up, sharing the session's own baseline read, and again after each of
the main loop's turns, never while the pane draws. As the pane narrows the
chips go first, then the commit, the branch and the reason. In a detail the
first row is the way back: `Knossos-MCP › Hubs › StableId`. The second row is
the tab bar: each tab by its name, with the Issues and Changes counts as
superscript badges (`Issues³`), the open one on the selection colour; where
the names do not fit, and always when narrow, the others by their digit. The
tabs stand two cells apart while they fit. The digits that switch tabs are
said in the `h` help, not on the bar.

When the dashboard names the drifted files, the drifted tile's label is a
button that lists them, and `d` does the same on every tab: it lists the
drifted files right there, under the tabs, each marked as Changes marks a file (`+` added, `−`
deleted) with its own boundary, the first twenty by path and how many more.
While they are listed the marker walks them: `o` opens a file's detail as the
snapshot holds it, `e` the file itself. `d` again, or a tab, hides them.

```text
Drifted since the snapshot                                 3
›  hooks/lib/changes.ts                            hooks
 + hooks/lib/files.ts
   src/Query/DashboardService.php                  core
```

- **Overview:** measures; the lists are on Hubs. The stat tiles
  (components, boundaries, cycles, maximum degree, dead-code candidates,
  diagnostic errors and warnings, policy violations, drifted files), each
  with its delta and, where five snapshots or more move, a sparkline. Then
  the charts, each in the one form that fits what it says:

    **This session:** the session's scans as a timeline, how many files it
    touched, their dependents and the tests that reach them (a warning when
    none does), and the way to Changes, which the marker rests on first;
    `t` copies the tests' command. Before anything changed, one line.

    **Composition:** what the project is made of, as a 100%-stacked bar per
    dimension: components by boundary (each in its colour, the five largest
    named and the rest as `other`), files by language and components by kind
    (one neutral ink told apart by texture, `█ ▓ ▒` and `░` for the rest, so
    no colour means two things), the whole right-aligned beside each bar and
    every part's share in a legend under it.

    **Dependency concentration:** how many components have how many
    dependents, in five buckets of in-degree (0, 1–5, 6–20, 21–100, 101+),
    a bar each in the accent, the counts right-aligned and the top bucket
    marked `◆` as the hubs. It counts every component the hub ranking could
    hold (tests and external code left out). `o` or a press on a bucket opens
    Hubs narrowed to it.

    **Health over time:** one row per figure (cycles, `gate unreferenced`,
    maximum degree, diagnostics), each a small chart on its own
    scale, all on one time axis (the same snapshots, column for column, oldest
    left), the newest value right-aligned and its range beside it (`no
change` for a flat one, drawn faint). Only with five snapshots or more.
    `gate unreferenced` is the quality gate's count of components nothing
    references, and a line under the axis says so: it is wider than the
    dead-code tile, which counts the candidates the Issues tab lists, so the
    two numbers differ by design.

    **Cross-boundary flows:** the strongest dependencies from one boundary
    to another, `■ tests → ■ core` and a bar with the count; one a declared
    policy forbids has its bar in `error` and says `× forbidden`. `o` or a
    press on one opens its cell on Boundaries.

    The marker walks the way to Changes, the buckets, then the flows; `c`
    copies and `q` asks about the one it rests on.

- **Hubs:** hubs and hotspots as one list, one row per component, with its
  boundary and its in, out and cross-boundary degree, and beside them (below
  them when narrower) the files most depended on, each with how many files
  depend on it; the marker walks the components, then the files, and `o`
  opens a file's detail. The filter narrows both. Opened from an Overview
  bucket, the list holds only the components in that range of in-degree
  (`in-degree 101+ · 26 listed of 26`; the list holds the fifty most depended
  on, the bucket may count more) and the files step aside; `x` clears it, as
  does another tab.
  `◆` marks a hotspot
  that is not also a hub. Methods read `Class::method`. `n` opens a filter
  field: the list narrows as you type to the components whose name holds the
  text, Enter keeps the filter and `x` clears it (`f` is the finder, on every tab). `s` sorts by in, out or
  cross-boundary degree in turn; the bar follows the sort, and the title says
  it (`Hubs and hotspots · sorted by in`). Resting the pointer on a name
  (where the surface has a pointer: not on mobile) shows a card over the
  rows below it, without opening anything: its name and boundary, how many
  files depend on it (and a component's in- and out-degree), and the three
  files that depend on it most (the dashboard names them for every listed
  component and the first fifty files). The surface reveals it alone;
  nothing reaches the mod. The sorted column's numbers are in
  the text colour and the other two are dimmed. When every listed row sits in
  one boundary, the boundary column goes and the header says it once
  (`all in core`); otherwise every row draws its chip the same way, the
  swatch in the boundary's colour and the name dim, whatever the row above
  holds. `e` opens a hub's file at its declaration.
- **Cycles:** the marked cycle drawn, and under it every cycle (the ten
  largest), each as a line naming its size, the boundary most of its members
  are in and how many others it reaches. The drawing is a serpentine of boxes,
  one member a box: the first row left to right, the next right to left
  under it, as many to a row as fit, an arrow from each member to the next,
  and a return edge from the last along a lane under the boxes and up the
  left margin into the first, labelled `back to the start` where it has
  room. A cycle that spans boundaries frames each box in its boundary's
  colour (the names stay in the text tone), with a legend of chips, and marks each hop that crosses from one boundary
  into another with `╫` (`╪` on a vertical hop), in the warning colour; one
  that stays in one boundary is drawn plain. The marked member's box is
  framed in the accent and tinted, the hops into and out of it in the accent
  too. The marker walks members, not cycles: `j` and `k` move from box to box
  in the order the eye reads them, on to the next cycle's first member past
  a cycle's last; a press on a cycle's line marks its first member; `o` or a
  press on a box opens that member, `c` copies its name and `q` asks Claude
  how to break the cycle it is in. A long cycle on a narrow pane (more than
  six members; ten on a medium one, sixteen wide) folds its middle into one
  `… N more …` box the marker can rest on: `o` there, or a press on it,
  unfolds the cycle and marks the first member it hid. Members knossos did
  not list (past forty) close the loop as one `+N not listed` box. Below 50
  columns the drawing gives way to the chain as text
  (`↻ ■ a → ■ b → c → ↻`, each member after a swatch in its boundary's
  colour) and a member a row.

    ```text
       ╭───────────────────────╮       ╭───────────────────────╮
    ╭─►│ Index::_add_instances ├──────►│ Index::read_bounded   │
    │  ╰───────────────────────╯       ╰───────────┬───────────╯
    │                                              ╪
    │                                              ▼
    │                                  ╭───────────────────────╮
    │                                  │ Index::safe_file      │
    │                                  ╰───────────┬───────────╯
    ╰───────────── back to the start ────────────╫─╯
    ```

- **Issues:** the declared policy violations (each with the offending file
  and line), the scan's errors and warnings, the first fifty dead-code
  candidates (`◇` marks one only tests reach), the complexity hotspots (the
  files with the most lines times dependent files, a bar of that product
  beside the two figures) and the files over the project's maintainability
  budget (a PHP function longer than `max_php_function_lines` in its
  `maintainability-budgets.json`: how many functions are over and the
  longest; `✓ 0` when none is, and `no maintainability-budgets.json` without
  one), each list as long as the height allows. The hotspots and the files
  over budget are rows the marker walks: `o` opens the file's detail, `e`
  the file (at its longest function). A card with nothing to list is one
  line (`── ! Policy violations ──── ✓ 0 ──`).
  The tab label carries the count of violations, errors and warnings, as in
  `Issues³`.
- **Changes:** everything that changed in the project since this session
  began, whoever changed it: the session's own turns and subagents, your
  editor, a checkout. While the live watcher runs, the list comes from the
  scan ledger (`knossos session-changes --since=<the session's first
snapshot>`, read again after each scan the watcher sees, one read at a
  time), and each file says where its change came from: `this session` when
  the session's own Edit, Write or NotebookEdit calls (main loop or subagent)
  wrote it, or when a scan that changed it took in changes made while one of
  the session's tool calls ran; `outside` otherwise. The header then reads
  `since it began`. Above the files a thin row draws the session's scans, a
  dot each, oldest first: in the accent for one that took in the session's
  own changes, dim for one that took in changes made outside it, then how
  many of each (`session-changes` lists the scans since the session began,
  the newest 60). A row too short for all of them keeps the newest behind a
  `…`. A
  tests column says how many test files reach each changed file (counted one
  file at a time, the 60 most depended on, within three seconds; `20+` at its
  cap, blank where it is not known): `▲ none` in the warning colour for a
  file no test reaches, and the count of those files stands above the table
  (`▲ 2 files no test reaches`) and on the Overview's session card. With
  no watcher (switched off, or a container install) the tab falls back to
  what the session's turn briefs reported, added up, and says so. When the
  ledger cannot reach back to the session's start (a ledger an older knossos
  pruned, or a scan it never recorded), the tab says so with the date the
  session began, and lists every change since the oldest point the ledger
  still answers for, with that date in the header (`since 2026-10-04 09:14`);
  only when it answers for none does it fall back to the turn briefs. A
  session that began among the scans the ledger keeps merged (see below)
  lists what changed after it and says its start is approximate.
  Either way it lists every file (most dependents first, `+` added, `−` deleted)
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
  copies it, as on Overview's session card; `c` copies the marked file's path, as on every
  list. `o` opens the marked file's detail and `e` the file in your editor.
  The tab label counts the files.
  The list lives for the session and keeps at most 500 files and 500 tests
  (`partial` past that).

    Whose a change was is told by when it was made, since a shell command
    (`sed -i`, a heredoc, `git mv`, a formatter's `--write`) edits files as
    surely as the edit tools do. The mod keeps when each of the session's tool
    calls ran, in the main loop and in every subagent alike (their calls reach
    the mod's hooks with the subagent's id), by any tool but the ones that only
    wait: Agent, AskUserQuestion, TaskOutput, TaskStop, Monitor, SendMessage
    and the plan-mode tools. A call to one of those runs while the session
    waits on someone, so a change made meanwhile is yours. A scan is the
    session's when a call ran at some moment from the watcher's poll, its
    debounce and 1.5 seconds before the scan began, up to when it landed;
    when the scan before ended inside that window it reaches back to where
    that one began, since a change made while a scan runs is noticed after it.
    While following another session's watcher, the leader's scans are timed by
    its lock, which the follower reports as `leader_scanning`; an unannounced
    move of the graph (another writer's scan) is given ten seconds of scan
    before the poll that saw it. A turn brief that scanned itself took in the
    turn's own edits, so its scan is the session's too. `session-changes`
    names, for each file, the snapshots of the scans that changed it (the
    newest 20), which is what the label is read from. A change you make in
    your editor while one of the session's calls runs is counted as the
    session's; a command started in the background is counted only while the
    call that started it runs.

- **Boundaries:** a heat map of how much each boundary depends on each
  other one. Rows are where a dependency starts, columns where it lands; the
  axes are lettered (`A`, `B`, ...) and the row labels spell the letters out.
  The map is one hue, the theme's accent, in four steps on a log scale of
  the busiest cell, and a faint `·` where nothing crosses; only a swatch in
  the margin of each row carries its boundary's colour, the letters and
  names stay in the text tones. The `error` colour marks a pair a
  declared policy forbids: `×` while nothing crosses it, an error tile once
  something does. On the terminal the map is one grid of solid tiles in the
  theme's colours (it follows a theme picked in `/config`, daltonized and
  ANSI ones included, the ANSI colours as xterm paints them); elsewhere it is
  drawn as shades from `░` to `█`. Row labels are written out in full when
  they fit; with rows to spare each cell grows to two or three rows tall and
  twice as many cells wide, so it stays square. Below it each boundary is listed with
  its components and its dependencies in from and out to other boundaries.
  A component counts in one boundary, the one the pane labels it with, and
  only boundaries that label something get a row. `j` and `k` mark a
  boundary, and the pane spells it out under the table: the boundaries it
  depends on and the ones that depend on it, each with its count (red where a
  policy forbids the pair), and the ones a policy forbids it to use. There is
  nothing to open; `c` copies the boundary's name and `q` asks Claude what
  its dependencies are for.

    The marker also stands on one cell of the map: the marked boundary's row,
    in the column of what it depends on most. The cell is a solid tile in the
    theme's text colour, its row label and column letter on the marked row's
    tint. `l` moves it to the next boundary the marked one depends on (most
    first, coming round again: `h` already lists the keys, so the cell steps
    one way), as does a press on one of those boundaries in the marked boundary's
    card; marking another boundary starts on its own top dependency. Under the
    marked boundary the cell is drawn: a box for each boundary, the arrow
    between them carrying how many dependencies run that way (in the error
    colour and marked `✕`, with `forbidden` in the card's note, where a policy
    forbids the pair), and under it the five component pairs that make up
    most of them in columns: the sources padded to one width, an arrow, the
    targets, their edges right-aligned. They are read by
    `knossos boundary-couplings --from=<boundary> --to=<boundary>` (the same
    bounded walk as the map, once per cell and snapshot) on a timer, never while
    the pane draws; the card says it is reading meanwhile.

```text
G tooling
   depends on   core 18  hooks 4
   used by      nothing outside itself

tests → php-worker                                       11 deps
╭───────╮                           ╭────────────╮
│ tests ├───────── 11 deps ────────►│ php-worker │
╰───────╯                           ╰────────────╯

   PhpScannerReceiverFactsTest::scan ──► PhpScanner                1
   PhpScannerTest::testAScannerRead… ──► UnreadableFileException   1
```

```text
Boundaries                                  10 · 29,854 deps
   from→to             A  B  C  D  E  F  G  H  I  J
   A tests             ██ ██ ·  ·  ·  ▒▒ ·  ·  ·  ·
   B core              ×  ██ ×  ×  ×  ×  ×  ·  ·  ·
   C typescript-worker ·  ·  ▓▓ ·  ·  ·  ·  ·  ·  ·
```

```text
Changes this session                                        since it began
   3 files → 29 dependents reaching core hooks
   file                                    deps from
›  src/Query/TurnBriefService.php core ━━━━━━  21 this session
   hooks/register.tsx             hooks ━╸····  6 outside
 + src/Query/SessionChangesService.php core  ·  2 this session

Tests that reach these changes · 3              hops
   hooks/lib/changes.spec.ts                       1
   tests/phpunit/Query/TurnBriefServiceTest.php    1
   tests/phpunit/Store/StoreTest.php               2

   $ vendor/bin/phpunit --filter
     '(TurnBriefServiceTest|StoreTest)' && npx vitest run
     hooks/lib/changes.spec.ts
```

Every action is a button, so a click works as well as its key. The footer
is a bar: one full-width row (more when the keys wrap) on a faint ground
(`composerSidebarBackground`), padded in from the edges, at the bottom of the
pane: blank rows fill the pane above it when what it shows is shorter, and
when it is taller the bar is drawn over the window's last rows wherever the
pane is scrolled to (the body ends in room for it). Once the header is
scrolled out of view, the bar also says the graph's state at its right edge
(`● live · 7s`). The bar groups the keys: moving about (`j`, `k`, and `b` in a detail) dim on the left,
what can be done to the marked row on the right, only the keys that do
something in the view on show. For a moment after an action the footer says
what it did, between the two (`✓ copied StableId`, `✓ opened in editor`,
`✗ no editor · path copied`, in the success or error colour); the mod's age
tick lets it fade after about two seconds. `1` to `8` switch tabs, `f` opens the finder, `p` starts a route and `m` a note on a component's detail, `j` and `k` move the `›` marker (as does moving the focus with
Tab or the arrows onto a row), `o` or Enter opens the marked row, `e` opens
the marked row's file in your editor, `c` copies and `q` asks about it, `t`
copies the test command, `d` lists the drifted files, `l` moves the
Boundaries tab's cell, `b` goes back, `h` lists the keys, one a line. The keys mean the same on every tab. A component
opens from the Hubs, Cycles and Issues tabs and from a detail; the
pane looks it up by its canonical name. A file opens from Hubs, Changes, the
drifted files and a file's detail; an Overview bar opens the tab it counts. Back
on the tab, the marker stands where the detail was opened from. Numbers of four digits or more are grouped
(`10,867`), or compact in a narrow table.
`/knossos inspect <component>` opens the pane on one component directly.

```text
── StalenessProbe ────────────────────────── class · core ──
src/Query/StalenessProbe.php:21

── ⇄ Dependencies · used by 91 · uses 13 ─────────── edges ──
╭──────────────────────╮                                   ╭──────────────────────╮
│ RefreshIfStaleTest:… ├─ 2 ──╮                     ╭─ 1 ─►│ DriftOracle          │
╰──────────────────────╯      │  ╭────────────────╮ │      ╰──────────────────────╯
                              ├─►│ StalenessProbe ├─┤
╭──────────────────────╮      │  ╰────────────────╯ │      ╭──────────────────────╮
│ +90 more             ├──────╯                     ╰─────►│ +12 more             │
╰──────────────────────╯                                   ╰──────────────────────╯
```

The detail heads with the component's name, kind, boundary (as a chip), file and line.
Below it, its neighbourhood drawn: the component in a box in the middle,
what uses it fanning in from the left, each a box joined to a bus that enters
the middle with an arrow, and what it uses fanning out to the right, the bus
branching to each with an arrow; how many relationships run along each edge
is set into it. Each box is framed in its boundary's colour; its name stays
in the text tone. Each side shows
as many neighbours as the pane's height allows, around the marker, and the
rest (those knossos did not list included) as one `+N more` box. Narrower
than the three boxes and two edges need, the users stand above the
component and what it uses below it, on one spine. Below 50 columns the two
sides are listed as tables instead. The marker walks both sides, used by
first; the marked box is framed in the accent and tinted, and every name
there opens in turn.
Annotations recorded on the component follow.

A file's detail answers what depends on it. It heads with the file's name,
its own boundary, its path (a link), language and size. Below, its
neighbourhood drawn as a component's is, each file by its name in a box: the
files that depend on it (the ten most connected, with how many relationships
run from each) fanning in, the files it depends on (`file-detail`'s `uses`,
the same ten and counts the other way round) fanning out, with the boundaries
a change here reaches above them; then the components it declares, the
twelve most used with how many components in other files use each. The
marker walks the dependents, then what it depends on, then the components; a
file opens as its own file detail, a component as a component's detail. An
older knossos that does not say what the file depends on draws its
dependents alone. The counts leave out
the stand-ins for symbols declared outside the project (`sprintf`, a vendor
class), which knossos files under the first file that names them, and the
module node that stands for the file itself; the fan-in figures in the band,
the notes and Changes leave out the same stand-ins.

A file opened from Changes also shows, below the
files that depend on it, how it changed since the session began: `git diff`
against the commit the project was at when the session started (read once,
by `knossos session-head`, before anything else; again after a `/clear`),
so it covers the commits made during the session and the working tree
alike. An added file shows whole as added (an untracked one read from
disk), a deleted one whole as removed, and a file git moved as the rename,
with the other path named. The hunks are drawn with Claude Code's own diff
element, line numbers, markers and colours as its own diffs have them,
under `Changed since the session began` and the lines added and removed.
A hunk longer than 40 lines is folded with how many lines are left out;
past twelve hunks the rest are counted; `session-diff` returns at most
2,000 lines and 200 KB, and a cut diff says so. Lines longer than 200
characters are cut, and control characters other than a tab show as `�`.
The diff is read by `knossos session-diff --rev=<commit> --file=<path>` on
a timer, never while the pane draws, once per file and snapshot (a new scan
reads it again). With no git repository, no commit yet, a commit that is
gone, a binary file or no answer, the detail says which instead of a diff.
A container install answers both commands too: the container reads git as
your own user and group (`docker run --user`), since git refuses a
repository another user owns and the image's user owns none of yours.

**Continued sessions.** Where a session began (the commit, the snapshot its
changes are read since, and when it started) is kept per session id in the
plugin's own store (`$.store`, the newest 50 sessions). A session continued
in a new process (`claude --continue`, `--resume`) or resumed within one reads
its baseline back, so Changes and the diffs still count from where it began;
a session with an id the mod has not seen starts fresh. Which of the changes
the session's own tools made is known only to the process that saw them: in
a new process, earlier changes are labelled `outside`.

```text
Assertions.php                                                tests
tests/phpunit/Support/Assertions.php · PHP · 91 lines

⇄ Dependencies · used by 251 · uses 0                          edges
   reaching tests core
╭─────────────────────────╮
│ GraphReconcilerTest.php ├─ 206 ─╮
╰─────────────────────────╯       │
                                  │
╭─────────────────────────╮       │
│ +250 more               ├───────┤
╰─────────────────────────╯       │
                                  │
                                  ▼
                         ╭────────────────╮
                         │ Assertions.php │
                         ╰────────────────╯

                            uses nothing
```

Escape cannot be caught by a
pane (it hands the keyboard back), so `b` is the way back, and the filter
clears with `x` or an empty Enter.

Every `file:line` the pane shows is a link: the detail's place, the places on
the Issues tab, the Overview's secondary file lists, a file detail's path and the Changes
tab's tests. It is a Markdown `file:` link (`#L<line>` names the
line), so a ctrl- or cmd-click opens it as a link in one of Claude's replies
would. A plain click, and `e` on the marked row (or on what the detail shows), runs `code -g <path>:<line>`, which VS Code
and its forks answer; where no such command answers, the pane copies
`path:line` to your clipboard instead and its footer says so. A terminal
editor from `$EDITOR` cannot be started this way: it needs a terminal of its
own.

Two more keys act on the marked row, or on what the detail shows:

- `c` copies its canonical name (a file's path) to the clipboard of the surface you pressed
  it on, and the footer says so.
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
reloads the dashboard. The header's pill gives the snapshot's state and age
(`● stale 38m`), which keeps counting while the pane is open. While the live
watcher keeps a fresh graph current, it says `● live · 7s` instead, the age
since the graph last moved (`● following · 7s` when another session's
watcher leads; `● fresh 4s` with `another session's watcher is stuck` beside
it when that watcher stopped answering), and `● scanning…` while the
watcher scans. When a reload fails, the pane keeps the figures it had and
the pill says `● refresh failed 2m`.

With no figures to draw yet, the pane says which of three things is the
case: `Reading the graph…` while the first load is on its way; `Could not
read the graph` when knossos did not answer or answered with an error (it
asks again every 15 seconds while the pane shows this); and `No architecture
graph yet` only when knossos answered that it never scanned the project.
Only that last one offers `q: ask Claude to scan it`, and the press checks
again before it sends anything, so a habitual `q` on a slow first load sends
no prompt.

When the snapshot is stale or files drifted since it, the header offers
`r: rescan`. It runs an incremental scan (`knossos rescan`) under the same
rules as the turn's scan, shows `scanning…` meanwhile with the state and age
of the figures still on show (`scanning… · stale 2h`), and reloads the pane
when it is done. A rescan that does not land turns the pill to
`● scan failed`, says why beside it, and leaves the figures as they were.

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
it. At a session end the mod also signals the watcher by the process id it
reported, so its lock is free at once rather than at its next heartbeat, and
the header stops saying `● live` at once. After an exit, a logout or a
signal no watcher starts again; after a `/clear` or a resume within the same
process, the next second's tick starts one for the new session. A new
session that meets the earlier watcher of its own process (on its way out)
does not call it another session. The watcher also stops itself once the
process that started it is gone, so a watcher outliving its session never
keeps polling. A watcher that ends on its own after coming up is started
again after 30 seconds, at most three times; one that never says a word (as
with a container installation, which offers no watcher) is not started again.

**Stuck scans.** Each of the watcher's scans may run five minutes. Past that,
or when the watcher itself is stopped, the scan is asked to stop and, after
three seconds, killed with everything in its process group. The leader keeps
its heartbeat while a scan runs, so a follower reports a leader as stuck only
when that heartbeat is more than a minute old, and says so again when it is
back.

**The turn's brief.** The turn-end brief still runs: it computes what the
turn did, its notes and its policy verdict. With a watcher running it waits
for the watcher to take the turn's last edits in, then passes `--reuse-scan`,
so it reads them from the graph instead of scanning again. It also passes
`--since=<snapshot>`, the snapshot the graph was at before the turn's first
edit. Every scan of an existing project is recorded in the `scan_ledger`
table, whoever runs it: the watcher, the pane's rescan, a turn's brief, the
model's `scan_project` and `knossos scan` from a shell. Each writer takes the
project's write lease before it reads the graph and keeps it until its scan
is recorded, so no other scan can slip in between. An entry holds the
snapshot it started from and the one
it made, each changed file's content hash before it and, for up to 20 changed
files, the policy violations each held before it. Read in order from the
turn's snapshot, the first entry that changed a file says what that file was
before the turn, so the brief's changed, added and deleted files and its
before-and-after policy check stay the turn's own, whoever scanned the edits.
A watcher records a scan for every save, so the ledger keeps the newest 200
entries per project and merges older ones into one span instead of dropping
them: the span keeps where its first scan started and its last ended, each
file's hash before its first change and the newest 20 scans that changed it,
and a short key for each of up to 5,000 of its scans, so a session that
began among them is still found. A span keeps no policy baselines, and one
for more than 2,000 files is cut like an entry. The merge runs in the same
transaction as the recording that sets it off, under that writer's lease.
When a scan since the turn began was not recorded (a scan of a directory
below the project's root, say, or one older than the span's 5,000), or
changed more than 2,000 files (a branch switch: such an entry keeps no file
list, only that it was cut), the brief still names the files but leaves the
policy unevaluated rather than guess.

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
| `notifications`   | `true`  | Toasts each new cycle or policy violation a scan brings, once.     |

They appear in Claude Code's config menu under the plugin's name.

## What it writes

After a turn that edited files, the mod runs `knossos turn-brief`, which
runs an incremental scan into the project's existing database. The pane's
rescan runs `knossos rescan`, the same incremental scan without the brief.
The live watcher runs incremental scans as files change, each a `knossos scan`
process of its own. Those are the only writes to the graph the mod makes, and
each of them records what it changed in the `scan_ledger` table (migration 019, applied the
first time an updated knossos opens the database; past 200 entries per
project the oldest are merged into one bounded span). The watcher also writes its lock and state files under
`watch/` beside the database. They happen only for a project that
is already scanned and inside an allowed root, never inside a hook dispatch,
and never two at a time within a session. The pane's allow-root action writes
the roots file, and only after you confirmed it; a note added on a
component's detail writes one row of the `annotations` table, also only after
you confirmed it (`knossos annotate --execute`). `knossos dashboard` and
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

An install over an earlier one deletes the files the earlier one left in the
plugin's own directories that this one no longer ships (a module since
renamed, say), and only those: never a subdirectory, never the plugin
directory's own root, never through a linked directory. A failed install puts
them back. A container install with `--out` deletes nothing: that directory is
whatever you named, and a file of yours in it stays.

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
whole graph) and for `branch-diff` (two graphs compared, read one after the
other with only the columns the comparison uses), 15 for
`component-detail`, `file-detail`, `file-context`, `graph-search`,
`churn`, `blast-radius`, `path-between`, `annotate`,
`session-changes`, `session-head`, `session-diff`, `boundary-couplings` and
`allow-root`. `churn` takes nothing but the project; `blast-radius` exactly
`--component=`; `path-between` exactly `--from=` and `--to=`, in that order;
`annotate` exactly `--component=` and `--value=` (one line of text of at
most 2,000 characters) and, last, `--execute` when you confirmed the note,
without which it writes nothing. `file-context` takes one file inside the project directory, as
`file-detail` does; `graph-search` exactly `--query=` with one line of text
of at most 200 characters; `branch-diff` nothing but the project. A line of
text has no control character and may hold any other character, in whatever
locale the hook runs, counted by its characters rather than its bytes. The
container wrapper runs `file-context`, `branch-diff` and `churn` as the
image's user, and names the mounted project as git's safe directory, so git
reads it although that user does not own it.
`boundary-couplings` takes exactly `--from=` and `--to=`, in that order, each
a non-empty boundary name of one line of text. `session-diff` takes only a
hex commit id and a file inside the project directory. The container
wrapper takes the same commands with the same checks, except `watch`.
`watch` is not bounded: the wrapper replaces itself with the watcher, so
stopping the process the session started stops the watcher. It runs
`allow-root` only with a roots file the installation (or the environment)
names, never one it would guess from the working directory.

Paths are compared after resolving symbolic links, so a checkout you reach
through a linked directory is still recognised as the project.
