# Agent integration

Three surfaces are for coding agents more than for people: a paste-ready
orientation brief, a bounded evidence bundle for one task, and durable
annotations an agent writes back to the graph. Use them when you want an agent
oriented before it greps, and when you want it to remember a judgment between
sessions.

Two related surfaces have their own pages: the brief injected at the start of a
Claude Code session ([session brief](../claude-code/session-brief.md)), and the
skill that decides which questions reach these tools at all
([the routing skill](../claude-code/skill.md)).

| Tool                   | CLI                    | Answers                                           |
| ---------------------- | ---------------------- | ------------------------------------------------- |
| `export_agent_brief`   | `export-agent-brief`   | Markdown orientation to paste into a memory file. |
| `architecture_context` | `architecture-context` | A bounded evidence bundle for one coding task.    |
| `annotate_component`   | `annotate-component`   | Record a durable judgment on a component.         |
| `list_annotations`     | `list-annotations`     | Read those judgments back.                        |

The server also exposes two MCP surfaces with no CLI equivalent: per-project
resources at `knossos://<project_id>/summary`, `/boundaries` and `/brief` (the
first two JSON, the last the markdown `export_agent_brief` renders), and the
`orient` and `review_diff` prompts. Every field of every tool is in
[the MCP tool reference](../reference/mcp-tools.md).

## Refreshing a stale graph

Most read tools accept `refresh_if_stale`, which defaults to `true`. A call that
lands on a stale graph rescans before it answers, so the agent skips the round
trip of reading a staleness banner, calling `scan_project` and asking again.
A tool's entry in [the MCP tool reference](../reference/mcp-tools.md) says
whether it takes the argument. `architecture_context` does not, and passing it
there is rejected as an unknown argument.

The rescan runs only when it fits inside the call you are already waiting on. A
project whose own scan history estimates more than a 5000 ms budget gets the
stored graph and a warning that names `scan_project` as the next step. Set
`KNOSSOS_AUTO_REFRESH=0` on the server process to turn the default off
everywhere; only the literal `0` does. An explicit `refresh_if_stale` argument
wins over both the default and the switch, so `false` returns the stored graph
as stored. The budget and the warning's shape are in
[response envelopes](../reference/response-envelopes.md#refreshing-a-stale-graph).

A rescan writes Knossos's own graph and starts language workers, so these tools
carry `readOnlyHint: false` and a client that confirms write tools will confirm
here. Pass `refresh_if_stale: false` for a call that is read-only. The
annotation details are in
[response envelopes](../reference/response-envelopes.md#refreshing-a-stale-graph).

## Agent brief

`export_agent_brief` renders a compact, deterministic markdown orientation
brief from the graph, sized to paste into a `CLAUDE.md` or `AGENTS.md`
section. A future agent session starts oriented on the codebase with zero tool
calls, because the essentials sit in the project's own memory file.

### What it renders

Sections are appended in a fixed priority order:

1. **Head**: project name, the scan's timestamp, file, component and
   relationship counts, and the language mix. Always included.
2. **Boundaries**: the ten largest explicit or inferred boundaries by member
   count.
3. **Entry points**: up to twelve routes, commands and controller or command
   components, where execution starts. Test-role components are excluded,
   because a command stub in a test file is no way into the system. The
   [session brief](../claude-code/session-brief.md) asks the same question of
   the same graph, with the same predicate.
4. **Key hubs (most depended-on)**: the five highest-degree components from
   `architecture_health`'s filtered hub ranking. Test-role and external or
   unresolved components are left out, so the ranking can be trusted at a
   glance.
5. **Framework signals**: the detected framework roles among Laravel, Symfony,
   Django, FastAPI, Next.js, NestJS, React and Vue.
6. **Closing pointer**: a one-line reminder to rescan, and which live tools to
   call next (`scan_project`, then `architecture_summary`, `impact_analysis` or
   `explain_flow`). Always included.

A section with no data is skipped silently and is not reported as omitted.

### Budget and omitted sections

`max_chars` (1000–20000, default 4000) is a hard bound: the markdown is never
longer. Sections are appended in the order above while they fit. A section that
would push the brief over budget is dropped whole, never cut mid-list, and its
name lands in `omitted_sections`. The head caps its language list at five
entries and folds the rest into a `+N more` suffix. When even the head and the
closing pointer exceed `max_chars`, every section is omitted and the head is
truncated, so the pointer back to the live tools is never lost.

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

`--out=FILE` writes the markdown to a file, and `--json` prints the full
envelope instead. The MCP form takes `project_id` and `max_chars`, plus
`refresh_if_stale`.

Run it after a scan and paste the result, or write it with `--out`, into the
project's memory file. The brief reflects the last scan and no later edit, so
regenerate it after significant structural changes. For anything current, call
the live tools its closing line names.

## Architecture context

`architecture_context` loads just enough context for one coding task in a single
call. It assembles a deterministic, bounded bundle of the project summary,
likely location, explicit changed-file impact and a few component dossiers,
without executing target-project code.

Supply a task description (up to 2000 bytes), up to 50 changed files, or
both:

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

`max_chars` runs from 4000 to 100000 and defaults to 30000. It is split across
the summary, location, impact and dossier sections. Each section reports
whether it was `included`, `truncated`, `not_requested` or `omitted` to protect
the total, and the response reports the serialized size and the allocation.
`verbosity` is `compact` (the default, evidence trimmed to a preview) or `full`.

Ranking is static and deterministic. The bundle is evidence for navigation and
review, and no proof of runtime behavior; dynamic dispatch and generated code
can stay unresolved.

### Source snippets

Set `include_source: true`, or `--include-source` on the CLI, to inline a code
window of at most 40 lines for each included dossier's primary evidence
location, as a sibling `snippet` key. A snippet is either
`{status: 'included', path, start_line, end_line, code}` or
`{status: 'unavailable', reason}`. The reasons are `no_line_evidence`,
`outside_project_root_or_missing`, `missing_or_oversized`, `unreadable` and
`stale_line_evidence`, the last when the recorded line range no longer exists.

Snippets are read from the working tree at query time and come from no scan,
so they drift from the graph's evidence when you have edited since. They count
against the dossier section's budget, so a large section can still be truncated
under `max_chars`.

This is the one query path that reads project source. It uses the scan's root
guard and degrades to `unavailable` where a scan would fail, and it never needs
write access.

## Component annotations

Use `annotate_component` to record a durable judgment on a component and
`list_annotations` to read it back. Annotations are the one part of the graph
that comes from an agent and not from a scan. They sit in their own table,
keyed by canonical name and not by node id. `component` must match exactly: a
stable id, a canonical name or a display name. A name that only starts like a
component's is recorded as given, with a warning that names the components it
might have meant, so an annotation never lands on a different component.

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

- `intended_boundary`: this component's placement is deliberate.
- `confirmed_dead`: a human or agent verified that nothing uses this component,
  beyond what static analysis can prove. `architecture_health` attaches
  `annotation: {kind, value}` to that component's dead-code candidate entry, so
  the judgment shows next to the candidate.
- `false_positive`: this component was flagged wrongly. `architecture_health`
  leaves it out of its dead-code candidates and counts it under
  `annotated_false_positives`.
- `intentional`: the finding is true and meant, such as a route parked on
  purpose or a helper only tests use by design. `architecture_health` leaves it
  out of its dead-code candidates and counts it under `annotated_intentional`.
  On one component, `false_positive` wins over `intentional`, which wins over
  `confirmed_dead`.
- `note`: a free-form remark. The [session brief](../claude-code/session-brief.md)
  lists the most recent notes at the start of a session.

`false_positive`, `intentional`, `confirmed_dead` and `note` change what another tool shows.
`intended_boundary` is recorded and listed, and no tool reads it.

### Survival across rescans

Every scan rebuilds `nodes` and everything keyed to a node id, because node ids
change between scans. Annotations are keyed by
`(project_id, canonical_name, kind)` and have no foreign key to `nodes`, so a
rescan keeps them. Removing the project cascades the cleanup.

### Preview convention

`annotate_component` previews by default, and `execute: true` applies it.
`remove: true` deletes the `(component, kind)` pair. Writing the same pair
again is an upsert: the value and `updated_at` change, `created_at` stays. The
response's `previous` field holds the annotation as it stood before the write,
or `null`, so a caller can tell an upsert from a fresh insert. A `value` is at
most 2000 bytes, like `task_description` on `architecture_context`. Both
limits count bytes, so text with accents or CJK characters, which take two to
four bytes each, hits them sooner than a character count suggests.

`component` resolves like in other tools: an exact canonical or display name,
or a unique name prefix. An ambiguous prefix is rejected with the candidates.
A name that matches no node is still accepted, with a "not found" warning,
because the target may be a symbol the scanner cannot see yet or one a planned
change will add.

### Reading annotations

`list_annotations` returns rows ordered by canonical name, then kind, with
`value`, `author`, `created_at` and `updated_at`. Filter by `component` (an
exact canonical name) or `kind`. `limit` (1–100, default 100) and `offset`
paginate.

### Not exported in graph bundles

`export-bundle` and `import-bundle` move a derived graph between databases, and
annotations are outside their table list. They are agent-authored state tied to
one project's history, so a bundle never carries them.
