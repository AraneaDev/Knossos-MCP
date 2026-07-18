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
