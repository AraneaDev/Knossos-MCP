# Project catalogue

Knossos stores stable project IDs in SQLite. Use the catalogue instead of
rescanning merely to recover an ID:

```sh
knossos list-projects --limit=50 --offset=0 --json
```

The equivalent MCP tool is `list_projects`:

```json
{
    "limit": 50,
    "offset": 0,
    "include_roots": false
}
```

Absolute project roots are omitted by default because clients normally need
the stable ID, name, snapshot, freshness, and counts. Opt in with
`--include-roots` on the CLI or `include_roots: true` through MCP when the
local path is necessary.

Each result includes active and latest scan metadata plus file, node, edge, and
diagnostic counts. `freshness` has these stable values:

| Value                   | Meaning                                                                        |
| ----------------------- | ------------------------------------------------------------------------------ |
| `ready`                 | The active snapshot is complete and its local root is available.               |
| `unscanned`             | The project exists but has no active snapshot.                                 |
| `scan_in_progress`      | A newer scan is running while the previous snapshot remains queryable.         |
| `latest_scan_failed`    | A newer scan failed; the previous snapshot remains active.                     |
| `latest_scan_cancelled` | A newer scan was cancelled; the previous snapshot remains active.              |
| `root_unavailable`      | The active graph is queryable, but its recorded root is not currently mounted. |

Results are ordered by most recently updated project and then stable project
ID. Pagination returns `next_offset` and `result_limit` when another page is
available. Limits are bounded to 100 projects per request and offsets to
100,000.
