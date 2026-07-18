# Architecture quality budgets

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
