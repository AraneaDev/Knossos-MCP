# Scanner and enricher SDK v1

Third-party scanners integrate as isolated newline-delimited JSON-RPC workers.
The stable contract is protocol `1.0` plus output schema `1.0`; workers are
processes, not in-process PHP plugins, so their implementation language and
dependency graph remain independent from Knossos core.

## Published artifacts

- [`scanner-protocol-v1.md`](scanner-protocol-v1.md) defines lifecycle,
  framing, cancellation, replacement ownership, and the trust boundary.
- [`manifest.schema.json`](../../schemas/scanner/v1/manifest.schema.json) and
  [`contribution.schema.json`](../../schemas/scanner/v1/contribution.schema.json)
  are JSON Schema 2020-12 contracts.
- `Knossos\Scanner\Sdk\FixtureBuilder` creates protocol-shaped nodes, edges,
  and contributions for PHP extension tests.
- [`golden.json`](../../tests/Fixtures/scanner-sdk/golden.json) records lifecycle,
  required fields, notification name, and stable incompatibility errors.
- `tools/scanner-conformance` exercises initialization, capability negotiation,
  an empty scan, contribution validation, and shutdown.

Run a worker conformance check with an argument-safe command after `--`:

```sh
tools/scanner-conformance --require=partial_ast -- python3 worker.py
```

The command prints JSON and exits nonzero for malformed manifests, unsupported
protocol/schema versions, missing required capabilities, invalid
contributions, lifecycle errors, or unsafe protocol output.

## Compatibility rules

Workers declare their own semantic version independently. Core rejects a
different protocol or output-schema major/minor before sending scan paths.
Consumers may require named optional capabilities with
`ProcessScannerClient::requireCapabilities()`; missing capabilities fail as
`WORKER_CAPABILITY_MISMATCH` before any scan. Unknown optional
capabilities may be ignored unless a consumer explicitly requires them.

The `content_hash` capability promises that every contribution for a file the
worker read carries the SHA-256 of those raw bytes; see
[`scanner-protocol-v1.md`](scanner-protocol-v1.md). `tools/scanner-conformance`
scans one fixture file, which starts with a byte-order mark, and checks that
promise whenever a worker declares it. When you add the capability to an
existing worker, bump its version in the same change: cache hits are not
verified again, so only a version change purges the contributions it cached
before it hashed. Facts without a hash degrade your worker's whole language
for that scan, and an unhashed contribution with only diagnostics is kept but
never cached.

The `input_hashes` capability promises the same, per request rather than per
contribution, for every project file the worker read while deriving a
request's facts, the requested files included, and with `null` for a read it
attempted that failed or a lookup that decided facts and found nothing; see
`scanner-protocol-v1.md` for the field, how to key reads through links and
probes, the `scan/input_hashes` notification for a map too large for one
frame, and the verification rules. `tools/scanner-conformance` checks that a
declaring worker's empty scan and its one-file fixture scan both carry the
field, that the fixture's hash matches, and that the whole map passes the
core's own check against the fixture's discovery.

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
