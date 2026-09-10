# Installation and MCP configuration

## One installation, every project

### One data directory

`KNOSSOS_DATA_DIR` holds the graph database and the roots file. Pin it, in the
MCP registration and in any shell you type `knossos` from. `tools/install`
defaults it to `~/.knossos` and writes it into the registration it creates.

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

The fallback is deliberate, so that `knossos scan .` works on a fresh checkout
with no configuration. It is only a hazard when a _server_ is also involved,
which is exactly when the variable should be set.

For the same reason this repository ships no `.mcp.json`. A project-scoped
registration inherits no environment, so it would always be the unpinned case.

### Granting projects

The allow-list lives in a **roots file** that the server re-reads on every
request, so granting another project needs no restart and no re-registration:

```json
{ "roots": ["/absolute/path/one", "/absolute/path/two"] }
```

It lives at `KNOSSOS_ROOTS_FILE`, or `roots.json` beside the graph database
(`<KNOSSOS_DATA_DIR>/roots.json`). `tools/install` creates it, seeded with the
directory you ran the installer from, and registers the server with **no**
`--allow-root` argument so the registration stays correct as projects are added.
Re-running the installer from another project appends rather than replaces.

`--allow-root=PATH` and `KNOSSOS_ALLOWED_ROOTS` still work and are unioned with
the file. Use them for a root that must not depend on a mutable file; use the
file for everything else.

Two tools make this self-service from inside a session:

- **`server_info`**: the roots in force, where each came from, whether each
  actually exists, the roots file to extend, and whether the server is
  containerised. Call it first in an unfamiliar setup, or whenever a path is
  rejected.
- **`diagnose_runtime`**: runtimes, scanner workers, protocol, database, and
  migrations, for when a scan fails for no visible reason. Slower, because it
  starts each language worker.

A rejected path reports the roots in force and the file to add it to, so the fix
does not require guessing.

## Docker (recommended)

Docker is the reproducible distribution and removes host PHP/Node coupling.

```sh
docker build -t knossos-mcp:dev .
docker run --rm knossos-mcp:dev doctor --json
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
                "type=bind,source=/absolute/project,target=/workspace,readonly",
                "--mount",
                "type=volume,source=knossos-data,target=/data",
                "knossos-mcp:dev",
                "serve",
                "--allow-root=/workspace"
            ]
        }
    }
}
```

## Native stdio (repository checkout)

Register the server once, for your user, and let the roots file decide which
projects it may read. `tools/install` does this for you: it creates the data
directory (`~/.knossos` unless `KNOSSOS_DATA_DIR` says otherwise), seeds the
roots file, and writes a registration that pins both paths.

```sh
claude mcp add knossos --scope user \
    -e KNOSSOS_DATA_DIR="$HOME/.knossos" \
    -e KNOSSOS_ROOTS_FILE="$HOME/.knossos/roots.json" \
    -- /absolute/path/to/checkout/tools/mcp-serve
```

The repository deliberately ships **no** `.mcp.json`. A project-scoped
registration inherits no environment, so `tools/mcp-serve` falls back to
`<checkout>/.knossos` and builds a second graph beside the installed one.
Nothing warns about it: both servers answer, each from its own database, and
the session brief reports whichever it reaches first by walking parent
directories. One registration with an explicit data directory is what keeps
every caller, the CLI included, on a single graph.

That is also why the paths above are absolute where the old checked-in file
used relative ones. A registration that resolves against the client's working
directory is portable across checkouts and ambiguous about which graph it
means; this one is neither.

The allow-list is a security boundary, not a convenience. It is the only thing
standing between the server and the rest of the filesystem, so at least one root
is required: `serve` exits with `KNOSSOS_INVALID_ARGUMENT` when the flag, the
environment variable, and the roots file are all empty. Grant the narrowest tree
that works.

Anything able to write the roots file can widen what Knossos reads, which is why
the grant is an inspectable file rather than a tool the caller can invoke on
itself. Knossos never writes it during normal operation. Keep it owned by the
user running the server. If you would rather the boundary could not move at all,
omit the file entirely and pass `--allow-root` only; the file is optional.

The configuration shape is accepted by MCP clients that use the common
`mcpServers` stdio convention. Client-specific placement varies; keep the
command and argument array unchanged. Do not add `-t`, because terminal framing
can corrupt NDJSON.

## Native

Supported native runtimes are PHP 8.3 or newer with JSON, PDO, and PDO SQLite;
Node 22 or newer; Python 3.11 or newer; Composer 2; and Git. Each is a floor
rather than a range: newer releases are supported, and `doctor` reports a
version below the floor rather than capping the ones above it. Install locked dependencies without running project
scripts. `change_impact` still returns static impact when a scanned root is not
a Git repository:

```sh
composer install --no-interaction
composer --working-dir=workers/php install --no-interaction
npm --prefix workers/typescript ci --ignore-scripts
php bin/knossos doctor --json
```

Native MCP command:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "/absolute/Knossos-MCP/bin/knossos",
            "args": ["serve", "--allow-root=/absolute/project"],
            "env": { "KNOSSOS_DATA_DIR": "/absolute/knossos-data" }
        }
    }
}
```

Linux and macOS are directly supported. Windows is supported through Docker
Desktop or WSL2; native Windows process/path behavior is not yet in the tested
matrix. `doctor` verifies the effective runtime, workers, protocol, database,
migrations, and data-directory writability.

Cargo 1.82 or newer is optional. When it is present, `tools/install` builds the
Rust worker and Rust scanning is available; when it is absent, `doctor` reports
`worker.rust` as `skipped` rather than an error, and every other language keeps
working. The Docker image needs nothing extra: it always carries a built Rust
worker.

## Operational safety

- Scanning never installs dependencies, executes project code, or boots Laravel.
- Preconfigure every MCP allowed root.
- Prefer read-only source mounts and `--network none`.
- Back up no index state: the SQLite database is derived and rebuildable.
- Use `scan_project` with `mode: auto`; force `full` only for verification or
  after changing analyzer code outside the packaged release.
