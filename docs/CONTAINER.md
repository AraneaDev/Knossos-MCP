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
