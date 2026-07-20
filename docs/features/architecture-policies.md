# Declared architecture policies

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
the indexed static graph—not runtime enforcement—and may miss dependencies
created through reflection, configuration, framework conventions, or dynamic
dispatch.
