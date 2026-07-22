# MCP tool reference

This file is generated from the live `ToolService` definitions; edit the source schemas, not this file.

## `list_projects`

Start here to find a project_id. Lists scanned projects with freshness and graph size so you can pick the right project_id before any other call.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |
| `include_roots` | boolean | no | default=false |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `scan_project`

Build or refresh a project's architecture graph. Run this first for a new project, or when a query reports the graph is missing or stale.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `path` | string | yes | minLength=1 |
| `name` | string | no | minLength=1 |
| `mode` | string | no | default="auto"; enum=auto, full, incremental |
| `max_files` | integer | no | minimum=1; maximum=100000 |
| `max_file_bytes` | integer | no | minimum=1; maximum=100000000 |
| `worker_timeout_ms` | integer | no | minimum=1000; maximum=120000; default=30000 |
| `snapshot_retention` | integer | no | minimum=0; maximum=20; default=5 |
| `boundaries` | array | no | maxItems=50 |

Annotations: read-only `no`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_snapshots`

See a project's scan history. Use to find an older snapshot id to diff against or to check when it was last scanned.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `snapshot_diff`

See what changed architecturally between two scans. Use after a rescan to review added/removed components and relationships instead of eyeballing a code diff.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `from_snapshot` | string | yes | minLength=1 |
| `to_snapshot` | string | no | minLength=1; default="active" |
| `max_changes` | integer | no | minimum=1; maximum=1000; default=200 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `quality_gate`

Check architecture budgets against a baseline in CI. Use to fail a build on regressions (new cycles, boundary breaks) rather than reviewing them by hand.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `baseline_snapshot` | string | yes | minLength=1 |
| `budgets` | object | yes | — |
| `policies` | array | no | maxItems=50 |
| `sarif` | boolean | no | default=false |
| `propose_baseline` | boolean | no | default=false |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_trends`

See how architecture metrics moved over recent scans. Use for release notes or to spot slow structural drift.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=2; maximum=20; default=10 |
| `release_from` | string | no | minLength=1 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `find_component`

Locate a component by name when you are unsure of its exact canonical path. Returns ranked candidates — use before inspect_component when the name is ambiguous.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `name` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `inspect_component`

Get the full dossier for one component — its roles, boundary, containment, relationships, and evidence — in a single call. Faster than opening and cross-referencing several files by hand.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `component` | string | yes | minLength=1 |
| `max_relationships` | integer | no | minimum=1; maximum=100; default=25 |
| `max_children` | integer | no | minimum=1; maximum=100; default=25 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_summary`

Get a one-call overview of the codebase by language, node kind, and relationship kind. Use to orient yourself in an unfamiliar project before drilling in.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `file_metrics`

Find the largest or longest files. Use to spot refactor targets without shelling out to wc/find.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `path_contains` | string | no | minLength=1; maxLength=1000 |
| `language` | string | no | minLength=1; maxLength=100 |
| `sort_by` | string | no | default="line_count"; enum=path, line_count |
| `order` | string | no | default="desc"; enum=asc, desc |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `explain_flow`

Answer 'how does A reach B?' Traces evidence-backed static paths between two components — more reliable than grepping call sites across layers.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `from` | string | yes | minLength=1 |
| `to` | string | yes | minLength=1 |
| `max_depth` | integer | no | minimum=1; maximum=8; default=6 |
| `max_paths` | integer | no | minimum=1; maximum=20; default=5 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `impact_analysis`

Before editing a symbol, find everything that depends on it. Answers 'what breaks if I change this?' by following real static references, so it is more complete than grepping for callers.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `symbol` | string | yes | minLength=1 |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 |
| `limit` | integer | no | minimum=1; maximum=100; default=100 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `dependency_cycles`

Find circular dependencies. Use before a refactor to see which modules are tangled, instead of tracing imports by hand.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `max_nodes` | integer | no | minimum=1; maximum=50000; default=10000 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_health`

Rank the structural hotspots, hubs, and likely-dead code. Use to decide where cleanup or extra test coverage pays off most.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `max_nodes` | integer | no | minimum=1; maximum=50000; default=10000 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |
| `include_external` | boolean | no | default=false |
| `include_tests` | boolean | no | default=false |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `check_architecture`

Verify declared boundary rules still hold. Use to confirm a change did not introduce a forbidden cross-boundary dependency.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `policies` | array | yes | maxItems=50 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `limit` | integer | no | minimum=1; maximum=100; default=100 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `suggest_location`

Decide where new code for a feature belongs. Ranks existing boundaries by lexical and dependency fit so a new file lands in a cohesive place.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `feature_description` | string | yes | minLength=1; maxLength=2000 |
| `limit` | integer | no | minimum=1; maximum=20; default=5 |
| `max_members` | integer | no | minimum=1; maximum=50000; default=20000 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |
| `ranking_mode` | string | no | default="deterministic"; enum=deterministic, semantic_if_available |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `change_impact`

Blend static blast radius with recent Git churn to prioritize review. Use when you want risk-ranked impact, not just a reachable-set list.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `symbol` | string | yes | minLength=1 |
| `since_days` | integer | no | minimum=1; maximum=3650; default=90 |
| `max_commits` | integer | no | minimum=1; maximum=5000; default=500 |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 |
| `limit` | integer | no | minimum=1; maximum=100; default=100 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `changed_files_impact`

Map a set of changed files (explicit or from a Git diff) to the components they affect. Use to scope review or tests to what a change actually touches.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `files` | array | no | maxItems=50 |
| `working_tree` | boolean | no | default=false |
| `base_ref` | string | no | minLength=1; maxLength=200 |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 |
| `limit` | integer | no | minimum=1; maximum=100; default=100 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_context`

Assemble a bounded, task-shaped evidence bundle (summary + likely location + impact + dossiers) for a coding task in one call. Use at the start of a task to load just-enough context cheaply.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `project_id` | string | yes | minLength=1 |
| `task_description` | string | no | maxLength=2000 |
| `files` | array | no | maxItems=50 |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1500 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `export_diagram`

Render the current graph as Mermaid or PlantUML source. Use to embed an up-to-date architecture diagram in docs without a renderer.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `format` | string | no | default="mermaid"; enum=mermaid, plantuml |
| `boundary` | string | no | minLength=1 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `direction` | string | no | default="LR"; enum=LR, TB |
| `max_nodes` | integer | no | minimum=1; maximum=400; default=200 |
| `max_edges` | integer | no | minimum=1; maximum=1000; default=500 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `list_boundaries`

List the architecture boundaries and sample members. Use to learn how the codebase is partitioned before navigating it.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `source` | string | no | enum=explicit, inferred |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `search_architecture`

Search components by name, attribute, or role with structured filters. Use when you know a trait of what you want but not its exact name.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `verbosity` | string | no | default="compact"; enum=compact, full |
| `max_chars` | integer | no | minimum=4000; maximum=100000 |
| `project_id` | string | yes | minLength=1 |
| `query` | string | yes | minLength=1 |
| `kinds` | array | no | maxItems=20 |
| `roles` | array | no | maxItems=20 |
| `boundary_ids` | array | no | maxItems=20 |
| `confidences` | array | no | maxItems=20 |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `remove_project`

Delete a project and its stored graph. Preview by default; pass the confirm flag to actually remove. Use to clean up projects you no longer query.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `execute` | boolean | no | default=false |

Annotations: read-only `no`; destructive `yes`; idempotent `no`; open-world `no`.

## `cleanup_stale_scans`

Remove failed, cancelled, or abandoned scan records. Preview by default. Use for housekeeping when the scan history is cluttered.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `older_than_hours` | integer | no | minimum=1; maximum=8760; default=24 |
| `execute` | boolean | no | default=false |

Annotations: read-only `no`; destructive `yes`; idempotent `yes`; open-world `no`.

## `maintain_database`

Check integrity or run a checkpoint/optimize/backup of the graph store. Use for routine upkeep or before an upgrade.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `action` | string | yes | enum=integrity, checkpoint, optimize, backup |
| `execute` | boolean | no | default=false |
| `backup_name` | string | no | minLength=8; maxLength=127 |

Annotations: read-only `no`; destructive `no`; idempotent `no`; open-world `no`.
