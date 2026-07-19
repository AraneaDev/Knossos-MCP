# Agent usability design

Date: 2026-07-19

## Goal

Make Knossos ergonomic for a coding agent working mid-task, without adding a
front-door/router tool and without changing the read-only contract of query
tools. The work targets three observed pain points: agents not reaching for the
tools at the right moment (discovery), responses that cost too many tokens or
bury the actionable answer (output shape), and clunky scan / `project_id` /
staleness handling (onboarding).

The approach keeps all 28 tools and makes each one **self-explaining** and, on
missing/stale data, **self-guiding** — never self-acting.

## Non-goals (YAGNI)

- No new high-level "architecture" front-door or router tool.
- No auto-scan, no `auto_scan` flag, no silent graph mutation from a query.
- No changes to tool annotations (`read-only`, `destructive`, `idempotent`).
- No semantic/LLM ranking changes, no new transports, no CLI surface changes
  beyond what falls out of the shared envelope.

## Pillars

### Pillar A — Discovery: self-explaining tools

Rewrite all 28 tool `description` strings in `src/Mcp/ToolService.php` from
architect-jargon to an intent-first template:

> *[When to reach for it]. [What question it answers]. [Why it beats the manual
> (grep/read) alternative].*

Constraints:

- 1–3 sentences each; kept tight.
- Titles and annotations unchanged.
- `docs/MCP-REFERENCE.md` is generated from these definitions, so it improves
  for free and must be regenerated as part of the change.

Example transformation:

- Before: "Find a bounded conservative static blast radius by traversing
  dependencies in reverse."
- After: "Before editing a symbol, find everything that depends on it. Answers
  'what breaks if I change this?' by following real static references, not text
  matches — more complete than grepping for callers."

### Pillar B — Onboarding: guide, don't act

Query tools stay strictly read-only. When the graph is **missing** or **stale**,
the tool returns a structured guidance response (via the envelope's `staleness`
block, below) rather than a bare error or silently scanning. Detection is cheap
and best-effort; it never triggers a scan.

- `state: "fresh" | "stale" | "missing"` plus `scanned_at` and `age_seconds`
  are computed from data the query layer already holds (`snapshot_id`,
  `scanned_at`) — effectively free.
- `changed_files_since` is best-effort: a bounded mtime/git-HEAD comparison
  against the scan time. If it cannot be determined within a tight bound, it is
  omitted and the response still reports age plus a soft "may be stale" note.
- `missing` state (no graph for the requested project/path) returns guidance to
  call `scan_project`.

### Pillar C — Output shape: agent-shaped envelope

Every query flows through `src/Query/ResultEnvelope.php`, so it is extended once
and all 28 tools inherit the new shape. Existing fields are unchanged, making
the change backward-compatible.

Existing fields (unchanged): `project_id`, `snapshot_id`, `summary` (already the
answer-first line), `data`, `evidence`, `warnings`, `truncated`.

New fields:

```
staleness: {                           // always present on query tools
  state: "fresh" | "stale" | "missing",
  scanned_at, age_seconds,
  changed_files_since?: int,           // best-effort, may be omitted
  guidance?: string                    // e.g. "Rescan with scan_project(...) for current results"
}

next_steps: [                          // structured, callable follow-ups; capped at 3
  { tool: string, args: object, why: string }
]

meta: {
  result_bytes: int,                   // actual serialized size (token honesty)
  verbosity: "compact" | "full"
}
```

Behavioural rules:

- **Answer-first / evidence-on-demand.** A new `verbosity` input on read tools
  defaults to `compact`. `compact` returns `summary` + `data` + a *count* of
  evidence with the top few items; `full` returns the complete `evidence` array
  as today. `meta.result_bytes` reports actual size so the agent knows when it
  is seeing a partial view. **Default verbosity is `compact`** (approved) — this
  is the right agent default; humans/CI pass `full`.
- **`next_steps`** are generated per-tool from the result, turning each tool
  into a discovery surface for the others. Examples: `find_component` with
  ambiguous candidates suggests `inspect_component` on the top match;
  `inspect_component` on a hub suggests `impact_analysis` on it. **Capped at 3**
  (approved) to avoid bloat and choice-paralysis.

## Components touched

- `src/Query/ResultEnvelope.php` — add `staleness`, `next_steps`, `meta`;
  extend `jsonSerialize()`. Single source of the stable machine shape.
- `src/Query/*QueryService.php` — populate `staleness` (freshness computation),
  honour `verbosity`, and emit `next_steps` appropriate to each result shape.
- `src/Mcp/ToolService.php` — rewritten intent-first descriptions; add the
  `verbosity` input to read tools; thread it to the query layer.
- `docs/MCP-REFERENCE.md` — regenerated from the updated definitions.

## Testing

Extend the existing PHP test suite:

1. Envelope serialization including the new `staleness`, `next_steps`, `meta`
   fields (and backward-compat of existing fields).
2. `compact` vs `full` verbosity for a representative set of tools, including
   `meta.result_bytes` reporting.
3. Staleness states `fresh` / `stale` / `missing` each produce the correct
   guidance, and that a query never triggers a scan.
4. `next_steps` generation for the key result shapes (ambiguous
   `find_component`, hub `inspect_component`, etc.), including the cap of 3.
5. `docs/MCP-REFERENCE.md` is regenerated and asserted in sync with the live
   definitions.

## Rollout notes

The change is additive to the envelope and to tool inputs, so existing MCP/CLI
callers keep working. The one observable default change is `verbosity=compact`:
callers that relied on full evidence by default now receive a compact view plus
a count, and must pass `verbosity=full` for the old behaviour. This is
documented in the regenerated MCP reference.
