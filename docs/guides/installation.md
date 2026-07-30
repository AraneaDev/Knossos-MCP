# Installation and MCP configuration

## One installation, every project

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

- **`server_info`** — the roots in force, where each came from, whether each
  actually exists, the roots file to extend, and whether the server is
  containerised. Call it first in an unfamiliar setup, or whenever a path is
  rejected.
- **`diagnose_runtime`** — runtimes, scanner workers, protocol, database, and
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

A checked-in `.mcp.json` at the repository root registers the server for
clients that read project-scoped configuration:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "php",
            "args": ["bin/knossos", "serve", "--allow-root=."]
        }
    }
}
```

Both paths are relative on purpose. `bin/knossos` and `--allow-root=.` resolve
against the working directory the client launches the server in, so the file is
valid on any checkout without editing. A client that launches the server
somewhere unexpected fails immediately on the relative binary path rather than
silently granting access to the wrong tree.

For a client that configures servers imperatively instead:

```sh
claude mcp add knossos -- php bin/knossos serve --allow-root=.
```

The allow-list is a security boundary, not a convenience. It is the only thing
standing between the server and the rest of the filesystem, so at least one root
is required: `serve` exits with `KNOSSOS_INVALID_ARGUMENT` when the flag, the
environment variable, and the roots file are all empty. Grant the narrowest tree
that works.

Anything able to write the roots file can widen what Knossos reads, which is why
the grant is an inspectable file rather than a tool the caller can invoke on
itself — Knossos never writes it during normal operation. Keep it owned by the
user running the server. If you would rather the boundary could not move at all,
omit the file entirely and pass `--allow-root` only; the file is optional.

The configuration shape is accepted by MCP clients that use the common
`mcpServers` stdio convention. Client-specific placement varies; keep the
command and argument array unchanged. Do not add `-t`, because terminal framing
can corrupt NDJSON.

## Native

Supported native runtimes are PHP 8.3–8.4 with JSON, PDO, and PDO SQLite; Node
22–24; Python 3.11–3.13; Composer 2; and Git. Install locked dependencies without running project
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

## Operational safety

- Scanning never installs dependencies, executes project code, or boots Laravel.
- Preconfigure every MCP allowed root.
- Prefer read-only source mounts and `--network none`.
- Back up no index state: the SQLite database is derived and rebuildable.
- Use `scan_project` with `mode: auto`; force `full` only for verification or
  after changing analyzer code outside the packaged release.
