<?php

declare(strict_types=1);

namespace Knossos\Query;

use Knossos\Scan\ProjectPathResolver;
use PDO;

/**
 * The architecture pane's path explorer: the routes by which one component
 * reaches another, as {@see ArchitectureQueryService::explainFlow()} finds
 * them, each hop with its kind and where it is written.
 *
 * When `from` reaches `to` by no route within the bounds, the other way is
 * tried: a person who picked the two in the wrong order still sees how they
 * are joined: `from` and `to` stay as asked, and `reversed` says the
 * routes run from `to` to `from`.
 * Read-only, never scans.
 */
final readonly class PathBetweenService
{
    /** The longest route looked for, in hops. */
    public const DEPTH = 6;

    /** The most routes listed, the strongest first. */
    public const ROUTES = 5;

    /** How long each search may take. */
    private const TIMEOUT_MS = 1500;

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The routes from `$from` to `$to` in the project owning `$path`;
     * `unscanned`, or `not-found` / `ambiguous` with the end that failed.
     *
     * @return array<string, mixed>
     */
    public function routes(string $path, string $from, string $to): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null, 'from' => null, 'to' => null, 'reversed' => false, 'routes' => [], 'truncated' => false];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        $queries = ArchitectureQueryService::forDatabase($this->pdo);
        $flow = $queries->explainFlow($id, $from, $to, self::DEPTH, self::ROUTES, timeoutMs: self::TIMEOUT_MS);
        foreach (['from' => $from, 'to' => $to] as $end => $asked) {
            $candidates = $flow->data[$end]['candidates'] ?? null;
            if (is_array($candidates) && count($candidates) !== 1) {
                return ['status' => $candidates === [] ? 'not-found' : 'ambiguous', 'unresolved' => $end, 'asked' => $asked] + $envelope;
            }
        }
        $reversed = false;
        if ($flow->data['paths'] === []) {
            $back = $queries->explainFlow($id, $to, $from, self::DEPTH, self::ROUTES, timeoutMs: self::TIMEOUT_MS);
            if ($back->data['paths'] !== []) {
                [$flow, $reversed] = [$back, true];
            }
        }
        $ids = [];
        foreach ($flow->data['paths'] as $route) {
            foreach ($route['nodes'] as $node) {
                $ids[] = (string) $node['id'];
            }
        }
        $ids = [...$ids, (string) $flow->data['from']['id'], (string) $flow->data['to']['id']];
        $labels = BoundaryLabels::load($this->pdo, $id)->forNodes($ids);
        $named = static fn(array $node): array => ['name' => (string) $node['display_name'], 'canonical_name' => (string) $node['canonical_name'], 'kind' => (string) $node['kind'], 'boundary' => $labels[(string) $node['id']] ?? null];
        $ends = $reversed ? ['from' => $flow->data['to'], 'to' => $flow->data['from']] : ['from' => $flow->data['from'], 'to' => $flow->data['to']];

        return [
            'status' => 'ok',
            'from' => $named($ends['from']),
            'to' => $named($ends['to']),
            'reversed' => $reversed,
            'routes' => array_map(static fn(array $route): array => [
                'nodes' => array_map($named, $route['nodes']),
                'hops' => array_map(static fn(array $hop): array => [
                    'kind' => (string) $hop['kind'],
                    'confidence' => (string) $hop['confidence'],
                    'path' => $hop['evidence']['path'] ?? null,
                    'line' => isset($hop['evidence']['start_line']) ? (int) $hop['evidence']['start_line'] : null,
                ], $route['hops']),
            ], $flow->data['paths']),
            'truncated' => $flow->truncated,
        ] + $envelope;
    }
}
