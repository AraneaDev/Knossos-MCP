# Review diff

`review_diff` is a one-call architectural review of a change set: it composes
`changed_files_impact`, `check_architecture`, `quality_gate`, and
`dependency_cycles` into a single result, scoping the policy violations and
cycles it returns to the components the change actually touches. Use it when
you want a single answer to "what does this change put at risk?" instead of
chaining four separate calls.

Unlike `changed_files_impact`, `review_diff` has no `working_tree` flag: an
empty `files` list *is* the working-tree default, so there is no flag/argument
combination that can silently do the wrong thing. Passing `files` together
with `base_ref` is rejected.

Review the uncommitted working tree (the default):

```json
{
    "project_id": "project_..."
}
```

Review an explicit change set:

```json
{
    "project_id": "project_...",
    "files": ["src/Checkout.php"]
}
```

Review since a Git ref:

```json
{
    "project_id": "project_...",
    "base_ref": "origin/main"
}
```

CLI equivalents are:

```sh
knossos review-diff project_... --json
knossos review-diff project_... src/Checkout.php --json
knossos review-diff project_... --base-ref=origin/main --json
```

## Policies and budgets default to `knossos.json`

If `policies` or `budgets` are omitted, `review_diff` reads them from the
project's `knossos.json` (or `.jsonc`) at query time — no separate
`check_architecture`/`quality_gate` call is needed to exercise the project's
own declared rules. Pass either explicitly (as with `check_architecture` and
`quality_gate`) to override the file, including passing `[]`/`{}` to opt out.
The CLI accepts `--policies=FILE` and `--budgets=FILE`, read the same way as
`check-architecture` and `quality-gate`.

## Result shape

Each of the four sections carries its own `status`, `'evaluated'` or
`'not_evaluated'` (with a `reason` in the latter case) — a review with partial
signal beats an error, so a missing config file, an unreadable project root,
or the absence of a retained baseline snapshot degrades the affected section
instead of failing the whole call:

- `change` — the `changed_files_impact` result: `changed_files`,
  `unresolved_files`, `direct_components`, `impacted_components`, `git`.
- `policy_check` — `policies_evaluated`, `total_violations`, and
  `violations_touching_change` (the subset of `check_architecture`'s
  violations whose source or target is a direct or impacted component of the
  change). `not_evaluated` when no policies are declared or supplied.
- `quality_gate` — `passed`, `checks`, `baseline_snapshot`, computed against
  the most recently retained non-active snapshot unless `baseline_snapshot` is
  given explicitly. `not_evaluated` when no budgets are declared or supplied,
  or when no retained baseline snapshot exists yet.
- `cycles_touching_change` — the subset of `dependency_cycles`'s cycles with
  at least one member among the change's direct or impacted components.

`bounds` mirrors `changed_files_impact`'s bounds with `cycle_scan_limit`
added. The envelope's evidence, warnings, and truncation flag are the union of
the underlying calls'.

Results are static and conservative, subject to the same caveats as the
underlying tools: impact is a blast-radius estimate, not a guarantee; change
frequency signals are absent here (see `change_impact` for those); and a
truncated cycle or edge scan may under-report.
