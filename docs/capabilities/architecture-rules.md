# Declared rules and budgets

Two tools turn architectural intent into something a build can fail on:
`check_architecture` evaluates boundary dependency rules against the current
graph, and `quality_gate` compares the current graph with a reviewed baseline
snapshot. Both are also composed into
[`review_diff`](change-review.md#review-diff), which scopes their findings to
one change set.

Both read their declarations from files you check in, so the rules live beside
the code they govern. `knossos.json` can hold them directly; see
[project configuration](../guides/project-configuration.md).

## Boundary policies

`check_architecture` evaluates explicit boundary dependency rules against the
active static graph. A policy names its source boundary by stable ID or by an
unambiguous name and declares allowed and/or forbidden target boundaries.
Internal dependencies within the source boundary are implicitly allowed unless
that boundary is explicitly denied. Use `@unassigned` to match targets without
boundary membership.

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

CLI usage reads the declaration from a bounded JSON file:

```sh
knossos check-architecture PROJECT_ID --policies=policies.json --json
```

The equivalent MCP tool accepts the JSON array as `policies`. Optional
`min_confidence`, `limit`, `max_edges`, and `timeout_ms` inputs control the
evaluation. Boundary names that resolve to both explicit and inferred
boundaries are rejected; use the stable ID returned by `list_boundaries`.

Findings include the policy ID, violating relationship, both components,
boundary memberships, reason, confidence, and source evidence. They describe
the indexed static graph, not runtime enforcement, and may miss dependencies
created through reflection, configuration, framework conventions, or dynamic
dispatch.

## Quality budgets

`quality_gate` compares the active graph with a complete retained baseline and
evaluates only the limits supplied by the caller. Supported budgets are:

- `new_cycles`
- `boundary_violations`
- `error_diagnostics` and `warning_diagnostics`
- `hub_degree_growth`
- `unreferenced_candidates`
- `public_surface_changes`

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

The command exits nonzero when any budget fails. `--sarif` embeds SARIF 2.1.0
for findings with sound file mappings, currently boundary-policy evidence and
scanner diagnostics. Other metrics remain structured JSON because assigning a
single source location would be misleading.

`--propose-baseline` returns current metrics as a reviewable proposal. It never
writes, updates, or suppresses a checked-in baseline automatically; adopting a
proposal remains an explicit repository change.

Cycle and degree metrics use the same bounded static dependency kinds as other
Knossos analyses. Unreferenced and public-surface results are conservative
static signals and may not capture dynamic framework behavior.

Wiring both into CI, with exit codes and SARIF upload, is covered in
[CI and editor integration](../guides/ci-editor-integration.md).
