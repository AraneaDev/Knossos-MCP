# Structure analysis

Five tools walk the graph rather than list it: what depends on a symbol, how
one component reaches another, which modules are tangled, where the structural
hotspots are, and where new code belongs. Full input schemas are in the
[MCP tool reference](../reference/mcp-tools.md).

| Tool                  | CLI                   | Answers                                                     |
| --------------------- | --------------------- | ----------------------------------------------------------- |
| `impact_analysis`     | `impact-analysis`     | What depends on a symbol, with the edge that proves it.     |
| `explain_flow`        | `explain-flow`        | How A reaches B, as ranked evidence-backed paths.           |
| `dependency_cycles`   | `dependency-cycles`   | Circular dependencies as bounded strongly connected groups. |
| `architecture_health` | `architecture-health` | Hubs, hotspots, and dead-code candidates.                   |
| `suggest_location`    | `suggest-location`    | Where new code for a feature belongs.                       |

Every one of them is bounded the same way: `max_depth` or `max_nodes`/
`max_edges` cap the walk, `timeout_ms` caps the time, `min_confidence` filters
weaker static facts (it never upgrades them), and `edge_kinds` narrows which
relationships count. Exceeding a bound sets `truncated` on the
[envelope](../reference/response-envelopes.md) and records why in `bounds`; it
never silently returns a partial answer as a complete one.

## Impact analysis

`impact_analysis` walks the graph backwards from a symbol to everything that
statically depends on it, up to `max_depth` (1–8, default 4) hops.

```sh
knossos impact-analysis project_... 'Knossos\Scanner\ScannerClient' --json
```

`dependants` is a flat, BFS-ordered list rather than grouped by distance; sort
or filter on the `distance` and `path_confidence` fields directly. Each entry
carries the `via` edge that justifies it, including its `origin` and the
`path`/`start_line` of the source location, so the answer is checkable rather
than asserted. `counts` breaks the result down `by_distance` and
`by_confidence`.

The result always carries the warning "Impact is a conservative static blast
radius; it does not guarantee that a dependant will break." Reflection,
configuration-driven wiring, and dynamic dispatch are not visible to a static
scan.

## Explain flow

`explain_flow` answers "how does a request here reach code there?" by tracing
static paths between two components, returning up to `max_paths` (1–20, default 5) of them within `max_depth` (1–8, default 6) hops.

```sh
knossos explain-flow project_... 'App\Http\CheckoutController' 'App\Billing\InvoiceGenerator' --json
```

Each path is a sequence of edges with the same evidence a single relationship
carries, ranked so the shortest and most confident paths come first. A pair
with no static path returns an empty path list rather than an error: absence of
a proven route is a real answer, and not the same as proof that no route
exists.

## Dependency cycles

`dependency_cycles` reports circular dependencies as bounded strongly connected
groups rather than as one enumerated cycle per path, which keeps the output
proportional to the tangle instead of exponential in it.

```sh
knossos dependency-cycles project_... --limit=20 --json
```

Self-loops are excluded unless `--include-self-loops` is passed: a recursive
function is a real self-edge and almost never what someone hunting circular
imports is looking for.

## Architecture health

`architecture_health` ranks hubs (the most depended-on components), hotspots,
and dead-code candidates in one call. Test-role components and, unless
`include_tests`/`include_external` say otherwise, external and unresolved
components are excluded from the rankings: an unfiltered hub list is dominated
by framework symbols and says nothing about your own structure.

```sh
knossos architecture-health project_... --limit=20 --json
```

The dead-code half of the result is the subtlest thing Knossos reports, and it
splits what nothing references from what only tests reach. What it excludes
before reporting, and why, is documented separately in
[dead-code candidates](dead-code-candidates.md).

## Suggest location

`suggest_location` ranks existing boundaries by how well a described feature
fits them, so a new file lands somewhere cohesive instead of wherever the
author happened to be.

```sh
knossos suggest-location project_... "refund a completed checkout" --json
```

The response shows its factors rather than a bare score, so a ranking you
disagree with can be argued with.

### Optional semantic ranking

`suggest_location` defaults to `ranking_mode: deterministic`. This mode is
offline, reproducible, inspectable, and always available.

Library integrators may inject an implementation of
`Knossos\Query\SemanticRanker` into `ArchitectureQueryService` and request
`semantic_if_available`. The provider receives only the bounded feature text
and candidate boundary text and must return one finite normalized score from 0
through 1 for every candidate within the supplied timeout. Knossos adds at most
20 semantic points to the deterministic factor score and reports the provider,
applied mode, and semantic factor.

The packaged CLI/MCP runtime deliberately configures no external provider, so
an opt-in semantic request reports `provider_unavailable` and returns the exact
deterministic ordering. Missing candidates, extra candidates, non-numeric or
out-of-range scores, exceptions, and deadline overruns produce the same exact
fallback with a bounded reason. Project code is never sent or executed by the
core; a custom provider is responsible for its own data-handling policy.
