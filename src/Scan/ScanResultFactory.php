<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Query\ResultEnvelope;
use Knossos\Reconciliation\ReconciliationResult;

/**
 * Builds the envelope a scan returns.
 *
 * Reports counts, timings, per-scanner metadata, and diagnostics together, so a
 * caller can tell a complete scan from one where a worker degraded.
 */
final readonly class ScanResultFactory
{
    /**
     * Build the scan's result envelope from its counts, timings, and diagnostics.
     *
     * @param array<string, float> $stageMilliseconds
     */
    public function create(
        ScanPlan $plan,
        LanguageScanResult $language,
        ReconciliationResult $result,
        int $startedAt,
        array $stageMilliseconds,
        ?string $fastPath = null,
    ): ResultEnvelope {
        $warnings = array_map(
            static fn($diagnostic): string => sprintf('%s: %s', $diagnostic->code, $diagnostic->message),
            $plan->preparation->discovery->diagnostics,
        );
        foreach ($language->workerDiagnostics as $diagnostic) {
            $warnings[] = sprintf('%s: %s', $diagnostic['code'], $diagnostic['message']);
        }
        $degraded = array_values(array_unique(array_column($language->workerDiagnostics, 'owner')));
        $data = [
            'files' => $result->files,
            'nodes' => $result->nodes,
            'edges' => $result->edges,
            'diagnostics' => $result->diagnostics,
            'unresolved_nodes' => $result->unresolvedNodes,
            'mode' => $plan->effectiveMode,
            'parsed_files' => $language->parsed,
            'unchanged_files' => $language->unchanged,
            'added_files' => $language->added,
            'changed_files' => $language->changed,
            'deleted_files' => $plan->deletedFiles,
            'scanner_metadata' => $language->scannerMetadata,
            'degraded_languages' => $degraded,
            // Batch bounds are reported per language rather than as one pair of
            // numbers: they differ per language, and the source-byte budget can
            // differ per scan once a worker's output cap forces it down.
            'worker_execution' => $plan->preparation->executionPolicy->metadata() + ['scan_batches' => $language->batchBudgets],
            'configuration' => [
                'source' => $plan->preparation->configuration->path,
                'precedence' => 'explicit override > project configuration > built-in default',
                'framework_hints' => $plan->preparation->configuration->frameworks,
                'policies' => count($plan->preparation->configuration->policies),
                'quality_budgets' => $plan->preparation->configuration->qualityBudgets,
            ],
            'metrics' => [
                'elapsed_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
                'peak_memory_bytes' => memory_get_peak_usage(true),
                'discovered_files' => count($plan->preparation->discovery->files),
                'parsed_files' => $language->parsed,
                'replayed_files' => $language->unchanged,
                'stages_ms' => $stageMilliseconds,
            ],
        ];
        if ($fastPath !== null) {
            $data['fast_path'] = $fastPath;
        }
        return new ResultEnvelope(
            $result->projectId,
            $result->scanId,
            self::summaryLine($result, $degraded),
            $data,
            [],
            $warnings,
        );
    }

    /**
     * The one line every caller sees, and the only place a partial graph can say so.
     *
     * A worker that dies mid-scan degrades its language: the scan still
     * commits, still reports `fresh`, and still counts its nodes, so a graph
     * missing an entire language is indistinguishable at a glance from a
     * complete one — a Vue/TypeScript frontend simply was not there, and only
     * the `degraded_languages` field said so. A caller reading the summary and
     * moving on would answer architecture questions from half a codebase. The
     * detail stays in the field; the fact that it happened belongs here.
     *
     * @param list<string> $degraded
     */
    private static function summaryLine(ReconciliationResult $result, array $degraded): string
    {
        $line = sprintf(
            'Scanned %d files into %d nodes and %d relationships.',
            $result->files,
            $result->nodes,
            $result->edges,
        );
        if ($degraded === []) {
            return $line;
        }

        return $line . sprintf(
            ' PARTIAL GRAPH: %s failed, so nothing it scans is represented. Re-run before trusting this graph.',
            implode(', ', $degraded),
        );
    }
}
