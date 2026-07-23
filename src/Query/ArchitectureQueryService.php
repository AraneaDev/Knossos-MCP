<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use PDO;

final readonly class ArchitectureQueryService
{
    private PDO $pdo;
    private ProjectCatalogQueryService $catalogQueries;
    private ComponentQueryService $componentQueries;
    private GraphTopologyQueryService $topologyQueries;
    private ArchitecturePolicyQueryService $policyQueries;
    private ChangeImpactQueryService $changeQueries;
    private ArchitectureContextService $contextQueries;
    private ReviewDiffService $reviewQueries;
    private DiagramExportService $diagramQueries;
    private FileMetricsQueryService $fileMetricsQueries;
    private StalenessProbe $stalenessProbe;
    private AgentBriefService $briefQueries;
    private AnnotationService $annotationQueries;

    public function __construct(
        PDO $pdo,
        ?Closure $clock = null,
        ?SemanticRanker $semanticRanker = null,
        ?GitHistoryProvider $gitHistory = null,
        ?GitWorkingTreeProvider $gitWorkingTree = null,
        ?Closure $wallClock = null,
    ) {
        $this->pdo = $pdo;
        $this->policyQueries = new ArchitecturePolicyQueryService($pdo, $clock, $semanticRanker);
        $this->topologyQueries = new GraphTopologyQueryService($pdo, $clock);
        $this->componentQueries = new ComponentQueryService($pdo, $clock);
        $this->catalogQueries = new ProjectCatalogQueryService($pdo, $clock, $this->policyQueries);
        $this->changeQueries = new ChangeImpactQueryService(
            $pdo,
            $clock,
            $this->topologyQueries,
            $gitHistory,
            $gitWorkingTree,
        );
        $this->contextQueries = new ArchitectureContextService(
            $pdo,
            $clock,
            $this->topologyQueries,
            $this->changeQueries,
            $this->componentQueries,
            $this->policyQueries,
        );
        $this->reviewQueries = new ReviewDiffService($pdo, $clock, $this->changeQueries, $this->policyQueries, $this->catalogQueries, $this->topologyQueries);
        $this->diagramQueries = new DiagramExportService($pdo, $clock);
        $this->fileMetricsQueries = new FileMetricsQueryService($pdo, $clock);
        $this->stalenessProbe = new StalenessProbe($pdo, $wallClock);
        $this->briefQueries = new AgentBriefService($pdo, $clock, $this->topologyQueries);
        $this->annotationQueries = new AnnotationService($pdo, $clock);
    }

    /** @return array<string, mixed>|null */
    public function staleness(string $projectId): ?array
    {
        return $this->stalenessProbe->probe($projectId);
    }

    /** Root path recorded at scan time; used by the MCP layer to self-heal stale graphs. */
    public function projectRoot(string $projectId): ?string
    {
        $statement = $this->pdo->prepare('SELECT root_realpath FROM projects WHERE id = :id');
        $statement->execute(['id' => $projectId]);
        $root = $statement->fetchColumn();
        return is_string($root) && $root !== '' ? $root : null;
    }

    public function listProjects(int $limit = 50, int $offset = 0, bool $includeRoots = false): ResultEnvelope
    {
        return $this->catalogQueries->listProjects($limit, $offset, $includeRoots);
    }

    public function listSnapshots(string $projectId, int $limit = 20, int $offset = 0): ResultEnvelope
    {
        return $this->catalogQueries->listSnapshots($projectId, $limit, $offset);
    }

    public function snapshotDiff(string $projectId, string $fromSnapshot, string $toSnapshot = 'active', int $maxChanges = 200): ResultEnvelope
    {
        return $this->catalogQueries->snapshotDiff($projectId, $fromSnapshot, $toSnapshot, $maxChanges);
    }

    /** @param array<string, mixed> $budgets @param list<array<string, mixed>> $policies */
    public function qualityGate(
        string $projectId,
        string $baselineSnapshot,
        array $budgets,
        array $policies = [],
        bool $sarif = false,
        bool $proposeBaseline = false,
    ): ResultEnvelope {
        return $this->catalogQueries->qualityGate($projectId, $baselineSnapshot, $budgets, $policies, $sarif, $proposeBaseline);
    }

    public function architectureTrends(string $projectId, int $limit = 10, ?string $releaseFrom = null): ResultEnvelope
    {
        return $this->catalogQueries->architectureTrends($projectId, $limit, $releaseFrom);
    }

    public function findComponent(string $projectId, string $name, int $limit = 20): ResultEnvelope
    {
        return $this->componentQueries->findComponent($projectId, $name, $limit);
    }

    public function inspectComponent(
        string $projectId,
        string $component,
        int $maxRelationships = 25,
        int $maxChildren = 25,
        string $minConfidence = 'possible',
    ): ResultEnvelope {
        return $this->componentQueries->inspectComponent($projectId, $component, $maxRelationships, $maxChildren, $minConfidence);
    }

    /** @param list<string> $edgeKinds */
    public function listUsages(string $projectId, string $symbol, array $edgeKinds = [], string $minConfidence = 'possible', int $limit = 100): ResultEnvelope
    {
        return $this->componentQueries->listUsages($projectId, $symbol, $edgeKinds, $minConfidence, $limit);
    }

    public function architectureSummary(string $projectId, int $limit = 50): ResultEnvelope
    {
        return $this->topologyQueries->architectureSummary($projectId, $limit);
    }

    public function fileMetrics(
        string $projectId,
        ?string $pathContains = null,
        ?string $language = null,
        string $sortBy = 'line_count',
        string $order = 'desc',
        int $limit = 50,
        int $offset = 0,
    ): ResultEnvelope {
        return $this->fileMetricsQueries->fileMetrics($projectId, $pathContains, $language, $sortBy, $order, $limit, $offset);
    }

    /** @param list<string> $edgeKinds */
    public function dependencyCycles(
        string $projectId,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $limit = 20,
        int $maxNodes = 10_000,
        int $maxEdges = 20_000,
        int $timeoutMs = 1000,
        bool $includeSelfLoops = false,
    ): ResultEnvelope {
        return $this->topologyQueries->dependencyCycles($projectId, $edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $includeSelfLoops);
    }

    /** @param list<string> $edgeKinds */
    public function architectureHealth(
        string $projectId,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $limit = 20,
        int $maxNodes = 10_000,
        int $maxEdges = 20_000,
        int $timeoutMs = 1000,
        bool $includeExternal = false,
        bool $includeTests = false,
    ): ResultEnvelope {
        return $this->topologyQueries->architectureHealth($projectId, $edgeKinds, $minConfidence, $limit, $maxNodes, $maxEdges, $timeoutMs, $includeExternal, $includeTests);
    }

    /** @param list<array<string, mixed>> $policies */
    public function checkArchitecture(
        string $projectId,
        array $policies,
        string $minConfidence = 'possible',
        int $limit = 100,
        int $maxEdges = 20_000,
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->policyQueries->checkArchitecture($projectId, $policies, $minConfidence, $limit, $maxEdges, $timeoutMs);
    }

    public function suggestLocation(
        string $projectId,
        string $featureDescription,
        int $limit = 5,
        int $maxMembers = 20_000,
        int $maxEdges = 20_000,
        int $timeoutMs = 1000,
        string $rankingMode = 'deterministic',
    ): ResultEnvelope {
        return $this->policyQueries->suggestLocation($projectId, $featureDescription, $limit, $maxMembers, $maxEdges, $timeoutMs, $rankingMode);
    }

    /** @param list<string> $edgeKinds */
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
        return $this->changeQueries->changeImpact($projectId, $symbol, $sinceDays, $maxCommits, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /** @param list<string> $files @param list<string> $edgeKinds */
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
        return $this->changeQueries->changedFilesImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /** @param list<string> $files @param list<string> $edgeKinds */
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
        return $this->changeQueries->testImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /**
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
        return $this->reviewQueries->reviewDiff($projectId, $baseRef, $files, $policies, $budgets, $baselineSnapshot, $maxDepth, $limit, $minConfidence, $timeoutMs);
    }

    /** @param list<string> $files */
    public function architectureContext(
        string $projectId,
        string $taskDescription = '',
        array $files = [],
        int $maxChars = 30_000,
        int $timeoutMs = 1500,
        bool $includeSource = false,
    ): ResultEnvelope {
        return $this->contextQueries->architectureContext($projectId, $taskDescription, $files, $maxChars, $timeoutMs, $includeSource);
    }

    /** @param list<string> $edgeKinds */
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
        return $this->diagramQueries->exportDiagram($projectId, $format, $boundary, $edgeKinds, $minConfidence, $direction, $maxNodes, $maxEdges);
    }

    /** @param list<string> $edgeKinds */
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
        return $this->topologyQueries->explainFlow($projectId, $from, $to, $maxDepth, $maxPaths, $edgeKinds, $minConfidence, $timeoutMs);
    }

    /** @param list<string> $edgeKinds */
    public function impactAnalysis(
        string $projectId,
        string $symbol,
        int $maxDepth = 4,
        int $limit = 100,
        array $edgeKinds = [],
        string $minConfidence = 'possible',
        int $timeoutMs = 1000,
    ): ResultEnvelope {
        return $this->topologyQueries->impactAnalysis($projectId, $symbol, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
    }

    public function listBoundaries(string $projectId, ?string $source = null, int $limit = 50, int $offset = 0): ResultEnvelope
    {
        return $this->topologyQueries->listBoundaries($projectId, $source, $limit, $offset);
    }

    public function exportAgentBrief(string $projectId, int $maxChars = 4000): ResultEnvelope
    {
        return $this->briefQueries->exportAgentBrief($projectId, $maxChars);
    }

    /** @param list<string> $kinds @param list<string> $roles @param list<string> $boundaryIds @param list<string> $confidences */
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
        return $this->componentQueries->searchArchitecture($projectId, $query, $kinds, $roles, $boundaryIds, $confidences, $limit, $offset);
    }

    public function annotateComponent(string $projectId, string $component, string $kind, string $value = '', bool $remove = false, bool $execute = false): ResultEnvelope
    {
        return $this->annotationQueries->annotateComponent($projectId, $component, $kind, $value, $remove, $execute);
    }

    public function listAnnotations(string $projectId, ?string $component = null, ?string $kind = null, int $limit = 100, int $offset = 0): ResultEnvelope
    {
        return $this->annotationQueries->listAnnotations($projectId, $component, $kind, $limit, $offset);
    }
}
