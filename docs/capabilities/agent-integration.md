# Agent integration

Three surfaces exist for coding agents rather than for people: a paste-ready
orientation brief, a bounded task-shaped evidence bundle, and durable
annotations an agent can write back to the graph. Two more have their own
pages: the brief injected at the start of a Claude Code session
([session brief](session-brief.md)), and the skill that decides which
questions reach any of these tools at all
([the routing skill](agent-skill.md)).

| Tool                   | CLI                    | Answers                                           |
| ---------------------- | ---------------------- | ------------------------------------------------- |
| `export_agent_brief`   | `export-agent-brief`   | Markdown orientation to paste into a memory file. |
| `architecture_context` | `architecture-context` | A bounded evidence bundle for one coding task.    |
| `annotate_component`   | `annotate-component`   | Record a durable judgment on a component.         |
| `list_annotations`     | `list-annotations`     | Read those judgments back.                        |

The server also exposes MCP-protocol surfaces with no CLI equivalent:
per-project resources at `knossos://<project_id>/summary`, `/boundaries`, and
`/brief` (the first two JSON, the last the same markdown
`export_agent_brief` renders), plus the `orient` and `review_diff` prompts.

## Refreshing a stale graph without asking twice

`refresh_if_stale` is accepted by every read tool, and it defaults to
`true`, so a call that lands on a stale graph rescans before
answering instead of leaving you to read a staleness banner, call
`scan_project`, and ask again: the round trip a session would otherwise spend
discovering it needed a fresh graph.

The rescan only runs when it is cheap enough to fit inside the call you are
already waiting on; a project whose own scan history says it would cost more
than a 5000 ms budget gets the stored graph and a warning instead, naming
`scan_project` as the next step. Set `KNOSSOS_AUTO_REFRESH=0` on the server
process to turn the default off everywhere. Either way, an explicit
`refresh_if_stale` argument on a call always wins over both the default and
the kill switch, so passing `false` still gets you the stored graph exactly
as stored. Full detail on the budget and the warning's shape is in
[response envelopes](../reference/response-envelopes.md#refreshing-a-stale-graph).

Because of this, the read tools that declare `refresh_if_stale` are no longer
annotated `readOnlyHint: true`: a call to any of them can write Knossos's own
graph and spawn language worker subprocesses when it repairs a stale project
first, so a client that uses the annotation to decide whether to ask before
calling should treat these tools accordingly. They still carry
`destructiveHint: false` and `idempotentHint: true`, both of which remain
true regardless of whether a rescan runs. Pass `refresh_if_stale: false` to
get genuinely read-only behaviour back on a call. `KNOSSOS_AUTO_REFRESH=0`
does not do that on its own: it only turns the default off, and an explicit
`refresh_if_stale: true` still wins over it.

## Agent brief

`export_agent_brief` renders a compact, deterministic markdown orientation
brief from the graph, sized to paste directly into a `CLAUDE.md` or
`AGENTS.md` section. Its purpose is to get a future agent session oriented
on the codebase with zero tool calls (no `list_projects`, no
`architecture_summary`, nothing) by baking the essentials into the
project's own memory file.

### What it renders

Sections are appended in a fixed priority order:

1. **Head**: project name, scan freshness, file/component/relationship
   counts, and the language mix. Always included.
2. **Boundaries**: the top explicit/inferred boundaries by member count.
3. **Entry points**: routes, commands, and controller/command-classified
   components: where execution starts. Test-role components are excluded; a
   command stub declared in a test file carries the command role without
   being a way into the system. The predicate is shared with the
   [session brief](session-brief.md), which asks the same question of the
   same graph.
4. **Key hubs (most depended-on)**: the highest-degree components from
   `architecture_health`'s _filtered_ hub ranking (test-role and, unless
   requested, external/unresolved components are excluded: an unfiltered
   ranking would be misleading in a brief meant to be trusted at a glance).
5. **Framework signals**: detected framework roles (Laravel, Symfony,
   Django, FastAPI, Next.js, NestJS, React, Vue), if any.
6. **Closing pointer** (always included): a one-line reminder to rescan and
   which live tools to call next (`scan_project`, then `architecture_summary`,
   `impact_analysis`, or `explain_flow`).

Any section with no data is skipped silently (not reported as omitted).

### Budget and omission behavior

`max_chars` (1000–20000, default 4000) is a hard bound on the rendered
markdown: the result is never longer than `max_chars`. Sections are appended
in the priority order above only while they still fit; a section that would
push the brief over budget is dropped **whole** (never truncated mid-list)
and its name is reported in `omitted_sections`. The head (which caps its
language list at 5 entries, folding the rest into a `+N more` suffix) and the
closing pointer are always kept if there is any way to fit them. In the rare
case where even the head plus the closing pointer would exceed `max_chars`,
every section is omitted and, as a last resort, the head itself is truncated
so the closing pointer (the pointer back to the live query tools) is never
lost.

The response `data` shape is:

```json
{
    "markdown": "# Fixture Shop: architecture brief\n...",
    "omitted_sections": ["framework_signals"],
    "max_chars": 4000
}
```

### Invocations

```sh
knossos export-agent-brief project_... --max-chars=4000 --out=AGENTS.md
```

`--out=FILE` writes the rendered markdown directly to a file (e.g. appending
into `CLAUDE.md`/`AGENTS.md` during setup); `--json` prints the full envelope
instead of the markdown. The MCP form takes `project_id` and `max_chars`.

Run it once after a scan and paste (or `--out`) the result into the project's
memory file. The brief reflects the last scan, not the working tree, so
regenerate it after significant structural changes rather than treating it as
a live source of truth. For anything current, call the live query tools it
points to in its closing line.

## Architecture context

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

### Source snippets

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

This is the one query path that reads project source at all. It uses the same
root guard as scanning and degrades to `unavailable` rather than failing, so
it never needs write access.

## Component annotations

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

### Kinds

`kind` is one of:

- `intended_boundary`: this component's placement is deliberate; do not
  flag it as misplaced.
- `confirmed_dead`: a human or agent has verified this component is unused,
  beyond what static analysis alone can prove.
- `false_positive`: this component was wrongly flagged (for example, by
  `architecture_health`'s dead-code candidates); read surfaces that consume
  annotations use this to stop re-surfacing it.
- `note`: a free-form annotation with no special read-side effect.

### Survival across rescans

Every scan drops and rebuilds `nodes` (and everything keyed to a node id),
because node ids are not stable across scans. Annotations are keyed by
`(project_id, canonical_name, kind)` instead, with no foreign key to `nodes`,
so a full rescan that regenerates the graph does not lose them. Only removing
the project itself cascades the cleanup (`ON DELETE CASCADE` on `project_id`).

### Preview convention

`annotate_component` previews by default; pass `execute: true` to apply.
`remove: true` deletes the `(component, kind)` pair instead of writing it.
Writing the same `(component, kind)` again is an upsert: the existing value
and `updated_at` are replaced, `created_at` is not. The response's `previous`
field carries the annotation as it stood before the write (or `null`), so a
caller can tell an upsert from a fresh insert.

`component` resolves the same way as other component-accepting tools: an
exact canonical or display name match, or a unique name prefix. An ambiguous
prefix is rejected with candidates rather than silently picking one. A name
that does not resolve to any node in the current graph is still accepted. The
response carries a warning ("...not found...") because the target may be
a symbol the scanner does not see yet, or one that will exist after a
planned change.

### Reading annotations

`list_annotations` returns rows ordered by canonical name, then kind, with
`value`, `author`, `created_at`, and `updated_at`. Filter by `component`
(exact canonical name) or `kind`; `limit` (1–100, default 100) and `offset`
paginate.

### Not exported in graph bundles

Annotations are intentionally outside `export-bundle`/`import-bundle`'s table
list: bundles move a derived graph between databases, and annotations are
agent-authored state tied to a specific project's history, not a scan
artifact. Moving or replaying a bundle does not carry annotations with it.

This table exists so other query surfaces can read agent-recorded ground
truth. `annotate_component` and `list_annotations` only write and read the
table itself; which tools consume `false_positive` and `confirmed_dead`
annotations, and how, is documented on those tools once they do.
