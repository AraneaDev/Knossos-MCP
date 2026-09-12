# Response envelopes

Every query returns the same envelope shape, so an agent can read any tool's
result without learning a per-tool format. Over MCP that envelope is
additionally _enriched_: freshness is probed, follow-up steps are suggested,
and the payload is compacted to spend as little of the agent's context budget
as the answer allows.

Enrichment happens only on the MCP path (`knossos serve`). The CLI's `--json`
prints the raw envelope: no compaction, no `staleness`, no `next_steps`, no
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
returns a lossless superset: nothing is hoisted, trimmed, or shortened.

`compact` removes structural repetition rather than information:

- **Component legend.** Each node descriptor is registered once in
  `data.component_legend`, keyed by canonical name, and every place the
  descriptor appeared becomes a plain name string. Because tools address
  components by canonical name and never by `symbol_…`/`edge_…` id, those
  opaque ids drop out of the payload as a side effect.
- **Boundary legend.** The same treatment for repeated boundary objects, into
  `data.boundary_legend`. Ids that _are_ tool inputs (`boundary_…`, snapshot
  ids) are preserved wherever they are the answer.
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

That call is 3,763 bytes compact against 10,123 bytes full: the same names,
`path:line`, confidence, and distance in roughly a third of the tokens.

## Size budgets

Tools that accept `max_chars` size the _serialized envelope_ to that budget.
When the result is over budget, Knossos repeatedly drops the tail item of the
largest remaining list, descending into nested lists too, until it fits:

- `truncated` becomes `true`.
- `meta.dropped_items` records how many items were dropped, keyed by dotted
  path (for example `dependants` or `evidence`).
- `meta.max_chars` echoes the budget that forced the trimming.

Trimming shortens lists; it never rewrites or summarizes an item, so whatever
survives is exactly what an untrimmed call would have returned. If no
trimmable list remains and the envelope is still over budget, the result is
returned anyway with the warning `The max_chars budget could not be fully met
by trimming result lists.`, an honest overflow rather than a silent lie.

## Staleness

`staleness.state` is one of:

| State        | Meaning                                                                     |
| ------------ | --------------------------------------------------------------------------- |
| `fresh`      | No newer scan attempt, and no changed files since the active scan.          |
| `stale`      | A newer scan attempt exists, or files changed since the active scan.        |
| `unverified` | Change detection was skipped: root unavailable, or too many files to check. |

`stale` and `unverified` carry a `guidance` string naming the rescan to run.
`unverified` exists so an unconfirmable graph is never reported as fresh.

When change detection ran, `staleness` also carries:

- `changed_files_since`: tracked files whose content hash differs from the one
  the scan stored. Content decides drift, so a `touch` that moves an mtime
  without changing a byte is not a change, and neither is a `git checkout` that
  restores identical content.
- `added_files_since`: entries that appeared since the scan in the directories
  holding tracked files: entries absent from the tracked-path set, that the
  scanner would have tracked, whose inode change time is later than the scan.
  The project's ignore rules apply, and so does the scanner's own idea of
  source, so a build artifact, a vendored dependency and a new README are not
  additions. A new directory is, because nothing short of descending into it
  says whether it holds source. One limit follows from the 20,000-file bound
  below rather than from the method: a new directory is only seen when its
  parent holds a tracked file, so one created in a subtree with no tracked file
  in it is invisible.
- `deleted_files_since`: tracked files that no longer exist.

All three are omitted, and the state is `unverified`, above 20,000 tracked
files with no usable Git history to ask instead.

## Refreshing a stale graph

Every read tool that accepts `refresh_if_stale` defaults it to `true`. When a
call lands on a `stale` graph, the server tries a rescan before answering, so
you get a current answer without first reading a staleness banner, calling
`scan_project`, and asking again. Pass `refresh_if_stale: false` and the
default is overridden: the stored graph is served as stored, stale or not,
with no rescan attempted.

The rescan only runs when it is cheap enough to fit inside the call you are
already waiting on. `RefreshPolicy` estimates the cost from the duration the
project's own last scan recorded: a fixed overhead for the discovery, worker
startup and reconciliation any rescan pays, plus that scan's per-file cost
times the number of files that drifted, capped at what the full scan cost.
It compares that estimate against a 5000 ms budget. Over budget, or when the
active scan recorded no duration to estimate against, or when the graph is
`stale` with no measured change set to cost, the refresh is declined and the
stored graph answers instead. A declined or failed refresh adds one
line to `warnings`, prefixed `refresh_if_stale:`, naming the reason and, when
relevant, pointing you at `scan_project`. A refresh that succeeds adds no
warning at all: staleness is probed again after the rescan, so
`staleness.state` on the same result already reads `fresh`, and a second
announcement would land on every call for the one case that needs no
attention. It can still read `stale` if the tree moved again while the rescan
was running, which is the honest answer rather than a stale one.

Set the environment variable `KNOSSOS_AUTO_REFRESH=0` on the server process
to turn the default back off everywhere, for every tool and every project,
without touching a single call site. An explicit `refresh_if_stale` argument
still wins over both the default and the kill switch: passing `true`
refreshes even with the kill switch set, and passing `false` never refreshes
regardless of it.

`scan_project` itself is exempt: it is already the rescan, so `refresh_if_stale`
has nothing to trigger there.

Because any tool that declares `refresh_if_stale` can trigger a rescan, none
of them carry `readOnlyHint: true` any more: a call to one of them can write
Knossos's own graph and start language worker subprocesses, which is exactly
what that annotation promises a client does not happen. `destructiveHint:
false` and `idempotentHint: true` still hold, since a rescan neither destroys
data nor makes the tool behave differently when repeated.

For a call a client can rely on as genuinely read-only, pass
`refresh_if_stale: false`. `KNOSSOS_AUTO_REFRESH=0` is not enough on its own:
it turns the default off, but an explicit `refresh_if_stale: true` still wins
over it, so a caller that asks for a refresh gets one on a server with the
kill switch set. The variable makes the server not refresh unasked; only the
argument makes a particular call read-only.

## Next steps

`next_steps` offers at most three follow-up calls, each with the `tool`, the
`args` to pass, and a `why`. They are emitted after `find_component`,
`inspect_component`, `impact_analysis`, and `architecture_health`: the tools
whose results most often lead to an obvious next question (for example, an
ambiguous `find_component` match set suggests `inspect_component` on the top
candidate). They are suggestions, not instructions, and nothing is executed
on the agent's behalf.

One step is not a tool call. A `full` `scan_project` suggests installing the
[session brief](../capabilities/session-brief.md) plugin, and that entry carries `shell` and
`why` instead of `tool` and `args`, because it is a command a person runs in
a terminal: it installs a Claude Code plugin into the user's own
configuration, which is not the server's to do on anyone's behalf. A caller
walking `next_steps` mechanically has to key on which of the two keys is
present rather than assume `tool`.
