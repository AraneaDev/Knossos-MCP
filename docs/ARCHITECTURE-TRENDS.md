# Architecture trends and release notes

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
