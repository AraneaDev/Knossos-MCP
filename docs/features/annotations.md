# Component annotations

Use `annotate_component` to record a durable, agent-written note on a
component and `list_annotations` to read them back. Unlike everything else in
the graph, annotations are not derived from a scan: they are agent
write-backs, kept in their own table and keyed by canonical name rather than
node id.

```json
{
    "project_id": "project_...",
    "component": "App\\Checkout",
    "kind": "note",
    "value": "core flow"
}
```

The equivalent CLI command is:

```sh
knossos annotate-component project_... App\\Checkout note "core flow" --execute --json
knossos list-annotations project_... --json
```

## Kinds

`kind` is one of:

- `intended_boundary` — this component's placement is deliberate; do not
  flag it as misplaced.
- `confirmed_dead` — a human or agent has verified this component is unused,
  beyond what static analysis alone can prove.
- `false_positive` — this component was wrongly flagged (for example, by
  `architecture_health`'s dead-code candidates); read surfaces that consume
  annotations use this to stop re-surfacing it.
- `note` — a free-form annotation with no special read-side effect.

## Survival across rescans

Every scan drops and rebuilds `nodes` (and everything keyed to a node id),
because node ids are not stable across scans. Annotations are keyed by
`(project_id, canonical_name, kind)` instead, with no foreign key to `nodes`,
so a full rescan that regenerates the graph does not lose them. Only removing
the project itself cascades the cleanup (`ON DELETE CASCADE` on `project_id`).

## Preview convention

`annotate_component` previews by default; pass `execute: true` to apply.
`remove: true` deletes the `(component, kind)` pair instead of writing it.
Writing the same `(component, kind)` again is an upsert — the existing value
and `updated_at` are replaced, `created_at` is not. The response's `previous`
field carries the annotation as it stood before the write (or `null`), so a
caller can tell an upsert from a fresh insert.

`component` resolves the same way as other component-accepting tools: an
exact canonical or display name match, or a unique name prefix. An ambiguous
prefix is rejected with candidates rather than silently picking one. A name
that does not resolve to any node in the current graph is still accepted —
the response carries a warning ("...not found...") because the target may be
a symbol the scanner does not see yet, or one that will exist after a
planned change.

## Reading annotations

`list_annotations` returns rows ordered by canonical name, then kind, with
`value`, `author`, `created_at`, and `updated_at`. Filter by `component`
(exact canonical name) or `kind`; `limit` (1–100, default 100) and `offset`
paginate.

## Not exported in graph bundles

Annotations are intentionally outside `export-bundle`/`import-bundle`'s table
list: bundles move a derived graph between databases, and annotations are
agent-authored state tied to a specific project's history, not a scan
artifact. Moving or replaying a bundle does not carry annotations with it.

This table exists so other query surfaces can read agent-recorded ground
truth. `annotate_component` and `list_annotations` only write and read the
table itself; which tools consume `false_positive` and `confirmed_dead`
annotations, and how, is documented on those tools once they do.
