# Changed-file impact

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

The Git adapter disables optional locks, uses argument-array process execution,
resolves a supplied base ref to a commit, detects renames, includes untracked
files for the default working-tree comparison, and enforces time/output/file
caps. It never invokes hooks or executes target-project code. Deleted and old
rename paths can still resolve when they exist in the active indexed snapshot.

Results are static and conservative. Dynamic dispatch may be absent, unresolved
paths are returned explicitly, and truncation means the reported set is not a
complete proof of runtime impact.
