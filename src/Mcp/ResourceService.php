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

    /** Prefix of the decoded cursor; what follows is the JSON pair [created_at, id] of the last listed project. */
    private const CURSOR_PREFIX = 'after:';

    private const URI_PATTERN = '#^knossos://(project_[a-f0-9]{64})/(summary|boundaries|brief)$#';

    public function __construct(private ArchitectureQueryService $queries) {}

    /**
     * One page of resources, PAGE_SIZE projects at a time in creation order,
     * with the cursor of the next page when there is one.
     *
     * The cursor names the last project listed (its created_at and id), not an
     * offset, and the order is one a scan never changes, so a project rescanned,
     * added or removed between pages neither repeats nor pushes another out.
     *
     * @return array{resources: list<array<string, mixed>>, nextCursor?: string}
     * @throws InvalidArgumentException when the cursor is not one this server issued
     */
    public function list(?string $cursor = null): array
    {
        [$afterCreatedAt, $afterId] = $cursor === null ? [null, null] : self::position($cursor);
        $listing = $this->queries->projectsInCreationOrder(self::PAGE_SIZE, $afterCreatedAt, $afterId);
        $page = ['resources' => $this->resources($listing['projects'])];
        $last = $listing['projects'] === [] ? null : $listing['projects'][array_key_last($listing['projects'])];
        if ($listing['more'] && $last !== null) {
            $payload = self::CURSOR_PREFIX . json_encode([$last['created_at'], $last['id']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $page['nextCursor'] = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
        }

        return $page;
    }

    /**
     * The keyset position an opaque cursor encodes, refusing anything this server would not have issued.
     *
     * @return array{string, string} [created_at, id] of the last project on the previous page
     */
    private static function position(string $cursor): array
    {
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $pair = is_string($decoded) && str_starts_with($decoded, self::CURSOR_PREFIX)
            ? json_decode(substr($decoded, strlen(self::CURSOR_PREFIX)), true)
            : null;
        if (
            !is_array($pair) || !array_is_list($pair) || count($pair) !== 2
            || !is_string($pair[0]) || !is_string($pair[1]) || $pair[0] === '' || $pair[1] === ''
        ) {
            throw new InvalidArgumentException('Invalid cursor.');
        }

        return [$pair[0], $pair[1]];
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
