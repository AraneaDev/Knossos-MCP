<?php

declare(strict_types=1);

namespace Knossos\Mcp;

use InvalidArgumentException;
use Knossos\Query\ArchitectureQueryService;

/**
 * MCP resources: per-project orientation documents a client can pin into a
 * session without a tool round-trip. Read-only over the same query facade the
 * tools use; unknown or unscanned URIs read as null (protocol error -32002).
 */
final readonly class ResourceService
{
    private const URI_PATTERN = '#^knossos://(project_[a-f0-9]{64})/(summary|boundaries|brief)$#';

    public function __construct(private ArchitectureQueryService $queries) {}

    /** @return list<array<string, mixed>> */
    public function list(): array
    {
        $projects = $this->queries->listProjects(100)->data['projects'] ?? [];
        $resources = [];
        foreach ($projects as $project) {
            $id = $project['id'];
            $name = $project['name'];
            $resources[] = [
                'uri' => sprintf('knossos://%s/summary', $id),
                'name' => $name . ' architecture summary',
                'mimeType' => 'application/json',
                'description' => sprintf('Node, relationship, role, and language overview of %s.', $name),
            ];
            $resources[] = [
                'uri' => sprintf('knossos://%s/boundaries', $id),
                'name' => $name . ' boundaries',
                'mimeType' => 'application/json',
                'description' => sprintf('How %s is partitioned into boundaries.', $name),
            ];
            $resources[] = [
                'uri' => sprintf('knossos://%s/brief', $id),
                'name' => $name . ' agent brief',
                'mimeType' => 'text/markdown',
                'description' => sprintf('Markdown orientation brief for agents working on %s.', $name),
            ];
        }
        return $resources;
    }

    /** @return array<string, mixed>|null null when the URI is unknown or the project is unscanned */
    public function read(string $uri): ?array
    {
        if (preg_match(self::URI_PATTERN, $uri, $matches) !== 1) {
            return null;
        }
        [, $projectId, $section] = $matches;
        try {
            [$mimeType, $text] = match ($section) {
                'summary' => ['application/json', $this->json($this->queries->architectureSummary($projectId)->jsonSerialize())],
                'boundaries' => ['application/json', $this->json($this->queries->listBoundaries($projectId)->jsonSerialize())],
                'brief' => ['text/markdown', (string) $this->queries->exportAgentBrief($projectId)->data['markdown']],
            };
        } catch (InvalidArgumentException) {
            return null;
        }
        return ['contents' => [['uri' => $uri, 'mimeType' => $mimeType, 'text' => $text]]];
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
