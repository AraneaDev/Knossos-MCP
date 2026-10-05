# First scan

You have Knossos [installed](installation.md). This page takes you from there to
a graph of one of your projects, checks that the graph is there, and points you
at the two places to use it.

## Scan a project

Point `knossos scan` at the project's directory. If you registered the server
through `tools/install`, export the same data directory first, so the CLI and
the server read one graph (see
[one data directory](installation.md#one-data-directory)):

```sh
export KNOSSOS_DATA_DIR="$HOME/.knossos"
bin/knossos scan /absolute/path/to/your-project
```

The scan reads source files only. It never installs dependencies, runs your
code or boots a framework. It prints the size of the graph and the ids you need
for later calls:

```text
Scanned 212 files into 1480 nodes and 3105 relationships.
Project: project_5a21f82c...
Snapshot: scan_3a08192c...
```

A node is a component the scanners found (a class, a method, a route, a
module). A relationship is an edge between two of them: a call, an import, an
inheritance link. Your counts will differ.

The default mode, `auto`, scans everything the first time and rescans only what
changed afterwards.
If the project has a [`knossos.json`](project-configuration.md) in its root, the
scan reads it for ignores, boundaries and limits.

A project that is not a Git repository scans fine. The scan prints one line
saying Git could not answer, and `change_impact` still returns the static
impact.

## Check that it worked

List the projects in the data directory:

```sh
bin/knossos list-projects
```

```text
Found 1 persisted project.
project_5a21f82c...  your-project  ready  files=212 nodes=1480 edges=3105
```

`ready` means the active snapshot is complete. Then ask the graph something.
`architecture-summary` returns the boundaries and the hubs, and
`export-agent-brief` renders the same orientation as markdown:

```sh
bin/knossos architecture-summary project_5a21f82c...
bin/knossos export-agent-brief project_5a21f82c...
```

If the brief lists the boundaries and hubs you expect, the scan worked. If it is
empty or the language mix is wrong, run `bin/knossos doctor` to check that every
worker starts, and check [the ignore list](project-configuration.md#settings) in
case a directory you care about is excluded by default.

From inside a project you can skip the id. `session-brief`, `dashboard` and the
other path-addressed commands read the database `KNOSSOS_DATA_DIR` names, or,
when it is unset, the nearest `.knossos/knossos.sqlite` at or above the path:

```sh
cd /absolute/path/to/your-project
bin/knossos session-brief
```

## Let the server read it

The CLI scans any directory you give it. The MCP server answers only for
directories on its allow-list, and a project you scanned from the shell is not
on it until you add it:

```sh
bin/knossos allow-root /absolute/path/to/your-project --execute
```

Without `--execute` the command previews the change. The server re-reads the
roots file on every request, so the grant takes effect without a restart. When
a path is rejected, call `server_info`: it names the roots in force and the file
to extend.

## Next step

<!-- still:overview -->

Where you use the graph decides what you set up next.

- **You work in Claude Code.** Install [the plugin](../claude-code/plugin.md).
  It injects a [session brief](../claude-code/session-brief.md) at the start of
  each session and adds the architecture pane, where you browse hubs, cycles,
  boundaries and the diff of a turn. [The pane page](../claude-code/pane.md)
  shows how to open it.

- **You use another agent or a script.** Register the MCP server as described
  in [installation](installation.md#native-stdio-repository-checkout) and read
  [agent integration](../agents/agent-integration.md) for the tools an agent
  calls first. The full list is in
  [the MCP tool reference](../reference/mcp-tools.md).

- **You want the graph kept current.** Run
  [watch mode](../operate/watch-mode.md), or let a read tool rescan a stale
  graph on its own, as
  [agent integration](../agents/agent-integration.md#refreshing-a-stale-graph)
  describes.
