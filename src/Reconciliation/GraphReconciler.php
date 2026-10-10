<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\UnitInputSet;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\Evidence;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Scanner\Protocol\ScannerManifest;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;

/**
 * Merges scanner contributions into the persisted graph.
 *
 * The hard part is identity: facts arrive per file with canonical names, and have
 * to become stable ids, resolved edges, and per-scanner ownership so one language's
 * facts can be replaced without disturbing another's. Runs in one transaction — a
 * partially reconciled graph would answer queries confidently and wrongly.
 */
final readonly class GraphReconciler
{
    /**
     * A reference the graph cannot turn into a symbol. Emitted by a scanner that
     * could not name one segment — a relative import with no parent package, an
     * unqualified call it deferred and never resolved. One malformed fact must
     * cost its own edge, not the scan: the source file is still worth a graph,
     * and the caller needs to be told which reference was dropped.
     */
    private const UNRESOLVABLE_TARGET_CODE = 'reconciler.unresolvable_edge_target';

    public function __construct(
        private GraphRepository $repository,
    ) {}

    /**
     * Merge a scan's contributions into the graph, in one transaction.
     *
     * @param ?callable(): void $beforeBulkTransaction an optional final check
     *        run after reconciliation preparation and before persistence
     */
    public function reconcile(FullScanRequest $request, ?callable $beforeBulkTransaction = null): ReconciliationResult
    {
        // The timer starts before any other pre-transaction work, including
        // the file id hashing, so 'prepare' captures the full duration of all
        // pre-transaction preparation.
        $timer = new PhaseTimer();
        $graph = $this->prepare($request);
        $timer->mark('prepare');

        if ($beforeBulkTransaction !== null) {
            $beforeBulkTransaction();
        }
        $diagnosticCount = $this->persist($graph, $timer);
        // The commit is a phase of its own: it writes every page the reconcile
        // dirtied, which on this repository is a third of the reconciliation and
        // was previously invisible — the phase timings summed to well under the
        // total and gave no clue where the difference went.
        $timer->mark('commit');

        return new ReconciliationResult(
            $graph->projectId,
            $graph->scanId,
            count($request->discovery->files),
            count($graph->nodes),
            count($graph->edges),
            $diagnosticCount,
            $graph->externalNodes,
            $timer->phases(),
        );
    }

    /** Resolve the request into the rows the transaction writes, before any lock is taken. */
    private function prepare(FullScanRequest $request): PreparedGraph
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
        [$externalNodes, $edges, $edgeWarnings, $unconfirmedCalls] = $this->resolveEdges($projectId, $request->contributions, $nodeMap, $fileIds);
        self::recordUnconfirmedCalls($nodes, $unconfirmedCalls);
        foreach ($externalNodes as $id => $node) {
            $nodes[$id] = $node;
        }

        return new PreparedGraph(
            $request,
            $projectId,
            $scanId,
            $scannerSetHash,
            $fileIds,
            $nodes,
            $edges,
            $this->resolveClassifications($projectId, $request->classifications, $nodeMap, $fileIds),
            $this->resolveBoundaries($projectId, $request->boundaries, $nodeMap),
            [...$nodeWarnings, ...$edgeWarnings],
            count($externalNodes),
        );
    }

    /**
     * Write a prepared graph in one transaction, returning how many diagnostics it saved.
     *
     * A rewrite of this size is dominated by per-statement foreign-key
     * enforcement, so integrity is verified once before the commit instead.
     */
    private function persist(PreparedGraph $graph, PhaseTimer $timer): int
    {
        $diagnosticCount = 0;
        $this->repository->bulkTransaction(function () use ($graph, $timer, &$diagnosticCount): void {
            $existing = $this->openScan($graph, $timer);
            $memberships = $this->writeGraph($graph, $timer);

            $this->repository->pruneGraph($graph->projectId, $existing, [
                'files' => array_fill_keys(array_values($graph->fileIds), true),
                'nodes' => array_fill_keys(array_keys($graph->nodes), true),
                'edges' => array_fill_keys(array_keys($graph->edges), true),
                'classifications' => array_fill_keys(array_column($graph->classifications, 'id'), true),
                'boundaries' => array_fill_keys(array_column($graph->boundaries, 'id'), true),
                'boundary_memberships' => array_fill_keys(array_map(
                    static fn(array $membership): string => $membership['boundary_id'] . "\0" . $membership['node_id'],
                    $memberships,
                ), true),
            ]);
            // Rows this scan left untouched are still current, so they carry its
            // id too; nothing indexes the column, so this rewrites rows without
            // touching an index.
            $this->repository->stampGraphScan($graph->projectId, $graph->scanId);
            $timer->mark('prune');

            $this->repository->replaceContributionCache($graph->projectId, $graph->request->contributionCache, $graph->request->readGroups);
            $timer->mark('contribution_cache');

            // Diagnostics belong to the scan that produced them, so the previous
            // scan's are replaced wholesale rather than diffed.
            $this->repository->clearProjectDiagnostics($graph->projectId);
            $diagnosticCount = (new ReconciliationDiagnostics($this->repository))->save(
                $graph->request,
                $graph->projectId,
                $graph->scanId,
                $graph->fileIds,
                $graph->warnings,
            );
            // completeScan falls inside the save_diagnostics window (same rationale as
            // read_existing folding in saveProject/createScan): it's a cheap trailing
            // bookkeeping write, not worth its own phase.
            $this->repository->completeScan($graph->projectId, $graph->scanId);
            $timer->mark('save_diagnostics');
        });

        return $diagnosticCount;
    }

    /**
     * Archive the previous snapshot, record the project and the scan, and read
     * the ids the graph holds now, so what this scan does not produce can be
     * deleted afterwards.
     *
     * @return array<string, array<string, true>> the ids the graph holds now, per table
     */
    private function openScan(PreparedGraph $graph, PhaseTimer $timer): array
    {
        $request = $graph->request;
        $previousProject = $this->repository->findProject($graph->projectId);
        $this->repository->archiveActiveSnapshot(
            $graph->projectId,
            hash('sha256', (string) ($previousProject['config_json'] ?? '{}')),
            $request->projectConfig['snapshot_retention'] ?? GraphRepository::DEFAULT_SNAPSHOT_RETENTION,
        );
        $timer->mark('archive_snapshot');

        // saveProject/createScan fall inside the read_existing window per the
        // phase-timing contract: they are cheap bookkeeping writes that
        // immediately precede the read, and splitting them into their own
        // phase would add noise without profiling value.
        $this->repository->saveProject(
            $graph->projectId,
            $request->projectName,
            $request->discovery->rootRealpath,
            $request->projectConfig,
        );
        $this->repository->createScan(
            $graph->scanId,
            $graph->projectId,
            $request->mode,
            $graph->scannerSetHash,
            // Taken from the request, not resolved here. Two reasons, and the
            // second is the one that matters: shelling out while the graph write
            // lock is held would hold that lock for a subprocess timeout, and
            // resolving at this point resolves *after* discovery has read every
            // file, so a commit landing in between records a commit this graph was
            // never built against. The caller captures it before the walk; see
            // {@see \Knossos\Scan\ScanPlanner}.
            $request->gitHead,
            $request->dirtyPaths?->encode(),
            // The manifests and successful worker dependency reads this
            // scan used but stores no files row for. Without them, editing
            // composer.json or a node_modules declaration changes what a
            // scan would produce while the graph reports itself fresh.
            UnitInputSet::of($request->discovery->units)->encode($request->workerInputs),
        );
        // Reading ids is what makes the write proportional to the change:
        // clearing the project first meant every row had to be written back
        // whether or not the scan altered it.
        $existing = $this->repository->existingGraphIds($graph->projectId);
        $timer->mark('read_existing');

        return $existing;
    }

    /**
     * Write the prepared files, nodes, edges, classifications and boundaries.
     *
     * @return list<array{boundary_id: string, node_id: string}> the boundary memberships written
     */
    private function writeGraph(PreparedGraph $graph, PhaseTimer $timer): array
    {
        $projectId = $graph->projectId;
        $scanId = $graph->scanId;
        $versions = $this->scannerVersions($graph->request->scanners);
        $this->repository->saveFiles($this->fileRows($graph->request->discovery->files, $graph->fileIds, $versions), $projectId, $scanId);
        $timer->mark('save_files');

        $this->repository->saveNodes(array_values($graph->nodes), $projectId, $scanId);
        $timer->mark('save_nodes');
        $this->repository->saveEdges(array_values($graph->edges), $projectId, $scanId);
        $timer->mark('save_edges');

        $this->repository->saveClassifications($graph->classifications, $projectId, $scanId);
        $timer->mark('save_classifications');

        $memberships = [];
        foreach ($graph->boundaries as $boundary) {
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
        $timer->mark('save_boundaries');

        return $memberships;
    }

    /**
     * Resolve classification facts onto node ids, dropping any whose node vanished.
     *
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

    /**
     * Resolve boundary definitions and their memberships onto node ids.
     *
     * @param list<\Knossos\Boundary\BoundaryFact> $facts @param array<string, string> $nodeMap @return list<array<string, mixed>>
     */
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
                // Use identityName (the pre-suffix primary rule name) when present so a
                // merged inferred boundary's stable id is independent of its display-only
                // merged-from suffix; see BoundaryFact::$identityName.
                'id' => StableId::boundary($projectId, $fact->identityName ?? $fact->name, $fact->source),
                'name' => $fact->name,
                // The former names ride along in the stored matcher; see BoundaryAliases.
                'matcher' => $fact->storedMatcher(),
                'source' => $fact->source,
                'node_ids' => array_values(array_unique($nodeIds)),
            ];
        }
        return $resolved;
    }

    /**
     * Assign stable ids to the reported nodes and index them for edge resolution.
     *
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
     * Resolve each edge's endpoints to node ids, synthesising externals for unknown targets.
     *
     * @param list<ScanContribution> $contributions
     * @param array<string, string> $nodeMap
     * @param array<string, string> $fileIds
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>, 2: list<array<string, string>>, 3: array<string, array<string, true>>}
     */
    private function resolveEdges(string $projectId, array $contributions, array $nodeMap, array $fileIds): array
    {
        $external = [];
        $edges = [];
        $unconfirmed = [];
        $warnings = [];
        $warnedReferences = [];
        $targets = EdgeTargetResolver::forContributions($nodeMap, $contributions);
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $sourceId = $nodeMap[$edge->sourceReference] ?? null;
                if ($sourceId === null) {
                    throw new ReconciliationException(sprintf(
                        'Edge source was not emitted by any scanner: %s',
                        $edge->sourceReference,
                    ));
                }

                $expanded = $targets->expandedTargets($edge->targetReference);
                if ($expanded !== null) {
                    foreach ($expanded as $targetId) {
                        $record = $this->edgeWithEvidence($projectId, $edge, $sourceId, $targetId, $contribution->ownerKey, $fileIds);
                        $edges[$record['id']] = $record;
                    }
                    continue;
                }
                $returned = str_contains($edge->targetReference, ':method_of_return:')
                    || str_contains($edge->targetReference, ':method_of_property:');
                // A scanner marks an edge speculative when it knows the
                // receiver's type but not whether that type declares the member
                // (it may be a trait's or a base's): kept only when it resolves.
                $deferred = $returned || ($edge->attributes['speculative'] ?? false) === true;
                [$reference, $targetId] = $targets->resolve($edge->targetReference, $returned);
                if ($targetId === null && $deferred) {
                    // A speculative reference the graph cannot confirm. Dropping
                    // it keeps an inference that did not pay off out of the
                    // graph, rather than inventing an external symbol for a
                    // member that may not exist. The call still reached some
                    // method of that name, so the name is kept as a call on a
                    // receiver nobody could type. A member read as a value may
                    // be a data attribute, so only a call counts.
                    $member = $edge->kind === 'calls' ? self::calledMemberName($edge->targetReference) : null;
                    if ($member !== null) {
                        $unconfirmed[$sourceId][$member] = true;
                    }
                    continue;
                }
                if ($targetId === null && !self::isResolvableReference((string) $reference)) {
                    if (!isset($warnedReferences[$reference])) {
                        $warnedReferences[$reference] = true;
                        $warnings[] = [
                            'owner' => $contribution->ownerKey,
                            'code' => self::UNRESOLVABLE_TARGET_CODE,
                            'message' => sprintf(
                                'Edge target %s names no resolvable symbol; the %s edge from %s was dropped.',
                                json_encode($reference),
                                $edge->kind,
                                $edge->sourceReference,
                            ),
                            'path' => $edge->evidence->relativePath,
                        ];
                    }
                    continue;
                }
                if ($targetId === null) {
                    [$targetId, $externalNode] = $this->externalNode(
                        $projectId,
                        $targets->spelling((string) $reference),
                        $edge->evidence,
                        $contribution->ownerKey,
                        $fileIds,
                    );
                    $external[$targetId] ??= $externalNode;
                }

                $record = $this->edgeWithEvidence($projectId, $edge, $sourceId, $targetId, $contribution->ownerKey, $fileIds);
                $edges[$record['id']] = $record;
            }
        }

        return [$external, $edges, $warnings, $unconfirmed];
    }

    /**
     * The member a method reference names (`<lang>:method:Owner::member`,
     * `<lang>:method_of_return:callee::member`,
     * `<lang>:method_of_property:Type::$property::member`), or null for any other and for
     * one whose owner or member is empty.
     */
    private static function calledMemberName(string $reference): ?string
    {
        if (!str_contains($reference, ':method:') && !str_contains($reference, ':method_of_return:') && !str_contains($reference, ':method_of_property:')) {
            return null;
        }
        [, , $target] = array_pad(explode(':', $reference, 3), 3, '');
        $separator = strrpos($target, '::');
        if ($separator === false || $separator === 0) {
            // No owner, so the reference names no receiver a call was made on.
            return null;
        }
        $member = substr($target, $separator + 2);

        return $member === '' ? null : $member;
    }

    /**
     * Adds each source node's unconfirmed calls to its `unresolved_member_calls`,
     * the names dead-code analysis reads project-wide: a method by one of them is
     * only possibly dead, as it is when a scanner could not type the receiver.
     *
     * @param array<string, array<string, mixed>> $nodes
     * @param array<string, array<string, true>> $unconfirmed source node id => member names
     */
    private static function recordUnconfirmedCalls(array &$nodes, array $unconfirmed): void
    {
        // Every source id came from the node map, so its node is here.
        foreach ($unconfirmed as $sourceId => $members) {
            $existing = $nodes[$sourceId]['attributes']['unresolved_member_calls'] ?? [];
            $names = [...(is_array($existing) ? array_values(array_filter($existing, 'is_string')) : []), ...array_map('strval', array_keys($members))];
            $names = array_values(array_unique($names));
            sort($names, SORT_STRING);
            $nodes[$sourceId]['attributes']['unresolved_member_calls'] = $names;
        }
    }

    /**
     * An edge's record, identified by its kind, ends and evidence.
     *
     * @param array<string, string> $fileIds
     * @return array<string, mixed>
     */
    private function edgeWithEvidence(string $projectId, EdgeFact $edge, string $sourceId, string $targetId, string $ownerKey, array $fileIds): array
    {
        $evidenceKey = sprintf(
            '%s:%d:%d:%s',
            $edge->evidence->relativePath,
            $edge->evidence->startLine,
            $edge->evidence->endLine,
            $ownerKey,
        );
        $id = StableId::edge($projectId, $edge->kind, $sourceId, $targetId, $evidenceKey);

        return $this->edgeRecord($id, $edge, $sourceId, $targetId, $ownerKey, $fileIds);
    }

    /**
     * Whether a reference has the three non-empty segments an external node
     * needs. Mirrors the check {@see externalNode()} performs, so a caller can
     * decide to drop an edge before reaching it.
     */
    private static function isResolvableReference(string $reference): bool
    {
        $parts = explode(':', $reference, 3);

        return count($parts) === 3 && !in_array('', $parts, true);
    }

    /**
     * Build the persisted row for one reported node.
     *
     * @return array<string, mixed>
     */
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

    /** Synthesise a node for a referenced symbol outside the scanned tree, so the edge still has a target. */

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

    /**
     * Build the persisted row for one resolved edge.
     *
     * @param array<string, string> $fileIds @return array<string, mixed>
     */
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

    /**
     * Attach nodes to their file rows, which is what makes evidence paths resolvable.
     *
     * @param array<string, string> $fileIds
     */
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
     * The file rows this scan wrote, indexed for node attachment.
     *
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

    /**
     * Which scanner produced each contribution, recorded for provenance.
     *
     * @param list<ScannerManifest> $scanners @return array<string, string>
     */
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

    /**
     * Identity of the analyzer set, so a change invalidates incremental reuse.
     *
     * @param list<ScannerManifest> $scanners
     */
    public static function scannerSetHash(array $scanners): string
    {
        $serialized = [];
        foreach ($scanners as $scanner) {
            $serialized[$scanner->id] = $scanner->jsonSerialize();
        }
        ksort($serialized, SORT_STRING);
        return hash('sha256', json_encode($serialized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
    /** The owning scanner for a fact, from its owner key. */

    private function scannerFromOwner(string $owner): string
    {
        $parts = explode(':', $owner, 2);
        return $parts[0];
    }
    /** The language a canonical reference belongs to, used when synthesising an external node. */

    private function languageFromReference(string $reference): string
    {
        $parts = explode(':', $reference, 2);
        if (count($parts) !== 2 || $parts[0] === '') {
            throw new ReconciliationException(sprintf('Node local ID has no language namespace: %s', $reference));
        }
        return $parts[0];
    }
    /** The short name shown to a reader, derived from the canonical name. */

    private function displayName(string $canonical): string
    {
        $parts = preg_split('/(?:\\\\|::|[.#\/])/', $canonical);
        return $parts === false || $parts === [] ? $canonical : (string) end($parts);
    }
}
