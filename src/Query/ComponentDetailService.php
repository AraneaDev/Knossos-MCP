<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Scan\ProjectPathResolver;
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
 *
 * Each direction lists its first few counterparts with how many of the read
 * relationships run to each and the boundary each is labelled with (the
 * dashboard's label, {@see BoundaryLabels}), the most connected first. When
 * the lookup stopped reading, `truncated` says the counts are floors. The
 * component's own annotations come along; there are at most four, one per
 * annotation kind.
 */
final readonly class ComponentDetailService
{
    /** Relationships read per direction: the most the inspect lookup allows. */
    private const RELATIONSHIPS = 100;

    /** Names listed per direction; the count covers every distinct one read. */
    private const NAMED = 5;

    /** Counterparts listed per direction with their edge counts. */
    private const LISTED = 8;

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
        $result = ArchitectureQueryService::forDatabase($this->pdo)->inspectComponent($id, $name, self::RELATIONSHIPS, 1);
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
        $labels = BoundaryLabels::load($this->pdo, $id);
        $others = $labels->forNodes(array_map(
            static fn(array $edge): string => (string) $edge['component']['id'],
            [...$component['incoming'], ...$component['outgoing']],
        ));
        $envelope['status'] = 'ok';
        $envelope['component'] = [
            'name' => (string) $component['canonical_name'],
            'display_name' => (string) $component['display_name'],
            'kind' => (string) $component['kind'],
            'path' => $place === null ? null : (string) $place['path'],
            'line' => isset($place['start_line']) ? (int) $place['start_line'] : null,
            'boundary' => $labels->of($component['boundaries']),
            'boundaries' => array_map(static fn(array $b): string => (string) $b['name'], $component['boundaries']),
            'used_by' => self::related($component['incoming'], in_array('incoming_relationship_limit', $reasons, true), $others),
            'uses' => self::related($component['outgoing'], in_array('outgoing_relationship_limit', $reasons, true), $others),
            'annotations' => array_map(
                static fn(array $a): array => ['kind' => (string) $a['kind'], 'value' => (string) $a['value']],
                $component['annotations'],
            ),
        ];
        return $envelope;
    }

    /**
     * One direction of a component's relationships: `count` the distinct
     * components, `names` the first few distinct short names (two methods
     * called `run` read alike); `items` the most connected few
     * with their edge counts and boundary; `truncated` when the lookup stopped
     * reading, so the counts are floors.
     *
     * @param list<array<string, mixed>> $edges
     * @param array<string, string> $labels each counterpart's boundary label, by node id
     * @return array{count: int, truncated: bool, names: list<string>, items: list<array<string, mixed>>}
     */
    private static function related(array $edges, bool $truncated, array $labels): array
    {
        $names = [];
        $items = [];
        foreach ($edges as $edge) {
            $other = $edge['component'];
            $name = (string) ($other['display_name'] ?? $other['canonical_name']);
            $names[] = $name;
            $key = (string) $other['id'];
            $items[$key] ??= [
                'name' => $name,
                'canonical_name' => (string) $other['canonical_name'],
                'kind' => (string) $other['kind'],
                'boundary' => $labels[$key] ?? null,
                'edges' => 0,
            ];
            ++$items[$key]['edges'];
        }
        $names = array_values(array_unique($names));
        $count = count($items);
        $items = array_values($items);
        usort($items, static fn(array $a, array $b): int => [$b['edges'], $a['canonical_name']] <=> [$a['edges'], $b['canonical_name']]);
        return [
            'count' => $count,
            'truncated' => $truncated,
            'names' => array_slice($names, 0, self::NAMED),
            'items' => array_slice($items, 0, self::LISTED),
        ];
    }
}
