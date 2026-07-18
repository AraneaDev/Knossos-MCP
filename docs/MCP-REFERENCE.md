# MCP tool reference

This file is generated from the live `ToolService` definitions; edit the source schemas, not this file.

## `list_projects`

List persisted projects, active snapshots, freshness, and bounded graph counts.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |
| `include_roots` | boolean | no | default=false |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `scan_project`

Scan an allowed project root into the evidence-backed architecture graph.

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

List the active scan and bounded immutable retained snapshot metadata.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `find_component`

Find architecture components by canonical or display name with ranked ambiguity candidates.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `name` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `snapshot_diff`

Compare retained or active snapshots and report a bounded architectural changelog.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `from_snapshot` | string | yes | minLength=1 |
| `to_snapshot` | string | no | minLength=1; default="active" |
| `max_changes` | integer | no | minimum=1; maximum=1000; default=200 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `quality_gate`

Evaluate reviewed architecture budgets against a retained baseline and active graph.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `baseline_snapshot` | string | yes | minLength=1 |
| `budgets` | object | yes | — |
| `policies` | array | no | maxItems=50 |
| `sarif` | boolean | no | default=false |
| `propose_baseline` | boolean | no | default=false |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_trends`

Report bounded snapshot metrics and optional Markdown architecture release notes.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=2; maximum=20; default=10 |
| `release_from` | string | no | minLength=1 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `inspect_component`

Return one component dossier with roles, boundaries, containment, relationships, and evidence.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `component` | string | yes | minLength=1 |
| `max_relationships` | integer | no | minimum=1; maximum=100; default=25 |
| `max_children` | integer | no | minimum=1; maximum=100; default=25 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_summary`

Summarize the active architecture snapshot by language, node kind, and relationship kind.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `file_metrics`

List per-file byte size and physical line count for the active snapshot, filterable by path or language and sortable by path or line count.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `path_contains` | string | no | minLength=1; maxLength=1000 |
| `language` | string | no | minLength=1; maxLength=100 |
| `sort_by` | string | no | default="line_count"; enum=path, line_count |
| `order` | string | no | default="desc"; enum=asc, desc |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `explain_flow`

Find and explain bounded, evidence-backed plausible static paths between two components.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
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

Find a bounded conservative static blast radius by traversing dependencies in reverse.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `symbol` | string | yes | minLength=1 |
| `max_depth` | integer | no | minimum=1; maximum=8; default=4 |
| `limit` | integer | no | minimum=1; maximum=100; default=100 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `dependency_cycles`

Find bounded, evidence-backed strongly connected components in the static dependency graph.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `max_nodes` | integer | no | minimum=1; maximum=50000; default=10000 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `architecture_health`

Rank bounded static hubs, structural hotspots, and uncertain unreferenced-code candidates.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `edge_kinds` | array | no | maxItems=20 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `limit` | integer | no | minimum=1; maximum=100; default=20 |
| `max_nodes` | integer | no | minimum=1; maximum=50000; default=10000 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `check_architecture`

Evaluate strict declared boundary dependency policies against the bounded static graph.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `policies` | array | yes | maxItems=50 |
| `min_confidence` | string | no | default="possible"; enum=certain, probable, possible |
| `limit` | integer | no | minimum=1; maximum=100; default=100 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `suggest_location`

Deterministically rank existing boundaries for a feature using lexical evidence and dependency cohesion.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `feature_description` | string | yes | minLength=1; maxLength=2000 |
| `limit` | integer | no | minimum=1; maximum=20; default=5 |
| `max_members` | integer | no | minimum=1; maximum=50000; default=20000 |
| `max_edges` | integer | no | minimum=1; maximum=100000; default=20000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1000 |
| `ranking_mode` | string | no | default="deterministic"; enum=deterministic, semantic_if_available |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `change_impact`

Blend bounded reverse static impact with recent read-only Git file change signals.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
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

Map explicit files or an opt-in read-only Git diff to direct and statically impacted components.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
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

Build a character-budgeted architecture context bundle for a coding task or changed files.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `task_description` | string | no | maxLength=2000 |
| `files` | array | no | maxItems=50 |
| `max_chars` | integer | no | minimum=4000; maximum=100000; default=30000 |
| `timeout_ms` | integer | no | minimum=1; maximum=5000; default=1500 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `export_diagram`

Render bounded active static graph source as deterministic Mermaid or PlantUML.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
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

List explicit and inferred architecture boundaries with bounded member samples.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `source` | string | no | enum=explicit, inferred |
| `limit` | integer | no | minimum=1; maximum=100; default=50 |
| `offset` | integer | no | minimum=0; maximum=100000; default=0 |

Annotations: read-only `yes`; destructive `no`; idempotent `yes`; open-world `no`.

## `search_architecture`

Search component names, attributes, and roles with structured boundary and confidence filters.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
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

Preview or explicitly remove a persisted project and all of its stored graph data.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `execute` | boolean | no | default=false |

Annotations: read-only `no`; destructive `yes`; idempotent `no`; open-world `no`.

## `cleanup_stale_scans`

Preview or remove unreferenced failed, cancelled, or abandoned scan records.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `project_id` | string | yes | minLength=1 |
| `older_than_hours` | integer | no | minimum=1; maximum=8760; default=24 |
| `execute` | boolean | no | default=false |

Annotations: read-only `no`; destructive `yes`; idempotent `yes`; open-world `no`.

## `maintain_database`

Check integrity or preview/run a checkpoint, optimization, or contained atomic backup.

| Input | Type | Required | Constraints/default |
| --- | --- | --- | --- |
| `action` | string | yes | enum=integrity, checkpoint, optimize, backup |
| `execute` | boolean | no | default=false |
| `backup_name` | string | no | minLength=8; maxLength=127 |

Annotations: read-only `no`; destructive `no`; idempotent `no`; open-world `no`.
