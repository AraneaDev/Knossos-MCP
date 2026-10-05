# Codex and other MCP clients

Knossos is an MCP server first, so any client that speaks MCP over stdio can
use its tools. This page registers it with Codex and with a client that reads
the common `mcpServers` shape. The pane, the notes and the session brief are
Claude Code hooks and run only there; for Claude Code, see
[installation](../get-started/installation.md) and
[the plugin](../claude-code/plugin.md).

Every registration below pins the data directory and the roots file, so the
server, the CLI and any hooks read one graph. See
[one data directory](../get-started/installation.md#one-data-directory) for
what goes wrong without it.

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

## Any MCP client

A client that uses the common `mcpServers` stdio shape takes this entry. Keep
every path absolute, since a relative one resolves against the client's working
directory and reaches a different graph from each checkout:

```json
{
    "mcpServers": {
        "knossos": {
            "command": "/absolute/path/to/knossos/tools/mcp-serve",
            "env": {
                "KNOSSOS_DATA_DIR": "/absolute/knossos-data",
                "KNOSSOS_ROOTS_FILE": "/absolute/knossos-data/roots.json"
            }
        }
    }
}
```

Placement differs per client, so keep the command and the argument array
unchanged. Do not add `-t` to a Docker command, because terminal framing
corrupts the NDJSON. The Docker form of this entry is under
[Docker](../get-started/installation.md#docker).

The roots file is the allow-list, re-read on every request, so granting another
project needs no restart:

```sh
knossos allow-root /absolute/path --execute
```

Once the server answers, [agent integration](agent-integration.md) covers the
tools that orient an agent before it greps.
