# Running Knossos in Docker

The Docker distribution supplies the matched PHP, Node, Python, Composer, SQLite, and
Knossos versions. The scanned project does not need either runtime installed on
the host.

The image runs as unprivileged `www-data`, pins base-image digests and worker
lockfiles, caps Node/PHP scanner memory, isolates the Python AST worker, includes a version health check, and
keeps `/data` as the only required writable persistent location.

Build the development image:

```sh
docker build -t knossos-mcp:dev .
docker run --rm knossos-mcp:dev version --json
```

For scans, mount source read-only and keep derived graph state in a separate
Docker volume:

```sh
docker run --rm -i \
  --network none \
  --mount type=bind,source=/absolute/path/to/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev scan /workspace --json
```

The eventual `scan_project` path inside the container is `/workspace`, not the
host path. `--network none` is recommended because scanning is local and never
needs dependency installation or network access.

An agent cannot infer that mapping, so ask the server: `server_info` reports the
roots it can actually reach and sets `containerised: true`, and a rejected path
says so explicitly rather than leaving the host path looking merely wrong. A
root that was configured on the host and is not mounted shows up under
`unreachable_roots` instead of failing only when a scan is attempted.

The roots file is read from inside the container, so it belongs on the `/data`
volume (`/data/roots.json`) and must name container paths. Adding a project
still means adding a mount, which is a `docker run` change: the file removes
the restart, not the mount.

An MCP client can use `docker` as its server command and pass the `run` arguments
above. The `-i` flag is required for MCP standard-input/output transport. Avoid
`-t`: terminal framing can interfere with protocol messages.

For MCP, replace the final command with:

```sh
knossos-mcp:dev serve --allow-root=/workspace
```

Native installation remains supported for lower startup overhead and easier
integration with very large local workspaces. Both distributions use the same
scanner protocol and graph format.

## Compose

`docker-compose.yml` wraps the three supported invocations. Source is always
mounted read-only at `/workspace`; graph data lives in the `knossos-data`
volume.

Compose interpolates the entire file before applying `--profile` filtering, so
`KNOSSOS_HTTP_BEARER_TOKEN` must resolve for every compose command, even ones
that never touch the `http` profile. Compose loads `.env` automatically, so the
smallest fix is to add only that variable. Use `>>` (append), not `>`, so an
existing `.env` is not truncated:

```sh
printf '\nKNOSSOS_HTTP_BEARER_TOKEN=unused-by-non-http-profiles\n' >> .env
```

Copying `.env.example` also works, but it sets `KNOSSOS_SOURCE` to a placeholder
path; delete or fill in that line, or the bind mount stops following the
directory compose runs from.

One-shot CLI, with networking disabled:

```sh
docker compose run --rm knossos scan /workspace --json
```

MCP stdio. `-T` is required so compose does not allocate a TTY and corrupt
JSON-RPC framing:

```sh
docker compose --profile mcp run --rm -T knossos-mcp
```

Streamable HTTP, loopback-only and opt-in:

```sh
KNOSSOS_HTTP_BEARER_TOKEN="$(openssl rand -hex 32)" \
  docker compose --profile http up knossos-http
```

Both server services sit behind profiles, so a bare `docker compose up` starts
nothing that listens. The HTTP profile is subject to the limits in the
[HTTP threat model](http-threat-model.md): PHP's development server is
single-process and is not intended for hostile traffic. stdio remains the
recommended transport.

The mounted directory defaults to wherever `docker compose` is run from. Set
`KNOSSOS_SOURCE` to scan a different tree; PowerShell does not export `PWD`, so
Windows users must always set it. See `.env.example`.

## Agent orientation plugin for a containerised install

`knossos install-agent-plugin` normally installs the
[session brief](../features/session-brief.md) plugin by materialising a
`.plugin/` directory beside this checkout and running
`claude plugin marketplace add` against it, which only works when `claude`
can reach a local Knossos installation directly. That path does not
apply to a containerised install, so the same command has a second mode that
emits a self-contained plugin directory instead of installing anything:

```sh
knossos install-agent-plugin --out=DIR --data=HOSTPATH [--image=NAME]
```

`DIR` is a path _inside_ the container, so bind-mount a host directory onto it
and point `--out` at the target. Without the mount the emitted plugin lives
only in the container's writable layer and `--rm` takes it away with the
container:

```sh
docker run --rm \
  --mount type=bind,source="$PWD/plugin",target=/out \
  knossos-mcp:dev install-agent-plugin --out=/out --data="$HOME/.knossos"
```

- `--out=DIR` writes `.claude-plugin/`, `hooks/`, and `skills/` under `DIR`,
  the same five files a local install materialises and differing only in the
  hook script, all-or-nothing: a failure partway through removes everything
  the command created (or, if `DIR` already existed, only the files and
  directories this call added), so a failed emit never leaves a directory
  that looks like a working plugin but is missing pieces.
- `--data=HOSTPATH` is required. The process running `install-agent-plugin`
  is itself inside the container it is configuring, so it has no way to
  discover the host filesystem path of its own `/data` volume; nothing on
  disk inside the container names that path. `HOSTPATH` is what the emitted
  hook's `docker run` mounts back in at session start.
- `--image=NAME` overrides the image the emitted hook runs (default
  `knossos-mcp:dev`).

The emitted hook script mounts the project directory at the **same path**
inside the container as outside it, rather than at a fixed internal path such
as `/workspace`. That is not cosmetic. Projects are keyed by
`root_realpath`, the resolved filesystem path recorded at scan time. A
session that starts in `/home/me/project` but is scanned inside the
container as `/workspace` would record `/workspace` as the root; the next
session's brief, resolving `/home/me/project` again, would not find that
project at all, and would render `NOT SCANNED` for a project that has in fact
already been scanned. Mounting at the identical path keeps `root_realpath`
consistent between the scan and every later brief.

Install the emitted directory the same way as a local one, pointed at `DIR`
instead of this checkout:

```sh
claude plugin marketplace add DIR --scope user
```

MCP server registration is unaffected either way; see
[why the plugin does not register the server](../../README.md#agent-orientation-plugin-setup)
in the top-level README.
