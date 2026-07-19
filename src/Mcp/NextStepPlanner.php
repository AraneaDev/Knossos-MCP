<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use Knossos\Query\ResultEnvelope;

/**
 * Pure mapper from a completed tool result to at most three follow-up suggestions.
 *
 * The `data` key names below are the ones the corresponding query services actually
 * emit (see ComponentQueryService::findComponent/inspectComponent and
 * GraphTopologyQueryService::impactAnalysis/architectureHealth). Synthetic shapes used
 * by unit tests (e.g. a bare `candidates`/`name` pair) are tolerated as well so the
 * planner stays robust to both.
 */
final readonly class NextStepPlanner
{
    /** @return list<array{tool: string, args: array<string, mixed>, why: string}> */
    public function plan(string $toolName, ResultEnvelope $envelope): array
    {
        $data = $envelope->data;
        $steps = match ($toolName) {
            'find_component' => $this->afterFind($data),
            'inspect_component' => $this->afterInspect($data),
            'impact_analysis' => $this->afterImpact($data),
            'architecture_health' => $this->afterHealth($data),
            default => [],
        };
        return array_slice($steps, 0, 3);
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{tool: string, args: array<string, mixed>, why: string}>
     */
    private function afterFind(array $data): array
    {
        // Real query emits `components`; unit tests use `candidates`.
        $candidates = is_array($data['components'] ?? null) ? $data['components'] : null;
        $candidates ??= is_array($data['candidates'] ?? null) ? $data['candidates'] : [];
        if (count($candidates) < 2) {
            return [];
        }
        $name = $this->nameOf($candidates[array_key_first($candidates)]);
        if ($name === null) {
            return [];
        }
        return [[
            'tool' => 'inspect_component',
            'args' => ['component' => $name],
            'why' => 'multiple matches; inspect the top candidate',
        ]];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{tool: string, args: array<string, mixed>, why: string}>
     */
    private function afterInspect(array $data): array
    {
        // Real query nests the dossier under `component` (an array); tests pass a bare string.
        $name = $this->nameOf($data['component'] ?? null);
        if ($name === null) {
            return [];
        }
        $steps = [[
            'tool' => 'impact_analysis',
            'args' => ['symbol' => $name],
            'why' => 'see what depends on this before changing it',
        ]];
        if ($this->isHub($data)) {
            $steps[] = [
                'tool' => 'dependency_cycles',
                'args' => [],
                'why' => 'this is a hub; check for dependency cycles',
            ];
        }
        return $steps;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{tool: string, args: array<string, mixed>, why: string}>
     */
    private function afterImpact(array $data): array
    {
        // Real query emits `target` + `direct_dependants`; tests use `symbol` + `impacted`.
        $symbol = $this->nameOf($data['target'] ?? ($data['symbol'] ?? null));
        $impacted = is_array($data['direct_dependants'] ?? null) ? $data['direct_dependants'] : null;
        $impacted ??= is_array($data['impacted'] ?? null) ? $data['impacted'] : [];
        if ($symbol === null || $impacted === []) {
            return [];
        }
        $to = $this->nameOf($impacted[array_key_first($impacted)]);
        if ($to === null) {
            return [];
        }
        return [[
            'tool' => 'explain_flow',
            'args' => ['from' => $symbol, 'to' => $to],
            'why' => 'trace how the change reaches an affected component',
        ]];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<array{tool: string, args: array<string, mixed>, why: string}>
     */
    private function afterHealth(array $data): array
    {
        // Real query emits `static_hotspots`; tests use `hotspots`.
        $hotspots = is_array($data['static_hotspots'] ?? null) ? $data['static_hotspots'] : null;
        $hotspots ??= is_array($data['hotspots'] ?? null) ? $data['hotspots'] : [];
        if ($hotspots === []) {
            return [];
        }
        $name = $this->nameOf($hotspots[array_key_first($hotspots)]);
        if ($name === null) {
            return [];
        }
        return [[
            'tool' => 'inspect_component',
            'args' => ['component' => $name],
            'why' => 'inspect the top structural hotspot',
        ]];
    }

    /** @param array<string, mixed> $data */
    private function isHub(array $data): bool
    {
        if (($data['is_hub'] ?? false) === true) {
            return true;
        }
        $component = $data['component'] ?? null;
        return is_array($component) && ($component['is_hub'] ?? false) === true;
    }

    /**
     * Extract a component name from the many shapes the queries return: a bare string, a
     * node array (`canonical_name`/`display_name`/`name`), or a wrapper carrying the node
     * under `component` or `node`.
     */
    private function nameOf(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value === '' ? null : $value;
        }
        if (!is_array($value)) {
            return null;
        }
        foreach (['canonical_name', 'name', 'display_name'] as $key) {
            if (isset($value[$key]) && is_string($value[$key]) && $value[$key] !== '') {
                return $value[$key];
            }
        }
        foreach (['component', 'node'] as $key) {
            if (isset($value[$key])) {
                $nested = $this->nameOf($value[$key]);
                if ($nested !== null) {
                    return $nested;
                }
            }
        }
        return null;
    }
}
