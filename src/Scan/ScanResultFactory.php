<?php

declare(strict_types=1);

namespace Knossos\Scan;

use Knossos\Query\ResultEnvelope;
use Knossos\Reconciliation\ReconciliationResult;

final readonly class ScanResultFactory
{
    /** @param array<string, float> $stageMilliseconds */
    public function create(
        ScanPlan $plan,
        LanguageScanResult $language,
        ReconciliationResult $result,
        int $startedAt,
        array $stageMilliseconds,
    ): ResultEnvelope {
        $warnings = array_map(
            static fn($diagnostic): string => sprintf('%s: %s', $diagnostic->code, $diagnostic->message),
            $plan->preparation->discovery->diagnostics,
        );
        return new ResultEnvelope(
            $result->projectId,
            $result->scanId,
            sprintf('Scanned %d files into %d nodes and %d relationships.', $result->files, $result->nodes, $result->edges),
            [
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
                'worker_execution' => $plan->preparation->executionPolicy->metadata(),
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
            ],
            [],
            $warnings,
        );
    }
}
