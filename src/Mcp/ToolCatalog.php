<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * The MCP tool catalogue: every tool this server advertises, and its input schema.
 *
 * Kept apart from ToolService because the two answer different questions. This
 * is a declaration of the protocol surface — names, descriptions, JSON Schemas,
 * behavioural annotations — which changes when a tool is added or its arguments
 * move. ToolService is the machinery that validates a call against that surface
 * and routes it. Holding both put roughly 750 lines of literal schema in front
 * of the dispatch logic in one 1,719-line file.
 *
 * The generated reference in docs/reference/mcp-tools.md is produced from these
 * definitions, so a change here is a change to the documented contract.
 */
final readonly class ToolCatalog
{
    /**
     * Every tool definition, including those requiring runtime wiring; the superset argument validation uses.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(bool $withEnvironment = true): array
    {
        return [
            // Advertised only when the server can actually answer them, so the
            // tool list never promises something this wiring cannot do.
            ...($withEnvironment ? self::environmentDefinitions() : []),
            ...self::projectDefinitions(),
            ...self::componentDefinitions(),
            ...self::analysisDefinitions(),
            ...self::maintenanceDefinitions(),
        ];
    }

    /**
     * Self-description: where the server may read, and whether it is healthy.
     *
     * @return list<array<string, mixed>>
     */
    private static function environmentDefinitions(): array
    {
        return [
            [
                'name' => 'server_info', 'title' => 'Server info',
                'description' => 'Where this server may read and what it is. Call this first in an unfamiliar setup, or whenever a path is rejected: it returns the allowed roots, the roots file to extend to add a project, the data directory, and whether the server runs in a container (where host paths are not its paths).',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
            [
                'name' => 'diagnose_runtime', 'title' => 'Diagnose runtime',
                'description' => 'Check the runtimes, scanner workers, protocol, database, and migrations. Use when a scan fails for no obvious reason. Slower than server_info because it starts each language worker.',
                'inputSchema' => ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false],
                'annotations' => ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false],
            ],
        ];
    }

    /**
     * The declared property names and required keys for a tool, or null when
     * the tool name is unknown. Memoized because definitions() is pure data.
     *
     * @return array{properties: list<string>, required: list<string>}|null
     */
    public static function schemaFor(string $name): ?array
    {
        /** @var array<string, array{properties: list<string>, required: list<string>}>|null $index */
        static $index = null;
        if ($index === null) {
            $index = [];
            foreach (self::definitions() as $definition) {
                $properties = $definition['inputSchema']['properties'] ?? [];
                $index[$definition['name']] = [
                    'properties' => array_keys(is_array($properties) ? $properties : []),
                    'required' => array_values($definition['inputSchema']['required'] ?? []),
                ];
            }
        }
        return $index[$name] ?? null;
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
                        'worker_timeout_ms' => ['type' => 'integer', 'minimum' => 1000, 'maximum' => 120000, 'description' => 'Per-file worker timeout. Omit to use the project\'s knossos.json setting, or the built-in default (30000) when it is unset.'],
                        'snapshot_retention' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 20, 'description' => 'How many historical snapshots to keep. Omit to use the project\'s knossos.json setting, or the built-in default (5) when it is unset.'],
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
                        'max_changes' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 25],
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
                            'boundary_violations' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100000, 'description' => 'Requires policies: there is nothing to count violations against without them.'],
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
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 100000],
                        'timeout_ms' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5000, 'default' => 1000],
                        'include_self_loops' => ['type' => 'boolean', 'default' => false, 'description' => 'Include single-symbol self-recursion; excluded by default because recursion is not an architectural cycle.'],
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
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 100000],
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
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 100000],
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
                        'max_edges' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 100000],
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
                'description' => 'Map a set of changed files (explicit or from a Git diff) to the components they affect. Use to scope review or tests to what a change actually touches. Provide exactly one source: either files (an explicit list) or working_tree: true (let Git supply the changes). base_ref only applies with working_tree: true — it diffs the working tree against that ref; it cannot be combined with files.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        ...self::commonReadProperties(),
                        'project_id' => ['type' => 'string', 'minLength' => 1],
                        'files' => ['type' => 'array', 'maxItems' => 50, 'items' => ['type' => 'string', 'minLength' => 1], 'description' => 'Explicit changed paths (repo-relative). Mutually exclusive with working_tree.'],
                        'working_tree' => ['type' => 'boolean', 'default' => false, 'description' => 'Take the change set from Git instead of files. Required to use base_ref.'],
                        'base_ref' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200, 'description' => 'Git ref to diff the working tree against. Requires working_tree: true.'],
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
                    'action' => ['type' => 'string', 'enum' => ['integrity', 'checkpoint', 'optimize', 'vacuum', 'backup']],
                    'execute' => ['type' => 'boolean', 'default' => false],
                    'backup_name' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 127, 'pattern' => '^[A-Za-z0-9._-]+\\.sqlite$', 'description' => 'A plain filename ending in .sqlite, with no directory part; the backup is written inside the server\'s data directory.'],
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
            'max_chars' => ['type' => 'integer', 'minimum' => 4000, 'maximum' => 100000, 'default' => 30000, 'description' => 'Byte budget for the serialized result; supporting material (legends, evidence) is trimmed before findings, tail-first, and reported in meta.dropped_items. Defaults to 30000 so a large result cannot exceed the host\'s response cap; raise it to trade context window for detail.'],
            'refresh_if_stale' => ['type' => 'boolean', 'default' => false, 'description' => 'If the graph is stale, run an incremental rescan (of Knossos\'s own derived database only) before answering; a failed rescan serves the last complete graph with a warning. A missing graph still requires scan_project.'],
        ];
    }

    /**
     * The file_metrics schema, kept separate because it shares no options with the graph queries.
     *
     * @return array<string, mixed>
     */
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
}
