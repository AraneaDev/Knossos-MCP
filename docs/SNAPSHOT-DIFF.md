# Snapshot diff and architectural changelog

`snapshot_diff` compares two complete snapshots using stable persisted facts.
Either side may be a retained scan ID; `active` selects the project's current
normalized graph.

```json
{
    "project_id": "project_...",
    "from_snapshot": "scan_...",
    "to_snapshot": "active",
    "max_changes": 200
}
```

The CLI equivalent is:

```sh
knossos snapshot-diff project_... scan_... active --max-changes=200 --json
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
