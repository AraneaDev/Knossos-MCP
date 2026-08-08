# Response envelopes

Every query returns the same envelope shape, so an agent can read any tool's
result without learning a per-tool format. Over MCP that envelope is
additionally _enriched_: freshness is probed, follow-up steps are suggested,
and the payload is compacted to spend as little of the agent's context budget
as the answer allows.

Enrichment happens only on the MCP path (`knossos serve`). The CLI's `--json`
prints the raw envelope — no compaction, no `staleness`, no `next_steps`, no
`meta`.

## Envelope fields

| Field         | Always present | Meaning                                                          |
| ------------- | -------------- | ---------------------------------------------------------------- |
| `project_id`  | yes            | The project the answer came from.                                |
| `snapshot_id` | yes            | The exact scan the answer was computed against.                  |
| `summary`     | yes            | One-line natural-language answer.                                |
| `data`        | yes            | The tool-specific payload.                                       |
| `evidence`    | yes            | `path`/`start_line` records backing the answer. May be empty.    |
| `warnings`    | yes            | Caveats that qualify the answer, such as heuristic blast radius. |
| `truncated`   | yes            | Whether any list in the result was cut short.                    |
| `staleness`   | MCP only       | Whether the graph still matches the working tree.                |
| `next_steps`  | MCP only       | Up to three suggested follow-up calls. Omitted when empty.       |
| `meta`        | MCP only       | Envelope accounting: size, verbosity, evidence shown vs. total.  |

## Verbosity

Every query tool accepts `verbosity`, which is `compact` by default. `full`
returns a lossless superset — nothing is hoisted, trimmed, or shortened.

`compact` removes structural repetition rather than information:

- **Component legend.** Each node descriptor is registered once in
  `data.component_legend`, keyed by canonical name, and every place the
  descriptor appeared becomes a plain name string. Because tools address
  components by canonical name and never by `symbol_…`/`edge_…` id, those
  opaque ids drop out of the payload as a side effect.
- **Boundary legend.** The same treatment for repeated boundary objects, into
  `data.boundary_legend`. Ids that _are_ tool inputs — `boundary_…`, snapshot
  ids — are preserved wherever they are the answer.
- **Edge shortening.** A `via` edge object collapses to just its edge kind, so
  `"via": "implements"` replaces the edge id, origin, reconstructable
  `explanation` prose, and nested evidence.
- **Evidence preview.** `evidence` is capped at the first three records;
  `meta.evidence_total` and `meta.evidence_shown` report what was held back.

Compaction runs before the `max_chars` sizing loop, so budgets and the
tool-result overflow fallback trip far less often.

## Worked comparison

The same `impact_analysis` call at each verbosity. Compact:

```json
{
    "data": {
        "dependants": [
            {
                "node": "Knossos\\Scanner\\Worker\\ProcessScannerClient",
                "distance": 1,
                "path_confidence": "certain",
                "via": "implements"
            }
        ],
        "counts": { "by_distance": { "1": 1, "2": 8 } },
        "component_legend": {
            "Knossos\\Scanner\\ScannerClient": {
                "kind": "interface",
                "confidence": "certain"
            }
        }
    },
    "staleness": {
        "state": "fresh",
        "age_seconds": 811,
        "changed_files_since": 0,
        "added_files_since": 0,
        "deleted_files_since": 0
    },
    "meta": {
        "result_bytes": 3763,
        "verbosity": "compact",
        "evidence_total": 9,
        "evidence_shown": 3
    }
}
```

The identical dependant under `verbosity: "full"` carries the descriptor and
edge inline instead:

```json
{
    "node": {
        "id": "symbol_54fd5b94...",
        "kind": "class",
        "canonical_name": "Knossos\\Scanner\\Worker\\ProcessScannerClient",
        "display_name": "ProcessScannerClient",
        "confidence": "certain"
    },
    "distance": 1,
    "path_confidence": "certain",
    "via": {
        "edge_id": "edge_2c19892d...",
        "kind": "implements",
        "source_id": "symbol_54fd5b94...",
        "target_id": "symbol_255f075e...",
        "origin": "ast",
        "explanation": "ProcessScannerClient depends through --implements (certain, ast)--> ScannerClient"
    }
}
```

That call is 3,763 bytes compact against 10,123 bytes full — the same names,
`path:line`, confidence, and distance in roughly a third of the tokens.

## Size budgets

Tools that accept `max_chars` size the _serialized envelope_ to that budget.
When the result is over budget, Knossos repeatedly drops the tail item of the
largest remaining list — descending into nested lists too — until it fits:

- `truncated` becomes `true`.
- `meta.dropped_items` records how many items were dropped, keyed by dotted
  path (for example `dependants` or `evidence`).
- `meta.max_chars` echoes the budget that forced the trimming.

Trimming shortens lists; it never rewrites or summarizes an item, so whatever
survives is exactly what an untrimmed call would have returned. If no
trimmable list remains and the envelope is still over budget, the result is
returned anyway with the warning `The max_chars budget could not be fully met
by trimming result lists.` — an honest overflow rather than a silent lie.

## Staleness

`staleness.state` is one of:

| State        | Meaning                                                                      |
| ------------ | ---------------------------------------------------------------------------- |
| `fresh`      | No newer scan attempt, and no changed files since the active scan.           |
| `stale`      | A newer scan attempt exists, or files changed since the active scan.         |
| `unverified` | Change detection was skipped — root unavailable, or too many files to check. |

`stale` and `unverified` carry a `guidance` string naming the rescan to run.
`unverified` exists so an unconfirmable graph is never reported as fresh.

When change detection ran, `staleness` also carries:

- `changed_files_since` — tracked files whose on-disk mtime differs from the scan's.
- `added_files_since` — entries that appeared since the scan in the directories
  holding tracked files: entries absent from the tracked-path set whose inode
  change time is later than the scan. Two limits follow from the 500-file bound
  below rather than from the method. A new directory is only seen when its
  parent holds a tracked file, so one created in a subtree with no tracked file
  in it is invisible. Ignore rules are not applied either, so a build artifact
  or a vendored dependency counts as an addition even though a rescan would
  skip it.
- `deleted_files_since` — tracked files that no longer exist.

All three are omitted, and the state is `unverified`, above 500 tracked files.

## Next steps

`next_steps` offers at most three follow-up calls, each with the `tool`, the
`args` to pass, and a `why`. They are emitted after `find_component`,
`inspect_component`, `impact_analysis`, and `architecture_health` — the tools
whose results most often lead to an obvious next question (for example, an
ambiguous `find_component` match set suggests `inspect_component` on the top
candidate). They are suggestions, not instructions, and nothing is executed
on the agent's behalf.
