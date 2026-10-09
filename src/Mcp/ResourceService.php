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
    /** Projects per resources/list page; each contributes three resources. */
    public const PAGE_SIZE = 100;

    /** The largest offset the project catalog accepts. */
    private const MAX_OFFSET = 100_000;

    private const URI_PATTERN = '#^knossos://(project_[a-f0-9]{64})/(summary|boundaries|brief)$#';

    public function __construct(private ArchitectureQueryService $queries) {}

    /**
     * One page of resources, PAGE_SIZE projects at a time, with the cursor of the next page when there is one.
     *
     * @return array{resources: list<array<string, mixed>>, nextCursor?: string}
     * @throws InvalidArgumentException when the cursor is not one this server issued
     */
    public function list(?string $cursor = null): array
    {
        $listing = $this->queries->listProjects(self::PAGE_SIZE, $cursor === null ? 0 : self::offset($cursor))->data;
        $page = ['resources' => $this->resources($listing['projects'] ?? [])];
        $next = $listing['pagination']['next_offset'] ?? null;
        if (is_int($next)) {
            $page['nextCursor'] = rtrim(strtr(base64_encode('offset:' . $next), '+/', '-_'), '=');
        }

        return $page;
    }

    /** The offset an opaque cursor encodes, refusing anything this server would not have issued. */
    private static function offset(string $cursor): int
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (!is_string($decoded) || preg_match('/^offset:(0|[1-9][0-9]{0,5})$/', $decoded, $matches) !== 1 || (int) $matches[1] > self::MAX_OFFSET) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return (int) $matches[1];
    }

    /**
     * The three resources of each listed project.
     *
     * @param list<array<string, mixed>> $projects
     * @return list<array<string, mixed>>
     */
    private function resources(array $projects): array
    {
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

    /**
     * One resource's contents, or null when the URI is unknown or the project unscanned.
     *
     * @return array<string, mixed>|null null when the URI is unknown or the project is unscanned
     */
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

    /**
     * Encode a resource payload for the wire.
     *
     * @param array<string, mixed> $value
     */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
