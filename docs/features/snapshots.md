# Retained scan snapshots

Knossos keeps the active graph in its original normalized tables for fast,
compatible reads. Before a successful rescan replaces that graph, it captures
the previous active scan as an immutable, versioned JSON fact set in the same
transaction.

The default retention is five prior snapshots. Projects may set
`snapshot_retention` from `0` through `20` in their persisted scan
configuration; zero disables history. Activation prunes older archives and
their unreferenced completed scan records atomically.

List available metadata with:

```sh
knossos list-snapshots project_... --json
```

Or call `list_snapshots` over MCP. Results distinguish the active normalized
scan from retained archives and include scanner/config fingerprints, timing,
fact count, byte size, and archive completeness.

Each fact table is capped at 200,000 rows and a complete archive at 50 MB. An
oversized graph retains an explicit incomplete metadata record instead of
silently presenting partial facts as complete. Incomplete snapshots are useful
for audit timing and fingerprints but are not eligible for full snapshot diffs.

Comparing retained scans is documented in [snapshot diff](snapshot-diff.md).
