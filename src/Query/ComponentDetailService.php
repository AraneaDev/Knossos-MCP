<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * One component as the architecture pane shows it, addressed like the
 * dashboard: by a path inside the project, plus the component's name.
 *
 * Read-only, and resolved through {@see ProjectPathResolver} exactly as
 * {@see DashboardService} resolves its project, so the pane's detail and its
 * overview always come from the same graph. The lookup is the one
 * `inspect-component` makes; this service only reshapes its envelope into
 * what the pane draws, with a status for every way there can be nothing:
 * `unscanned` (no project with an active scan contains the path),
 * `not-found` and `ambiguous` (with up to ten candidate names).
 */
final readonly class ComponentDetailService
{
    /** Relationships read per direction: the most the inspect lookup allows. */
    private const RELATIONSHIPS = 100;

    /** Names listed per direction; the count covers every distinct one read. */
    private const NAMED = 5;

    /** Candidate names listed for an ambiguous name. */
    private const CANDIDATES = 10;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The detail of `$name` in the project that owns `$path`.
     *
     * @return array<string, mixed>
     */
    public function detail(string $path, string $name): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = [
            'status' => 'unscanned', 'path' => $absolute, 'name' => $name, 'project_id' => null,
            'snapshot_id' => null, 'component' => null, 'candidates' => [],
        ];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        // A project row without an active scan has no graph to look in.
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return $envelope;
        }
        $id = (string) $project['id'];
        $result = (new ArchitectureQueryService($this->pdo))->inspectComponent($id, $name, self::RELATIONSHIPS, 1);
        $envelope['project_id'] = $id;
        $envelope['snapshot_id'] = $project['active_scan_id'];
        $data = $result->data;
        if ($data['component'] === null) {
            $envelope['status'] = $data['ambiguous'] ? 'ambiguous' : 'not-found';
            $envelope['candidates'] = array_map(
                static fn(array $candidate): string => (string) $candidate['canonical_name'],
                array_slice($data['candidates'], 0, self::CANDIDATES),
            );
            return $envelope;
        }
        $component = $data['component'];
        $reasons = $data['limits']['truncation_reasons'];
        $place = $result->evidence[0] ?? null;
        $envelope['status'] = 'ok';
        $envelope['component'] = [
            'name' => (string) $component['canonical_name'],
            'kind' => (string) $component['kind'],
            'path' => $place === null ? null : (string) $place['path'],
            'line' => isset($place['start_line']) ? (int) $place['start_line'] : null,
            'boundaries' => array_map(static fn(array $b): string => (string) $b['name'], $component['boundaries']),
            'used_by' => self::related($component['incoming'], in_array('incoming_relationship_limit', $reasons, true)),
            'uses' => self::related($component['outgoing'], in_array('outgoing_relationship_limit', $reasons, true)),
        ];
        return $envelope;
    }

    /**
     * One direction of a component's relationships: each other component once,
     * by its short name, the first few listed; `truncated` when the lookup
     * stopped reading, so the count is a floor.
     *
     * @param list<array<string, mixed>> $edges
     * @return array{count: int, truncated: bool, names: list<string>}
     */
    private static function related(array $edges, bool $truncated): array
    {
        $names = [];
        foreach ($edges as $edge) {
            $other = $edge['component'];
            $names[] = (string) ($other['display_name'] ?? $other['canonical_name']);
        }
        $names = array_values(array_unique($names));
        return ['count' => count($names), 'truncated' => $truncated, 'names' => array_slice($names, 0, self::NAMED)];
    }
}
