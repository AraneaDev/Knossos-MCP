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
                self::popTail($data, $victim);
                $label = implode('.', $victim);
                $dropped[$label] = ($dropped[$label] ?? 0) + 1;
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
     * Select the largest list field to trim one tail item from (alphabetical
     * dotted path on ties), or null if no trimmable list remains. The walk
     * descends into maps and list elements alike, so nested payloads such as
     * review_diff's change.direct_components and impact_analysis's
     * by_distance.0.dependants are trimmable too.
     *
     * @param array<string, mixed> $data
     * @return list<string>|null
     */
    private static function findVictim(array $data): ?array
    {
        $victim = null;
        $victimCount = 1;
        $walk = function (array $container, array $path) use (&$walk, &$victim, &$victimCount): void {
            foreach ($container as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $keyPath = [...$path, (string) $key];
                if (array_is_list($value)) {
                    $count = count($value);
                    if ($count > $victimCount || ($count === $victimCount && $victim !== null && strcmp(implode('.', $keyPath), implode('.', $victim)) < 0)) {
                        $victim = $keyPath;
                        $victimCount = $count;
                    }
                }
                $walk($value, $keyPath);
            }
        };
        $walk($data, []);
        return $victim;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     */
    private static function popTail(array &$data, array $path): void
    {
        $ref = &$data;
        foreach (array_slice($path, 0, -1) as $segment) {
            $ref = &$ref[$segment];
        }
        array_pop($ref[$path[count($path) - 1]]);
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
