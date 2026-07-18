# ADR 0001: PHP core with isolated language scanner workers

Status: Accepted for the Phase 1 foundation  
Date: 2026-07-17

## Context

Knossos must analyse PHP and TypeScript as first-class languages while keeping
storage, graph queries, and MCP delivery independent from parser ecosystems.
The original plan recommends PHP for the server. TypeScript semantic analysis
requires the TypeScript compiler and project-wide configuration.

## Decision

- Implement the orchestration, graph store, query engine, CLI, and initial MCP
  adapter as a PHP package compatible with PHP 8.3 and 8.4.
- Analyse PHP 8.4 syntax in an isolated PHP scanner worker.
- Analyse TypeScript in an isolated Node scanner worker using the TypeScript
  compiler API.
- Use one versioned NDJSON JSON-RPC protocol for every scanner worker.
- Keep MCP SDK types out of core and scanner contracts. Select and pin the SDK
  in P1-T08 after a compatibility spike.

PHP 8.3 compatibility applies to the Knossos core runtime; it does not limit the
PHP syntax version a version-matched scanner can parse.

## Consequences

- Scanner failures and resource usage can be supervised without corrupting the
  active graph.
- New language plugins do not have to be implemented in PHP.
- Installation needs both PHP and Node for the initial two scanners unless
  workers are later distributed as bundled executables.
- Serialization and worker startup add overhead, which streaming and persistent
  workers should keep bounded.
- The core-runtime choice may be revisited before the first stable release if
  the MCP SDK spike exposes a blocking compatibility problem.
