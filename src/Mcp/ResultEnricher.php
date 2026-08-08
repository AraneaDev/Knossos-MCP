<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Closure;
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

    /**
     * @param (Closure(ResultEnvelope): int)|null $measurer Encoded-size measurer.
     *        Injected only so a test can count sizing passes; production always
     *        passes null and uses the default, and the class stays final.
     */
    public function __construct(
        private StalenessProbe $probe,
        private NextStepPlanner $planner,
        private ?Closure $measurer = null,
    ) {}

    /** The encoded size of a candidate envelope, through the injected measurer when one was given. */
    private function measure(ResultEnvelope $candidate): int
    {
        return $this->measurer === null
            ? strlen((string) json_encode($candidate->jsonSerialize(), JSON_UNESCAPED_SLASHES))
            : ($this->measurer)($candidate);
    }
    /** Trim, budget, and annotate a result for an agent rather than a human. */

    public function enrich(ResultEnvelope $envelope, string $toolName, string $verbosity, ?int $maxChars = null): ResultEnvelope
    {
        $total = count($envelope->evidence);
        $base = $verbosity === 'compact' ? $this->compact($envelope) : $envelope;

        $staleness = $this->probe->probe($envelope->projectId);
        $steps = $this->planner->plan($toolName, $envelope);
        $enriched = $base->with(staleness: $staleness, nextSteps: $steps);

        $dropped = [];
        $met = true;
        $data = $enriched->data;
        $evidence = $enriched->evidence;
        if ($maxChars !== null) {
            while (true) {
                $size = $this->measure($this->candidate($enriched, $data, $evidence, $verbosity, $total, $dropped, $maxChars));
                if ($size <= $maxChars) {
                    $met = true;
                    break;
                }
                $victim = self::findVictim($data, $evidence);
                if ($victim === null) {
                    $met = false;
                    break;
                }
                // Drop in proportion to the overage rather than one element per
                // full re-encode. One element per pass made this O(n^2) in
                // dropped items against an O(n) payload, on every tool call —
                // and changed_files_impact routinely arrives at ~70,000
                // characters against a 30,000 budget.
                $label = self::victimLabel($victim);
                $batch = self::batchSize($size, $maxChars, self::victimCount($data, $evidence, $victim));
                for ($i = 0; $i < $batch; ++$i) {
                    if (self::victimCount($data, $evidence, $victim) === 0) {
                        break;
                    }
                    self::popTail($data, $evidence, $victim);
                    $dropped[$label] = ($dropped[$label] ?? 0) + 1;
                }
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

        $meta = [
            'result_bytes' => 0,
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
        // Measured with the meta block attached, so the number a caller reads is
        // the size of what it actually received — it previously excluded itself.
        // Iterated because substituting the value changes the length it
        // describes; two passes settle it for any payload, and the loop is
        // bounded so a pathological oscillation cannot spin.
        for ($pass = 0; $pass < 4; ++$pass) {
            $measured = $this->measure($final->with(meta: $meta));
            if ($measured === $meta['result_bytes']) {
                break;
            }
            $meta['result_bytes'] = $measured;
        }

        return $final->with(meta: $meta);
    }

    /**
     * The envelope as it would be returned right now, sized to decide whether
     * more trimming is needed. `result_bytes` is padded rather than measured:
     * measuring it would require encoding the very thing being measured, and the
     * padding is wider than any value the finished envelope can report, so the
     * candidate is never smaller than what is finally returned.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @param array<string, int> $dropped
     */
    private function candidate(ResultEnvelope $enriched, array $data, array $evidence, string $verbosity, int $total, array $dropped, int $maxChars): ResultEnvelope
    {
        $meta = [
            'result_bytes' => 99_999_999,
            'verbosity' => $verbosity,
            'evidence_total' => $total,
            'evidence_shown' => count($evidence),
        ];
        if ($dropped !== []) {
            $meta['max_chars'] = $maxChars;
            $meta['dropped_items'] = $dropped;
        }

        return new ResultEnvelope(
            $enriched->projectId,
            $enriched->snapshotId,
            $enriched->summary,
            $data,
            $evidence,
            $enriched->warnings,
            $enriched->truncated || $dropped !== [],
            $enriched->staleness,
            $enriched->nextSteps,
            $meta,
        );
    }

    /**
     * How many tail items to drop this pass: enough to cover the overage at the
     * collection's average item cost, capped at half the collection so a large
     * estimate cannot empty a list the next pass would have kept.
     *
     * The per-item cost is derived from the *whole* envelope rather than the
     * collection alone, so it can only overstate what one item is worth, which
     * makes the batch an under-estimate of the items actually needed. Dropping
     * can therefore never overshoot past the exact-fit check at the top of the
     * loop — it only reaches the fit in fewer passes.
     */
    private static function batchSize(int $size, int $maxChars, int $available): int
    {
        if ($available <= 1) {
            return 1;
        }
        $overage = $size - $maxChars;
        $perItem = max(1, intdiv($size, max(1, $available)));

        return max(1, min(intdiv($available, 2), (int) ceil($overage / $perItem)));
    }

    /**
     * The number of items currently in the collection at $path, so a batch never
     * over-drops.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @param list<string> $path
     */
    private static function victimCount(array $data, array $evidence, array $path): int
    {
        $cursor = $path[0] === self::EVIDENCE_KEY ? $evidence : $data;
        foreach (array_slice($path, $path[0] === self::EVIDENCE_KEY ? 1 : 0) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return 0;
            }
            $cursor = $cursor[$segment];
        }

        return is_array($cursor) ? count($cursor) : 0;
    }

    /**
     * Select the next collection to trim one tail item from, or null if none
     * remains.
     *
     * Decoration is spent before findings. Legends and evidence only annotate
     * the payload, and a legend is routinely the longest thing in a result, so
     * selecting purely by length emptied the payload while every legend entry
     * survived — dependency_cycles reported "Found 16 dependency cycle
     * components" over `cycles: []`. Supporting material is therefore exhausted
     * first, and only then does the payload pay.
     *
     * Within a tier the largest collection is chosen (alphabetical dotted path
     * on ties). The evidence list is folded into the walk under a reserved key
     * so it can be trimmed too, and the walk descends into maps and list
     * elements alike, so nested payloads such as review_diff's
     * change.direct_components are trimmable. A single-element list is a valid
     * victim, so a payload dominated by one one-item list can still be trimmed.
     *
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $evidence
     * @return list<string>|null
     */
    private static function findVictim(array $data, array $evidence): ?array
    {
        $root = $data;
        $root[self::EVIDENCE_KEY] = $evidence;
        foreach ([true, false] as $supporting) {
            $victim = self::largestIn($root, $supporting);
            if ($victim !== null) {
                return $victim;
            }
        }

        return null;
    }

    /**
     * The largest trimmable collection in one tier, or null when that tier is
     * already exhausted.
     *
     * @param array<string, mixed> $root
     * @return list<string>|null
     */
    private static function largestIn(array $root, bool $supporting): ?array
    {
        $victim = null;
        $victimCount = 0;
        $walk = function (array $container, array $path, bool $inSupporting) use (&$walk, &$victim, &$victimCount, $supporting): void {
            foreach ($container as $key => $value) {
                if (!is_array($value)) {
                    continue;
                }
                $keyPath = [...$path, (string) $key];
                $valueSupporting = $inSupporting || self::isSupportingKey((string) $key);
                // A legend is a map keyed by component name rather than a list, so
                // it is trimmable in its own right; other maps are not, because
                // popping a named field would change the result's shape. A
                // non-list array is necessarily non-empty, since [] is a list.
                $trimmable = array_is_list($value)
                    ? $value !== []
                    : self::isSupportingKey((string) $key);
                if ($trimmable && $valueSupporting === $supporting) {
                    $count = count($value);
                    if ($count > $victimCount || ($count === $victimCount && $victim !== null && strcmp(implode('.', $keyPath), implode('.', $victim)) < 0)) {
                        $victim = $keyPath;
                        $victimCount = $count;
                    }
                }
                $walk($value, $keyPath, $valueSupporting);
            }
        };
        $walk($root, [], false);

        return $victim;
    }

    /** Whether a key names material that only annotates the payload. */
    private static function isSupportingKey(string $key): bool
    {
        return $key === self::EVIDENCE_KEY || str_ends_with($key, '_legend');
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
