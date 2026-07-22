# Architecture context

`architecture_context` assembles a deterministic, bounded evidence bundle for
a coding task. It combines the project summary, likely boundaries, explicit
changed-file impact, and a small set of component dossiers without executing
target-project code.

Supply a task description, changed files, or both:

```json
{
    "project_id": "project_...",
    "task_description": "add checkout refund support",
    "files": ["src/Checkout.php"],
    "max_chars": 30000
}
```

The equivalent CLI command is:

```sh
knossos architecture-context project_... src/Checkout.php \
  --task="add checkout refund support" --max-chars=30000 --json
```

The character budget is split explicitly across summary, location, impact, and
dossier sections. Each section reports whether it was included, truncated, not
requested, or omitted to preserve the total limit. Responses also report the
actual serialized context size and allocation metadata.

Ranking remains static and deterministic when no optional semantic provider is
available. The bundle is evidence for navigation and review, not proof of
runtime behavior; dynamic dispatch and generated code can remain unresolved.

## Source snippets

Set `include_source: true` (or `--include-source` on the CLI) to inline a
bounded code window (≤40 lines) for each included dossier's primary evidence
location, alongside its `inspectComponent` serialization as a sibling
`snippet` key. Each snippet is either `{status: 'included', path, start_line,
end_line, code}` or `{status: 'unavailable', reason}` when the file is
missing, outside the project root, or the recorded line range no longer
exists.

Unlike the rest of the bundle, snippets are read from the working tree at
query time rather than from the scanned graph, so they may drift from the
graph's evidence if the working tree has changed since the last scan.
Snippets still count against the dossier section's character budget; a large
dossier section can still be truncated or omitted under `max_chars`.
