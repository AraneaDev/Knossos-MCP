# The Claude Code plugin

One plugin brings Knossos into Claude Code. It carries:

- the [session brief](session-brief.md), injected when a session starts;
- the [routing skill](skill.md), which says which questions to bring to the
  graph;
- the [mod](pane.md): the band, the pane, the live watcher, and the
  [notes for the model](agent-notes.md).

You install it from the same checkout that runs your Knossos server, because
the plugin ships no binary of its own: its hooks run the `knossos` already on
your machine.

## Install

Preview first:

```sh
knossos install-agent-plugin
```

This prints the two commands it would run, the data directory it would write
into the hooks, and changes nothing:

```text
claude plugin marketplace add '/home/me/knossos/.plugin' --scope user
claude plugin install knossos@knossos --scope user --yes
```

Then install:

```sh
knossos install-agent-plugin --data-dir="$HOME/.knossos" --execute
```

`--execute` writes the plugin directory, `.plugin/` beside the checkout, and
runs both commands. Options:

| option           | what it does                                                              |
| ---------------- | ------------------------------------------------------------------------- |
| `--execute`      | write `.plugin/` and run the two `claude` commands; without it, a preview |
| `--scope=SCOPE`  | `user` (the default), `project` or `local`                                |
| `--data-dir=DIR` | the data directory your server uses, written into the hooks (see below)   |
| `--json`         | print the result as JSON                                                  |

Start a new session afterwards. The brief appears at its start, and `/knossos`
opens the pane once the project is scanned.

For a containerised server, use `--out` instead; see
[the container guide](../operate/container.md#agent-orientation-plugin-for-a-containerised-install).

## The data directory

The MCP server and the hooks must read the same graph. The server gets its data
directory from its own registration, and hooks never see that environment. So
the install writes the data directory into the hooks, chosen in this order:

1. `--data-dir=DIR`, when you pass it.
2. The `KNOSSOS_DATA_DIR` of the shell you install from.
3. Neither: the hooks fall back to the `.knossos/` directory nearest the
   project, which is right only when your server uses that too.

The value must be an absolute path. The hooks then use `DIR/roots.json` as the
roots file. A `KNOSSOS_DATA_DIR` or `KNOSSOS_ROOTS_FILE` already set in the
environment Claude Code runs the hooks in takes precedence over the one written
in.

To check which directory your server reads, call its `server_info` tool. See
[one data directory](../get-started/installation.md#one-data-directory).

## The hooks need a knossos binary

The hooks look for `knossos` in a fixed, short order:

1. `KNOSSOS_BIN`, when it is set and executable.
2. `knossos` on `PATH`.
3. `$CLAUDE_PROJECT_DIR/bin/knossos`, `~/.local/bin/knossos`,
   `/usr/local/bin/knossos`.

Every failure is silent by design, so a session in a project outside your
Knossos checkout simply gets no brief, with nothing to say why. One symlink
into a directory on that list makes it work everywhere:

```sh
ln -s /absolute/path/to/knossos/bin/knossos ~/.local/bin/knossos
```

## Try it for one session with `--plugin-dir`

Claude Code can load a plugin from a directory for one session, without
installing it:

```sh
claude --plugin-dir /home/me/knossos/.plugin
```

That loads the plugin straight from `.plugin/`, which
`install-agent-plugin --execute` writes. This is how the stills on the
[pane page](pane.md) were captured, in a Claude Code configuration of their
own. Use it where the plugin is not also installed, so it loads once.

## What `.plugin/` holds

`.plugin/` is what Claude Code is pointed at, and the whole of what it copies:

- `.claude-plugin/plugin.json`, the manifest, rewritten with the running CLI's
  version;
- `.claude-plugin/marketplace.json`, the marketplace descriptor, generated;
- `hooks/hooks.json`, the `SessionStart` hook and the mod's module;
- `hooks/register.tsx`, `hooks/lib/*.ts` and `hooks/mod/*`, the mod, each file
  named one by one so no spec or config beside them is copied;
- `hooks/scripts/`, the hook and wrapper scripts and their shared library, with
  the data directory written in;
- `skills/graph/SKILL.md`, the routing skill;
- `types/index.d.ts`, the mod's types.

The checkout itself is never registered. Claude Code also keeps a copy of a
local marketplace in its plugin cache, so registering the repository root would
copy the whole working tree with it: `vendor/`, `node_modules/`, build caches, the graph
database, and any stray config file. A directory holding only the plugin copies
only the plugin.

`.plugin/` is git-ignored, deliberately: most of it is copies of files already
in the repository, and committing them would let the copies drift from their
sources. Anything that deletes ignored files removes it. `git clean -xdf` in
particular leaves the plugin `failed to load: cache-miss`. The same install
command puts it back.

## Update

Claude Code reads a plugin installed from a local directory in place:
`claude plugin list --json` shows `.plugin/` as its `readFromFolder`. After you
update your checkout, or change a hook script, the skill or the mod, rewrite
`.plugin/` with the same `--data-dir` you installed with, because an update
without it writes hooks that fall back to the project's own `.knossos/`:

```sh
knossos install-agent-plugin --data-dir="$HOME/.knossos" --execute
```

The change takes effect at the next session start, or at once with
`/reload-plugins`.

If `claude plugin list --json` shows no `readFromFolder` for `knossos@knossos`,
your Claude Code runs the copy in its plugin cache instead, cached by the
version in the manifest (the install writes the running CLI's version there).
Then a new release needs `claude plugin update knossos@knossos` and a restart,
and a change within one version needs a fresh copy:

```sh
claude plugin uninstall knossos@knossos --scope user
knossos install-agent-plugin --data-dir="$HOME/.knossos" --execute
```

An install over an earlier one deletes the files the earlier one left in the
plugin's own directories that this one no longer ships, such as a renamed
module, and only those: never a subdirectory, never the plugin directory's
root, never through a linked directory. It also removes the skill directories
earlier installs used, `skills/knossos` and `skills/ask-the-graph`. A failed
install puts everything back. A container install with `--out` deletes nothing,
since that directory is whatever you named.

## Remove

```sh
claude plugin uninstall knossos@knossos --scope user
claude plugin marketplace remove knossos
rm -rf /home/me/knossos/.plugin
```

Use the scope you installed with. `claude plugin uninstall` also removes the
plugin's data directory under `~/.claude/plugins/data/` unless you pass
`--keep-data`. Your graph, in your Knossos data directory, is untouched, and so
is the MCP server's registration.

## Why there is no public marketplace route

`claude plugin marketplace add AraneaDev/knossos` fails with
`Marketplace file not found`, on purpose. The descriptor is generated into
`.plugin/` and never committed.

A clone from a public marketplace would have no `vendor/`, so its own
`bin/knossos` could not run, and the hooks fail silent on every error. Such an
install would produce nothing, forever, with nothing to diagnose. Installing
from a checkout that already runs the server is the one source that can supply
the working binary the hooks need.

## The plugin and the MCP server are separate installs

Registering the server with an MCP client (see
[installation](../get-started/installation.md)) does not need the plugin, and
installing the plugin does not register a server.

A containerised installation does not run a local `knossos` binary at all, so
folding server registration into the plugin install would either duplicate an
existing registration or assume one that is not there.
