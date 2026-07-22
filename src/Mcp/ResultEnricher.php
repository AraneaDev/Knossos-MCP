<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;

final readonly class ResultEnricher
{
    private const COMPACT_EVIDENCE = 3;

    public function __construct(
        private StalenessProbe $probe,
        private NextStepPlanner $planner,
    ) {}

    public function enrich(ResultEnvelope $envelope, string $toolName, string $verbosity, ?int $maxChars = null): ResultEnvelope
    {
        $total = count($envelope->evidence);
        $base = $verbosity === 'compact' ? $this->compact($envelope) : $envelope;
        $shown = count($base->evidence);

        $staleness = $this->probe->probe($envelope->projectId);
        $steps = $this->planner->plan($toolName, $envelope);
        $enriched = $base->with(staleness: $staleness, nextSteps: $steps);

        $dropped = [];
        $met = true;
        $data = $enriched->data;
        if ($maxChars !== null) {
            while (true) {
                $candidateMeta = [
                    'result_bytes' => 99_999_999,
                    'verbosity' => $verbosity,
                    'evidence_total' => $total,
                    'evidence_shown' => $shown,
                ];
                if ($dropped !== []) {
                    $candidateMeta['max_chars'] = $maxChars;
                    $candidateMeta['dropped_items'] = $dropped;
                }
                $candidate = new ResultEnvelope(
                    $enriched->projectId,
                    $enriched->snapshotId,
                    $enriched->summary,
                    $data,
                    $enriched->evidence,
                    $enriched->warnings,
                    $enriched->truncated || $dropped !== [],
                    $enriched->staleness,
                    $enriched->nextSteps,
                    $candidateMeta,
                );
                $size = strlen((string) json_encode($candidate->jsonSerialize(), JSON_UNESCAPED_SLASHES));
                if ($size <= $maxChars) {
                    $met = true;
                    break;
                }
                $victim = self::findVictim($data);
                if ($victim === null) {
                    $met = false;
                    break;
                }
                array_pop($data[$victim]);
                $dropped[$victim] = ($dropped[$victim] ?? 0) + 1;
            }
        }

        $budgetUnmet = $maxChars !== null && !$met;
        $truncated = $enriched->truncated || $dropped !== [];
        $warnings = $enriched->warnings;
        if ($budgetUnmet) {
            $warnings = [...$warnings, 'The max_chars budget could not be fully met by trimming result lists.'];
        }
        $final = new ResultEnvelope(
            $enriched->projectId,
            $enriched->snapshotId,
            $enriched->summary,
            $data,
            $enriched->evidence,
            $warnings,
            $truncated,
            $enriched->staleness,
            $enriched->nextSteps,
        );

        $resultBytes = strlen((string) json_encode($final->jsonSerialize(), JSON_UNESCAPED_SLASHES));
        $meta = [
            'result_bytes' => $resultBytes,
            'verbosity' => $verbosity,
            'evidence_total' => $total,
            'evidence_shown' => $shown,
        ];
        if ($dropped !== [] || $budgetUnmet) {
            $meta['max_chars'] = $maxChars;
        }
        if ($dropped !== []) {
            $meta['dropped_items'] = $dropped;
        }
        return $final->with(meta: $meta);
    }

    /**
     * Select the largest top-level list field to trim one tail item from
     * (alphabetical key on ties), or null if no trimmable list remains.
     *
     * @param array<string, mixed> $data
     */
    private static function findVictim(array $data): ?string
    {
        $victim = null;
        $victimCount = 1;
        foreach ($data as $key => $value) {
            if (!is_array($value) || !array_is_list($value)) {
                continue;
            }
            $count = count($value);
            if ($count > $victimCount || ($count === $victimCount && $victim !== null && strcmp((string) $key, $victim) < 0)) {
                $victim = (string) $key;
                $victimCount = $count;
            }
        }
        return $victim;
    }

    private function compact(ResultEnvelope $envelope): ResultEnvelope
    {
        [$data, $legend] = BoundaryLegend::compress($envelope->data);
        if ($legend !== []) {
            $data['boundary_legend'] = $legend;
        }
        return new ResultEnvelope(
            $envelope->projectId,
            $envelope->snapshotId,
            $envelope->summary,
            $data,
            array_slice($envelope->evidence, 0, self::COMPACT_EVIDENCE),
            $envelope->warnings,
            $envelope->truncated,
            $envelope->staleness,
            $envelope->nextSteps,
            $envelope->meta,
        );
    }
}
