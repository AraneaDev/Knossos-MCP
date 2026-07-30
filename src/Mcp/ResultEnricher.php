<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\ResultEnvelope;
use Knossos\Query\StalenessProbe;

/**
 * Prepares a query result for an agent rather than a human.
 *
 * Three jobs, all about the caller's context budget and next move: trim evidence
 * to a preview unless full verbosity was asked for, enforce the byte budget by
 * dropping list tails and *reporting* what was dropped, and attach staleness plus
 * suggested next steps so a caller can tell an incomplete answer from a complete
 * one. Silent truncation is the failure mode this exists to prevent.
 */
final readonly class ResultEnricher
{
    private const COMPACT_EVIDENCE = 3;
    /** Reserved key under which the evidence list joins the victim walk; NUL keeps it clear of real data keys. */
    private const EVIDENCE_KEY = "\0evidence";

    public function __construct(
        private StalenessProbe $probe,
        private NextStepPlanner $planner,
    ) {}
    /** Trim, budget, and annotate a result for an agent rather than a human. */

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
        $evidence = $enriched->evidence;
        if ($maxChars !== null) {
            while (true) {
                $candidateMeta = [
                    'result_bytes' => 99_999_999,
                    'verbosity' => $verbosity,
                    'evidence_total' => $total,
                    'evidence_shown' => count($evidence),
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
                    $evidence,
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
                $victim = self::findVictim($data, $evidence);
                if ($victim === null) {
                    $met = false;
                    break;
                }
                self::popTail($data, $evidence, $victim);
                $label = self::victimLabel($victim);
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
            $evidence,
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
            'evidence_shown' => count($evidence),
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
     * Select the largest non-empty list to trim one tail item from (alphabetical
     * dotted path on ties), or null if no trimmable list remains. The evidence
     * list is folded into the walk under a reserved key so it can be trimmed
     * too. The walk descends into maps and list elements alike, so nested
     * payloads such as review_diff's change.direct_components and
     * impact_analysis's dependants are trimmable too. A
     * single-element list is a valid victim, so a payload dominated by one
     * one-item list can still be trimmed toward the budget.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @return list<string>|null
     */
    private static function findVictim(array $data, array $evidence): ?array
    {
        $root = $data;
        $root[self::EVIDENCE_KEY] = $evidence;
        $victim = null;
        $victimCount = 0;
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
        $walk($root, []);
        return $victim;
    }

    /**
     * Pop the tail item off the list at $path, which addresses either $data or
     * (when it starts with the reserved evidence key) $evidence.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @param list<string> $path
     */
    private static function popTail(array &$data, array &$evidence, array $path): void
    {
        if ($path[0] === self::EVIDENCE_KEY) {
            $ref = &$evidence;
            $path = array_slice($path, 1);
            if ($path === []) {
                array_pop($evidence);
                return;
            }
        } else {
            $ref = &$data;
        }
        foreach (array_slice($path, 0, -1) as $segment) {
            $ref = &$ref[$segment];
        }
        array_pop($ref[$path[count($path) - 1]]);
    }

    /**
     * Human-readable dotted label for a victim path, presenting the reserved
     * evidence key as "evidence" in meta.dropped_items.
     *
     * @param list<string> $path
     */
    private static function victimLabel(array $path): string
    {
        if ($path[0] === self::EVIDENCE_KEY) {
            $path[0] = 'evidence';
        }
        return implode('.', $path);
    }
    /** Trim evidence to a preview, which is the default because full evidence rarely fits a context budget. */

    private function compact(ResultEnvelope $envelope): ResultEnvelope
    {
        [$data, $legend] = BoundaryLegend::compress($envelope->data);
        if ($legend !== []) {
            $data['boundary_legend'] = $legend;
        }
        [$data, $components, $idToName] = ComponentLegend::compressWithIndex($data);
        if ($components !== []) {
            $data['component_legend'] = $components;
        }
        $evidence = array_slice($envelope->evidence, 0, self::COMPACT_EVIDENCE);
        if ($idToName !== []) {
            $evidence = self::rewriteEvidenceIds($evidence, $idToName);
        }
        return new ResultEnvelope(
            $envelope->projectId,
            $envelope->snapshotId,
            $envelope->summary,
            $data,
            $evidence,
            $envelope->warnings,
            $envelope->truncated,
            $envelope->staleness,
            $envelope->nextSteps,
            $envelope->meta,
        );
    }

    /**
     * A node hoisted into component_legend leaves its id-keyed references
     * (e.g. impact_analysis's dependant_id, find_component's component_id)
     * dangling: the id no longer appears anywhere in $data for an agent to
     * join back to a name. Rewrite any `*_id` evidence value that names a
     * hoisted node into the canonical-name string under a de-`_id`'d key
     * (dependant_id -> dependant). Keys whose value is not a hoisted node
     * (e.g. boundary_id, edge_id) are left untouched.
     *
     * @param list<array<string, mixed>> $evidence
     * @param array<string, string> $idToName
     * @return list<array<string, mixed>>
     */
    private static function rewriteEvidenceIds(array $evidence, array $idToName): array
    {
        return array_map(static function (array $entry) use ($idToName): array {
            $rewritten = [];
            foreach ($entry as $key => $value) {
                if (is_string($key) && str_ends_with($key, '_id') && is_string($value) && isset($idToName[$value])) {
                    $rewritten[substr($key, 0, -3)] = $idToName[$value];
                    continue;
                }
                $rewritten[$key] = $value;
            }
            return $rewritten;
        }, $evidence);
    }
}
