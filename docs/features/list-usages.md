# List usages

Use `list_usages` when you need every call/reference site of a symbol with
file:line evidence — the grep killer. Because edges are occurrence-level
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

## Occurrence semantics

Each row in `usages` is one edge: `edge_id`, `kind`, `confidence`, `origin`,
the calling `source` component (id, kind, canonical/display name), and the
evidence location (`path`, `start_line`, `end_line`). Rows are ordered by
path, then start line, then edge id, so results are deterministic across
repeated calls against the same snapshot.

## The `contains` exclusion

`edge_kinds` defaults to `IMPACT_EDGE_KINDS` — the same dependency-relationship
set used by `impact_analysis` and friends — which deliberately excludes
`contains`. A method's own class containment isn't a "usage" of that method,
so it never shows up here even though it is a real edge in the graph. Passing
`contains` explicitly in `edge_kinds` is rejected with an
`InvalidArgumentException`.

## Limits

- `limit` accepts 1–500 (default 100). Exceeding the available rows sets
  `bounds.truncation_reasons` to `["result_limit"]` and `truncated: true` on
  the envelope.
- `edge_kinds` accepts at most 20 kinds, each drawn from the impact edge kind
  set; anything else is rejected.
- `min_confidence` accepts `possible` (default), `probable`, or `certain`; a
  stricter level omits weaker static facts rather than upgrading their
  confidence.

Unlike `impact_analysis`, which walks the transitive dependency graph,
`list_usages` shows only the direct, one-hop occurrences — the literal call
sites you'd otherwise have to grep for.
