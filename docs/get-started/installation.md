# Installation

You install Knossos once and point it at as many projects as you like. There are
two ways to run it: from a checkout with the installer, which is what most
people want, or in Docker. Both end with an MCP server registered in your
client and a data directory holding the graph.

## Install from a checkout

Run the installer from the project you want to scan first:

```sh
cd /absolute/path/to/your-project
/absolute/path/to/knossos/tools/install
```

It does the following, in order, and is safe to re-run:

1. Checks that `php`, `node` and `python3` exist.
2. Installs the PHP and TypeScript worker dependencies if they are missing, and
   builds the Rust worker when `cargo` is on the path.
3. Runs `doctor`. Warnings are printed and do not stop the install.
4. Creates the data directory (`~/.knossos`) and a roots file in it, seeded with
   the directory you ran the installer from. Run it again from another project
   and that project is appended.
5. Scans the directory you ran it from, so the server can answer straight away.
   `KNOSSOS_SEED_SCAN=0` skips this step.
6. Registers the server with Claude Code at user scope, through `claude mcp add`.
   Without the `claude` CLI it prints the JSON to paste into your client.

The installer reads these environment variables:

| Variable             | Default                 | Meaning                                   |
| -------------------- | ----------------------- | ----------------------------------------- |
| `KNOSSOS_DATA_DIR`   | `~/.knossos`            | Holds the database and the roots file.    |
| `KNOSSOS_ROOTS_FILE` | `<data dir>/roots.json` | The allow-list file itself.               |
| `KNOSSOS_ALLOW_ROOT` | the current directory   | The project that seeds the roots file.    |
| `KNOSSOS_MCP_NAME`   | `knossos`               | The name of the registered server.        |
| `KNOSSOS_MCP_SCOPE`  | `user`                  | `claude mcp` scope: local, user, project. |
| `KNOSSOS_SEED_SCAN`  | `1`                     | `0` skips the initial scan.               |

Restart Claude Code afterwards, or reload its MCP servers. Then
[scan your first project](first-scan.md). To get the session brief and the
architecture pane in Claude Code, also install
[the plugin](../claude-code/plugin.md).

## One installation, every project

### One data directory

`KNOSSOS_DATA_DIR` holds the graph database, and the roots file too unless
`KNOSSOS_ROOTS_FILE` names one elsewhere. Pin it, in the MCP registration and
in any shell you type `knossos` from. `tools/install` defaults it to
`~/.knossos` and writes it into the registration it creates.

Unpinned, it falls back to `<cwd>/.knossos`, and that fallback is per-caller.
The consequences are quiet rather than loud:

- Two servers, one registered with the variable and one without, build **two
  graphs of the same project**. Both answer. Neither mentions the other.
- `knossos` typed inside a project addresses `<project>/.knossos`, not the graph
  the server reads, so a scan you just ran can leave the server's copy stale.
- `knossos allow-root` writes the roots file beside whichever database it
  derived, so a grant can land in a file the running server never reads. The
  command says which file it wrote and whether the location was named or
  derived; `server_info` reports the one actually in force.

The fallback exists so that `knossos scan .` works on a fresh checkout with no
configuration. It is a hazard only when a _server_ is also involved, which is
when the variable should be set.

The repository ships no `.mcp.json` for the same reason. A project-scoped
registration inherits no environment, so it would always be the unpinned case.

### Granting projects

The allow-list lives in a **roots file** that the server re-reads on every
request, so granting another project needs no restart and no re-registration:

```json
{ "roots": ["/absolute/path/one", "/absolute/path/two"] }
```

It lives at `KNOSSOS_ROOTS_FILE`, or at `<KNOSSOS_DATA_DIR>/roots.json`. The
installer registers the server with no `--allow-root` argument, so the
registration stays correct as projects are added.

To add a project, run `knossos allow-root /absolute/path` to preview the change
and add `--execute` to write it. Editing the file by hand works too.

`--allow-root=PATH` and `KNOSSOS_ALLOWED_ROOTS` are unioned with the file. Use
them for a root that has to survive without a mutable file, and the file for
everything else.

Two tools help from inside a session:

- **`server_info`** returns the roots in force, where each came from, whether
  each exists, the roots file to extend, and whether the server runs in a
  container. Call it first in an unfamiliar setup, or whenever a path is
  rejected.
- **`diagnose_runtime`** checks runtimes, scanner workers, protocol, database
  and migrations, for when a scan fails for no visible reason. It is slower,
  because it starts each language worker.

A rejected path reports the roots in force and the file to add it to.

## Docker

Docker is the reproducible distribution and needs no host PHP or Node.

```sh
docker build --target runtime -t knossos:dev .
docker run --rm knossos:dev doctor --json
```

Use an absolute source path, mount it read-only, keep `/data` in a separate
volume, disable networking, and keep stdin open for MCP:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "docker",
            "args": [
                "run",
                "--rm",
                "-i",
                "--network",
                "none",
                "--mount",
                "type=bind,source=/absolute/project,target=/absolute/project,readonly",
                "--mount",
                "type=volume,source=knossos-data,target=/data",
                "knossos:dev",
                "serve",
                "--allow-root=/absolute/project"
            ]
        }
    }
}
```

The image always carries a built Rust worker. For compose files, volumes and
the HTTP profile, see [the container guide](../operate/container.md).

## Native stdio (repository checkout)

`tools/install` runs the registration for you. To do it by hand, register the
server once for your user and let the roots file decide which projects it may
read:

```sh
claude mcp add knossos --scope user \
    -e KNOSSOS_DATA_DIR="$HOME/.knossos" \
    -e KNOSSOS_ROOTS_FILE="$HOME/.knossos/roots.json" \
    -- /absolute/path/to/checkout/tools/mcp-serve
```

`tools/mcp-serve` wraps `bin/knossos serve` and logs its start, signals, stderr
and exit status to `mcp-serve.log` in the data directory. A host that reports
only "server disconnected" leaves you that log to read.

Every path in the registration is absolute. A relative path resolves against
the client's working directory, so the same registration would reach a
different graph from each checkout. Without the data directory, `tools/mcp-serve` falls back to `<checkout>/.knossos` and
builds a second graph beside the installed one. Nothing warns about it: both
servers answer, each from its own database.

### The allow-list is a security boundary

It is the only thing between the server and the rest of the filesystem, so
`serve` needs at least one root. When the flag, the environment variable and the
roots file are all empty, it exits with `KNOSSOS_INVALID_ARGUMENT`. Grant the
narrowest tree that works.

Anything able to write the roots file can widen what Knossos reads, which is why
the grant is a file you inspect and no tool the caller can invoke on itself.
Knossos never writes it during normal operation; only `tools/install` and
`knossos allow-root --execute` do. Keep it owned by the user running the server.
If the boundary must be fixed, omit the file and pass `--allow-root` only.

Any MCP client that uses the common `mcpServers` stdio convention accepts this
shape. Placement differs per client, so keep the command and the argument array
unchanged. Do not add `-t`, because terminal framing corrupts the NDJSON.

## Codex

Register the stdio server with the Codex CLI, with the data and roots paths
pinned so the CLI and the server read one graph:

```sh
codex mcp add knossos \
    --env KNOSSOS_DATA_DIR="$HOME/.knossos" \
    --env KNOSSOS_ROOTS_FILE="$HOME/.knossos/roots.json" \
    -- /absolute/path/to/knossos/tools/mcp-serve
```

For Docker, build the image first and mount the project at the same absolute
path inside the container, so the paths Codex sends match the container's:

```sh
docker build --target runtime -t knossos:dev /absolute/path/to/knossos
codex mcp add knossos -- docker run --rm -i --network none \
    --mount type=bind,source=/absolute/project,target=/absolute/project,readonly \
    --mount type=volume,source=knossos-data,target=/data \
    knossos:dev serve --allow-root=/absolute/project
```

The checkout also carries a Codex plugin with the same
[routing skill](../claude-code/skill.md) the Claude Code plugin ships. It adds
the skill and no server, so install it beside the registration above:

```sh
codex plugin marketplace add /absolute/path/to/knossos
codex plugin add knossos@knossos-dev
codex plugin list
```

The marketplace is `.agents/plugins/marketplace.json` and the plugin source is
`plugins/knossos`. Start a new Codex session after installing or updating it.
The session brief and the pane are Claude Code hooks and do not run in Codex.

## Installation pitfalls

- **No PHP or Composer on the host.** `tools/mcp-serve` cannot start without
  them. Use the Docker image instead.
- **An unqualified `docker build`.** It builds the last stage of the
  Dockerfile, the CI `quality` stage, not the server. Always pass
  `--target runtime`. The image is not published, so `docker pull` cannot stand
  in for the build.
- **`-t` on Docker stdio.** Use `-i` and never `-t`: terminal framing corrupts
  the NDJSON stream. A socket permission error from Docker means the user
  starting your client cannot reach Docker; fix that before you start Codex or
  Claude Code.
- **An unpinned data directory.** Keep `KNOSSOS_DATA_DIR` and
  `KNOSSOS_ROOTS_FILE` explicit and absolute; see
  [one data directory](#one-data-directory).
- **Different paths inside the container.** Mount each allowed project
  read-only at the same absolute path inside the container, and pass that path
  to `--allow-root`. Otherwise the host paths in MCP requests match nothing in
  the container.
- **Git's `dubious ownership`.** The image runs as `www-data`, so a checkout
  owned by another user trips Git's ownership check. Scanning still works, but
  the Git-backed answers degrade. Set `KNOSSOS_GIT_SAFE_DIRECTORY` to the
  mounted project's absolute path, and Knossos passes it to every git call as
  `-c safe.directory=`. The Claude Code plugin's container hooks do this for
  you.

## Native runtimes

You need PHP 8.3 or newer with JSON, PDO and PDO SQLite, Node 22 or newer,
Python 3.11 or newer, Composer 2 and Git. Each is a floor. `doctor` reports a
version below it and does not cap the ones above. Install the locked
dependencies without running project scripts:

```sh
composer install --no-interaction
composer --working-dir=workers/php install --no-interaction
npm --prefix workers/typescript ci --ignore-scripts
php bin/knossos doctor --json
```

A native MCP registration, without the installer:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "/absolute/knossos/bin/knossos",
            "args": ["serve", "--allow-root=/absolute/project"],
            "env": { "KNOSSOS_DATA_DIR": "/absolute/knossos-data" }
        }
    }
}
```

Cargo 1.82 or newer is optional. With it, `tools/install` builds the Rust worker
and Rust scanning works. Without it, `doctor` reports `worker.rust` as `skipped`
and every other language keeps working.

`doctor` verifies the effective runtime, the workers, the protocol, the
database, the migrations and whether the data directory is writable. CI runs on
Linux. On Windows, use Docker Desktop or WSL2; native Windows is untested.
`change_impact` still returns static impact when a scanned root is not a Git
repository.

## Operating safely

- Scanning never installs dependencies, executes project code or boots Laravel.
- Preconfigure every allowed root before you hand the server to an agent.
- Prefer read-only source mounts and `--network none`.
- Back up the database when agents annotate components. The graph rebuilds
  from a scan, but annotations live only in that database, and bundles leave
  them out. `knossos maintain-database backup --execute` writes a copy under
  `backups/`; see [maintenance](../operate/maintenance.md).
- Call `scan_project` with `mode: auto`. Force `full` to verify a result, or
  after changing analyzer code outside the packaged release.
