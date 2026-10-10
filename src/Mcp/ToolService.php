<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Cancellation\CancellationToken;
use Knossos\Discovery\RootGuard;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Query\ArchitecturePolicyQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessSnapshot;
use Knossos\Runtime\ServerEnvironment;
use Knossos\Scan\ProjectScanner;

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

    /**
     * Tools whose answer reads the project's files or runs git in its root.
     * The database is shared with the CLI and can hold projects from anywhere
     * on the machine, so these are confined to the server's allowed roots the
     * same way a scan is. Graph-only tools answer for every project.
     */
    private const DISK_TOOLS = ['file_context', 'change_impact', 'changed_files_impact', 'test_impact', 'review_diff'];

    public function __construct(
        // The interface rather than ProjectScanService: scan() is the only
        // thing ever called on it, and WatchService and WatchScanAttempt
        // already take ProjectScanner, so this was the last consumer naming
        // the implementation for no reason. A test injecting a scanner that
        // fails a particular way follows from that; it is not the reason.
        private ProjectScanner $scanner,
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
            // Through the same normalisation every other literal string
            // argument gets: whitespace is invisible in a JSON payload, and
            // this value is then matched literally against the allowed set.
            $verbosity = ToolArguments::normalized($arguments['verbosity']);
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
        // On by default: an agent that has to ask for a fresh graph pays two
        // round trips to discover it needed one. RefreshPolicy is what makes
        // that safe, by skipping any refresh that would cost more than the
        // budget. KNOSSOS_AUTO_REFRESH=0 restores the previous behaviour.
        //
        // Only the literal string '0' disables it: 'false', 'off', 'no' and
        // even 'FALSE' all still read as enabled, the same idiom
        // ProtocolNegotiator::legacyEnabled() uses for KNOSSOS_LEGACY_PROTOCOL.
        // Consistency between the two operator switches is worth more than
        // accommodating spellings nobody has asked for; if that ever changes,
        // change both switches together, not just one.
        $refreshRequested = getenv('KNOSSOS_AUTO_REFRESH') !== '0';
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
        $this->assertWithinRoots($name, $arguments);
        // Every value is validated here too, before the refresh below, so a
        // call that is going to be refused costs no rescan.
        $run = $this->prepare($name, $arguments, $cancellation);

        $refreshWarnings = [];
        $probed = null;
        // Gated on the tool's own schema, not on an exclude-list: declaring
        // refresh_if_stale is exactly what marks a tool as one whose answer
        // may be repaired by a rescan first. A tool that does not declare it
        // (every graph-mutating tool, plus server_info/diagnose_runtime) must
        // never trigger one as a side effect of being called — remove_project
        // triggering a full rescan of the very project it is about to delete
        // is the case this guards against.
        if ($refreshRequested && in_array('refresh_if_stale', $declared, true)) {
            [$refreshWarnings, $probed] = $this->refreshIfStale($arguments, $cancellation);
        }
        $envelope = $run();
        if ($refreshWarnings !== []) {
            $envelope = $envelope->withWarnings($refreshWarnings);
        }
        // The verdict this call already reached is handed to the enricher
        // rather than derived a second time. It is withheld whenever a rescan
        // ran, because a repaired graph is no longer the graph that was probed.
        return $this->enricher->enrich($envelope, $name, $verbosity, $maxChars, $probed);
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
     * Refuses a tool that reads disk or git when its project lies outside the allowed roots.
     *
     * @param array<string, mixed> $arguments
     */
    private function assertWithinRoots(string $name, array $arguments): void
    {
        if ($this->environment === null) {
            return;
        }
        $readsDisk = in_array($name, self::DISK_TOOLS, true)
            || ($name === 'architecture_context' && ($arguments['include_source'] ?? false) === true);
        $projectId = ToolArguments::normalized($arguments['project_id'] ?? null);
        if (!$readsDisk || $projectId === '') {
            return;
        }
        $root = $this->queries->projectRoot($projectId);
        // A missing root is the tool's own answer to give; reporting it as
        // "outside the roots" would send the caller to fix the wrong thing.
        if ($root === null || !RootGuard::exists($root)) {
            return;
        }
        (new RootGuard($this->environment->roots))->resolve($root);
    }

    /**
     * Repair a stale graph before answering, when that fits the budget.
     *
     * Returns warnings and never throws for a scan fault: a query answerable
     * from the previous graph must still be answered. Cancellation is the one
     * exception, because the caller asked for it.
     *
     * Nothing is reported when the refresh succeeds. The staleness attached to
     * the same result already says 'fresh', so a second announcement would put
     * a line on every response for the case that needs no attention.
     *
     * Returns the verdict it probed alongside its warnings, so the enricher can
     * attach that verdict instead of running the oracle again — and returns
     * null for it whenever a scan was attempted, because the graph it probed is
     * not the graph the answer came from.
     *
     * @param array<string, mixed> $arguments
     * @return array{0: list<string>, 1: ?StalenessSnapshot}
     */
    private function refreshIfStale(array $arguments, ?CancellationToken $cancellation): array
    {
        // Normalised for the same reason string() normalises: a padded id looks
        // up no project, and silently skipping the refresh would leave the
        // caller with a stale answer their refresh_if_stale asked to avoid.
        $projectId = ToolArguments::normalized($arguments['project_id'] ?? null);
        if ($projectId === '') {
            return [[], null];
        }
        // Outside the allowed roots the probe would read files and run git, so
        // there is nothing to refresh from here. No warning: the enricher's
        // confined probe attaches 'unverified' with guidance saying why.
        if ($this->environment !== null) {
            $root = $this->queries->projectRoot($projectId);
            if ($root !== null && !$this->environment->admitsRoot($root)) {
                return [[], null];
            }
        }
        // The snapshot comes from the probe rather than being assembled here,
        // so the verdict and the scan it describes cannot be read at two
        // different moments.
        $snapshot = $this->queries->stalenessSnapshot($projectId);
        $staleness = $snapshot->staleness;
        if (($staleness['state'] ?? null) !== 'stale') {
            return [[], $snapshot];
        }
        // A 'stale' verdict can come from a newer failed scan attempt rather
        // than from measured drift, leaving no change set to cost the rescan
        // against. Guessing a size there is how an unbounded scan gets back in.
        //
        // Passed on split three ways rather than summed: the policy caps its
        // estimate at the previous full scan's duration, and that cap only
        // holds for a change set of deletions alone. A total alone would hide
        // the difference.
        $drift = isset($staleness['changed_files_since'])
            ? new \Knossos\Query\Drift\DriftCounts(
                (int) $staleness['changed_files_since'],
                (int) $staleness['added_files_since'],
                (int) $staleness['deleted_files_since'],
                ($staleness['added_files_truncated'] ?? false) === true,
            )
            : new \Knossos\Query\Drift\DriftCounts(0, 0, 0);
        if ($drift->total() < 1) {
            return [['refresh_if_stale: the graph is stale but the change set is unknown; call scan_project to refresh.'], $snapshot];
        }
        $decision = $this->queries->refreshDecision($projectId, $drift);
        if (!$decision->refresh) {
            return [['refresh_if_stale: ' . (string) $decision->reason], $snapshot];
        }
        $root = $this->queries->projectRoot($projectId);
        if ($root === null) {
            return [['refresh_if_stale: the project root is unknown; serving the last complete graph.'], $snapshot];
        }
        try {
            $this->scanner->scan($root, cancellation: $cancellation);
            return [[], null];
        } catch (\Knossos\Cancellation\ScanCancelledException $cancelled) {
            // A client-requested cancellation is not a rescan failure to paper
            // over; propagate it so the transport can surface/suppress it.
            throw $cancelled;
        } catch (\Throwable $error) {
            // No snapshot: a failed attempt leaves a scan row behind, and that
            // row is itself part of the staleness verdict.
            return [[sprintf('refresh_if_stale: rescan failed (%s); serving the last complete graph.', ToolErrorMapper::publicMessage($error))], null];
        }
    }

    /**
     * Route a call to its handler, which validates every argument now and
     * returns the work as a closure. Parsing eagerly is the point: call() runs
     * this before refresh_if_stale, so a call that is going to be refused
     * fails before any rescan. A handler's closure only calls the service.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function prepare(string $name, array $arguments, ?CancellationToken $cancellation): \Closure
    {
        $run = match ($name) {
            // Nothing to parse: the whole handler is the work.
            'server_info' => fn(): ResultEnvelope => $this->serverInfo($arguments),
            'diagnose_runtime' => fn(): ResultEnvelope => $this->diagnoseRuntime($arguments),
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
            'file_context' => $this->fileContext($arguments),
            'list_diagnostics' => $this->listDiagnostics($arguments),
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
        // Enums, policies and budgets: the rules the services apply, run now so
        // a call that is going to be refused fails before any rescan.
        ToolArgumentPreflight::check($name, $arguments);

        return $run;
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
                ? sprintf('All %d runtime checks passed%s.', count($result['checks']), $result['warnings'] > 0 ? sprintf(', %d with a warning', $result['warnings']) : '')
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
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::removeAnnotation()}
     * when `remove` is set, else to {@see ArchitectureQueryService::upsertAnnotation()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function annotateComponent(array $arguments): \Closure
    {
        $projectId = ToolArguments::string($arguments, 'project_id');
        $component = ToolArguments::string($arguments, 'component');
        $kind = ToolArguments::string($arguments, 'kind');
        // Stored as given: a note's whitespace is content.
        $value = ToolArguments::text($arguments, 'value', 2000, allowEmpty: true, default: '', trim: false);
        $remove = ToolArguments::boolean($arguments, 'remove', false);
        $execute = ToolArguments::boolean($arguments, 'execute', false);

        return $remove
            ? fn(): ResultEnvelope => $this->queries->removeAnnotation($projectId, $component, $kind, $value, $execute)
            : fn(): ResultEnvelope => $this->queries->upsertAnnotation($projectId, $component, $kind, $value, $execute);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::removeProject()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function removeProject(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::boolean($arguments, 'execute', false),
        ];

        return fn(): ResultEnvelope => $this->maintenance->removeProject(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::cleanupStaleScans()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function cleanupStaleScans(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::integer($arguments, 'older_than_hours', 24, 1, 8760),
            ToolArguments::boolean($arguments, 'execute', false),
        ];

        return fn(): ResultEnvelope => $this->maintenance->cleanupStaleScans(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see DatabaseMaintenanceService::maintain()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function maintainDatabase(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'action'),
            ToolArguments::boolean($arguments, 'execute', false),
            array_key_exists('backup_name', $arguments) ? ToolArguments::text($arguments, 'backup_name', 127) : null,
        ];

        return fn(): ResultEnvelope => $this->maintenance->maintain(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listProjects()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function projects(array $arguments): \Closure
    {
        $args = [
            ToolArguments::integer($arguments, 'limit', 50, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
            ToolArguments::boolean($arguments, 'include_roots', false),
        ];

        return fn(): ResultEnvelope => $this->queries->listProjects(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listSnapshots()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function snapshots(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::integer($arguments, 'limit', 20, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
        ];

        return fn(): ResultEnvelope => $this->queries->listSnapshots(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::snapshotDiff()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function snapshotDiff(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'from_snapshot'),
            array_key_exists('to_snapshot', $arguments) ? ToolArguments::string($arguments, 'to_snapshot') : 'active',
            ToolArguments::integer($arguments, 'max_changes', 25, 1, 1000),
        ];

        return fn(): ResultEnvelope => $this->queries->snapshotDiff(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::qualityGate()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function qualityGate(array $arguments): \Closure
    {
        $budgets = $arguments['budgets'];
        $policies = $arguments['policies'] ?? [];
        // An empty JSON object decodes to []; accept it as "no budgets" rather
        // than mistaking it for a list.
        if (!is_array($budgets) || ($budgets !== [] && array_is_list($budgets)) || !is_array($policies) || !array_is_list($policies)) {
            throw new InvalidArgumentException('budgets must be an object and policies must be a list.');
        }
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'baseline_snapshot'),
            $budgets,
            $policies,
            ToolArguments::boolean($arguments, 'sarif', false),
            ToolArguments::boolean($arguments, 'propose_baseline', false),
        ];

        return fn(): ResultEnvelope => $this->queries->qualityGate(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureTrends()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function architectureTrends(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::integer($arguments, 'limit', 10, 2, 20),
            array_key_exists('release_from', $arguments) ? ToolArguments::string($arguments, 'release_from') : null,
        ];

        return fn(): ResultEnvelope => $this->queries->architectureTrends(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ProjectScanService::scan()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function scan(array $arguments, ?CancellationToken $cancellation): \Closure
    {
        $path = ToolArguments::string($arguments, 'path');
        $name = array_key_exists('name', $arguments) ? ToolArguments::string($arguments, 'name') : null;
        $maxFiles = array_key_exists('max_files', $arguments) ? ToolArguments::integer($arguments, 'max_files', 100_000, 1, 100_000) : null;
        $maxBytes = array_key_exists('max_file_bytes', $arguments) ? ToolArguments::integer($arguments, 'max_file_bytes', 2_000_000, 1, 100_000_000) : null;

        $args = [
            $path,
            $name,
            $maxFiles,
            $maxBytes,
            array_key_exists('boundaries', $arguments) ? ToolArguments::boundariesArgument($arguments) : null,
            array_key_exists('mode', $arguments) ? ToolArguments::string($arguments, 'mode') : null,
            $cancellation,
            array_key_exists('snapshot_retention', $arguments) ? ToolArguments::integer($arguments, 'snapshot_retention', 5, 0, 20) : null,
            array_key_exists('worker_timeout_ms', $arguments) ? ToolArguments::integer($arguments, 'worker_timeout_ms', 30_000, 1_000, 120_000) : null,
        ];

        return fn(): ResultEnvelope => $this->scanner->scan(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::findComponent()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function find(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'name'),
            ToolArguments::integer($arguments, 'limit', 20, 1, 100),
        ];

        return fn(): ResultEnvelope => $this->queries->findComponent(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::inspectComponent()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function inspect(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'component'),
            ToolArguments::integer($arguments, 'max_relationships', 25, 1, 100),
            ToolArguments::integer($arguments, 'max_children', 25, 1, 100),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
        ];

        return fn(): ResultEnvelope => $this->queries->inspectComponent(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listUsages()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function listUsages(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'symbol'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'limit', 100, 1, 500),
        ];

        return fn(): ResultEnvelope => $this->queries->listUsages(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureSummary()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function summary(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::integer($arguments, 'limit', 50, 1, 100),
        ];

        return fn(): ResultEnvelope => $this->queries->architectureSummary(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::exportAgentBrief()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function exportAgentBrief(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::integer($arguments, 'max_chars', 4000, 1000, 20_000),
        ];

        return fn(): ResultEnvelope => $this->queries->exportAgentBrief(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::fileMetrics()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function fileMetrics(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('path_contains', $arguments) ? ToolArguments::text($arguments, 'path_contains', 1000) : null,
            array_key_exists('language', $arguments) ? ToolArguments::text($arguments, 'language', 100) : null,
            array_key_exists('sort_by', $arguments) ? ToolArguments::string($arguments, 'sort_by') : 'line_count',
            array_key_exists('order', $arguments) ? ToolArguments::string($arguments, 'order') : 'desc',
            ToolArguments::integer($arguments, 'limit', 50, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
        ];

        return fn(): ResultEnvelope => $this->queries->fileMetrics(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listDiagnostics()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function listDiagnostics(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('severity', $arguments) ? ToolArguments::string($arguments, 'severity') : null,
            array_key_exists('path_prefix', $arguments) ? ToolArguments::string($arguments, 'path_prefix') : null,
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
        ];

        return fn(): ResultEnvelope => $this->queries->listDiagnostics(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::fileContext()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function fileContext(array $arguments): \Closure
    {
        $args = [ToolArguments::string($arguments, 'project_id'), ToolArguments::string($arguments, 'path')];

        return fn(): ResultEnvelope => $this->queries->fileContext(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::explainFlow()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function flow(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'from'),
            ToolArguments::string($arguments, 'to'),
            ToolArguments::integer($arguments, 'max_depth', 6, 1, 8),
            ToolArguments::integer($arguments, 'max_paths', 5, 1, 20),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->explainFlow(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::impactAnalysis()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function impact(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'symbol'),
            ToolArguments::integer($arguments, 'max_depth', 4, 1, 8),
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->impactAnalysis(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::dependencyCycles()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function cycles(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'limit', 20, 1, 100),
            ToolArguments::integer($arguments, 'max_nodes', 50_000, 1, 50_000),
            ToolArguments::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            ToolArguments::boolean($arguments, 'include_self_loops', false),
        ];

        return fn(): ResultEnvelope => $this->queries->dependencyCycles(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureHealth()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function health(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'limit', 20, 1, 100),
            ToolArguments::integer($arguments, 'max_nodes', 50_000, 1, 50_000),
            ToolArguments::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            ToolArguments::boolean($arguments, 'include_external', false),
            ToolArguments::boolean($arguments, 'include_tests', false),
            array_key_exists('candidate_confidence', $arguments) ? ToolArguments::string($arguments, 'candidate_confidence') : 'possible',
            ToolArguments::integer($arguments, 'candidate_offset', 0, 0, 100_000),
            ToolArguments::integer($arguments, 'candidate_timeout_ms', 5000, 1, 60_000),
        ];

        return fn(): ResultEnvelope => $this->queries->architectureHealth(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::checkArchitecture()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function check(array $arguments): \Closure
    {
        // Absent, the project's declared policies are checked.
        $policies = $arguments['policies'] ?? null;
        if ($policies !== null && (!is_array($policies) || !array_is_list($policies))) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            $policies,
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::integer($arguments, 'max_edges', ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES, 1, 100_000),
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->checkArchitecture(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::suggestLocation()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function suggest(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::text($arguments, 'feature_description', 2000),
            ToolArguments::integer($arguments, 'limit', 5, 1, 20),
            ToolArguments::integer($arguments, 'max_members', 20_000, 1, 50_000),
            ToolArguments::integer($arguments, 'max_edges', 100_000, 1, 100_000),
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
            array_key_exists('ranking_mode', $arguments) ? ToolArguments::string($arguments, 'ranking_mode') : 'deterministic',
        ];

        return fn(): ResultEnvelope => $this->queries->suggestLocation(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::changeImpact()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function changeImpact(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'symbol'),
            ToolArguments::integer($arguments, 'since_days', 90, 1, 3650),
            ToolArguments::integer($arguments, 'max_commits', 500, 1, 5000),
            ToolArguments::integer($arguments, 'max_depth', 4, 1, 8),
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->changeImpact(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::changedFilesImpact()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function changedFilesImpact(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'files', 50),
            ToolArguments::boolean($arguments, 'working_tree', false),
            array_key_exists('base_ref', $arguments) ? ToolArguments::text($arguments, 'base_ref', 200) : null,
            ToolArguments::integer($arguments, 'max_depth', 4, 1, 8),
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->changedFilesImpact(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::testImpact()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function testImpact(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::strings($arguments, 'files', 50),
            ToolArguments::boolean($arguments, 'working_tree', false),
            array_key_exists('base_ref', $arguments) ? ToolArguments::text($arguments, 'base_ref', 200) : null,
            ToolArguments::integer($arguments, 'max_depth', 4, 1, 8),
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->testImpact(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::reviewDiff()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function reviewDiff(array $arguments): \Closure
    {
        $policies = $arguments['policies'] ?? null;
        if ($policies !== null && (!is_array($policies) || !array_is_list($policies))) {
            throw new InvalidArgumentException('policies must be a list.');
        }
        $budgets = $arguments['budgets'] ?? null;
        if ($budgets !== null && (!is_array($budgets) || array_is_list($budgets))) {
            throw new InvalidArgumentException('budgets must be an object.');
        }
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('base_ref', $arguments) ? ToolArguments::text($arguments, 'base_ref', 200) : null,
            ToolArguments::strings($arguments, 'files', 50),
            $policies,
            $budgets,
            array_key_exists('baseline_snapshot', $arguments) ? ToolArguments::string($arguments, 'baseline_snapshot') : null,
            ToolArguments::integer($arguments, 'max_depth', 4, 1, 8),
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            ToolArguments::integer($arguments, 'timeout_ms', 1000, 1, 5000),
        ];

        return fn(): ResultEnvelope => $this->queries->reviewDiff(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::architectureContext()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function architectureContext(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::text($arguments, 'task_description', 2000, allowEmpty: true, default: ''),
            ToolArguments::strings($arguments, 'files', 50),
            ToolArguments::integer($arguments, 'max_chars', 30_000, 4000, 100_000),
            ToolArguments::integer($arguments, 'timeout_ms', 1500, 1, 5000),
            ToolArguments::boolean($arguments, 'include_source', false),
        ];

        return fn(): ResultEnvelope => $this->queries->architectureContext(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::exportDiagram()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function diagram(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('format', $arguments) ? ToolArguments::string($arguments, 'format') : 'mermaid',
            array_key_exists('boundary', $arguments) ? ToolArguments::string($arguments, 'boundary') : null,
            ToolArguments::strings($arguments, 'edge_kinds'),
            array_key_exists('min_confidence', $arguments) ? ToolArguments::string($arguments, 'min_confidence') : 'possible',
            array_key_exists('direction', $arguments) ? ToolArguments::string($arguments, 'direction') : 'LR',
            ToolArguments::integer($arguments, 'max_nodes', 200, 1, 400),
            ToolArguments::integer($arguments, 'max_edges', 500, 1, 1000),
        ];

        return fn(): ResultEnvelope => $this->queries->exportDiagram(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listBoundaries()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function boundaries(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('source', $arguments) ? ToolArguments::string($arguments, 'source') : null,
            ToolArguments::integer($arguments, 'limit', 50, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
        ];

        return fn(): ResultEnvelope => $this->queries->listBoundaries(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::searchArchitecture()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function search(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            ToolArguments::string($arguments, 'query'),
            ToolArguments::strings($arguments, 'kinds'),
            ToolArguments::strings($arguments, 'roles'),
            ToolArguments::strings($arguments, 'boundary_ids'),
            ToolArguments::strings($arguments, 'confidences'),
            ToolArguments::integer($arguments, 'limit', 20, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
        ];

        return fn(): ResultEnvelope => $this->queries->searchArchitecture(...$args);
    }

    /**
     * Validates the tool arguments and forwards to {@see ArchitectureQueryService::listAnnotations()}.
     *
     * @param array<string, mixed> $arguments
     * @return \Closure(): ResultEnvelope
     */
    private function listAnnotations(array $arguments): \Closure
    {
        $args = [
            ToolArguments::string($arguments, 'project_id'),
            array_key_exists('component', $arguments) ? ToolArguments::string($arguments, 'component') : null,
            array_key_exists('kind', $arguments) ? ToolArguments::string($arguments, 'kind') : null,
            ToolArguments::integer($arguments, 'limit', 100, 1, 100),
            ToolArguments::integer($arguments, 'offset', 0, 0, 100_000),
        ];

        return fn(): ResultEnvelope => $this->queries->listAnnotations(...$args);
    }
}
