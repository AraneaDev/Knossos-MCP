<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;

final readonly class ToolService
{
    public function __construct(
        private ProjectScanService $scanner,
        private ArchitectureQueryService $queries,
        private DatabaseMaintenanceService $maintenance,
        private ResultEnricher $enricher,
    ) {}

    /** @return list<array<string, mixed>> */
    public function definitions(): array
    {
        return [
            ...self::projectDefinitions(),
            ...self::componentDefinitions(),
            ...self::analysisDefinitions(),
            ...self::maintenanceDefinitions(),
        ];
    }

    /**
     * Project catalogue, scanning, retained history, and CI gate tools.
     *
     * @return list<array<string, mixed>>
     */
    private static function projectDefinitions(): array
    {
        return [
            [
                'name' => 'list_projects',
                'title' => 'List projects',
                'description' => 'Start here to find a project_id. Lists scanned projects with freshness and graph size so you can pick the right project_id before any other call.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                        'include_roots' => ['type' => 'boolean', 'default' => false],
                    ],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'scan_project',
                'title' => 'Scan project',
                'description' => 'Build or refresh a project\'s architecture graph. Run this first for a new project, or when a query reports the graph is missing or stale.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'path' => ['type' => 'string', 'minLength' => 1, 'description' => 'Absolute path under a configured allowed root.'],
                        'name' => ['type' => 'string', 'minLength' => 1],
                        'mode' => ['type' => 'string', 'enum' => ['auto', 'full', 'incremental'], 'default' => 'auto'],
                        'max_files' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000],
                        'max_file_bytes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000000],
                        'worker_timeout_ms' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 120000, 'default' => 30000],
                        'snapshot_retention' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20, 'default' => 5],
                        'boundaries' => [
                            'type' => 'array', 'maxItems' => 50,
                            'items' => ['type' => 'object', 'properties' => [
                                'name' => ['type' => 'string', 'minLength' => 1],
                                'path_prefix' => ['type' => 'string'],
                                'namespace_prefix' => ['type' => 'string'],
                            ], 'required' => ['name'], 'additionalProperties' => false],
                        ],
                    ],
                    'required' => ['path'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'list_snapshots',
                'title' => 'List snapshots',
                'description' => 'See a project\'s scan history. Use to find an older snapshot id to diff against or to check when it was last scanned.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'snapshot_diff',
                'title' => 'Snapshot diff',
                'description' => 'See what changed architecturally between two scans. Use after a rescan to review added/removed components and relationships instead of eyeballing a code diff.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'from_snapshot' => ['type' => 'string', 'minLength' => 1],
                        'to_snapshot' => ['type' => 'string', 'minLength' => 1, 'default' => 'active'],
                        'max_changes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 200],
                    ],
                    'required' => ['project_id', 'from_snapshot'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'quality_gate',
                'title' => 'Architecture quality gate',
                'description' => 'Check architecture budgets against a baseline in CI. Use to fail a build on regressions (new cycles, boundary breaks) rather than reviewing them by hand.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'baseline_snapshot' => ['type' => 'string', 'minLength' => 1],
                        'budgets' => ['type' => 'object', 'properties' => [
                            'new_cycles' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                            'boundary_violations' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                            'error_diagnostics' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                            'warning_diagnostics' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                            'hub_degree_growth' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                            'unreferenced_candidates' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                            'public_surface_changes' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000],
                        ], 'additionalProperties' => false],
                        'policies' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object']],
                        'sarif' => ['type' => 'boolean', 'default' => false],
                        'propose_baseline' => ['type' => 'boolean', 'default' => false],
                    ],
                    'required' => ['project_id', 'baseline_snapshot', 'budgets'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'architecture_trends',
                'title' => 'Architecture trends',
                'description' => 'See how architecture metrics moved over recent scans. Use for release notes or to spot slow structural drift.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 2, 'maximum' => 20, 'default' => 10],
                        'release_from' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * Component lookup, dossier, summary, and file metric tools.
     *
     * @return list<array<string, mixed>>
     */
    private static function componentDefinitions(): array
    {
        return [
            [
                'name' => 'find_component',
                'title' => 'Find component',
                'description' => 'Locate a component by name when you are unsure of its exact canonical path. Returns ranked candidates — use before inspect_component when the name is ambiguous.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'name' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    ],
                    'required' => ['project_id', 'name'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'inspect_component',
                'title' => 'Inspect component',
                'description' => 'Get the full dossier for one component — its roles, boundary, containment, relationships, and evidence — in a single call. Faster than opening and cross-referencing several files by hand.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'component' => ['type' => 'string', 'minLength' => 1],
                        'max_relationships' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        'max_children' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 25],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                    ],
                    'required' => ['project_id', 'component'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'list_usages',
                'title' => 'List usages',
                'description' => 'List every usage site of a symbol with file:line evidence — one row per occurrence. Use instead of grepping for callers; unlike impact_analysis this shows the exact call sites, not the transitive set.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'symbol' => ['type' => 'string', 'minLength' => 1],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 100],
                    ],
                    'required' => ['project_id', 'symbol'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'architecture_summary',
                'title' => 'Architecture summary',
                'description' => 'Get a one-call overview of the codebase by language, node kind, and relationship kind. Use to orient yourself in an unfamiliar project before drilling in.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'export_agent_brief',
                'title' => 'Export agent brief',
                'description' => 'Render a compact markdown orientation brief (boundaries, entry points, key hubs) sized for a CLAUDE.md/AGENTS.md section, so future agent sessions start pre-oriented without tool calls.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'max_chars' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 20000, 'default' => 4000, 'description' => 'Brief size budget; whole sections are omitted (and reported) to fit.'],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            self::fileMetricsDefinition(),
            [
                'name' => 'list_annotations',
                'title' => 'List annotations',
                'description' => 'List durable agent annotations recorded on components, optionally filtered by component or kind. Use to review or audit prior annotate_component calls.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'component' => ['type' => 'string', 'minLength' => 1],
                        'kind' => ['type' => 'string', 'enum' => ['intended_boundary', 'confirmed_dead', 'false_positive', 'note']],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * Graph traversal, impact, policy, diagram, and search tools.
     *
     * @return list<array<string, mixed>>
     */
    private static function analysisDefinitions(): array
    {
        return [
            [
                'name' => 'explain_flow',
                'title' => 'Explain flow',
                'description' => 'Answer \'how does A reach B?\' Traces evidence-backed static paths between two components — more reliable than grepping call sites across layers.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'from' => ['type' => 'string', 'minLength' => 1],
                        'to' => ['type' => 'string', 'minLength' => 1],
                        'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 6],
                        'max_paths' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id', 'from', 'to'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'impact_analysis',
                'title' => 'Impact analysis',
                'description' => 'Before editing a symbol, find everything that depends on it. Answers \'what breaks if I change this?\' by following real static references, so it is more complete than grepping for callers.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'symbol' => ['type' => 'string', 'minLength' => 1],
                        'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 4],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id', 'symbol'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'dependency_cycles',
                'title' => 'Dependency cycles',
                'description' => 'Find circular dependencies. Use before a refactor to see which modules are tangled, instead of tracing imports by hand.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                        'max_nodes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50000, 'default' => 10000],
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 20000],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'architecture_health',
                'title' => 'Architecture health',
                'description' => 'Rank the structural hotspots, hubs, and likely-dead code. Use to decide where cleanup or extra test coverage pays off most.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                        'max_nodes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50000, 'default' => 10000],
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 20000],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                        'include_external' => ['type' => 'boolean', 'default' => false, 'description' => 'Include external/unresolved symbols (builtins, vendor targets) in hubs and hotspots.'],
                        'include_tests' => ['type' => 'boolean', 'default' => false, 'description' => 'Include test-role components in hubs and hotspots.'],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'check_architecture',
                'title' => 'Check architecture policies',
                'description' => 'Verify declared boundary rules still hold. Use to confirm a change did not introduce a forbidden cross-boundary dependency.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'policies' => [
                            'type' => 'array', 'minItems' => 1, 'maxItems' => 50,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                                    'from_boundary' => ['type' => 'string', 'minLength' => 1],
                                    'allow_targets' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1]],
                                    'deny_targets' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1]],
                                    'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'minLength' => 1]],
                                ],
                                'required' => ['id', 'from_boundary'],
                                'anyOf' => [['required' => ['allow_targets']], ['required' => ['deny_targets']]],
                                'additionalProperties' => false,
                            ],
                        ],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 20000],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id', 'policies'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'suggest_location',
                'title' => 'Suggest location',
                'description' => 'Decide where new code for a feature belongs. Ranks existing boundaries by lexical and dependency fit so a new file lands in a cohesive place.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'feature_description' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20, 'default' => 5],
                        'max_members' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50000, 'default' => 20000],
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 20000],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                        'ranking_mode' => ['type' => 'string', 'enum' => ['deterministic', 'semantic_if_available'], 'default' => 'deterministic'],
                    ],
                    'required' => ['project_id', 'feature_description'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'change_impact',
                'title' => 'Change-aware impact',
                'description' => 'Blend static blast radius with recent Git churn to prioritize review. Use when you want risk-ranked impact, not just a reachable-set list.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'symbol' => ['type' => 'string', 'minLength' => 1],
                        'since_days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650, 'default' => 90],
                        'max_commits' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 500],
                        'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 4],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id', 'symbol'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'changed_files_impact',
                'title' => 'Changed files impact',
                'description' => 'Map a set of changed files (explicit or from a Git diff) to the components they affect. Use to scope review or tests to what a change actually touches.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'files' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1]],
                        'working_tree' => ['type' => 'boolean', 'default' => false],
                        'base_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                        'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 4],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            ...self::changeAnalysisDefinitions(),
        ];
    }

    /**
     * Test-impact, review, context, diagram, boundary, and search tools.
     *
     * @return list<array<string, mixed>>
     */
    private static function changeAnalysisDefinitions(): array
    {
        return [
            [
                'name' => 'test_impact',
                'title' => 'Test impact',
                'description' => 'Map a change set to the test files that statically exercise it, ranked by distance. Use to run the relevant tests first in an edit-test loop; the list is a lower bound, not a substitute for the full suite.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'files' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1]],
                        'working_tree' => ['type' => 'boolean', 'default' => false],
                        'base_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                        'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 4],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'review_diff',
                'title' => 'Review diff',
                'description' => 'One-call architectural review of a change set: blast radius, boundary-policy violations touching the change, quality-gate delta vs the last snapshot, and cycles the change participates in. Defaults to the working tree; policies/budgets default to knossos.json.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'base_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                        'files' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1]],
                        'policies' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'object']],
                        'budgets' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000]],
                        'baseline_snapshot' => ['type' => 'string', 'minLength' => 1],
                        'max_depth' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8, 'default' => 4],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 100],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'architecture_context',
                'title' => 'Architecture context',
                'description' => 'Assemble a bounded, task-shaped evidence bundle (summary + likely location + impact + dossiers) for a coding task in one call. Use at the start of a task to load just-enough context cheaply.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'verbosity' => ['type' => 'string', 'enum' => ['compact', 'full'], 'default' => 'compact', 'description' => 'compact (default) trims evidence to a preview; full returns all evidence.'],
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'task_description' => ['type' => 'string', 'maxLength' => 2000],
                        'files' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1]],
                        'max_chars' => ['type' => 'integer', 'minimum' => 4000, 'maximum' => 100000, 'default' => 30000],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1500],
                        'include_source' => ['type' => 'boolean', 'default' => false, 'description' => 'Inline bounded source snippets (≤40 lines each) for the included dossiers, read from the working tree.'],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'export_diagram',
                'title' => 'Export diagram',
                'description' => 'Render the current graph as Mermaid or PlantUML source. Use to embed an up-to-date architecture diagram in docs without a renderer.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'format' => ['type' => 'string', 'enum' => ['mermaid', 'plantuml'], 'default' => 'mermaid'],
                        'boundary' => ['type' => 'string', 'minLength' => 1],
                        'edge_kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                        'min_confidence' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible'], 'default' => 'possible'],
                        'direction' => ['type' => 'string', 'enum' => ['LR', 'TB'], 'default' => 'LR'],
                        'max_nodes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 400, 'default' => 200],
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 500],
                    ],
                    'required' => ['project_id'],
                    'additionalProperties' => false,
                ],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'list_boundaries', 'title' => 'List boundaries',
                'description' => 'List the architecture boundaries and sample members. Use to learn how the codebase is partitioned before navigating it.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    ...self::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'source' => ['type' => 'string', 'enum' => ['explicit', 'inferred']],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                ], 'required' => ['project_id'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'search_architecture', 'title' => 'Search architecture',
                'description' => 'Search components by name, attribute, or role with structured filters. Use when you know a trait of what you want but not its exact name.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    ...self::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'query' => ['type' => 'string', 'minLength' => 1],
                    'kinds' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                    'roles' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                    'boundary_ids' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string']],
                    'confidences' => ['type' => 'array', 'maxItems' => 20, 'items' => ['type' => 'string', 'enum' => ['certain', 'probable', 'possible']]],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                ], 'required' => ['project_id', 'query'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * Destructive or state-changing upkeep tools.
     *
     * @return list<array<string, mixed>>
     */
    private static function maintenanceDefinitions(): array
    {
        return [
            [
                'name' => 'annotate_component', 'title' => 'Annotate component',
                'description' => 'Record a durable annotation on a component (intended_boundary, confirmed_dead, false_positive, note) that survives rescans. false_positive annotations remove the component from dead-code candidates. Preview by default; pass execute to apply.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'component' => ['type' => 'string', 'minLength' => 1],
                    'kind' => ['type' => 'string', 'enum' => ['intended_boundary', 'confirmed_dead', 'false_positive', 'note']],
                    'value' => ['type' => 'string', 'maxLength' => 2000, 'default' => ''],
                    'remove' => ['type' => 'boolean', 'default' => false],
                    'execute' => ['type' => 'boolean', 'default' => false],
                ], 'required' => ['project_id', 'component', 'kind'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'remove_project', 'title' => 'Remove project',
                'description' => 'Delete a project and its stored graph. Preview by default; pass the confirm flag to actually remove. Use to clean up projects you no longer query.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'execute' => ['type' => 'boolean', 'default' => false],
                ], 'required' => ['project_id'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
            [
                'name' => 'cleanup_stale_scans', 'title' => 'Clean up stale scans',
                'description' => 'Remove failed, cancelled, or abandoned scan records. Preview by default. Use for housekeeping when the scan history is cluttered.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'older_than_hours' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8760, 'default' => 24],
                    'execute' => ['type' => 'boolean', 'default' => false],
                ], 'required' => ['project_id'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'maintain_database', 'title' => 'Maintain database',
                'description' => 'Check integrity or run a checkpoint/optimize/backup of the graph store. Use for routine upkeep or before an upgrade.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['integrity', 'checkpoint', 'optimize', 'backup']],
                    'execute' => ['type' => 'boolean', 'default' => false],
                    'backup_name' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 127],
                ], 'required' => ['action'], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * Properties shared by every read tool; handled centrally in call(), so
     * handlers' keys() allow-lists never see them.
     *
     * @return array<string, mixed>
     */
    private static function commonReadProperties(): array
    {
        return [
            'verbosity' => ['type' => 'string', 'enum' => ['compact', 'full'], 'default' => 'compact', 'description' => 'compact (default) trims evidence to a preview; full returns all evidence.'],
            'max_chars' => ['type' => 'integer', 'minimum' => 4000, 'maximum' => 100000, 'description' => 'Byte budget for the serialized result; oversized list fields are trimmed tail-first and reported in meta.dropped_items.'],
            'refresh_if_stale' => ['type' => 'boolean', 'default' => false, 'description' => 'If the graph is stale, run an incremental rescan (of Knossos\'s own derived database only) before answering; a failed rescan serves the last complete graph with a warning. A missing graph still requires scan_project.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function fileMetricsDefinition(): array
    {
        return [
            'name' => 'file_metrics',
            'title' => 'File metrics',
            'description' => 'Find the largest or longest files. Use to spot refactor targets without shelling out to wc/find.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    ...self::commonReadProperties(),
                    'project_id' => ['type' => 'string', 'minLength' => 1],
                    'path_contains' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 1000],
                    'language' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
                    'sort_by' => ['type' => 'string', 'enum' => ['path', 'line_count'], 'default' => 'line_count'],
                    'order' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                    'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50],
                    'offset' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'default' => 0],
                ],
                'required' => ['project_id'],
                'additionalProperties' => false,
            ],
            'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
        ];
    }

    /** @param array<string, mixed> $arguments */
    public function call(string $name, array $arguments, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $verbosity = 'compact';
        if (array_key_exists('verbosity', $arguments)) {
            $verbosity = $arguments['verbosity'];
            unset($arguments['verbosity']);
            if ($verbosity !== 'compact' && $verbosity !== 'full') {
                throw new InvalidArgumentException('verbosity must be "compact" or "full".');
            }
        }
        $maxChars = null;
        if (array_key_exists('max_chars', $arguments) && !in_array($name, ['architecture_context', 'export_agent_brief'], true)) {
            $maxChars = $arguments['max_chars'];
            unset($arguments['max_chars']);
            if (!is_int($maxChars) || $maxChars < 4000 || $maxChars > 100_000) {
                throw new InvalidArgumentException('max_chars must be an integer between 4000 and 100000.');
            }
        }
        $refreshWarnings = [];
        if (array_key_exists('refresh_if_stale', $arguments)) {
            $refresh = $arguments['refresh_if_stale'];
            unset($arguments['refresh_if_stale']);
            if (!is_bool($refresh)) {
                throw new InvalidArgumentException('refresh_if_stale must be a boolean.');
            }
            if ($refresh && $name !== 'scan_project') {
                $refreshWarnings = $this->refreshIfStale($arguments, $cancellation);
            }
        }
        $envelope = $this->dispatch($name, $arguments, $cancellation);
        if ($refreshWarnings !== []) {
            $envelope = $envelope->withWarnings($refreshWarnings);
        }
        return $this->enricher->enrich($envelope, $name, $verbosity, $maxChars);
    }

    /**
     * Opt-in self-healing: when the target project is stale, run an
     * incremental rescan before dispatching the query. A failed rescan never
     * blocks the answer — the last complete graph is served with a warning,
     * matching the recovery model. A missing graph is not auto-healed: the
     * first full scan is an expensive, user-visible choice.
     *
     * @param array<string, mixed> $arguments
     * @return list<string>
     */
    private function refreshIfStale(array $arguments, ?CancellationToken $cancellation): array
    {
        $projectId = $arguments['project_id'] ?? null;
        if (!is_string($projectId) || $projectId === '') {
            return [];
        }
        $staleness = $this->queries->staleness($projectId);
        if (($staleness['state'] ?? null) !== 'stale') {
            return [];
        }
        $root = $this->queries->projectRoot($projectId);
        if ($root === null) {
            return ['refresh_if_stale: the project root is unknown; serving the last complete graph.'];
        }
        try {
            $this->scanner->scan($root, cancellation: $cancellation);
            return [];
        } catch (\Throwable $error) {
            return [sprintf('refresh_if_stale: rescan failed (%s); serving the last complete graph.', $error->getMessage())];
        }
    }

    /** @param array<string, mixed> $arguments */
    private function dispatch(string $name, array $arguments, ?CancellationToken $cancellation): ResultEnvelope
    {
        return match ($name) {
            'list_projects' => $this->projects($arguments),
            'scan_project' => $this->scan($arguments, $cancellation),
            'list_snapshots' => $this->snapshots($arguments),
            'snapshot_diff' => $this->snapshotDiff($arguments),
            'quality_gate' => $this->qualityGate($arguments),
            'architecture_trends' => $this->architectureTrends($arguments),
            'find_component' => $this->find($arguments),
            'inspect_component' => $this->inspect($arguments),
            'list_usages' => $this->listUsages($arguments),
            'architecture_summary' => $this->summary($arguments),
            'export_agent_brief' => $this->exportAgentBrief($arguments),
            'file_metrics' => $this->fileMetrics($arguments),
            'explain_flow' => $this->flow($arguments),
            'impact_analysis' => $this->impact($arguments),
            'dependency_cycles' => $this->cycles($arguments),
            'architecture_health' => $this->health($arguments),
            'check_architecture' => $this->check($arguments),
            'suggest_location' => $this->suggest($arguments),
            'change_impact' => $this->changeImpact($arguments),
            'changed_files_impact' => $this->changedFilesImpact($arguments),
            'test_impact' => $this->testImpact($arguments),
            'review_diff' => $this->reviewDiff($arguments),
            'architecture_context' => $this->architectureContext($arguments),
            'export_diagram' => $this->diagram($arguments),
            'list_boundaries' => $this->boundaries($arguments),
            'search_architecture' => $this->search($arguments),
            'list_annotations' => $this->listAnnotations($arguments),
            'annotate_component' => $this->annotateComponent($arguments),
            'remove_project' => $this->removeProject($arguments),
            'cleanup_stale_scans' => $this->cleanupStaleScans($arguments),
            'maintain_database' => $this->maintainDatabase($arguments),
            default => throw new InvalidArgumentException(sprintf('Unknown tool: %s', $name)),
        };
    }

    /** @param array<string, mixed> $arguments */
    private function annotateComponent(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'component', 'kind'], ['value', 'remove', 'execute']);
        return $this->queries->annotateComponent(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'component'),
            self::string($arguments, 'kind'),
            array_key_exists('value', $arguments) && is_string($arguments['value']) ? $arguments['value'] : '',
            self::boolean($arguments, 'remove', false),
            self::boolean($arguments, 'execute', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function removeProject(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['execute']);
        return $this->maintenance->removeProject(
            self::string($arguments, 'project_id'),
            self::boolean($arguments, 'execute', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function cleanupStaleScans(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['older_than_hours', 'execute']);
        return $this->maintenance->cleanupStaleScans(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'older_than_hours', 24, 1, 8760),
            self::boolean($arguments, 'execute', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function maintainDatabase(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['action'], ['execute', 'backup_name']);
        return $this->maintenance->maintain(
            self::string($arguments, 'action'),
            self::boolean($arguments, 'execute', false),
            array_key_exists('backup_name', $arguments) ? self::string($arguments, 'backup_name') : null,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function projects(array $arguments): ResultEnvelope
    {
        self::keys($arguments, [], ['limit', 'offset', 'include_roots']);
        return $this->queries->listProjects(
            self::integer($arguments, 'limit', 50, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
            self::boolean($arguments, 'include_roots', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function snapshots(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['limit', 'offset']);
        return $this->queries->listSnapshots(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function snapshotDiff(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'from_snapshot'], ['to_snapshot', 'max_changes']);
        return $this->queries->snapshotDiff(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'from_snapshot'),
            array_key_exists('to_snapshot', $arguments) ? self::string($arguments, 'to_snapshot') : 'active',
            self::integer($arguments, 'max_changes', 200, 1, 1000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function qualityGate(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'baseline_snapshot', 'budgets'], ['policies', 'sarif', 'propose_baseline']);
        $budgets = $arguments['budgets'];
        $policies = $arguments['policies'] ?? [];
        if (!is_array($budgets) || array_is_list($budgets) || !is_array($policies) || !array_is_list($policies)) {
            throw new InvalidArgumentException('budgets must be an object and policies must be a list.');
        }
        return $this->queries->qualityGate(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'baseline_snapshot'),
            $budgets,
            $policies,
            self::boolean($arguments, 'sarif', false),
            self::boolean($arguments, 'propose_baseline', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function architectureTrends(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['limit', 'release_from']);
        return $this->queries->architectureTrends(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'limit', 10, 2, 20),
            array_key_exists('release_from', $arguments) ? self::string($arguments, 'release_from') : null,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function scan(array $arguments, ?CancellationToken $cancellation): ResultEnvelope
    {
        self::keys($arguments, ['path'], ['name', 'mode', 'max_files', 'max_file_bytes', 'worker_timeout_ms', 'snapshot_retention', 'boundaries']);
        $path = self::string($arguments, 'path');
        $name = array_key_exists('name', $arguments) ? self::string($arguments, 'name') : null;
        $maxFiles = array_key_exists('max_files', $arguments) ? self::integer($arguments, 'max_files', 100_000, 1, 100_000) : null;
        $maxBytes = array_key_exists('max_file_bytes', $arguments) ? self::integer($arguments, 'max_file_bytes', 2_000_000, 1, 100_000_000) : null;

        return $this->scanner->scan(
            $path,
            $name,
            $maxFiles,
            $maxBytes,
            array_key_exists('boundaries', $arguments) ? self::boundariesArgument($arguments) : null,
            array_key_exists('mode', $arguments) ? self::string($arguments, 'mode') : null,
            $cancellation,
            array_key_exists('snapshot_retention', $arguments) ? self::integer($arguments, 'snapshot_retention', 5, 0, 20) : null,
            array_key_exists('worker_timeout_ms', $arguments) ? self::integer($arguments, 'worker_timeout_ms', 30_000, 1_000, 120_000) : null,
        );
    }

    /** @param array<string, mixed> $arguments */
    private function find(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'name'], ['limit']);
        return $this->queries->findComponent(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'name'),
            self::integer($arguments, 'limit', 20, 1, 100),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function inspect(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'component'], ['max_relationships', 'max_children', 'min_confidence']);
        return $this->queries->inspectComponent(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'component'),
            self::integer($arguments, 'max_relationships', 25, 1, 100),
            self::integer($arguments, 'max_children', 25, 1, 100),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
        );
    }

    /** @param array<string, mixed> $arguments */
    private function listUsages(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'symbol'], ['edge_kinds', 'min_confidence', 'limit']);
        return $this->queries->listUsages(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'symbol'),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 100, 1, 500),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function summary(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['limit']);
        return $this->queries->architectureSummary(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'limit', 50, 1, 100),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function exportAgentBrief(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['max_chars']);
        return $this->queries->exportAgentBrief(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'max_chars', 4000, 1000, 20_000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function fileMetrics(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['path_contains', 'language', 'sort_by', 'order', 'limit', 'offset']);
        return $this->queries->fileMetrics(
            self::string($arguments, 'project_id'),
            array_key_exists('path_contains', $arguments) ? self::string($arguments, 'path_contains') : null,
            array_key_exists('language', $arguments) ? self::string($arguments, 'language') : null,
            array_key_exists('sort_by', $arguments) ? self::string($arguments, 'sort_by') : 'line_count',
            array_key_exists('order', $arguments) ? self::string($arguments, 'order') : 'desc',
            self::integer($arguments, 'limit', 50, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function flow(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'from', 'to'], ['max_depth', 'max_paths', 'edge_kinds', 'min_confidence', 'timeout_ms']);
        return $this->queries->explainFlow(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'from'),
            self::string($arguments, 'to'),
            self::integer($arguments, 'max_depth', 6, 1, 8),
            self::integer($arguments, 'max_paths', 5, 1, 20),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function impact(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'symbol'], ['max_depth', 'limit', 'edge_kinds', 'min_confidence', 'timeout_ms']);
        return $this->queries->impactAnalysis(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'symbol'),
            self::integer($arguments, 'max_depth', 4, 1, 8),
            self::integer($arguments, 'limit', 100, 1, 100),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function cycles(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['edge_kinds', 'min_confidence', 'limit', 'max_nodes', 'max_edges', 'timeout_ms']);
        return $this->queries->dependencyCycles(
            self::string($arguments, 'project_id'),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'max_nodes', 10_000, 1, 50_000),
            self::integer($arguments, 'max_edges', 20_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function health(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['edge_kinds', 'min_confidence', 'limit', 'max_nodes', 'max_edges', 'timeout_ms', 'include_external', 'include_tests']);
        return $this->queries->architectureHealth(
            self::string($arguments, 'project_id'),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'max_nodes', 10_000, 1, 50_000),
            self::integer($arguments, 'max_edges', 20_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            self::boolean($arguments, 'include_external', false),
            self::boolean($arguments, 'include_tests', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function check(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'policies'], ['min_confidence', 'limit', 'max_edges', 'timeout_ms']);
        $policies = $arguments['policies'];
        if (!is_array($policies) || !array_is_list($policies)) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        return $this->queries->checkArchitecture(
            self::string($arguments, 'project_id'),
            $policies,
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 100, 1, 100),
            self::integer($arguments, 'max_edges', 20_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function suggest(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'feature_description'], ['limit', 'max_members', 'max_edges', 'timeout_ms', 'ranking_mode']);
        return $this->queries->suggestLocation(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'feature_description'),
            self::integer($arguments, 'limit', 5, 1, 20),
            self::integer($arguments, 'max_members', 20_000, 1, 50_000),
            self::integer($arguments, 'max_edges', 20_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            array_key_exists('ranking_mode', $arguments) ? self::string($arguments, 'ranking_mode') : 'deterministic',
        );
    }

    /** @param array<string, mixed> $arguments */
    private function changeImpact(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'symbol'], ['since_days', 'max_commits', 'max_depth', 'limit', 'edge_kinds', 'min_confidence', 'timeout_ms']);
        return $this->queries->changeImpact(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'symbol'),
            self::integer($arguments, 'since_days', 90, 1, 3650),
            self::integer($arguments, 'max_commits', 500, 1, 5000),
            self::integer($arguments, 'max_depth', 4, 1, 8),
            self::integer($arguments, 'limit', 100, 1, 100),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function changedFilesImpact(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['files', 'working_tree', 'base_ref', 'max_depth', 'limit', 'edge_kinds', 'min_confidence', 'timeout_ms']);
        return $this->queries->changedFilesImpact(
            self::string($arguments, 'project_id'),
            self::strings($arguments, 'files', 50),
            self::boolean($arguments, 'working_tree', false),
            array_key_exists('base_ref', $arguments) ? self::string($arguments, 'base_ref') : null,
            self::integer($arguments, 'max_depth', 4, 1, 8),
            self::integer($arguments, 'limit', 100, 1, 100),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function testImpact(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['files', 'working_tree', 'base_ref', 'max_depth', 'limit', 'edge_kinds', 'min_confidence', 'timeout_ms']);
        return $this->queries->testImpact(
            self::string($arguments, 'project_id'),
            self::strings($arguments, 'files', 50),
            self::boolean($arguments, 'working_tree', false),
            array_key_exists('base_ref', $arguments) ? self::string($arguments, 'base_ref') : null,
            self::integer($arguments, 'max_depth', 4, 1, 8),
            self::integer($arguments, 'limit', 100, 1, 100),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function reviewDiff(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['base_ref', 'files', 'policies', 'budgets', 'baseline_snapshot', 'max_depth', 'limit', 'min_confidence', 'timeout_ms']);
        $policies = $arguments['policies'] ?? null;
        if ($policies !== null && (!is_array($policies) || !array_is_list($policies))) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        $budgets = $arguments['budgets'] ?? null;
        if ($budgets !== null && (!is_array($budgets) || array_is_list($budgets))) {
            throw new InvalidArgumentException('budgets must be an object.');
        }
        return $this->queries->reviewDiff(
            self::string($arguments, 'project_id'),
            array_key_exists('base_ref', $arguments) ? self::string($arguments, 'base_ref') : null,
            self::strings($arguments, 'files', 50),
            $policies,
            $budgets,
            array_key_exists('baseline_snapshot', $arguments) ? self::string($arguments, 'baseline_snapshot') : null,
            self::integer($arguments, 'max_depth', 4, 1, 8),
            self::integer($arguments, 'limit', 100, 1, 100),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function architectureContext(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['task_description', 'files', 'max_chars', 'timeout_ms', 'include_source']);
        return $this->queries->architectureContext(
            self::string($arguments, 'project_id'),
            array_key_exists('task_description', $arguments) ? self::string($arguments, 'task_description') : '',
            self::strings($arguments, 'files', 50),
            self::integer($arguments, 'max_chars', 30_000, 4000, 100_000),
            self::integer($arguments, 'timeout_ms', 1500, 1, 5000),
            self::boolean($arguments, 'include_source', false),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function diagram(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['format', 'boundary', 'edge_kinds', 'min_confidence', 'direction', 'max_nodes', 'max_edges']);
        return $this->queries->exportDiagram(
            self::string($arguments, 'project_id'),
            array_key_exists('format', $arguments) ? self::string($arguments, 'format') : 'mermaid',
            array_key_exists('boundary', $arguments) ? self::string($arguments, 'boundary') : null,
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            array_key_exists('direction', $arguments) ? self::string($arguments, 'direction') : 'LR',
            self::integer($arguments, 'max_nodes', 200, 1, 400),
            self::integer($arguments, 'max_edges', 500, 1, 1000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function boundaries(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['source', 'limit', 'offset']);
        return $this->queries->listBoundaries(
            self::string($arguments, 'project_id'),
            array_key_exists('source', $arguments) ? self::string($arguments, 'source') : null,
            self::integer($arguments, 'limit', 50, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function search(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id', 'query'], ['kinds', 'roles', 'boundary_ids', 'confidences', 'limit', 'offset']);
        return $this->queries->searchArchitecture(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'query'),
            self::strings($arguments, 'kinds'),
            self::strings($arguments, 'roles'),
            self::strings($arguments, 'boundary_ids'),
            self::strings($arguments, 'confidences'),
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /** @param array<string, mixed> $arguments */
    private function listAnnotations(array $arguments): ResultEnvelope
    {
        self::keys($arguments, ['project_id'], ['component', 'kind', 'limit', 'offset']);
        return $this->queries->listAnnotations(
            self::string($arguments, 'project_id'),
            array_key_exists('component', $arguments) ? self::string($arguments, 'component') : null,
            array_key_exists('kind', $arguments) ? self::string($arguments, 'kind') : null,
            self::integer($arguments, 'limit', 100, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /** @param array<string, mixed> $arguments @param list<string> $required @param list<string> $optional */
    private static function keys(array $arguments, array $required, array $optional): void
    {
        foreach ($required as $key) {
            if (!array_key_exists($key, $arguments)) {
                throw new InvalidArgumentException(sprintf('Missing required argument: %s', $key));
            }
        }
        $unknown = array_diff(array_keys($arguments), [...$required, ...$optional]);
        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Unknown argument: %s', reset($unknown)));
        }
    }

    /** @param array<string, mixed> $arguments */
    private static function string(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $arguments */
    private static function integer(array $arguments, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $arguments[$key] ?? $default;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be an integer between %d and %d.', $key, $minimum, $maximum));
        }
        return $value;
    }

    /** @param array<string, mixed> $arguments */
    private static function boolean(array $arguments, string $key, bool $default): bool
    {
        $value = $arguments[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }
        return $value;
    }

    /** @param array<string, mixed> $arguments @return list<string> */
    private static function strings(array $arguments, string $key, int $maximum = 20): array
    {
        $value = $arguments[$key] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be a list of at most %d strings.', $key, $maximum));
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw new InvalidArgumentException(sprintf('%s must contain non-empty strings.', $key));
            }
        }
        return $value;
    }

    /** @param array<string, mixed> $arguments @return list<array<string, mixed>> */
    private static function boundariesArgument(array $arguments): array
    {
        $values = $arguments['boundaries'] ?? [];
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            throw new InvalidArgumentException('boundaries must be a list of at most 50 objects.');
        }
        foreach ($values as $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException('Each boundary must be an object.');
            }
            self::keys($value, ['name'], ['path_prefix', 'namespace_prefix']);
            self::string($value, 'name');
            $matchers = (int) array_key_exists('path_prefix', $value) + (int) array_key_exists('namespace_prefix', $value);
            if ($matchers !== 1) {
                throw new InvalidArgumentException('Each boundary requires exactly one matcher.');
            }
            self::string($value, array_key_exists('path_prefix', $value) ? 'path_prefix' : 'namespace_prefix');
        }
        return $values;
    }
}
