# Reviewing a change

Four tools answer "what does this change put at risk?" at different widths.
Start with `review_diff`; reach for the others when you want one of its parts
on its own.

| Tool                   | CLI                    | Answers                                                     |
| ---------------------- | ---------------------- | ----------------------------------------------------------- |
| `review_diff`          | `review-diff`          | All four of the below in one call, scoped to the change.    |
| `changed_files_impact` | `changed-files-impact` | What a set of changed files, or your working tree, touches. |
| `test_impact`          | `test-impact`          | Which test files statically exercise the change.            |
| `change_impact`        | `change-impact`        | Static blast radius weighted by recent Git churn.           |

Every Git-reading tool here runs `git` read-only in the scanned root: optional
locks disabled, argument-array process execution, hooks never invoked, a hard
timeout, and stdout/stderr byte caps. None modifies the worktree, index, refs,
or repository configuration. See the
[HTTP threat model](../operations/http-threat-model.md#git-subprocesses) for
the exact hardening.

## Review diff

`review_diff` is a one-call architectural review of a change set: it composes
`changed_files_impact`, `check_architecture`, `quality_gate`, and
`dependency_cycles` into a single result, scoping the policy violations and
cycles it returns to the components the change actually touches. Use it when
you want a single answer instead of chaining four separate calls.

Unlike `changed_files_impact`, `review_diff` has no `working_tree` flag: an
empty `files` list _is_ the working-tree default, so there is no flag/argument
combination that can silently do the wrong thing. Passing `files` together
with `base_ref` is rejected.

```sh
knossos review-diff project_... --json                       # the working tree
knossos review-diff project_... src/Checkout.php --json      # an explicit set
knossos review-diff project_... --base-ref=origin/main --json
```

The MCP forms take the same three shapes: `{}` for the working tree, `files`
for an explicit set, `base_ref` for a Git range.

### Policies and budgets default to `knossos.json`

If `policies` or `budgets` are omitted, `review_diff` reads them from the
project's `knossos.json` (or `.jsonc`) at query time: no separate
`check_architecture`/`quality_gate` call is needed to exercise the project's
own declared rules. Pass either explicitly (as with `check_architecture` and
`quality_gate`) to override the file, including passing `[]`/`{}` to opt out.
The CLI accepts `--policies=FILE` and `--budgets=FILE`, read the same way as
`check-architecture` and `quality-gate`.

### Result shape

Each of the four sections carries its own `status`, `'evaluated'` or
`'not_evaluated'` (with a `reason` in the latter case): a review with partial
signal beats an error, so a missing config file, an unreadable project root,
or the absence of a retained baseline snapshot degrades the affected section
instead of failing the whole call:

- `change`: the `changed_files_impact` result: `changed_files`,
  `unresolved_files`, `direct_components`, `impacted_components`, `git`.
- `policy_check`: `policies_evaluated`, `total_violations`, and
  `violations_touching_change` (the subset of `check_architecture`'s
  violations whose source or target is a direct or impacted component of the
  change). `not_evaluated` when no policies are declared or supplied.
- `quality_gate`: `passed`, `checks`, `baseline_snapshot`, computed against
  the most recently retained non-active snapshot unless `baseline_snapshot` is
  given explicitly. `not_evaluated` when no budgets are declared or supplied,
  or when no retained baseline snapshot exists yet.
- `cycles_touching_change`: the subset of `dependency_cycles`'s cycles with
  at least one member among the change's direct or impacted components.
  `not_evaluated` (with a reason) if the cycle scan itself fails.

`bounds` mirrors `changed_files_impact`'s bounds with `cycle_scan_limit`
added. The envelope's evidence, warnings, and truncation flag are the union of
the underlying calls': evidence from `change`, the policy check (when
evaluated), the quality gate (when evaluated), and the cycle scan (when
evaluated), capped at the first 100 rows; a section that degrades to
`not_evaluated` contributes no evidence.

Results are static and conservative, subject to the same caveats as the
underlying tools: impact is a blast-radius estimate, not a guarantee; change
frequency signals are absent here (see `change_impact` for those); and a
truncated cycle or edge scan may under-report.

## Changed-file impact

`changed_files_impact` maps a bounded set of changed project-relative files to
their indexed components and then performs conservative reverse static impact
analysis. It separates direct components, impacted dependants, known entry
points, and unresolved paths.

Pass explicit paths when a client already has a change set:

```json
{
    "project_id": "project_...",
    "files": ["src/Checkout.php", "frontend/src/cart.ts"],
    "max_depth": 4,
    "limit": 100
}
```

Or explicitly opt into a read-only Git working-tree query:

```json
{
    "project_id": "project_...",
    "working_tree": true,
    "base_ref": "main"
}
```

CLI equivalents are:

```sh
knossos changed-files-impact project_... src/Checkout.php --json
knossos changed-files-impact project_... --working-tree --base-ref=main --json
```

The Git adapter resolves a supplied base ref to a commit, detects renames, and
includes untracked files for the default working-tree comparison. Deleted and
old rename paths can still resolve when they exist in the active indexed
snapshot.

Results are static and conservative. Dynamic dispatch may be absent, unresolved
paths are returned explicitly, and truncation means the reported set is not a
complete proof of runtime impact.

## Test impact

`test_impact` projects a changed-files blast radius (the same analysis behind
`changed_files_impact`) onto the test files that statically reach the changed
code, ranked by distance. Use it to run the relevant tests first in an
edit-test loop; it is a lower bound, not a substitute for the full suite:
data-driven tests, fixtures, and glob-only discovery are invisible to the
graph.

```sh
knossos test-impact project_... src/Checkout.php --json
knossos test-impact project_... --working-tree --base-ref=main --json
```

The MCP form takes the same `files` / `working_tree` + `base_ref` inputs as
`changed_files_impact`.

`test_files` is a list of `{path, distance, via}`, sorted by distance then
path:

- `distance` is the shortest static hop count from a changed component to a
  component classified `quality.test_module` in that file; `0` means the test
  file itself was in the changed set.
- `via` names up to three of the test components (classes/functions) in that
  file responsible for the reachability, sorted and de-duplicated.

`changed_files`, `unresolved_files`, and `bounds` mirror
`changed_files_impact`'s fields, with `bounds.impacted_scan_limit` added to
record the per-component dependant scan cap. A warning is always attached
reminding callers this is a lower bound.

## Git change signals and time-aware impact

`change_impact` starts with the same bounded reverse static dependency analysis
as `impact_analysis`, then reads recent Git history for the impacted components'
indexed files.

Inputs include `since_days`, `max_commits`, static `max_depth`/relationship
filters, a result `limit`, and `timeout_ms`. Per-file signals are recent commit
count, distinct author email identifiers, and latest author timestamp. The risk
ranking exposes its simple factors: three points per commit, one per distinct
author, and a bounded static-proximity weight.

These are prioritization heuristics, not proof of risk, ownership, code quality,
or future failure. Renames are intentionally not followed in the first version,
and history outside the selected window is absent. If Git is unavailable or the
scanned root is not a repository, the tool returns the static impact with zero
change scores and a reason instead of failing the whole query.

When history was read, `git.available` is true and `git.reason` is `null`.
Otherwise `git.reason` says what stopped it, and the value distinguishes two
different failures. `unmatched_target` and `ambiguous_target` mean the request
never resolved to one component, so there is no blast radius to score and
`risk_ranking` is empty: the first when `symbol` matched nothing, the second when
it matched several. Every other value still carries the full static impact with
zero change scores, either `provider_unavailable` when no Git provider is
configured, or the Git error message itself, truncated to 500 characters. That
last case is free-form, so read `reason` as a diagnostic string rather than a
closed set of codes.
