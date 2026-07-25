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
    /**
     * Kinds a scanner may name interchangeably when a type appears in a type
     * position (a parameter, a property, a return, a static access target).
     *
     * A scanner reads one file at a time: seeing `Payable $x`, it cannot know
     * whether `Payable` is declared as a class, an interface, a trait, or an
     * enum elsewhere, so the PHP worker emits every such reference as `class`.
     * Resolution is by exact reference string, so without this list the lookup
     * misses the real declaration and a phantom `external_class` twin is
     * fabricated beside it — leaving every interface and enum in a PHP graph
     * with an in-degree of zero however heavily it is used.
     *
     * Order is fixed so a resolution never depends on scan order.
     */
    private const TYPE_KINDS = ['class', 'interface', 'trait', 'enum'];

    /**
     * Kinds that name a member of a type, written `<type>::<member>`, and so
     * may be satisfied by a trait the type uses or a type it inherits from
     * rather than by the type itself.
     */
    private const MEMBER_KINDS = ['method', 'property'];

    /**
     * Edge kinds that put another type's members in scope on the source type,
     * in PHP's own resolution order: a trait method shadows an inherited one.
     */
    private const INHERITANCE_KINDS = ['uses_trait', 'extends', 'implements'];

    /**
     * Guard against a cyclic inheritance graph. PHP cannot express one, but a
     * partial or malformed contribution can, and resolution must terminate.
     */
    private const MAX_INHERITANCE_DEPTH = 20;

    public function __construct(private GraphRepository $repository) {}

    public function reconcile(FullScanRequest $request): ReconciliationResult
    {
        $projectId = StableId::project($request->projectIdentity);
        $scannerSetHash = self::scannerSetHash($request->scanners);
        $scanId = StableId::scan($projectId, bin2hex(random_bytes(16)));

        // Initialize phase timing window before any other pre-transaction work (including
        // the $fileIds StableId hashing loop below) so 'prepare' captures the full
        // duration of all pre-transaction preparation, matching its docblock elsewhere.
        $phaseMs = [];
        $phaseStarted = hrtime(true);
        $mark = static function (string $phase) use (&$phaseMs, &$phaseStarted): void {
            $phaseMs[$phase] = round((hrtime(true) - $phaseStarted) / 1_000_000, 3);
            $phaseStarted = hrtime(true);
        };

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

        $mark('prepare');

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
            $mark,
        ): void {
            $previousProject = $this->repository->findProject($projectId);
            $this->repository->archiveActiveSnapshot(
                $projectId,
                hash('sha256', (string) ($previousProject['config_json'] ?? '{}')),
                $request->projectConfig['snapshot_retention'] ?? 5,
            );
            $mark('archive_snapshot');

            // saveProject/createScan fall inside the clear_graph window per the
            // phase-timing contract: they are cheap bookkeeping writes that
            // immediately precede clearProjectGraph, and splitting them into
            // their own phase would add noise without profiling value.
            $this->repository->saveProject(
                $projectId,
                $request->projectName,
                $request->discovery->rootRealpath,
                $request->projectConfig,
            );
            $this->repository->createScan($scanId, $projectId, $request->mode, $scannerSetHash);
            $this->repository->clearProjectGraph($projectId);
            $mark('clear_graph');

            $versions = $this->scannerVersions($request->scanners);
            $this->repository->saveFiles($this->fileRows($request->discovery->files, $fileIds, $versions), $projectId, $scanId);
            $mark('save_files');

            $this->repository->saveNodes(array_values($nodes), $projectId, $scanId);
            $mark('save_nodes');
            $this->repository->saveEdges(array_values($edges), $projectId, $scanId);
            $mark('save_edges');

            $this->repository->saveClassifications($classifications, $projectId, $scanId);
            $mark('save_classifications');

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
            $mark('save_boundaries');

            $this->repository->replaceContributionCache($projectId, $request->contributionCache);
            $mark('contribution_cache');

            $diagnosticCount = $this->saveDiagnostics($request, $projectId, $scanId, $fileIds, $nodeWarnings);
            // completeScan falls inside the save_diagnostics window (same rationale as
            // clear_graph folding in saveProject/createScan above): it's a cheap trailing
            // bookkeeping write, not worth its own phase.
            $this->repository->completeScan($projectId, $scanId);
            $mark('save_diagnostics');
        });

        return new ReconciliationResult(
            $projectId,
            $scanId,
            count($request->discovery->files),
            count($nodes),
            count($edges),
            $diagnosticCount,
            count($externalNodes),
            $phaseMs,
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
                // Use identityName (the pre-suffix primary rule name) when present so a
                // merged inferred boundary's stable id is independent of its display-only
                // merged-from suffix; see BoundaryFact::$identityName.
                'id' => StableId::boundary($projectId, $fact->identityName ?? $fact->name, $fact->source),
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
        $inheritanceSources = $this->inheritanceSources($contributions);
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                $sourceId = $nodeMap[$edge->sourceReference] ?? null;
                if ($sourceId === null) {
                    throw new ReconciliationException(sprintf(
                        'Edge source was not emitted by any scanner: %s',
                        $edge->sourceReference,
                    ));
                }

                $targetId = $nodeMap[$edge->targetReference]
                    ?? $this->aliasedTypeTarget($edge->targetReference, $nodeMap)
                    ?? $this->inheritedMemberTarget($edge->targetReference, $nodeMap, $inheritanceSources);
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
    /**
     * Resolve a type reference whose kind segment disagrees with the kind the
     * declaration was emitted under, by retrying the lookup against the other
     * {@see self::TYPE_KINDS}. The language segment is never varied: a PHP
     * `Error` and a TypeScript `Error` are different symbols.
     *
     * Returns null when the reference is not in a type position, or names
     * nothing declared in this graph — both of which stay external.
     *
     * @param array<string, string> $nodeMap
     */
    private function aliasedTypeTarget(string $reference, array $nodeMap): ?string
    {
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$language, $kind, $canonical] = $parts;
        if (!in_array($kind, self::TYPE_KINDS, true)) {
            return null;
        }

        foreach (self::TYPE_KINDS as $alias) {
            if ($alias === $kind) {
                continue;
            }
            $candidate = $nodeMap[$language . ':' . $alias . ':' . $canonical] ?? null;
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Index the `uses_trait`, `extends`, and `implements` edges of this scan as
     * type reference => the type references whose members it inherits, so a
     * member lookup can walk the composition.
     *
     * Each kind is collected separately and merged in INHERITANCE_KINDS order,
     * so the breadth-first search visits a type's traits before its parents —
     * PHP's own precedence — however the scanner happened to order its edges.
     *
     * @param list<ScanContribution> $contributions
     * @return array<string, list<string>>
     */
    private function inheritanceSources(array $contributions): array
    {
        $byKind = array_fill_keys(self::INHERITANCE_KINDS, []);
        foreach ($contributions as $contribution) {
            foreach ($contribution->edges as $edge) {
                if (isset($byKind[$edge->kind])) {
                    $byKind[$edge->kind][$edge->sourceReference][] = $edge->targetReference;
                }
            }
        }

        $sources = [];
        foreach (self::INHERITANCE_KINDS as $kind) {
            foreach ($byKind[$kind] as $source => $targets) {
                $sources[$source] = [...($sources[$source] ?? []), ...$targets];
            }
        }

        return $sources;
    }

    /**
     * Resolve a member reference the named type does not itself declare to the
     * trait, parent class, or interface that does.
     *
     * A scanner reads one file at a time: seeing `$this->log()` inside
     * `App\Invoice` it can only emit `php:method:App\Invoice::log`, even when
     * `log` is declared by a trait `App\Invoice` uses or by the class it
     * extends. Resolution is by exact reference string, so without this the
     * lookup misses the declaration and a phantom `external_method` twin is
     * fabricated beside it — leaving every trait method, every inherited
     * helper, and every interface method with an in-degree of zero however
     * heavily it is called.
     *
     * The search follows nested `use` statements and the full inheritance
     * chain, since both compose, and is depth-bounded so a cyclic contribution
     * cannot hang the reconcile. Returns null when the reference is not a
     * member, names no known type, or names a member nothing in scope declares
     * — all of which stay external.
     *
     * @param array<string, string> $nodeMap
     * @param array<string, list<string>> $inheritanceSources
     */
    private function inheritedMemberTarget(string $reference, array $nodeMap, array $inheritanceSources): ?string
    {
        if ($inheritanceSources === []) {
            return null;
        }
        $parts = explode(':', $reference, 3);
        if (count($parts) !== 3) {
            return null;
        }
        [$language, $kind, $canonical] = $parts;
        if (!in_array($kind, self::MEMBER_KINDS, true)) {
            return null;
        }
        $separator = strrpos($canonical, '::');
        if ($separator === false) {
            return null;
        }
        $type = substr($canonical, 0, $separator);
        $member = substr($canonical, $separator + 2);
        if ($type === '' || $member === '') {
            return null;
        }

        // The reference carries the member's kind, not the declaring type's, so
        // every type kind that could inherit members is tried.
        $frontier = [];
        foreach (self::TYPE_KINDS as $typeKind) {
            foreach ($inheritanceSources[$language . ':' . $typeKind . ':' . $type] ?? [] as $source) {
                $frontier[] = $source;
            }
        }
        $seen = [];
        for ($depth = 0; $depth < self::MAX_INHERITANCE_DEPTH && $frontier !== []; $depth++) {
            $next = [];
            foreach ($frontier as $sourceReference) {
                if (isset($seen[$sourceReference])) {
                    continue;
                }
                $seen[$sourceReference] = true;
                $sourceParts = explode(':', $sourceReference, 3);
                if (count($sourceParts) !== 3 || $sourceParts[0] !== $language) {
                    continue;
                }
                $candidate = $nodeMap[$language . ':' . $kind . ':' . $sourceParts[2] . '::' . $member] ?? null;
                if ($candidate !== null) {
                    return $candidate;
                }
                foreach ($inheritanceSources[$sourceReference] ?? [] as $nested) {
                    $next[] = $nested;
                }
            }
            $frontier = $next;
        }

        return null;
    }

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
