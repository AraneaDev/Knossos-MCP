<?php

declare(strict_types=1);

namespace Knossos\Mcp;

/**
 * MCP prompts: canned instructions that drive the existing tools through the
 * workflows agents otherwise re-derive each session. Pure data, no state.
 */
final readonly class PromptService
{
    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        return [
            [
                'name' => 'orient',
                'title' => 'Orient in a scanned project',
                'description' => 'Scan (or refresh) a project, then survey its architecture before coding.',
                'arguments' => [
                    ['name' => 'path', 'description' => 'Absolute project path under an allowed root; omit to reuse an already-scanned project.', 'required' => false],
                ],
            ],
            [
                'name' => 'review_diff',
                'title' => 'Review a change set architecturally',
                'description' => 'Map changed files to impacted components, boundary violations, and budget breaches.',
                'arguments' => [
                    ['name' => 'base_ref', 'description' => 'Git ref to diff against; omit to review uncommitted working-tree changes.', 'required' => false],
                ],
            ],
        ];
    }

    /**
     * @param array<string, string> $arguments
     * @return array<string, mixed>|null
     */
    public function get(string $name, array $arguments): ?array
    {
        return match ($name) {
            'orient' => [
                'description' => 'Architecture orientation workflow',
                'messages' => [self::user(self::orientText($arguments['path'] ?? null))],
            ],
            'review_diff' => [
                'description' => 'Architectural change review workflow',
                'messages' => [self::user(self::reviewDiffText($arguments['base_ref'] ?? null))],
            ],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    private static function user(string $text): array
    {
        return ['role' => 'user', 'content' => ['type' => 'text', 'text' => $text]];
    }

    private static function orientText(?string $path): string
    {
        $scanLine = $path === null
            ? 'If no project is scanned yet, call list_projects to find the project_id; call scan_project if the graph is missing or stale.'
            : sprintf('Call scan_project with path "%s" (it is incremental and cheap when nothing changed), and use the returned project_id below.', $path);
        return <<<TEXT
            Orient yourself in this codebase using the Knossos architecture graph instead of reading the source tree.

            1. {$scanLine}
            2. Call architecture_summary for the component/relationship/language overview.
            3. Call list_boundaries to learn how the codebase is partitioned.
            4. Call architecture_health (defaults exclude test helpers and external symbols) for the key hubs and risk spots.

            Then report: the major modules and boundaries, the main entry points, the most depended-on components, and anything architecture_health flags. Cite the evidence paths the tools return.
            TEXT;
    }

    private static function reviewDiffText(?string $baseRef): string
    {
        $target = $baseRef === null
            ? 'the uncommitted working tree (omit base_ref)'
            : sprintf('the changes since "%s" (pass base_ref: "%s")', $baseRef, $baseRef);
        return <<<TEXT
            Review the architectural blast radius of {$target} using the Knossos graph.

            1. Resolve the project_id via list_projects (scan_project first if the graph is stale, or pass refresh_if_stale: true).
            2. Call review_diff — one call returns the impacted components, boundary-policy violations touching the change, the quality-gate delta, and any dependency cycles the change participates in. Policies and budgets default to the project's knossos.json.

            Then report: what the change can break (with the evidence edges), any policy violations, and any budget regressions. Impact is a conservative static blast radius — say so when reporting.
            TEXT;
    }
}
