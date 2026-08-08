<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;

/**
 * The MCP tool surface: schemas, validation, and dispatch.
 *
 * Definitions are declared as data so the same source produces the advertised
 * schemas, the argument validation, and the generated reference — three things
 * that drift apart when maintained separately. Arguments are rejected rather than
 * coerced, and unknown keys are an error, because a silently ignored parameter
 * reads to a caller as a parameter that had no effect.
 */
final readonly class ToolService
{
    /**
     * Response budget applied when a caller names none. Sized to stay well
     * inside a host's per-result cap while leaving room for a substantial
     * answer; callers who want more pass max_chars explicitly, up to 100000.
     */
    private const DEFAULT_MAX_CHARS = 30_000;

    public function __construct(
        private ProjectScanService $scanner,
        private ArchitectureQueryService $queries,
        private DatabaseMaintenanceService $maintenance,
        private ResultEnricher $enricher,
        // Optional so the many fixtures that build a ToolService directly need
        // no runtime wiring; the server binaries always supply one.
        private ?ServerEnvironment $environment = null,
    ) {}

    /**
     * The advertised tool list, filtered to what this wiring can actually answer.
     *
     * @return list<array<string, mixed>>
     */
    public function definitions(): array
    {
        return ToolCatalog::definitions($this->environment !== null);
    }

    /**
     * Validate a tool call, apply the shared read options, and dispatch it.
     *
     * @param array<string, mixed> $arguments
     */
    public function call(string $name, array $arguments, ?CancellationToken $cancellation = null): ResultEnvelope
    {
        $schema = ToolCatalog::schemaFor($name);
        if ($schema === null) {
            throw new ToolInputException(sprintf('Unknown tool: %s', $name));
        }
        $declared = $schema['properties'];

        // Common options are honoured centrally only for the tools whose schema
        // actually declares them; passing one to a tool that does not (e.g.
        // refresh_if_stale to maintain_database) is left in $arguments so the
        // key validation below rejects it as unknown.
        $verbosity = 'compact';
        if (in_array('verbosity', $declared, true) && array_key_exists('verbosity', $arguments)) {
            $verbosity = $arguments['verbosity'];
            unset($arguments['verbosity']);
            if ($verbosity !== 'compact' && $verbosity !== 'full') {
                throw new ToolInputException('verbosity must be "compact" or "full".');
            }
        }
        $maxChars = null;
        if (
            in_array('max_chars', $declared, true)
            && !in_array($name, ['architecture_context', 'export_agent_brief'], true)
        ) {
            // Budgeted even when the caller says nothing. An unbounded result is
            // bounded by the graph rather than by anything the host can take:
            // changed_files_impact over a ten-file diff serialized to ~70,000
            // characters and was rejected outright by the client, so the caller
            // got nothing at all instead of a trimmed answer that says what it
            // dropped. Raise it explicitly to trade context window for detail.
            $maxChars = self::DEFAULT_MAX_CHARS;
            if (array_key_exists('max_chars', $arguments)) {
                $maxChars = $arguments['max_chars'];
                unset($arguments['max_chars']);
                if (!is_int($maxChars) || $maxChars < 4000 || $maxChars > 100_000) {
                    throw new ToolInputException('max_chars must be an integer between 4000 and 100000.');
                }
            }
        }
        $refreshRequested = false;
        if (in_array('refresh_if_stale', $declared, true) && array_key_exists('refresh_if_stale', $arguments)) {
            $refresh = $arguments['refresh_if_stale'];
            unset($arguments['refresh_if_stale']);
            if (!is_bool($refresh)) {
                throw new ToolInputException('refresh_if_stale must be a boolean.');
            }
            $refreshRequested = $refresh;
        }

        // Validate the request's keys before any (potentially expensive) rescan
        // so a malformed request cannot trigger a refresh it will never use.
        self::validateKeys($arguments, $schema);

        $refreshWarnings = [];
        if ($refreshRequested && $name !== 'scan_project') {
            $refreshWarnings = $this->refreshIfStale($arguments, $cancellation);
        }
        $envelope = $this->dispatch($name, $arguments, $cancellation);
        if ($refreshWarnings !== []) {
            $envelope = $envelope->withWarnings($refreshWarnings);
        }
        return $this->enricher->enrich($envelope, $name, $verbosity, $maxChars);
    }

    /**
     * The only top-level key check: every remaining argument must be declared
     * and every non-common required key present. Driven by ToolCatalog, so the
     * advertised schema and the accepted arguments cannot drift apart, and run
     * before refresh_if_stale so an invalid request triggers no rescan.
     *
     * @param array<string, mixed> $arguments
     * @param array{properties: list<string>, required: list<string>} $schema
     */
    private static function validateKeys(array $arguments, array $schema): void
    {
        $required = array_diff($schema['required'], ['verbosity', 'max_chars', 'refresh_if_stale']);
        foreach ($required as $key) {
            if (!array_key_exists($key, $arguments)) {
                throw new ToolInputException(sprintf('Missing required argument: %s', $key));
            }
        }
        $unknown = array_diff(array_keys($arguments), $schema['properties']);
        if ($unknown !== []) {
            throw new ToolInputException(sprintf('Unknown argument: %s', reset($unknown)));
        }
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
        // Normalised for the same reason string() normalises: a padded id looks
        // up no project, and silently skipping the refresh would leave the
        // caller with a stale answer their refresh_if_stale asked to avoid.
        $projectId = self::normalized($arguments['project_id'] ?? null);
        if ($projectId === '') {
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
        } catch (\Knossos\Scan\ScanCancelledException $cancelled) {
            // A client-requested cancellation is not a rescan failure to paper
            // over; propagate it so the transport can surface/suppress it.
            throw $cancelled;
        } catch (\Throwable $error) {
            return [sprintf('refresh_if_stale: rescan failed (%s); serving the last complete graph.', $error->getMessage())];
        }
    }

    /**
     * Route a validated call to its handler.
     *
     * @param array<string, mixed> $arguments
     */
    private function dispatch(string $name, array $arguments, ?CancellationToken $cancellation): ResultEnvelope
    {
        return match ($name) {
            'server_info' => $this->serverInfo($arguments),
            'diagnose_runtime' => $this->diagnoseRuntime($arguments),
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

    /**
     * Report the roots this server may read, the file to extend, and whether it is containerised.
     *
     * @param array<string, mixed> $arguments
     */
    private function serverInfo(array $arguments): ResultEnvelope
    {
        $environment = $this->requireEnvironment('server_info');
        $info = $environment->describe();
        /** @var list<array{path: string, source: string, exists: bool}> $roots */
        $roots = $info['allowed_roots'];
        /** @var list<string> $unreachable */
        $unreachable = $info['unreachable_roots'];

        $warnings = [];
        if ($roots === []) {
            $warnings[] = sprintf(
                'No roots are configured, so no project can be scanned. Create %s containing {"roots": ["/absolute/path"]}; it is re-read per request.',
                (string) ($info['roots_file'] ?? '<no roots file configured>'),
            );
        }
        if ($unreachable !== []) {
            // Almost always a host path handed to a containerised server, or a
            // root configured on another machine. Both look fine until a scan.
            $warnings[] = 'These configured roots do not exist on this server: ' . implode(', ', $unreachable);
        }

        return new ResultEnvelope(
            'server',
            '',
            sprintf('Knossos %s with %d allowed root(s).', (string) $info['version'], count($roots)),
            $info,
            warnings: $warnings,
        );
    }

    /**
     * Run the runtime and worker checks, summarising failures as warnings.
     *
     * @param array<string, mixed> $arguments
     */
    private function diagnoseRuntime(array $arguments): ResultEnvelope
    {
        $result = $this->requireEnvironment('diagnose_runtime')->doctor()->run();
        $failed = array_values(array_filter($result['checks'], static fn(array $check): bool => $check['status'] !== 'ok'));

        return new ResultEnvelope(
            'server',
            '',
            $result['ok']
                ? sprintf('All %d runtime checks passed.', count($result['checks']))
                : sprintf('%d of %d runtime checks failed.', count($failed), count($result['checks'])),
            $result,
            warnings: array_map(static fn(array $check): string => $check['name'] . ': ' . $check['detail'], $failed),
        );
    }
    /** The runtime environment, or a clear error when this server was built without one. */

    private function requireEnvironment(string $tool): ServerEnvironment
    {
        // Unreachable through a server binary, which always wires an
        // environment; reachable only if a caller builds a ToolService by hand.
        return $this->environment ?? throw new InvalidArgumentException(
            sprintf('%s is unavailable: this server was built without runtime environment wiring.', $tool),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::annotateComponent()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function annotateComponent(array $arguments): ResultEnvelope
    {
        return $this->queries->annotateComponent(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'component'),
            self::string($arguments, 'kind'),
            array_key_exists('value', $arguments) && is_string($arguments['value']) ? $arguments['value'] : '',
            self::boolean($arguments, 'remove', false),
            self::boolean($arguments, 'execute', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::removeProject()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function removeProject(array $arguments): ResultEnvelope
    {
        return $this->maintenance->removeProject(
            self::string($arguments, 'project_id'),
            self::boolean($arguments, 'execute', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::cleanupStaleScans()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function cleanupStaleScans(array $arguments): ResultEnvelope
    {
        return $this->maintenance->cleanupStaleScans(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'older_than_hours', 24, 1, 8760),
            self::boolean($arguments, 'execute', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::maintain()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function maintainDatabase(array $arguments): ResultEnvelope
    {
        return $this->maintenance->maintain(
            self::string($arguments, 'action'),
            self::boolean($arguments, 'execute', false),
            array_key_exists('backup_name', $arguments) ? self::string($arguments, 'backup_name') : null,
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listProjects()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function projects(array $arguments): ResultEnvelope
    {
        return $this->queries->listProjects(
            self::integer($arguments, 'limit', 50, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
            self::boolean($arguments, 'include_roots', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listSnapshots()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function snapshots(array $arguments): ResultEnvelope
    {
        return $this->queries->listSnapshots(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::snapshotDiff()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function snapshotDiff(array $arguments): ResultEnvelope
    {
        return $this->queries->snapshotDiff(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'from_snapshot'),
            array_key_exists('to_snapshot', $arguments) ? self::string($arguments, 'to_snapshot') : 'active',
            self::integer($arguments, 'max_changes', 25, 1, 1000),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::qualityGate()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function qualityGate(array $arguments): ResultEnvelope
    {
        $budgets = $arguments['budgets'];
        $policies = $arguments['policies'] ?? [];
        // An empty JSON object decodes to []; accept it as "no budgets" rather
        // than mistaking it for a list.
        if (!is_array($budgets) || ($budgets !== [] && array_is_list($budgets)) || !is_array($policies) || !array_is_list($policies)) {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureTrends()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function architectureTrends(array $arguments): ResultEnvelope
    {
        return $this->queries->architectureTrends(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'limit', 10, 2, 20),
            array_key_exists('release_from', $arguments) ? self::string($arguments, 'release_from') : null,
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ProjectScanService::scan()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function scan(array $arguments, ?CancellationToken $cancellation): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::findComponent()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function find(array $arguments): ResultEnvelope
    {
        return $this->queries->findComponent(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'name'),
            self::integer($arguments, 'limit', 20, 1, 100),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::inspectComponent()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function inspect(array $arguments): ResultEnvelope
    {
        return $this->queries->inspectComponent(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'component'),
            self::integer($arguments, 'max_relationships', 25, 1, 100),
            self::integer($arguments, 'max_children', 25, 1, 100),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listUsages()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function listUsages(array $arguments): ResultEnvelope
    {
        return $this->queries->listUsages(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'symbol'),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 100, 1, 500),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureSummary()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function summary(array $arguments): ResultEnvelope
    {
        return $this->queries->architectureSummary(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'limit', 50, 1, 100),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::exportAgentBrief()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function exportAgentBrief(array $arguments): ResultEnvelope
    {
        return $this->queries->exportAgentBrief(
            self::string($arguments, 'project_id'),
            self::integer($arguments, 'max_chars', 4000, 1000, 20_000),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::fileMetrics()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function fileMetrics(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::explainFlow()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function flow(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::impactAnalysis()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function impact(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::dependencyCycles()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function cycles(array $arguments): ResultEnvelope
    {
        return $this->queries->dependencyCycles(
            self::string($arguments, 'project_id'),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'max_nodes', 10_000, 1, 50_000),
            self::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            self::boolean($arguments, 'include_self_loops', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureHealth()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function health(array $arguments): ResultEnvelope
    {
        return $this->queries->architectureHealth(
            self::string($arguments, 'project_id'),
            self::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 20, 1, 100),
            self::integer($arguments, 'max_nodes', 10_000, 1, 50_000),
            self::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            self::boolean($arguments, 'include_external', false),
            self::boolean($arguments, 'include_tests', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::checkArchitecture()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function check(array $arguments): ResultEnvelope
    {
        $policies = $arguments['policies'];
        if (!is_array($policies) || !array_is_list($policies)) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        return $this->queries->checkArchitecture(
            self::string($arguments, 'project_id'),
            $policies,
            array_key_exists('min_confidence', $arguments) ? self::string($arguments, 'min_confidence') : 'possible',
            self::integer($arguments, 'limit', 100, 1, 100),
            self::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::suggestLocation()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function suggest(array $arguments): ResultEnvelope
    {
        return $this->queries->suggestLocation(
            self::string($arguments, 'project_id'),
            self::string($arguments, 'feature_description'),
            self::integer($arguments, 'limit', 5, 1, 20),
            self::integer($arguments, 'max_members', 20_000, 1, 50_000),
            self::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            self::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            array_key_exists('ranking_mode', $arguments) ? self::string($arguments, 'ranking_mode') : 'deterministic',
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::changeImpact()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function changeImpact(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::changedFilesImpact()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function changedFilesImpact(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::testImpact()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function testImpact(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::reviewDiff()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function reviewDiff(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureContext()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function architectureContext(array $arguments): ResultEnvelope
    {
        return $this->queries->architectureContext(
            self::string($arguments, 'project_id'),
            array_key_exists('task_description', $arguments) ? self::string($arguments, 'task_description') : '',
            self::strings($arguments, 'files', 50),
            self::integer($arguments, 'max_chars', 30_000, 4000, 100_000),
            self::integer($arguments, 'timeout_ms', 1500, 1, 5000),
            self::boolean($arguments, 'include_source', false),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::exportDiagram()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function diagram(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listBoundaries()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function boundaries(array $arguments): ResultEnvelope
    {
        return $this->queries->listBoundaries(
            self::string($arguments, 'project_id'),
            array_key_exists('source', $arguments) ? self::string($arguments, 'source') : null,
            self::integer($arguments, 'limit', 50, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::searchArchitecture()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function search(array $arguments): ResultEnvelope
    {
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

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listAnnotations()}.
     *
     * @param array<string, mixed> $arguments
     */
    private function listAnnotations(array $arguments): ResultEnvelope
    {
        return $this->queries->listAnnotations(
            self::string($arguments, 'project_id'),
            array_key_exists('component', $arguments) ? self::string($arguments, 'component') : null,
            array_key_exists('kind', $arguments) ? self::string($arguments, 'kind') : null,
            self::integer($arguments, 'limit', 100, 1, 100),
            self::integer($arguments, 'offset', 0, 0, 100_000),
        );
    }

    /**
     * Reject unknown or missing keys in a nested object argument. The top-level
     * argument check lives in {@see validateKeys()}, driven by ToolCatalog; this
     * is for shapes the catalog does not describe, such as a boundary entry.
     *
     * @param array<string, mixed> $arguments @param list<string> $required @param list<string> $optional
     */
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

    /**
     * A required string argument, rejecting an empty value rather than treating
     * it as absent, and returning it trimmed — surrounding whitespace matched
     * no id in the database and surfaced as "not found" rather than "invalid".
     *
     * @param array<string, mixed> $arguments
     */
    private static function string(array $arguments, string $key): string
    {
        $value = self::normalized($arguments[$key] ?? null);
        if ($value === '') {
            throw new InvalidArgumentException(sprintf('%s must be a non-empty string.', $key));
        }
        return $value;
    }

    /**
     * The one place an incoming string argument is normalised.
     *
     * Every helper here routes through this rather than spelling out its own
     * trim(): surrounding whitespace is invisible in a JSON payload, and every
     * value these helpers produce is then matched literally — an id looked up
     * in the database, an enum value, a path prefix — so ' proj_1' matched
     * nothing and surfaced as "not found" rather than "invalid". The rule had
     * been written out at four separate sites, with nothing structural stopping
     * a fifth helper from omitting it.
     *
     * A non-string collapses to the empty string so each caller rejects it with
     * its own message, which is what they did with the type check inline.
     */
    private static function normalized(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * An integer argument within its declared bounds, rejecting anything outside them.
     *
     * @param array<string, mixed> $arguments
     */
    private static function integer(array $arguments, string $key, int $default, int $minimum, int $maximum): int
    {
        $value = $arguments[$key] ?? $default;
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be an integer between %d and %d.', $key, $minimum, $maximum));
        }
        return $value;
    }

    /**
     * A boolean argument, rejecting a truthy string rather than coercing it.
     *
     * @param array<string, mixed> $arguments
     */
    private static function boolean(array $arguments, string $key, bool $default): bool
    {
        $value = $arguments[$key] ?? $default;
        if (!is_bool($value)) {
            throw new InvalidArgumentException(sprintf('%s must be a boolean.', $key));
        }
        return $value;
    }

    /**
     * A list-of-strings argument, rejecting a bare string so a caller cannot
     * pass one by mistake. Entries are trimmed for the same reason {@see string()}
     * trims: every list here holds enum values, ids, or paths matched literally,
     * so a padded entry silently matched nothing instead of being rejected.
     *
     * @param array<string, mixed> $arguments @return list<string>
     */
    private static function strings(array $arguments, string $key, int $maximum = 20): array
    {
        $value = $arguments[$key] ?? [];
        if (!is_array($value) || !array_is_list($value) || count($value) > $maximum) {
            throw new InvalidArgumentException(sprintf('%s must be a list of at most %d strings.', $key, $maximum));
        }
        $trimmed = [];
        foreach ($value as $item) {
            $entry = self::normalized($item);
            if ($entry === '') {
                throw new InvalidArgumentException(sprintf('%s must contain non-empty strings.', $key));
            }
            $trimmed[] = $entry;
        }
        return $trimmed;
    }

    /**
     * Boundary definitions from the scan arguments, validated into the shape the
     * planner expects. The validated strings are written back rather than
     * discarded: BoundaryInference matches a prefix literally, so a padded
     * path_prefix would define a boundary that matches nothing.
     *
     * @param array<string, mixed> $arguments @return list<array<string, mixed>>
     */
    private static function boundariesArgument(array $arguments): array
    {
        $values = $arguments['boundaries'] ?? [];
        if (!is_array($values) || !array_is_list($values) || count($values) > 50) {
            throw new InvalidArgumentException('boundaries must be a list of at most 50 objects.');
        }
        $normalized = [];
        foreach ($values as $value) {
            if (!is_array($value) || array_is_list($value)) {
                throw new InvalidArgumentException('Each boundary must be an object.');
            }
            self::keys($value, ['name'], ['path_prefix', 'namespace_prefix']);
            $value['name'] = self::string($value, 'name');
            $matchers = (int) array_key_exists('path_prefix', $value) + (int) array_key_exists('namespace_prefix', $value);
            if ($matchers !== 1) {
                throw new InvalidArgumentException('Each boundary requires exactly one matcher.');
            }
            $matcher = array_key_exists('path_prefix', $value) ? 'path_prefix' : 'namespace_prefix';
            $value[$matcher] = self::string($value, $matcher);
            $normalized[] = $value;
        }
        return $normalized;
    }
}
