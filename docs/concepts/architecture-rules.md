# Declared rules and budgets

Two tools turn architectural intent into something a build can fail on:
`check_architecture` evaluates boundary dependency rules against the current
graph, and `quality_gate` compares the current graph with a reviewed baseline
snapshot. Both are also composed into
[`review_diff`](change-review.md#review-diff), which scopes their findings to
one change set.

Both read their declarations from files you check in, so the rules live beside
the code they govern. `knossos.json` holds them directly, as `policies` and
`quality_budgets`; see [project configuration](../get-started/project-configuration.md).
Boundaries are explained in [the graph and its evidence](graph-and-evidence.md#boundaries-and-roles).

## Boundary policies

`check_architecture` evaluates explicit boundary dependency rules against the
active static graph. A policy names its source boundary by stable ID or by an
unambiguous name and declares allowed and/or forbidden target boundaries.
Internal dependencies within the source boundary are implicitly allowed unless
that boundary is explicitly denied. Use `@unassigned` to match targets without
boundary membership.

A policy takes these fields and no others: `id` (required, unique, at most 100
bytes), `from_boundary` (required), `allow_targets`, `deny_targets` (at least
one of the two) and `edge_kinds`. When you leave out `edge_kinds`, every
dependency relationship counts. At most 50 policies can be evaluated at once.

```json
[
    {
        "id": "domain-isolation",
        "from_boundary": "Domain",
        "allow_targets": ["Domain", "Shared"],
        "deny_targets": ["Infrastructure", "@unassigned"],
        "edge_kinds": ["calls", "imports", "depends_on", "constructs"]
    }
]
```

Without a file, the CLI checks the policies the project declares in
`knossos.json`, and refuses to run when it declares none or the file cannot be
read. `--policies` reads them from a bounded JSON file instead. The project is
a path inside it or its ID:

```sh
knossos check-architecture . --json
knossos check-architecture . --policies=policies.json --json
knossos policies .
```

`knossos policies` shows the boundaries and policies the project declares. The
equivalent MCP tool accepts the JSON array as `policies`, and without it checks
the declared ones too. Optional
`min_confidence`, `limit`, `max_edges`, and `timeout_ms` inputs control the
evaluation. Boundary names that resolve to both explicit and inferred
boundaries are rejected; use the stable ID returned by `list_boundaries`.

Findings include the policy ID, the violating relationship (with its kind,
confidence and origin), both components, the boundary memberships on each side,
the `reasons`, and source evidence in the envelope. `bounds.violation_count` is
the exact total even when `limit` cuts the list. Findings describe
the indexed static graph, not runtime enforcement, and may miss dependencies
created through reflection, configuration, framework conventions, or dynamic
dispatch.

## Quality budgets

`quality_gate` compares the active graph with a complete retained baseline
(see [scan history](history.md)) and evaluates only the limits supplied by the
caller. Each limit is an integer from 0 to 100000, and the baseline must differ
from the active snapshot. Supported budgets are:

| Budget                                     | What it limits                                                                         |
| ------------------------------------------ | -------------------------------------------------------------------------------------- |
| `new_cycles`                               | Cycles beyond the baseline's count.                                                    |
| `boundary_violations`                      | Policy violations in the active graph. Needs `policies`.                               |
| `error_diagnostics`, `warning_diagnostics` | Diagnostics of that severity in the active scan.                                       |
| `hub_degree_growth`                        | How far the highest component degree grew past the baseline's.                         |
| `unreferenced_candidates`                  | Dead-code candidates in the active graph.                                              |
| `public_surface_changes`                   | Routes, commands, endpoints, exports and public or entry-point roles added or removed. |

Example budget file:

```json
{
    "new_cycles": 0,
    "boundary_violations": 0,
    "error_diagnostics": 0,
    "hub_degree_growth": 4,
    "public_surface_changes": 0
}
```

Run it with a reviewed retained baseline:

```sh
knossos quality-gate project_... scan_... \
  --budgets=knossos-budgets.json --policies=architecture-policies.json --json
```

The command exits nonzero when any budget fails. A `boundary_violations` check
whose policy scan hit its edge or time bound is marked `indeterminate` and
fails, because a count taken from a partial scan is only a lower bound.
`--sarif` embeds SARIF 2.1.0
for findings with sound file mappings, currently boundary-policy evidence and
scanner diagnostics. Other metrics remain structured JSON because assigning a
single source location would be misleading.

`--propose-baseline` returns the current metrics as `proposed_baseline`, a
reviewable proposal. It never
writes, updates, or suppresses a checked-in baseline automatically; adopting a
proposal remains an explicit repository change.

Cycle and degree metrics use the same bounded static dependency kinds as other
Knossos analyses. Unreferenced and public-surface results are conservative
static signals and may not capture dynamic framework behavior.

Wiring both into CI, with exit codes and SARIF upload, is covered in
[CI and editor integration](../operate/ci-editor-integration.md).
