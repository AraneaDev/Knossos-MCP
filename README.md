# Knossos MCP

Knossos is a local-first MCP server that scans a repository once and answers
architecture questions from an evidence-backed graph, so an agent stops
re-reading the whole source tree to work out what depends on what.

Every fact points back to a file and a source location. Facts that static
analysis cannot prove are labelled with their confidence and origin instead of
being guessed. Nothing in the scan pipeline installs dependencies, imports a
module, or boots an application framework.

## What you can ask after one scan

- What are the major modules, entry points, and boundaries?
- What depends, directly or transitively, on `UserRepository`?
- How can a checkout request reach invoice generation?
- Which relationships cross a declared boundary policy?
- Where would a refunds feature fit the existing structure?
- What did this branch's changed files just put at risk?

Every capability is available both as an MCP tool and as an equivalent CLI
command. See the [documentation index](docs/README.md) for the full map.

## Quick start

The recommended distribution is Docker: it pins PHP 8.4, Node 24, Python,
Composer, SQLite, the PHP parser, and the TypeScript compiler, so the scanned
project needs none of them.

```sh
docker build -t knossos-mcp:dev .
docker run --rm knossos-mcp:dev doctor --json
```

Scan a project with networking disabled and the source mounted read-only:

```sh
docker run --rm --network none \
  --mount type=bind,source=/absolute/project,target=/workspace,readonly \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev scan /workspace --json
```

Recover persisted project IDs later without exposing absolute roots:

```sh
docker run --rm \
  --mount type=volume,source=knossos-data,target=/data \
  knossos-mcp:dev list-projects --json
```

Register the server with an MCP client, and grant the narrowest readable tree:

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

Docker, native, and client-specific variants are in
[installation](docs/guides/installation.md).

## Supported languages

| Language              | Extraction                                                             | Framework enrichment                                                               |
| --------------------- | ---------------------------------------------------------------------- | ---------------------------------------------------------------------------------- |
| PHP 8.3–8.4           | Declarations, inheritance, calls, construction, types, injection       | [Laravel](docs/languages/php-laravel.md), [Symfony](docs/languages/php-symfony.md) |
| TypeScript/JavaScript | Compiler symbol resolution, imports, calls, types, project references  | [Next.js, React, Vue, stores, endpoints](docs/languages/typescript.md)             |
| Python 3.11–3.13      | Standard-library AST in an isolated interpreter; never imports modules | [FastAPI, Django, Celery](docs/languages/python.md)                                |

Mixed repositories reconcile into one graph. Third-party scanners plug in as
isolated worker processes through the [scanner SDK](docs/reference/scanner-sdk.md).

## Safety model

- Scanning never installs dependencies, executes project code, or boots a
  framework; workers are supervised, resource-capped, and their output is
  untrusted until it passes schema and limit validation.
- `--allow-root` is a security boundary, not a convenience flag. `serve` refuses
  to start without one.
- The SQLite database is derived and rebuildable; source mounts stay read-only.
- MCP stdio is the default and recommended transport. The constrained
  loopback-only Streamable HTTP profile and its deployment limits are documented
  in the [HTTP threat model](docs/operations/http-threat-model.md).
- Failed work is never activated: the last complete scan remains the queryable
  graph. See the [fault recovery matrix](docs/operations/recovery-matrix.md).

## Documentation

[docs/README.md](docs/README.md) is the index. The most-used entries:

- [Installation and MCP configuration](docs/guides/installation.md)
- [Checked-in project configuration](docs/guides/project-configuration.md)
- [Running in Docker](docs/operations/container.md)
- [CLI reference](docs/reference/cli.md) and [MCP tool reference](docs/reference/mcp-tools.md)
- [Troubleshooting and migrations](docs/operations/troubleshooting-and-migrations.md)

## Development

One versioned quality profile runs locally, in Git hooks, and in CI:

```sh
tools/quality-container fast
tools/quality-container full
```

`fast` covers linting, static analysis, formatting, hygiene, and the whole test
suite; `full` adds security audits, coverage floors, performance budgets,
mutation score, and supply-chain assurance. Details are in
[quality gates](docs/development/quality.md).
