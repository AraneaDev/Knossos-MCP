# Agent brief

`export_agent_brief` renders a compact, deterministic markdown orientation
brief from the graph, sized to paste directly into a `CLAUDE.md` or
`AGENTS.md` section. Its purpose is to get a future agent session oriented
on the codebase with zero tool calls — no `list_projects`, no
`architecture_summary`, nothing — by baking the essentials into the
project's own memory file.

## What it renders

Sections are appended in a fixed priority order:

1. **Head** — project name, scan freshness, file/component/relationship
   counts, and the language mix. Always included.
2. **Boundaries** — the top explicit/inferred boundaries by member count.
3. **Entry points** — routes, commands, and controller/command-classified
   components: where execution starts.
4. **Key hubs (most depended-on)** — the highest-degree components from
   `architecture_health`'s *filtered* hub ranking (test-role and, unless
   requested, external/unresolved components are excluded — an unfiltered
   ranking would be misleading in a brief meant to be trusted at a glance).
5. **Framework signals** — detected framework roles (Laravel, Symfony,
   Django, FastAPI, Next.js, NestJS, React, Vue), if any.
6. **Closing pointer** — always included: a one-line reminder to rescan and
   which live tools to call next (`scan_project`, then `architecture_summary`,
   `impact_analysis`, or `explain_flow`).

Any section with no data is skipped silently (not reported as omitted).

## Budget and omission behavior

`max_chars` (1000–20000, default 4000) bounds the rendered markdown. Sections
are appended in the priority order above only while they still fit; a section
that would push the brief over budget is dropped **whole** — never truncated
mid-list — and its name is reported in `omitted_sections`. The head and the
closing pointer are always kept.

The response `data` shape is:

```json
{
    "markdown": "# Fixture Shop — architecture brief\n...",
    "omitted_sections": ["framework_signals"],
    "max_chars": 4000
}
```

## Invocations

MCP:

```json
{
    "project_id": "project_...",
    "max_chars": 4000
}
```

CLI:

```sh
knossos export-agent-brief project_... --max-chars=4000 --out=AGENTS.md
```

`--out=FILE` writes the rendered markdown directly to a file (e.g. appending
into `CLAUDE.md`/`AGENTS.md` during setup); `--json` prints the full envelope
instead of the markdown.

## Intended use

Run it once after a scan and paste (or `--out`) the result into the project's
`CLAUDE.md` or `AGENTS.md`. The brief reflects the last scan, not the working
tree, so regenerate it after significant structural changes rather than
treating it as a live source of truth — for anything current, call the live
query tools it points to in its closing line.
