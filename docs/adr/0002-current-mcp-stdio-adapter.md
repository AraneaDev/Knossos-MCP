# ADR 0002: Isolate a current MCP stdio adapter

Status: Accepted (2026-07-17)

## Context

Phase 1 needs a PHP-hosted MCP stdio server. The evaluated community PHP SDK,
`php-mcp/server` 3.3.0, targets MCP `2025-03-26`, while the current protocol is
`2025-11-25`. Adding it would also pull transport and framework dependencies
that the three-tool stdio surface does not need.

References: [MCP lifecycle](https://modelcontextprotocol.io/specification/2025-11-25/basic/lifecycle),
[MCP tools](https://modelcontextprotocol.io/specification/2025-11-25/server/tools),
and the [official Inspector](https://github.com/modelcontextprotocol/inspector).

## Decision

Implement the small required MCP subset behind `Knossos\Mcp\StdioServer` and
`ToolService`: initialization/version negotiation, initialized notification,
ping, `tools/list`, and `tools/call`. Pin the adapter contract to MCP
`2025-11-25`. Standard output contains NDJSON protocol frames only; diagnostics
go to standard error.

Tool business logic is transport-neutral. This makes replacing the adapter
with an official or current PHP SDK a contained change when one meets the
runtime, dependency, and protocol requirements.

## Consequences

- Knossos interoperates with the current stdio lifecycle without a stale SDK.
- The adapter must maintain protocol conformance tests.
- HTTP, resources, prompts, sampling, and task-augmented execution are outside
  this phase and require a later contract decision.
