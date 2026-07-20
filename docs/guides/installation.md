# Installation and MCP configuration

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

`--allow-root` is a security boundary, not a convenience flag. It is the only
thing standing between the server and the rest of the filesystem, so it is
required: `serve` exits with `KNOSSOS_INVALID_ARGUMENT` when no root is given
through the flag or `KNOSSOS_ALLOWED_ROOTS`. Pass the flag once per tree that
should be readable, and grant the narrowest tree that works.

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
