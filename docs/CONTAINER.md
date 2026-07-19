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
that never touch the `http` profile. The simplest fix is
`cp .env.example .env`; compose loads `.env` automatically.

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
[HTTP threat model](HTTP-THREAT-MODEL.md): PHP's development server is
single-process and is not intended for hostile traffic. stdio remains the
recommended transport.

The mounted directory defaults to wherever `docker compose` is run from. Set
`KNOSSOS_SOURCE` to scan a different tree; PowerShell does not export `PWD`, so
Windows users must always set it. See `.env.example`.
