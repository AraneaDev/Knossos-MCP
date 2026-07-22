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
        if ($maxChars !== null) {
            $skeleton = new ResultEnvelope(
                $enriched->projectId,
                $enriched->snapshotId,
                $enriched->summary,
                [],
                $enriched->evidence,
                $enriched->warnings,
                $enriched->truncated,
                $enriched->staleness,
                $enriched->nextSteps,
            );
            $overhead = strlen((string) json_encode($skeleton->jsonSerialize(), JSON_UNESCAPED_SLASHES)) + 120;
            [$data, $dropped, $met] = self::shrinkData($enriched->data, $maxChars, $overhead);
            if ($dropped !== []) {
                $enriched = new ResultEnvelope(
                    $enriched->projectId,
                    $enriched->snapshotId,
                    $enriched->summary,
                    $data,
                    $enriched->evidence,
                    $met ? $enriched->warnings : [...$enriched->warnings, 'The max_chars budget could not be fully met by trimming result lists.'],
                    true,
                    $enriched->staleness,
                    $enriched->nextSteps,
                );
            }
        }

        $resultBytes = strlen((string) json_encode($enriched->jsonSerialize(), JSON_UNESCAPED_SLASHES));
        $meta = [
            'result_bytes' => $resultBytes,
            'verbosity' => $verbosity,
            'evidence_total' => $total,
            'evidence_shown' => $shown,
        ];
        if ($dropped !== []) {
            $meta['max_chars'] = $maxChars;
            $meta['dropped_items'] = $dropped;
        }
        return $enriched->with(meta: $meta);
    }

    /**
     * Trim top-level list fields tail-first (largest list first, alphabetical
     * key on ties) until the serialized envelope fits the byte budget. The 120
     * bytes of headroom in the caller's overhead cover the meta block itself.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: array<string, int>, 2: bool}
     */
    private static function shrinkData(array $data, int $budget, int $overhead): array
    {
        $dropped = [];
        while (true) {
            $size = strlen((string) json_encode($data, JSON_UNESCAPED_SLASHES)) + $overhead;
            if ($size <= $budget) {
                return [$data, $dropped, true];
            }
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
            if ($victim === null) {
                return [$data, $dropped, false];
            }
            array_pop($data[$victim]);
            $dropped[$victim] = ($dropped[$victim] ?? 0) + 1;
        }
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
