<?php

declare(strict_types=1);

namespace Knossos\Reconciliation;

use Knossos\Scanner\Protocol\Diagnostic;
use Knossos\Store\GraphRepository;
use Knossos\Store\StableId;

/**
 * Writes a scan's diagnostics: the scanners', discovery's, the reconciler's
 * own warnings, and the workers that failed.
 *
 * Each row's id is derived from its position in that fixed order, so the same
 * scan writes the same ids.
 */
final readonly class ReconciliationDiagnostics
{
    public function __construct(
        private GraphRepository $repository,
    ) {}

    /**
     * Persist the scan's diagnostics alongside the graph.
     *
     * @param array<string, string> $fileIds
     * @param list<array<string, string>> $nodeWarnings
     */
    public function save(
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
        // A language whose worker died has no file to anchor to, so the row
        // carries a null file_id (the column is nullable). Persisting it here is
        // what makes a degraded scan visible in the graph, not only in the
        // response envelope the caller happened to read.
        foreach ($request->workerDiagnostics as $diagnostic) {
            $this->repository->saveDiagnostic(
                StableId::edge($projectId, 'diagnostic', $scanId, $diagnostic['code'], 'worker:' . $count),
                $projectId,
                $scanId,
                null,
                'error',
                $diagnostic['code'],
                $diagnostic['message'],
                null,
                null,
                $diagnostic['owner'],
            );
            ++$count;
        }

        return $count;
    }

    /**
     * Persist one diagnostic.
     *
     * @param array<string, string> $fileIds
     */
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
}
