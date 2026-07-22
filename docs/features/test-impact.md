# Test impact

`test_impact` projects a changed-files blast radius (the same analysis behind
`changed_files_impact`) onto the test files that statically reach the changed
code, ranked by distance. Use it to run the relevant tests first in an
edit-test loop; it is a lower bound, not a substitute for the full suite —
data-driven tests, fixtures, and glob-only discovery are invisible to the
graph.

Pass explicit paths when a client already has a change set:

```json
{
    "project_id": "project_...",
    "files": ["src/Checkout.php"],
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
knossos test-impact project_... src/Checkout.php --json
knossos test-impact project_... --working-tree --base-ref=main --json
```

## Result shape

`test_files` is a list of `{path, distance, via}`, sorted by distance then
path:

- `distance` is the shortest static hop count from a changed component to a
  component classified `quality.test_module` in that file; `0` means the test
  file itself was in the changed set.
- `via` names up to three of the test components (classes/functions) in that
  file responsible for the reachability, sorted and de-duplicated.

`changed_files`, `unresolved_files`, and `bounds` mirror
`changed_files_impact`'s fields, with `bounds.impacted_scan_limit` added to
record the per-component dependant scan cap.

Results are static and conservative, subject to the same Git-adapter and
confidence caveats as `changed_files_impact`. A warning is always attached
reminding callers this is a lower bound.
