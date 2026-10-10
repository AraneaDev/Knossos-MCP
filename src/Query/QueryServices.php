<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Git\{GitHistoryProvider, GitWorkingTreeProvider};
use Knossos\Query\Drift\DriftOracle;
use PDO;

/**
 * The query services {@see ArchitectureQueryService} delegates to, wired
 * against one graph database.
 *
 * Exists so the facade only delegates: which service needs which other one,
 * and which optional collaborators reach which service, is decided here.
 */
final readonly class QueryServices
{
    /** Each service under the name the facade delegates to it by; {@see self::wire()} builds them. */
    public function __construct(
        public ProjectCatalogQueryService $catalogQueries,
        public SnapshotDiffQuery $diffQueries,
        public QualityGateQueryService $gateQueries,
        public ComponentQueryService $componentQueries,
        public GraphSummaryQuery $summaryQueries,
        public DependencyCycleQuery $cycleQueries,
        public ArchitectureHealthQuery $healthQueries,
        public FlowQuery $flowQueries,
        public ImpactAnalysisQuery $impactQueries,
        public ArchitecturePolicyQueryService $policyQueries,
        public LocationSuggestionService $locationQueries,
        public ChangeImpactQueryService $changeQueries,
        public ArchitectureContextService $contextQueries,
        public ReviewDiffService $reviewQueries,
        public DiagramExportService $diagramQueries,
        public FileMetricsQueryService $fileMetricsQueries,
        public StalenessProbe $stalenessProbe,
        public AgentBriefService $briefQueries,
        public AnnotationService $annotationQueries,
        public FileContextQueryService $fileContextQueries,
        public DiagnosticsQueryService $diagnosticsQueries,
        public RefreshPolicy $refreshPolicy,
    ) {}

    /**
     * Every service built against `$pdo`, in the order the facade always built
     * them, sharing the ones another service depends on.
     *
     * @param PDO $pdo a migrated graph database
     * @param Closure|null $clock the monotonic clock the query services time their budgets by; null reads hrtime()
     * @param SemanticRanker|null $semanticRanker the optional ranker `suggest_location` may consult
     * @param GitHistoryProvider|null $gitHistory where change impact reads commit history; null reports none
     * @param GitWorkingTreeProvider|null $gitWorkingTree where change impact reads the working tree; null makes that unavailable
     * @param Closure|null $wallClock the clock the staleness probe dates scans by; null reads time()
     * @param RefreshPolicy|null $refreshPolicy decides whether a stale graph may be repaired in a query
     * @param DriftOracle|null $driftOracle how the staleness probe measures drift; null uses the probe's default
     */
    public static function wire(
        PDO $pdo,
        ?Closure $clock = null,
        ?SemanticRanker $semanticRanker = null,
        ?GitHistoryProvider $gitHistory = null,
        ?GitWorkingTreeProvider $gitWorkingTree = null,
        ?Closure $wallClock = null,
        ?RefreshPolicy $refreshPolicy = null,
        ?DriftOracle $driftOracle = null,
    ): self {
        $refreshPolicy ??= new RefreshPolicy($pdo);
        $policyQueries = new ArchitecturePolicyQueryService($pdo, $clock);
        $locationQueries = new LocationSuggestionService($pdo, $clock, $semanticRanker);
        $summaryQueries = new GraphSummaryQuery($pdo, $clock);
        $cycleQueries = new DependencyCycleQuery($pdo, $clock);
        $healthQueries = new ArchitectureHealthQuery($pdo, $clock, $cycleQueries, new DeadCodeCandidates($pdo, $clock));
        $flowQueries = new FlowQuery($pdo, $clock);
        $impactQueries = new ImpactAnalysisQuery($pdo, $clock);
        $componentQueries = new ComponentQueryService($pdo, $clock);
        $catalogQueries = new ProjectCatalogQueryService($pdo, $clock);
        $diffQueries = new SnapshotDiffQuery($pdo, $clock);
        $gateQueries = new QualityGateQueryService($pdo, $clock, $policyQueries, $catalogQueries, $diffQueries);
        $changeQueries = new ChangeImpactQueryService($pdo, $clock, $impactQueries, $gitHistory, $gitWorkingTree);

        return new self(
            catalogQueries: $catalogQueries,
            diffQueries: $diffQueries,
            gateQueries: $gateQueries,
            componentQueries: $componentQueries,
            summaryQueries: $summaryQueries,
            cycleQueries: $cycleQueries,
            healthQueries: $healthQueries,
            flowQueries: $flowQueries,
            impactQueries: $impactQueries,
            policyQueries: $policyQueries,
            locationQueries: $locationQueries,
            changeQueries: $changeQueries,
            contextQueries: new ArchitectureContextService($pdo, $clock, $summaryQueries, $changeQueries, $componentQueries, $locationQueries),
            reviewQueries: new ReviewDiffService($pdo, $clock, $changeQueries, $policyQueries, $gateQueries, $cycleQueries),
            diagramQueries: new DiagramExportService($pdo, $clock),
            fileMetricsQueries: new FileMetricsQueryService($pdo, $clock),
            stalenessProbe: new StalenessProbe($pdo, $wallClock, $driftOracle),
            briefQueries: new AgentBriefService($pdo, $clock, $healthQueries),
            annotationQueries: new AnnotationService($pdo, $clock),
            fileContextQueries: new FileContextQueryService($pdo, $clock),
            diagnosticsQueries: new DiagnosticsQueryService($pdo, $clock),
            refreshPolicy: $refreshPolicy,
        );
    }
}
