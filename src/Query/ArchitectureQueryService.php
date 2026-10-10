<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Query\Drift\DriftOracle;
use Knossos\Result\ResultEnvelope;
use PDO;

/**
 * Facade over the specialised query services.
 *
 * Exists so callers — the MCP tools, the CLI commands — depend on one seam
 * rather than on a dozen services, and so the split between those services can
 * change without touching them. Almost every method here is a one-line
 * delegation; the behaviour, limits, and result shape are documented on the
 * delegate named in each `@see`.
 */
final readonly class ArchitectureQueryService
{
    /** @param QueryServices $services the services every method delegates to, and the database they read */
    public function __construct(
        private QueryServices $services,
    ) {}

    /**
     * The facade over every query service wired against `$pdo`; see
     * {@see QueryServices::wire()} for what each optional collaborator does.
     */
    public static function forDatabase(
        PDO $pdo,
        ?Closure $clock = null,
        ?SemanticRanker $semanticRanker = null,
        ?GitHistoryProvider $gitHistory = null,
        ?GitWorkingTreeProvider $gitWorkingTree = null,
        ?DriftOracle $driftOracle = null,
    ): self {
        return new self(QueryServices::wire($pdo, $clock, $semanticRanker, $gitHistory, $gitWorkingTree, $driftOracle));
    }

    /**
     * The verdict together with the scan it was measured against, for a caller
     * that will hand it on to be attached to an answer.
     *
     * {@see StalenessProbe::snapshot()}
     */
    public function stalenessSnapshot(string $projectId): \Knossos\Query\StalenessSnapshot
    {
        return $this->services->stalenessProbe->snapshot($projectId);
    }

    /** Root path recorded at scan time; used by the MCP layer to self-heal stale graphs. */
    public function projectRoot(string $projectId): ?string
    {
        $statement = $this->services->pdo->prepare('SELECT root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $root = $statement->fetchColumn();
        return is_string($root) && $root !== '' ? $root : null;
    }

    /**
     * Whether a stale graph may be repaired inside the caller's query.
     *
     * Beside projectRoot() on purpose: both exist so the MCP layer can
     * self-heal a stale graph, and both are reads the facade already owns the
     * connection for.
     *
     * {@see RefreshPolicy::decide()}
     */
    public function refreshDecision(string $projectId, \Knossos\Query\Drift\DriftCounts $drift): RefreshDecision
    {
        return $this->services->refreshPolicy->decide($projectId, $drift);
    }

    /**
     * {@see ProjectCatalogQueryService::projectsInCreationOrder()}
     *
     * @return array{projects: list<array{id: string, name: string, created_at: string}>, more: bool}
     */
    public function projectsInCreationOrder(int $limit, ?string $afterCreatedAt = null, ?string $afterId = null): array
    {
        return $this->services->catalogQueries->projectsInCreationOrder($limit, $afterCreatedAt, $afterId);
    }

    /** {@see ProjectCatalogQueryService::listProjects()} */
    public function listProjects(int $limit = 50, int $offset = 0, bool $includeRoots = false): ResultEnvelope
    {
        return $this->services->catalogQueries->listProjects($limit, $offset, $includeRoots);
    }

    /** {@see ProjectCatalogQueryService::listSnapshots()} */
    public function listSnapshots(string $projectId, int $limit = 20, int $offset = 0): ResultEnvelope
    {
        return $this->services->catalogQueries->listSnapshots($projectId, $limit, $offset);
    }

    /** {@see SnapshotDiffQuery::snapshotDiff()} */
    public function snapshotDiff(string $projectId, string $fromSnapshot, string $toSnapshot = 'active', int $maxChanges = 25): ResultEnvelope
    {
        return $this->services->diffQueries->snapshotDiff($projectId, $fromSnapshot, $toSnapshot, $maxChanges);
    }

    /**
     * {@see QualityGateQueryService::branchComparison()}
     *
     * @param list<array<string, mixed>> $policies
     * @return array<string, mixed>
     */
    public function branchComparison(string $projectId, string $baseSnapshot, array $policies, int $limit = 8): array
    {
        return $this->services->gateQueries->branchComparison($projectId, $baseSnapshot, $policies, $limit);
    }

    /**
     * {@see QualityGateQueryService::qualityGate()}
     *
     * @param array<string, mixed> $budgets
     * @param list<array<string, mixed>> $policies
     */
    public function qualityGate(
        string $projectId,
        string $baselineSnapshot,
        array $budgets,
        array $policies = [],
        bool $sarif = false,
        bool $proposeBaseline = false,
    ): ResultEnvelope {
        return $this->services->gateQueries->qualityGate($projectId, $baselineSnapshot, $budgets, $policies, $sarif, $proposeBaseline);
    }

    /** {@see QualityGateQueryService::architectureTrends()} */
    public function architectureTrends(string $projectId, int $limit = 10, ?string $releaseFrom = null): ResultEnvelope
    {
        return $this->services->gateQueries->architectureTrends($projectId, $limit, $releaseFrom);
    }

    /** {@see ComponentQueryService::findComponent()} */
    public function findComponent(string $projectId, string $name, int $limit = 20): ResultEnvelope
    {
        return $this->services->componentQueries->findComponent($projectId, $name, $limit);
    }

    /** {@see ComponentQueryService::inspectComponent()} */
    public function inspectComponent(
        string $projectId,
        string $component,
        int $maxRelationships = 25,
        int $maxChildren = 25,
        string $minConfidence = 'possible',
    ): ResultEnvelope {
        return $this->services->componentQueries->inspectComponent($projectId, $component, $maxRelationships, $maxChildren, $minConfidence);
    }

    /**
     * {@see ComponentQueryService::listUsages()}
     *
     * @param list<string> $edgeKinds
     */
    public function listUsages(string $projectId, string $symbol, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 100): ResultEnvelope
    {
        return $this->services->componentQueries->listUsages($projectId, $symbol, $edgeKinds, $minConfidence, $limit);
    }

    /** {@see GraphSummaryQuery::architectureSummary()} */
    public function architectureSummary(string $projectId, int $limit = 50): ResultEnvelope
    {
        return $this->services->summaryQueries->architectureSummary($projectId, $limit);
    }

    /** {@see FileMetricsQueryService::fileMetrics()} */
    public function fileMetrics(
        string $projectId,
        ?string $pathContains = null,
        ?string $language = null,
        string $sortBy = 'line_count',
        string $order = 'desc',
        int $limit = 50,
        int $offset = 0,
    ): ResultEnvelope {
        return $this->services->fileMetricsQueries->fileMetrics($projectId, $pathContains, $language, $sortBy, $order, $limit, $offset);
    }

    /**
     * {@see DependencyCycleQuery::dependencyCycles()}
     *
     * @param list<string> $edgeKinds
     */
    public function dependencyCycles(
        string $projectId,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $limit = 20,
        int $maxNodes = 50_000,
        int $maxEdges = 100_000,
        int $timeoutMs = 1000,
        bool $includeSelfLoops = false,
    ): ResultEnvelope {
        return $this->services->cycleQueries->dependencyCycles($projectId, $edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $includeSelfLoops);
    }

    /**
     * {@see ArchitectureHealthQuery::architectureHealth()}
     *
     * @param list<string> $edgeKinds
     */
    public function architectureHealth(
        string $projectId,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $limit = 20,
        int $maxNodes = 50_000,
        int $maxEdges = 100_000,
        int $timeoutMs = 1000,
        bool $includeExternal = false,
        bool $includeTests = false,
        string $candidateConfidence = 'possible',
        int $candidateOffset = 0,
        int $candidateTimeoutMs = 5000,
    ): ResultEnvelope {
        return $this->services->healthQueries->architectureHealth($projectId, $edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $includeExternal, $includeTests, $candidateConfidence, $candidateOffset, $candidateTimeoutMs);
    }

    /**
     * {@see ArchitecturePolicyQueryService::checkArchitecture()}
     *
     * @param list<array<string, mixed>> $policies
     * @param list<string> $sourceFiles
     */
    public function checkArchitecture(
        string $projectId,
        ?array $policies,
        string $minConfidence = 'possible',
        int $limit = 100,
        int $maxEdges = ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES,
        int $timeoutMs = 1000,
        array $sourceFiles = [],
    ): ResultEnvelope {
        return $this->services->policyQueries->checkArchitecture($projectId, $policies, $minConfidence, $limit, $maxEdges, $timeoutMs, $sourceFiles);
    }

    /** {@see LocationSuggestionService::suggestLocation()} */
    public function suggestLocation(
        string $projectId,
        string $featureDescription,
        int $limit = 5,
        int $maxMembers = 20_000,
        int $maxEdges = 100_000,
        int $timeoutMs = 1000,
        string $rankingMode = 'deterministic',
    ): ResultEnvelope {
        return $this->services->locationQueries->suggestLocation($projectId, $featureDescription, $limit, $maxMembers, $maxEdges, $timeoutMs, $rankingMode);
    }

    /**
     * {@see ChangeImpactQueryService::changeImpact()}
     *
     * @param list<string> $edgeKinds
     */
    public function changeImpact(
        string $projectId,
        string $symbol,
        int $sinceDays = 90,
        int $maxCommits = 500,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->changeQueries->changeImpact($projectId, $symbol, $sinceDays, $maxCommits, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ChangeImpactQueryService::changedFilesImpact()}
     *
     * @param list<string> $files
     * @param list<string> $edgeKinds
     */
    public function changedFilesImpact(
        string $projectId,
        array $files = [],
        bool $workingTree = false,
        ?string $baseRef = null,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->changeQueries->changedFilesImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ChangeImpactQueryService::testImpact()}
     *
     * @param list<string> $files
     * @param list<string> $edgeKinds
     */
    public function testImpact(
        string $projectId,
        array $files = [],
        bool $workingTree = false,
        ?string $baseRef = null,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->changeQueries->testImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ReviewDiffService::reviewDiff()}
     *
     * @param list<string> $files
     * @param list<array<string, mixed>>|null $policies
     * @param array<string, int>|null $budgets
     */
    public function reviewDiff(
        string $projectId,
        ?string $baseRef = null,
        array $files = [],
        ?array $policies = null,
        ?array $budgets = null,
        ?string $baselineSnapshot = null,
        int $maxDepth = 4,
        int $limit = 100,
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->reviewQueries->reviewDiff($projectId, $baseRef, $files, $policies, $budgets, $baselineSnapshot, $maxDepth, $limit, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ArchitectureContextService::architectureContext()}
     *
     * @param list<string> $files
     */
    public function architectureContext(
        string $projectId,
        string $taskDescription = '',
        array $files = [],
        int $maxChars = 30_000,
        int $timeoutMs = 1500,
        bool $includeSource = false,
    ): ResultEnvelope {
        return $this->services->contextQueries->architectureContext($projectId, $taskDescription, $files, $maxChars, $timeoutMs, $includeSource);
    }

    /**
     * {@see DiagramExportService::exportDiagram()}
     *
     * @param list<string> $edgeKinds
     */
    public function exportDiagram(
        string $projectId,
        string $format = 'mermaid',
        ?string $boundary = null,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        string $direction = 'LR',
        int $maxNodes = 200,
        int $maxEdges = 500,
    ): ResultEnvelope {
        return $this->services->diagramQueries->exportDiagram($projectId, $format, $boundary, $edgeKinds, $minConfidence, $direction, $maxNodes, $maxEdges);
    }

    /**
     * {@see FlowQuery::explainFlow()}
     *
     * @param list<string> $edgeKinds
     */
    public function explainFlow(
        string $projectId,
        string $from,
        string $to,
        int $maxDepth = 6,
        int $maxPaths = 5,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->flowQueries->explainFlow($projectId, $from, $to, $maxDepth, $maxPaths, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
     * {@see ImpactAnalysisQuery::impactAnalysis()}
     *
     * @param list<string> $edgeKinds
     */
    public function impactAnalysis(
        string $projectId,
        string $symbol,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->services->impactQueries->impactAnalysis($projectId, $symbol, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /** {@see GraphSummaryQuery::listBoundaries()} */
    public function listBoundaries(string $projectId, ?string $source = null, int $limit = 50, int $offset = 0): ResultEnvelope
    {
        return $this->services->summaryQueries->listBoundaries($projectId, $source, $limit, $offset);
    }

    /** {@see AgentBriefService::exportAgentBrief()} */
    public function exportAgentBrief(string $projectId, int $maxChars = 4000): ResultEnvelope
    {
        return $this->services->briefQueries->exportAgentBrief($projectId, $maxChars);
    }

    /**
     * {@see ComponentQueryService::searchArchitecture()}
     *
     * @param list<string> $kinds
     * @param list<string> $roles
     * @param list<string> $boundaryIds
     * @param list<string> $confidences
     */
    public function searchArchitecture(
        string $projectId,
        string $query,
        array $kinds = [],
        array $roles = [],
        array $boundaryIds = [],
        array $confidences = [],
        int $limit = 20,
        int $offset = 0,
    ): ResultEnvelope {
        return $this->services->componentQueries->searchArchitecture($projectId, $query, $kinds, $roles, $boundaryIds, $confidences, $limit, $offset);
    }

    /** {@see AnnotationService::upsertAnnotation()} */
    public function upsertAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false): ResultEnvelope
    {
        return $this->services->annotationQueries->upsertAnnotation($projectId, $component, $kind, $value, $execute);
    }

    /** {@see AnnotationService::removeAnnotation()} */
    public function removeAnnotation(string $projectId, string $component, string $kind, string $value = '', bool $execute = false): ResultEnvelope
    {
        return $this->services->annotationQueries->removeAnnotation($projectId, $component, $kind, $value, $execute);
    }

    /** {@see FileContextQueryService::fileContext()} */
    public function fileContext(string $projectId, string $path): ResultEnvelope
    {
        return $this->services->fileContextQueries->fileContext($projectId, $path);
    }

    /** {@see DiagnosticsQueryService::listDiagnostics()} */
    public function listDiagnostics(string $projectId, ?string $severity = null, ?string $pathPrefix = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        return $this->services->diagnosticsQueries->listDiagnostics($projectId, $severity, $pathPrefix, $limit, $offset);
    }

    /** {@see AnnotationService::listAnnotations()} */
    public function listAnnotations(string $projectId, ?string $component = null, ?string $kind = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        return $this->services->annotationQueries->listAnnotations($projectId, $component, $kind, $limit, $offset);
    }

    /**
     * {@see SessionBriefService::brief()}
     *
     * $databasePath is optional so every existing caller keeps working; pass
     * it to let the brief warn when $path falls outside every allowed root.
     */
    public function sessionBrief(string $path, ?string $databasePath = null): string
    {
        return $this->services->sessionBrief($databasePath)->brief($path);
    }
}
