# Scan history

Every successful rescan archives the graph it replaced, so architecture can be
compared over time rather than only inspected in the present. Three tools read
that history.

| Tool                  | CLI                   | Answers                                          |
| --------------------- | --------------------- | ------------------------------------------------ |
| `list_snapshots`      | `list-snapshots`      | The retained scan history for a project.         |
| `snapshot_diff`       | `snapshot-diff`       | What changed architecturally between two scans.  |
| `architecture_trends` | `architecture-trends` | How metrics moved, plus generated release notes. |

## Retained snapshots

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

## Snapshot diff

`snapshot_diff` compares two complete snapshots using stable persisted facts.
Either side may be a retained scan ID; `active` selects the project's current
normalized graph.

```json
{
    "project_id": "project_...",
    "from_snapshot": "scan_...",
    "to_snapshot": "active",
    "max_changes": 25
}
```

The CLI equivalent is:

```sh
knossos snapshot-diff project_... scan_... active --max-changes=25 --json
```

The changelog separates added, removed, changed, and moved components along
with relationship, role, boundary, membership, and diagnostic changes. It also
counts confidence increases and decreases. Every category has deterministic
ordering, while `max_changes` applies a global output cap and reports both the
total and reported counts.

Rename candidates require a unique removed/added component pair with exactly
the same kind and display name. They are labelled `possible` and include the
heuristic name; they are navigation hints rather than asserted identity.

Only complete archives can be diffed. Oversized or unavailable retained facts
return an explicit error instead of silently comparing partial data. Static
facts may still miss runtime-generated architecture.

## Trends and release notes

`architecture_trends` reports a bounded chronological series across the active
and retained snapshots. Each complete point contains component, relationship,
role, boundary, and diagnostic counts plus static cycles, maximum degree,
diagnostic severity, and unreferenced-candidate metrics.

```sh
knossos architecture-trends project_... --limit=10 --json
```

Add `--release-from=scan_...` to compare that retained baseline with the active
graph and include deterministic Markdown release notes:

```sh
knossos architecture-trends project_... \
  --release-from=scan_... --limit=10 --json
```

Release notes include bounded structured change details alongside counts for
components, relationships, moves, and confidence changes. When more than 100
details exist, the Markdown explicitly reports truncation.

Incomplete retained snapshots remain visible as incomplete timeline points but
are not interpreted as metric data. Scanner or configuration fingerprint
changes are included because they can affect comparability even when source
architecture did not change.
