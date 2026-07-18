<?php

declare(strict_types=1);

namespace Knossos\Store;

use Knossos\Reconciliation\ContributionCacheEntry;

interface GraphRepository
{
    /**
     * Execute an operation atomically and return its result.
     *
     * @template T
     * @param callable(GraphRepository): T $operation
     * @return T
     */
    public function transaction(callable $operation): mixed;

    /**
     * Create or update project identity and non-secret configuration metadata.
     *
     * @param array<string, mixed> $config
     */
    public function saveProject(string $id, string $name, string $rootRealpath, array $config = []): void;

    /**
     * Find one project by stable ID.
     *
     * @return array<string, mixed>|null
     */
    public function findProject(string $id): ?array;

    /** Record the start of a scan before graph reconciliation. */
    public function createScan(string $id, string $projectId, string $mode, string $scannerSetHash): void;

    /** Atomically make a successfully reconciled scan active. */
    public function completeScan(string $projectId, string $scanId): void;

    /** Retain the active snapshot under the configured bounded history policy. */
    public function archiveActiveSnapshot(string $projectId, string $configHash, int $retention): void;

    /** Remove replaceable active graph facts while preserving project identity. */
    public function clearProjectGraph(string $projectId): void;

    /** Persist one scanned file and its content/provenance fingerprints. */
    public function saveFile(
        string $id,
        string $projectId,
        string $relativePath,
        string $contentHash,
        int $size,
        int $mtime,
        string $language,
        string $scannerVersion,
        string $scanId,
        int $lineCount = 0,
    ): void;

    /**
     * Persist one evidence-backed graph node.
     *
     * @param array<string, mixed> $attributes
     */
    public function saveNode(
        string $id,
        string $projectId,
        string $kind,
        string $canonicalName,
        string $displayName,
        ?string $parentId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void;

    /**
     * Persist one occurrence-level, evidence-backed directed graph edge.
     *
     * Repeated relations between the same nodes remain distinct because the
     * stable edge ID includes evidence identity.
     *
     * @param array<string, mixed> $attributes
     */
    public function saveEdge(
        string $id,
        string $projectId,
        string $kind,
        string $sourceId,
        string $targetId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        string $origin,
        string $confidence,
        array $attributes,
        string $ownerKey,
        string $scanId,
    ): void;

    /** Persist one bounded scanner or reconciliation diagnostic. */
    public function saveDiagnostic(
        string $id,
        string $projectId,
        string $scanId,
        ?string $fileId,
        string $severity,
        string $code,
        string $message,
        ?int $startLine,
        ?int $endLine,
        string $ownerKey,
    ): void;

    /**
     * Persist one deterministic role classification with rule provenance.
     *
     * @param array<string, mixed> $attributes
     */
    public function saveClassification(
        string $id,
        string $projectId,
        string $nodeId,
        string $role,
        string $origin,
        string $confidence,
        string $ruleId,
        ?string $fileId,
        ?int $startLine,
        ?int $endLine,
        array $attributes,
        string $scanId,
    ): void;

    /**
     * Persist an explicit or inferred architecture boundary.
     *
     * @param array<string, mixed> $matcher
     */
    public function saveBoundary(string $id, string $projectId, string $name, array $matcher, string $source, string $scanId): void;

    /** Associate a node with one boundary for the active scan. */
    public function saveBoundaryMembership(string $boundaryId, string $projectId, string $nodeId, string $scanId): void;

    /**
     * Replace all incremental contribution-cache entries for a project.
     *
     * @param list<ContributionCacheEntry> $entries
     */
    public function replaceContributionCache(string $projectId, array $entries): void;

    /**
     * Return bounded exact and display-name component matches.
     *
     * @return list<array<string, mixed>>
     */
    public function findNodesByName(string $projectId, string $name, int $limit = 20): array;

    /**
     * Return bounded outgoing adjacency rows for one node.
     *
     * @return list<array<string, mixed>>
     */
    public function outgoing(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array;

    /**
     * Return bounded incoming adjacency rows for one node.
     *
     * @return list<array<string, mixed>>
     */
    public function incoming(string $projectId, string $nodeId, ?string $kind = null, int $limit = 100): array;

    /** Delete every replaceable fact owned by one scanner contribution key. */
    public function deleteFactsByOwner(string $projectId, string $ownerKey): void;
}
