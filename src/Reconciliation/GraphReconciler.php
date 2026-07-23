<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;

final readonly class GraphReconciler
{
    public function __construct(private GraphRepository $repository) {}

    public function reconcile(FullScanRequest $request): ReconciliationResult
    {
        $projectId = StableId::project($request->projectIdentity);
        $scannerSetHash = self::scannerSetHash($request->scanners);
        $scanId = StableId::scan($projectId, bin2hex(random_bytes(16)));
        $fileIds = [];
        foreach ($request->discovery->files as $file) {
            $fileIds[$file->relativePath] = StableId::file($projectId, $file->relativePath);
        }

        [$nodeMap, $nodes, $nodeWarnings] = $this->collectNodes($projectId, $request->contributions);
        $this->attachNodeFiles($nodes, $fileIds);
        [$externalNodes, $edges] = $this->resolveEdges($projectId, $request->contributions, $nodeMap, $fileIds);
        foreach ($externalNodes as $id => $node) {
            $nodes[$id] = $node;
        }
        $classifications = $this->resolveClassifications(
            $projectId,
            $request->classifications,
            $nodeMap,
            $fileIds,
        );
        $boundaries = $this->resolveBoundaries($projectId, $request->boundaries, $nodeMap);

        $diagnosticCount = 0;
        $this->repository->transaction(function () use (
            $request,
            $projectId,
            $scanId,
            $scannerSetHash,
            $fileIds,
            $nodes,
            $edges,
            $classifications,
            $boundaries,
            $nodeWarnings,
            &$diagnosticCount,
        ): void {
            $previousProject = $this->repository->findProject($projectId);
            $this->repository->archiveActiveSnapshot(
                $projectId,
                hash('sha256', (string) ($previousProject['config_json'] ?? '{}')),
                $request->projectConfig['snapshot_retention'] ?? 5,
            );
            $this->repository->saveProject(
                $projectId,
                $request->projectName,
                $request->discovery->rootRealpath,
                $request->projectConfig,
            );
            $this->repository->createScan($scanId, $projectId, $request->mode, $scannerSetHash);
            $this->repository->clearProjectGraph($projectId);

            $versions = $this->scannerVersions($request->scanners);
            $this->repository->saveFiles($this->fileRows($request->discovery->files, $fileIds, $versions), $projectId, $scanId);

            $this->repository->saveNodes(array_values($nodes), $projectId, $scanId);
            $this->repository->saveEdges(array_values($edges), $projectId, $scanId);

            $this->repository->saveClassifications($classifications, $projectId, $scanId);

            $memberships = [];
            foreach ($boundaries as $boundary) {
                $this->repository->saveBoundary(
                    $boundary['id'],
                    $projectId,
                    $boundary['name'],
                    $boundary['matcher'],
                    $boundary['source'],
                    $scanId,
                );
                foreach ($boundary['node_ids'] as $nodeId) {
                    $memberships[] = ['boundary_id' => $boundary['id'], 'node_id' => $nodeId];
                }
            }
            $this->repository->saveBoundaryMemberships($memberships, $projectId, $scanId);

            $this->repository->replaceContributionCache($projectId, $request->contributionCache);

            $diagnosticCount = $this->saveDiagnostics($request, $projectId, $scanId, $fileIds, $nodeWarnings);
            $this->repository->completeScan($projectId, $scanId);
        });

        return new ReconciliationResult(
            $projectId,
            $scanId,
            count($request->discovery->files),
            count($nodes),
            count($edges),
            $diagnosticCount,
            count($externalNodes),
        );
    }

    /**
     * @param list<\Knossos\Classification\ClassificationFact> $facts
     * @param array<string, string> $nodeMap
     * @param array<string, string> $fileIds
     * @return list<array<string, mixed>>
     */
    private function resolveClassifications(string $projectId, array $facts, array $nodeMap, array $fileIds): array
    {
        $resolved = [];
        foreach ($facts as $fact) {
            $nodeId = $nodeMap[$fact->nodeReference] ?? null;
            if ($nodeId === null) {
                throw new ReconciliationException(sprintf('Classification target was not emitted: %s', $fact->nodeReference));
            }
            $fileId = $fileIds[$fact->evidence->relativePath] ?? null;
            if ($fileId === null) {
                throw new ReconciliationException(sprintf('Classification evidence file was not discovered: %s', $fact->evidence->relativePath));
            }
            $resolved[] = [
                'id' => StableId::classification($projectId, $nodeId, $fact->role, $fact->ruleId),
                'node_id' => $nodeId,
                'role' => $fact->role,
                'origin' => $fact->origin->value,
                'confidence' => $fact->confidence->value,
                'rule_id' => $fact->ruleId,
                'file_id' => $fileId,
                'start_line' => $fact->evidence->startLine,
                'end_line' => $fact->evidence->endLine,
                'attributes' => $fact->attributes,
            ];
        }
        return $resolved;
    }

    /** @param list<\Knossos\Boundary\BoundaryFact> $facts @param array<string, string> $nodeMap @return list<array<string, mixed>> */
    private function resolveBoundaries(string $projectId, array $facts, array $nodeMap): array
    {
        $resolved = [];
        foreach ($facts as $fact) {
            $nodeIds = [];
            foreach ($fact->nodeReferences as $reference) {
                if (!isset($nodeMap[$reference])) {
                    throw new ReconciliationException(sprintf('Boundary member was not emitted: %s', $reference));
                }
                $nodeIds[] = $nodeMap[$reference];
            }
            $resolved[] = [
                'id' => StableId::boundary($projectId, $fact->name, $fact->source),
                'name' => $fact->name,
                'matcher' => $fact->matcher,
                'source' => $fact->source,
                'node_ids' => array_values(array_unique($nodeIds)),
            ];
        }
        return $resolved;
    }

    /**
     * @param list<ScanContribution> $contributions
     * @return array{0: array<string, string>, 1: array<string, array<string, mixed>>, 2: list<array<string, string>>}
     */
    private function collectNodes(string $projectId, array $contributions): array
    {
        $references = [];
        $nodes = [];
        $warnings = [];
        $warnedIds = [];
        foreach ($contributions as $contribution) {
            $scanner = $this->scannerFromOwner($contribution->ownerKey);
            foreach ($contribution->nodes as $node) {
                $language = $this->languageFromReference($node->localId);
                $id = StableId::symbol($projectId, $language, $node->kind, $node->canonicalName);
                if (isset($references[$node->localId]) && $references[$node->localId] !== $id) {
                    throw new ReconciliationException(sprintf('Conflicting scanner reference: %s', $node->localId));
                }
                $references[$node->localId] = $id;

                if (isset($nodes[$id])) {
                    // Two declarations share a stable id iff they share
                    // (language, kind, canonical_name) — the very inputs the id
                    // hashes — so a kind/name mismatch here is unreachable. A
                    // genuine collision is a re-declaration from a different
                    // evidence file; keep the first and surface a warning rather
                    // than silently discarding the divergent provenance.
                    // `package`/`external_*` kinds are exempt: they are shared
                    // across every importing file by design, so a re-declaration
                    // there is not suspicious. For kinds that are suspicious, warn
                    // once per stable id rather than once per re-declaring file.
                    $existingPath = $nodes[$id]['evidence_path'];
                    $sharedByDesign = $node->kind === 'package' || str_starts_with($node->kind, 'external_');
                    if ($existingPath !== $node->evidence->relativePath && !$sharedByDesign && !isset($warnedIds[$id])) {
                        $warnedIds[$id] = true;
                        $warnings[] = [
                            'owner' => $contribution->ownerKey,
                            'code' => 'reconciler.duplicate_symbol_evidence',
                            'message' => sprintf(
                                'Stable id %s re-declared by %s with a different evidence file (%s vs %s); keeping the first declaration.',
                                $id,
                                $contribution->ownerKey,
                                $existingPath,
                                $node->evidence->relativePath,
                            ),
                            'path' => $node->evidence->relativePath,
                        ];
                    }
                    continue;
                }

                $nodes[$id] = $this->nodeRecord($id, $language, $node, $contribution->ownerKey, $scanner);
            }
        }

        return [$references, $nodes, $warnings];
    }

    /**
     * @param list<ScanContribution> $contributions
     * @param array<string, string> $nodeMap
     * @param array<string, string> $fileIds
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    private function resolveEdges(string $projectId, array $contributions, array $nodeMap, array $fileIds): array
    {
        $external = [];
        $edges = [];
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $sourceId = $nodeMap[$edge->sourceReference] ?? null;
                if ($sourceId === null) {
                    throw new ReconciliationException(sprintf(
                        'Edge source was not emitted by any scanner: %s',
                        $edge->sourceReference,
                    ));
                }

                $targetId = $nodeMap[$edge->targetReference] ?? null;
                if ($targetId === null) {
                    [$targetId, $externalNode] = $this->externalNode(
                        $projectId,
                        $edge->targetReference,
                        $edge->evidence,
                        $contribution->ownerKey,
                        $fileIds,
                    );
                    $external[$targetId] ??= $externalNode;
                }

                $evidenceKey = sprintf(
                    '%s:%d:%d:%s',
                    $edge->evidence->relativePath,
                    $edge->evidence->startLine,
                    $edge->evidence->endLine,
                    $contribution->ownerKey,
                );
                $id = StableId::edge($projectId, $edge->kind, $sourceId, $targetId, $evidenceKey);
                $edges[$id] = $this->edgeRecord(
                    $id,
                    $edge,
                    $sourceId,
                    $targetId,
                    $contribution->ownerKey,
                    $fileIds,
                );
            }
        }

        return [$external, $edges];
    }

    /** @return array<string, mixed> */
    private function nodeRecord(string $id, string $language, NodeFact $node, string $owner, string $scanner): array
    {
        return [
            'id' => $id,
            'language' => $language,
            'kind' => $node->kind,
            'canonical_name' => $node->canonicalName,
            'display_name' => $node->displayName,
            'file_id' => null,
            'evidence_path' => $node->evidence->relativePath,
            'start_line' => $node->evidence->startLine,
            'end_line' => $node->evidence->endLine,
            'origin' => $node->origin->value,
            'confidence' => $node->confidence->value,
            'attributes' => $node->attributes + [
                'scanner' => $scanner,
                'scanner_local_id' => $node->localId,
            ],
            'owner_key' => $owner,
        ];
    }

    /**
     * @param array<string, string> $fileIds
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function externalNode(
        string $projectId,
        string $reference,
        Evidence $evidence,
        string $owner,
        array $fileIds,
    ): array {
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            throw new ReconciliationException(sprintf('Unresolvable edge target reference: %s', $reference));
        }
        [$language, $kind, $canonical] = $parts;
        $externalKind = str_starts_with($kind, 'external_') ? $kind : 'external_' . $kind;
        $id = StableId::symbol($projectId, $language, $externalKind, $canonical);

        return [$id, [
            'id' => $id,
            'language' => $language,
            'kind' => $externalKind,
            'canonical_name' => $canonical,
            'display_name' => $this->displayName($canonical),
            'file_id' => $fileIds[$evidence->relativePath] ?? null,
            'start_line' => $evidence->startLine,
            'end_line' => $evidence->endLine,
            'origin' => 'derived',
            'confidence' => 'possible',
            'attributes' => ['unresolved' => true, 'reference' => $reference],
            'owner_key' => $owner,
        ]];
    }

    /** @param array<string, string> $fileIds @return array<string, mixed> */
    private function edgeRecord(
        string $id,
        EdgeFact $edge,
        string $sourceId,
        string $targetId,
        string $owner,
        array $fileIds,
    ): array {
        $fileId = $fileIds[$edge->evidence->relativePath] ?? null;
        if ($fileId === null) {
            throw new ReconciliationException(sprintf(
                'Edge evidence file was not discovered: %s',
                $edge->evidence->relativePath,
            ));
        }

        return [
            'id' => $id,
            'kind' => $edge->kind,
            'source_id' => $sourceId,
            'target_id' => $targetId,
            'file_id' => $fileId,
            'start_line' => $edge->evidence->startLine,
            'end_line' => $edge->evidence->endLine,
            'origin' => $edge->origin->value,
            'confidence' => $edge->confidence->value,
            'attributes' => $edge->attributes,
            'owner_key' => $owner,
        ];
    }

    /** @param array<string, string> $fileIds */
    private function attachNodeFiles(array &$nodes, array $fileIds): void
    {
        foreach ($nodes as &$node) {
            $node['file_id'] = $fileIds[$node['evidence_path']] ?? null;
            if ($node['file_id'] === null) {
                throw new ReconciliationException(sprintf(
                    'Node evidence file was not discovered: %s',
                    $node['evidence_path'],
                ));
            }
            unset($node['evidence_path']);
        }
    }

    /**
     * @param array<string, string> $fileIds
     * @param list<array<string, string>> $nodeWarnings
     */
    private function saveDiagnostics(
        FullScanRequest $request,
        string $projectId,
        string $scanId,
        array $fileIds,
        array $nodeWarnings = [],
    ): int {
        $count = 0;
        foreach ($request->contributions as $contribution) {
            foreach ($contribution->diagnostics as $diagnostic) {
                $this->saveDiagnostic(
                    $diagnostic,
                    $contribution->ownerKey,
                    $projectId,
                    $scanId,
                    $fileIds,
                    $count++,
                );
            }
        }
        foreach ($request->discovery->diagnostics as $diagnostic) {
            $evidence = $diagnostic->relativePath === null ? null : [
                'path' => $diagnostic->relativePath,
                'start' => null,
                'end' => null,
            ];
            $this->repository->saveDiagnostic(
                StableId::edge($projectId, 'diagnostic', $scanId, $diagnostic->code, 'discovery:' . $count),
                $projectId,
                $scanId,
                $evidence === null ? null : ($fileIds[$evidence['path']] ?? null),
                $diagnostic->severity,
                $diagnostic->code,
                $diagnostic->message,
                null,
                null,
                'discovery',
            );
            ++$count;
        }
        foreach ($nodeWarnings as $warning) {
            $this->repository->saveDiagnostic(
                StableId::edge($projectId, 'diagnostic', $scanId, $warning['code'], 'reconciler:' . $count),
                $projectId,
                $scanId,
                $fileIds[$warning['path']] ?? null,
                'warning',
                $warning['code'],
                $warning['message'],
                null,
                null,
                $warning['owner'],
            );
            ++$count;
        }

        return $count;
    }

    /** @param array<string, string> $fileIds */
    private function saveDiagnostic(
        Diagnostic $diagnostic,
        string $owner,
        string $projectId,
        string $scanId,
        array $fileIds,
        int $sequence,
    ): void {
        $evidence = $diagnostic->evidence;
        $identity = sprintf('%s:%s:%d', $owner, $diagnostic->code, $sequence);
        $this->repository->saveDiagnostic(
            StableId::edge($projectId, 'diagnostic', $scanId, $diagnostic->code, $identity),
            $projectId,
            $scanId,
            $evidence === null ? null : ($fileIds[$evidence->relativePath] ?? null),
            $diagnostic->severity,
            $diagnostic->code,
            $diagnostic->message,
            $evidence?->startLine,
            $evidence?->endLine,
            $owner,
        );
    }

    /** @param array<string, string> $versions */
    /**
     * @param list<DiscoveredFile> $files
     * @param array<string, string> $fileIds relative path => stable file id
     * @param array<string, string> $versions language => scanner version
     * @return list<array<string, mixed>>
     */
    private function fileRows(array $files, array $fileIds, array $versions): array
    {
        $rows = [];
        foreach ($files as $file) {
            $rows[] = [
                'id' => $fileIds[$file->relativePath],
                'relative_path' => $file->relativePath,
                'content_hash' => $file->contentHash,
                'size' => $file->size,
                'mtime' => $file->mtime,
                'language' => $file->language,
                'scanner_version' => $versions[$file->language] ?? 'unknown',
                'line_count' => $file->lineCount,
            ];
        }
        return $rows;
    }

    /** @param list<ScannerManifest> $scanners @return array<string, string> */
    private function scannerVersions(array $scanners): array
    {
        $versions = [];
        foreach ($scanners as $scanner) {
            foreach ($scanner->languages as $language) {
                $versions[$language] = $scanner->id . '@' . $scanner->version;
            }
        }
        return $versions;
    }

    /** @param list<ScannerManifest> $scanners */
    public static function scannerSetHash(array $scanners): string
    {
        $serialized = [];
        foreach ($scanners as $scanner) {
            $serialized[$scanner->id] = $scanner->jsonSerialize();
        }
        ksort($serialized, SORT_STRING);
        return hash('sha256', json_encode($serialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function scannerFromOwner(string $owner): string
    {
        $parts = explode(':', $owner, 2);
        return $parts[0];
    }

    private function languageFromReference(string $reference): string
    {
        $parts = explode(':', $reference, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new ReconciliationException(sprintf('Node local ID has no language namespace: %s', $reference));
        }
        return $parts[0];
    }

    private function displayName(string $canonical): string
    {
        $parts = preg_split('/(?:\\\\|::|[.#\/])/', $canonical);
        return $parts === false || $parts === [] ? $canonical : (string) end($parts);
    }
}
