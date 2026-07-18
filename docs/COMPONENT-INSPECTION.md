# Component inspection

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
