# Diagram source export

`export_diagram` emits deterministic Mermaid flowchart or PlantUML component
source for the active static graph. It never invokes a renderer or writes an
output file. Use `format`, `direction`, optional boundary ID/name,
`edge_kinds`, `min_confidence`, `max_nodes`, and `max_edges` to control scope.

Stable local aliases keep generated syntax safe while human labels are escaped.
An ambiguous boundary name is rejected; use its stable ID. The response carries
the diagram string, source evidence, exact exported counts, and node/edge
truncation reasons. A CLI call without `--json` prints the raw diagram:

```sh
knossos export-diagram PROJECT_ID --format=mermaid --boundary=BOUNDARY_ID
```

The export is a bounded view of indexed static facts, not a runtime trace. It
may omit dynamic relationships and is explicitly incomplete when truncated.
