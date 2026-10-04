<?php

declare(strict_types=1);

namespace Knossos\Query;

use InvalidArgumentException;
use PDO;

/**
 * The architecture pane's reads that look past one component's detail, and
 * its one write: `churn` (the files changed most that much depends on),
 * `blast-radius` (a component's dependents in rings), `path-between` (the
 * routes from one component to another) and `annotate` (a note on a
 * component, previewed unless `execute` says to record it).
 *
 * Each answers for the project owning a path, as the other pane commands
 * do; the command around it has already checked that the database exists.
 * `annotate` writes only when `execute` is given, which the pane passes
 * only after the person confirmed the preview in it.
 */
final readonly class PaneQueries
{
    /** The commands answered here. */
    public const COMMANDS = ['churn', 'blast-radius', 'path-between', 'annotate'];

    /** @param PDO $pdo an existing, migrated graph database */
    public function __construct(private PDO $pdo) {}

    /**
     * The options each command takes, beside `db` and `json`.
     *
     * @return list<string>
     */
    public static function options(string $command): array
    {
        return match ($command) {
            'blast-radius' => ['component'],
            'path-between' => ['from', 'to'],
            'annotate' => ['component', 'kind', 'value', 'remove', 'execute'],
            default => [],
        };
    }

    /**
     * The answer to `$command` for the project owning `$path`. `$single`
     * reads an option's one value (null when absent); `$flag` whether a
     * flag was given.
     *
     * @param \Closure(string): ?string $single
     * @param \Closure(string): bool $flag
     * @return array<string, mixed>
     */
    public function answer(string $command, string $path, \Closure $single, \Closure $flag): array
    {
        $required = static fn(string $name): string => $single($name) ?? throw new InvalidArgumentException(sprintf('--%s is required.', $name));

        return match ($command) {
            'churn' => (new ChurnService($this->pdo))->churn($path),
            'blast-radius' => (new BlastRadiusService($this->pdo))->rings($path, $required('component')),
            'path-between' => (new PathBetweenService($this->pdo))->routes($path, $required('from'), $required('to')),
            'annotate' => $this->annotate($path, $required('component'), $single('kind') ?? 'note', $single('value') ?? '', $flag('remove'), $flag('execute')),
            default => throw new InvalidArgumentException(sprintf('Unknown pane command %s.', $command)),
        };
    }

    /**
     * A note on a component, previewed (nothing written) unless `$execute`:
     * what would be recorded or removed and what was there before, or, with
     * `$execute`, what now is. A request knossos refuses (an ambiguous name,
     * a kind it does not know, a value too long) is `refused` with why.
     *
     * @return array<string, mixed>
     */
    private function annotate(string $path, string $component, string $kind, string $value, bool $remove, bool $execute): array
    {
        $absolute = realpath($path) ?: $path;
        $envelope = ['path' => $absolute, 'project_id' => null, 'snapshot_id' => null];
        $project = (new ProjectPathResolver($this->pdo))->resolve($absolute);
        if ($project === null || !is_string($project['active_scan_id']) || $project['active_scan_id'] === '') {
            return ['status' => 'unscanned'] + $envelope;
        }
        $id = (string) $project['id'];
        $envelope = ['project_id' => $id, 'snapshot_id' => $project['active_scan_id']] + $envelope;
        try {
            $result = (new ArchitectureQueryService($this->pdo))->annotateComponent($id, $component, $kind, $value, $remove, $execute);
        } catch (InvalidArgumentException $refused) {
            return ['status' => 'refused', 'reason' => $refused->getMessage()] + $envelope;
        }
        $data = $result->data;

        return [
            'status' => 'ok',
            'component' => (string) $data['component'],
            'kind' => (string) $data['kind'],
            'action' => (string) $data['action'],
            'executed' => (bool) $data['executed'],
            'value' => $remove ? null : $value,
            'previous' => is_array($data['previous'] ?? null) ? (string) $data['previous']['value'] : null,
            'warnings' => array_values(array_filter($result->warnings, static fn(string $w): bool => !str_starts_with($w, 'Set execute=true'))),
        ] + $envelope;
    }
}
