# Scanner and enricher SDK v1

Third-party scanners integrate as isolated newline-delimited JSON-RPC workers.
The stable contract is protocol `1.0` plus output schema `1.0`; workers are
processes, not in-process PHP plugins, so their implementation language and
dependency graph remain independent from Knossos core.

## Published artifacts

- [`scanner-protocol-v1.md`](scanner-protocol-v1.md) defines lifecycle,
  framing, cancellation, replacement ownership, and the trust boundary.
- [`manifest.schema.json`](../schemas/scanner/v1/manifest.schema.json) and
  [`contribution.schema.json`](../schemas/scanner/v1/contribution.schema.json)
  are JSON Schema 2020-12 contracts.
- `Knossos\Scanner\Sdk\FixtureBuilder` creates protocol-shaped nodes, edges,
  and contributions for PHP extension tests.
- [`golden.json`](../tests/Fixtures/scanner-sdk/golden.json) records lifecycle,
  required fields, notification name, and stable incompatibility errors.
- `tools/scanner-conformance` exercises initialization, capability negotiation,
  discovery, an empty scan, contribution validation, and shutdown.

Run a worker conformance check with an argument-safe command after `--`:

```sh
tools/scanner-conformance --require=discover -- python3 worker.py
```

The command prints JSON and exits nonzero for malformed manifests, unsupported
protocol/schema versions, missing required capabilities, invalid
contributions, lifecycle errors, or unsafe protocol output.

## Compatibility rules

Workers declare their own semantic version independently. Core rejects a
different protocol or output-schema major/minor before sending scan paths.
Consumers may require named optional capabilities with
`ProcessScannerClient::requireCapabilities()`; missing capabilities fail as
`WORKER_CAPABILITY_MISMATCH` before discovery or scan. Unknown optional
capabilities may be ignored unless a consumer explicitly requires them.

Every contribution owns its facts through a stable `owner_key`. Re-emission
replaces that owner's facts. IDs must be deterministic, evidence paths must be
project-relative, and repeated edges should be collapsed to the persistence
identity of kind/source/target within one owner.

## Security contract

Workers receive only validated roots, relative paths, and bounded limits. They
must not import or execute target code, run package lifecycle hooks, install
dependencies, follow paths outside the allowed root, or write to the scanned
project. Output remains untrusted and is decoded under core byte, row, time,
and schema limits.
