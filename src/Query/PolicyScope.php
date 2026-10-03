<?php

declare(strict_types=1);

namespace Knossos\Query;

use PDO;

/**
 * The project's declared boundary policies as rules an agent can follow
 * before it writes code, and the files each rule binds.
 *
 * A policy binds the components of its `from_boundary`, so the files it binds
 * are the files with a component in that boundary (any membership, not only
 * the one the pane labels the file with). Boundary references, which a policy
 * may write as an id or a name, are given as names; `@unassigned` is kept.
 * Policies that do not validate (as the policy check would reject them) are
 * left out rather than guessed at.
 *
 * Bounded: at most {@see self::FILE_CAP} files, `files_truncated` when more
 * are bound, so an absent file is not proof that no rule binds it.
 */
final readonly class PolicyScope
{
    /** The most bound files listed. */
    public const FILE_CAP = 2000;

    /**
     * @param PDO $pdo an existing, migrated graph database
     */
    public function __construct(private PDO $pdo, private int $fileCap = self::FILE_CAP) {}

    /**
     * The rules and the files they bind.
     *
     * @param list<array<string, mixed>> $policies as the project configuration declares them
     * @return array{rules: list<array{id: string, from: string, deny: list<string>, allow: list<string>, edge_kinds: list<string>}>, files: array<string, list<string>>, files_truncated: bool}
     */
    public function build(string $projectId, array $policies, BoundaryLabels $labels): array
    {
        $rules = [];
        $from = [];
        foreach ($policies as $policy) {
            $rule = self::rule($policy, $labels);
            $id = $rule === null ? null : $labels->idOf((string) $policy['from_boundary']);
            if ($rule !== null && $id !== null) {
                $rules[] = $rule;
                $from[$id] = true;
            }
        }
        [$files, $truncated] = $this->boundFiles($projectId, array_map('strval', array_keys($from)));

        return ['rules' => $rules, 'files' => $files, 'files_truncated' => $truncated];
    }

    /**
     * One policy as a rule with names for references, or null when it is not
     * one the check would evaluate (no id or source, nothing to allow or deny).
     *
     * @param mixed $policy
     * @return array{id: string, from: string, deny: list<string>, allow: list<string>, edge_kinds: list<string>}|null
     */
    private static function rule(mixed $policy, BoundaryLabels $labels): ?array
    {
        if (!is_array($policy) || !is_string($policy['id'] ?? null) || !is_string($policy['from_boundary'] ?? null)) {
            return null;
        }
        $names = static fn(string $key): array => array_values(array_unique(array_map(
            static fn(string $reference): string => $reference === '@unassigned' ? $reference : ($labels->nameOf($reference) ?? $reference),
            array_values(array_filter(is_array($policy[$key] ?? null) ? $policy[$key] : [], 'is_string')),
        )));
        $deny = $names('deny_targets');
        $allow = $names('allow_targets');
        if ($deny === [] && $allow === []) {
            return null;
        }
        $kinds = array_values(array_filter(is_array($policy['edge_kinds'] ?? null) ? $policy['edge_kinds'] : [], 'is_string'));

        return [
            'id' => $policy['id'],
            'from' => $labels->nameOf($policy['from_boundary']) ?? $policy['from_boundary'],
            'deny' => $deny,
            'allow' => $allow,
            'edge_kinds' => $kinds,
        ];
    }

    /**
     * Each file with a component in one of `$boundaries`, with the boundaries
     * of those it sits in, paths in order; and whether the cap cut the list.
     * Boundaries are matched by id, so another boundary that only shares a
     * policed one's name (an inferred one beside a declared one) binds nothing.
     *
     * @param list<string> $boundaries boundary ids
     * @return array{0: array<string, list<string>>, 1: bool}
     */
    private function boundFiles(string $projectId, array $boundaries): array
    {
        if ($boundaries === []) {
            return [[], false];
        }
        $statement = $this->pdo->prepare(
            'SELECT DISTINCT f.relative_path, b.name FROM boundaries b JOIN boundary_memberships bm ON bm.boundary_id = b.id '
            . 'JOIN nodes n ON n.id = bm.node_id JOIN files f ON f.id = n.file_id '
            . 'WHERE b.project_id = ? AND b.id IN (' . implode(',', array_fill(0, count($boundaries), '?')) . ') '
            . 'ORDER BY f.relative_path, b.name',
        );
        $statement->execute([$projectId, ...$boundaries]);
        $files = [];
        $truncated = false;
        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$path, $name]) {
            $path = (string) $path;
            if (!isset($files[$path]) && count($files) >= $this->fileCap) {
                $truncated = true;
                break;
            }
            $files[$path][] = (string) $name;
        }

        return [$files, $truncated];
    }
}
