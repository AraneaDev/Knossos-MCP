# Finding and reading components

The read surface: locate a project, locate a component inside it, read that
component, and list every place a symbol is used. Every tool here is annotated
read-only and idempotent, and every one returns the shared
[response envelope](../reference/response-envelopes.md).

| Tool                   | CLI                    | Answers                                             |
| ---------------------- | ---------------------- | --------------------------------------------------- |
| `list_projects`        | `list-projects`        | Which projects are scanned, how fresh, how large.   |
| `find_component`       | `find-component`       | Ranked candidates when you know part of a name.     |
| `inspect_component`    | `inspect-component`    | One component's roles, relations, and evidence.     |
| `list_usages`          | `list-usages`          | Every usage site of a symbol, with `path:line`.     |
| `architecture_summary` | `architecture-summary` | A one-call overview by language and node/edge kind. |
| `search_architecture`  | `search-architecture`  | Components filtered by kind, role, boundary.        |
| `file_metrics`         | `file-metrics`         | Files ranked by line count or path.                 |
| `list_boundaries`      | `list-boundaries`      | How the codebase is partitioned.                    |
| `export_diagram`       | `export-diagram`       | Mermaid or PlantUML source for the current graph.   |

## Project catalogue

Knossos stores stable project IDs in SQLite. Use the catalogue instead of
rescanning merely to recover an ID:

```sh
knossos list-projects --limit=50 --offset=0 --json
```

The equivalent MCP tool is `list_projects`:

```json
{
    "limit": 50,
    "offset": 0,
    "include_roots": false
}
```

Absolute project roots are omitted by default because clients normally need
the stable ID, name, snapshot, freshness, and counts. Opt in with
`--include-roots` on the CLI or `include_roots: true` through MCP when the
local path is necessary.

Each result includes active and latest scan metadata plus file, node, edge, and
diagnostic counts. `freshness` has these stable values:

| Value                   | Meaning                                                                        |
| ----------------------- | ------------------------------------------------------------------------------ |
| `ready`                 | The active snapshot is complete and its local root is available.               |
| `unscanned`             | The project exists but has no active snapshot.                                 |
| `scan_in_progress`      | A newer scan is running while the previous snapshot remains queryable.         |
| `latest_scan_failed`    | A newer scan failed; the previous snapshot remains active.                     |
| `latest_scan_cancelled` | A newer scan was cancelled; the previous snapshot remains active.              |
| `root_unavailable`      | The active graph is queryable, but its recorded root is not currently mounted. |

Results are ordered by most recently updated project and then stable project
ID. Pagination returns `next_offset` and `result_limit` when another page is
available. Limits are bounded to 100 projects per request and offsets to
100,000.

## Component inspection

Use `inspect_component` after search when a coding task needs one bounded,
model-friendly component dossier rather than several graph queries:

```json
{
    "project_id": "project_...",
    "component": "symbol_...",
    "max_relationships": 25,
    "max_children": 25,
    "min_confidence": "possible"
}
```

The equivalent CLI command is:

```sh
knossos inspect-component project_... symbol_... \
  --max-relationships=25 --max-children=25 --json
```

`component` accepts a stable node ID or a name. A unique match returns identity,
attributes, roles, boundaries, parent, children, source evidence, and separately
bounded incoming and outgoing relationships. An ambiguous name returns
`ambiguous: true` and candidates without silently selecting one; a missing name
returns a null component.

Relationship limits apply independently in each direction. `truncation_reasons`
uses `child_limit`, `incoming_relationship_limit`, and
`outgoing_relationship_limit`. Confidence filtering accepts `possible`,
`probable`, or `certain`; a stricter level omits weaker static facts rather than
upgrading their confidence.

## List usages

Use `list_usages` when you need every call/reference site of a symbol with
file:line evidence: the grep killer. Because edges are occurrence-level
facts (their stable id includes the evidence location), this is a direct
listing, not an aggregation: two calls from the same caller to the same
callee on different lines are two distinct rows, not one merged edge.

```json
{
    "project_id": "project_...",
    "symbol": "App\\InvoiceService",
    "edge_kinds": [],
    "min_confidence": "possible",
    "limit": 100
}
```

The equivalent CLI command is:

```sh
knossos list-usages project_... App\\InvoiceService \
  --min-confidence=possible --limit=100 --json
```

`symbol` accepts a stable node ID or a name; ambiguous names return
`ambiguous: true` with candidates rather than silently picking one, and an
unmatched name returns an empty `candidates` list without a `target` key.

### Occurrence semantics

Each row in `usages` is one edge: `edge_id`, `kind`, `confidence`, `origin`,
the calling `source` component (id, kind, canonical/display name), and the
evidence location (`path`, `start_line`, `end_line`). Rows are ordered by
path, then start line, then edge id, so results are deterministic across
repeated calls against the same snapshot.

### The `contains` exclusion

`edge_kinds` defaults to `IMPACT_EDGE_KINDS` (the same dependency-relationship
set used by `impact_analysis` and friends), which deliberately excludes
`contains`. A method's own class containment isn't a "usage" of that method,
so it never shows up here even though it is a real edge in the graph. Passing
`contains` explicitly in `edge_kinds` is rejected with an
`InvalidArgumentException`.

### Limits

- `limit` accepts 1–500 (default 100). Exceeding the available rows sets
  `bounds.truncation_reasons` to `["result_limit"]` and `truncated: true` on
  the envelope.
- `edge_kinds` accepts at most 20 kinds, each drawn from the impact edge kind
  set; anything else is rejected.
- `min_confidence` accepts `possible` (default), `probable`, or `certain`; a
  stricter level omits weaker static facts rather than upgrading their
  confidence.

Unlike `impact_analysis`, which walks the transitive dependency graph,
`list_usages` shows only the direct, one-hop occurrences: the literal call
sites you'd otherwise have to grep for.

## Diagram source export

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
