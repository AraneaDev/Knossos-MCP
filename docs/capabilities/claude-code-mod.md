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
`enforcePolicies` is on, the post-turn scan evaluates them for the files the
turn changed. Violations reach the model as a note it reads before its next
step:

```text
knossos: this turn introduced 1 boundary-policy violation. Fix it before finishing:
- domain-isolation: App\Domain\Order → App\Infra\Db
```

The count is per violating dependency, so one call can count twice (once for
the class, once for the method).

## The pane

`/knossos` opens and closes the architecture pane: hubs, hotspots, the
largest dependency cycles, the dead-code candidate count, and two sparklines
over the last snapshots (cycles and maximum degree per snapshot). A count
that hit a search limit reads `50+`, never `50`. Pressing a hub or hotspot
shows its detail: kind, location, boundaries and who uses it.
`/knossos inspect <component>` opens the pane on one component directly.

At 144 terminal columns or more you can have it open on start with
`openPaneOnStart`.

## Settings

| Field             | Default | What it does                                                     |
| ----------------- | ------- | ---------------------------------------------------------------- |
| `enabled`         | `true`  | Turns the band, notes and pane on or off.                        |
| `fanInThreshold`  | `20`    | Dependent files at which an edit gets a note.                    |
| `enforcePolicies` | `true`  | Tells the model about violations a turn introduced.              |
| `openPaneOnStart` | `false` | Opens the pane when a session starts, on a wide enough terminal. |

They appear in Claude Code's config menu under the plugin's name.

## What it writes

After a turn that edited files, the mod runs `knossos turn-brief`, which
runs an incremental scan into the project's existing database. That is the
only write. It happens only for a project that is already scanned and inside
an allowed root, never inside a hook dispatch, and never two at a time.
`knossos dashboard` and `knossos component-detail` only read. None of the
three ever creates a database.

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
  `knossos allow-root ... --execute` command instead of figures.
- The project has never been scanned.
- An older Claude Code without function hooks: the module is ignored and the
  session-start brief keeps working as before.

Failures in the wrapper are silent by design: it exits 0 with no output, and
the band keeps its last figures with their real age.
