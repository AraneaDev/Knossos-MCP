<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * The counts and the short lists the architecture pane's header and Issues
 * tab draw: what the project holds, its scan diagnostics, its largest files
 * and its boundary-policy violations.
 *
 * Read-only and bounded. Each list is a page of the most telling few, and a
 * page is the design rather than a cut, as with the dashboard's hubs: the
 * counts beside it say how many there are. A flag says so only where a figure
 * would otherwise mislead: `kinds_truncated` and `languages_truncated` when a
 * breakdown lists fewer categories than exist (so it no longer adds up to its
 * total), and the policy's `truncated` when the check stopped at its edge or
 * time limit, so its total is a floor.
 */
final readonly class ProjectFindings
{
    /** Component kinds and languages listed in the summary. */
    private const CATEGORIES = 8;

    /** Errors and warnings listed. */
    private const DIAGNOSTICS = 5;

    /** Files listed by size. */
    private const LARGEST_FILES = 5;

    /** Policy violations listed; the total counts them all. */
    private const VIOLATIONS = 5;

    /** A diagnostic's message is cut to this many characters: the pane shows one line of it. */
    private const MESSAGE = 160;

    /**
     * @param PDO $pdo an existing, migrated graph database
     * @param Closure|null $clock nanosecond clock handed to the policy check, so its time limit is testable
     */
    public function __construct(private PDO $pdo, private ?Closure $clock = null) {}

    /**
     * What the project holds: components (external symbols left out) by kind,
     * and files by language, the most common first.
     *
     * @return array<string, mixed>
     */
    public function summary(string $projectId): array
    {
        $kinds = $this->grouped(
            "SELECT kind AS name, COUNT(*) AS n FROM nodes WHERE project_id = :project AND kind NOT LIKE 'external\\_%' ESCAPE '\\' GROUP BY kind",
            $projectId,
        );
        $languages = $this->grouped('SELECT language AS name, COUNT(*) AS n FROM files WHERE project_id = :project GROUP BY language', $projectId);

        return [
            'components' => array_sum($kinds),
            'kinds' => self::top($kinds, 'kind', 'count'),
            'kinds_truncated' => count($kinds) > self::CATEGORIES,
            'files' => array_sum($languages),
            'languages' => self::top($languages, 'language', 'files'),
            'languages_truncated' => count($languages) > self::CATEGORIES,
        ];
    }

    /**
     * The first categories of a grouping as records: `[$name => category, $count => n]`.
     *
     * @param array<array-key, int> $counts
     * @return list<array<string, int|string>>
     */
    private static function top(array $counts, string $name, string $count): array
    {
        $records = [];
        foreach (array_slice($counts, 0, self::CATEGORIES, true) as $category => $n) {
            $records[] = [$name => (string) $category, $count => $n];
        }

        return $records;
    }

    /**
     * The project's scan diagnostics: counted by severity, and the first
     * errors, then warnings, by file and line. Information notes are counted
     * but never listed.
     *
     * @return array{total: int, errors: int, warnings: int, infos: int, items: list<array{severity: string, code: string, message: string, path: string|null, line: int|null}>}
     */
    public function diagnostics(string $projectId): array
    {
        $counts = $this->grouped('SELECT severity AS name, COUNT(*) AS n FROM diagnostics WHERE project_id = :project GROUP BY severity', $projectId);
        $statement = $this->pdo->prepare(
            'SELECT d.severity, d.code, d.message, f.relative_path, d.start_line FROM diagnostics d LEFT JOIN files f ON f.id = d.file_id '
            . "WHERE d.project_id = :project AND d.severity IN ('error', 'warning') "
            . "ORDER BY CASE d.severity WHEN 'error' THEN 0 ELSE 1 END, f.relative_path, d.start_line, d.code, d.id LIMIT :limit",
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':limit', self::DIAGNOSTICS, PDO::PARAM_INT);
        $statement->execute();

        return [
            'total' => array_sum($counts),
            'errors' => $counts['error'] ?? 0,
            'warnings' => $counts['warning'] ?? 0,
            'infos' => $counts['info'] ?? 0,
            'items' => array_map(static fn(array $row): array => [
                'severity' => (string) $row['severity'],
                'code' => (string) $row['code'],
                'message' => mb_substr((string) $row['message'], 0, self::MESSAGE),
                'path' => $row['relative_path'] === null ? null : (string) $row['relative_path'],
                'line' => $row['start_line'] === null ? null : (int) $row['start_line'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /**
     * The project's largest files by line count, the largest first.
     *
     * @return list<array{path: string, language: string, lines: int}>
     */
    public function largestFiles(string $projectId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT relative_path, language, line_count FROM files WHERE project_id = :project ORDER BY line_count DESC, relative_path LIMIT :limit',
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':limit', self::LARGEST_FILES, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn(array $row): array => [
            'path' => (string) $row['relative_path'],
            'language' => (string) $row['language'],
            'lines' => (int) $row['line_count'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The project's boundary-policy violations against the policies its
     * `knossos.json` declares: the total and the first few, each with the
     * file and line of the offending reference. `not_evaluated` when the
     * project declares no policy or its policies cannot be compiled.
     *
     * @param string $root the project root, where `knossos.json` is read
     * @return array{status: string, total: int, truncated: bool, truncation_reasons: list<string>, items: list<array<string, mixed>>}
     */
    public function policy(string $projectId, string $root, BoundaryLabels $labels): array
    {
        $none = ['status' => 'not_evaluated', 'total' => 0, 'truncated' => false, 'truncation_reasons' => [], 'items' => []];
        $policies = FileViolationQuery::policies($root, null);
        if ($policies === []) {
            return $none;
        }
        try {
            $check = (new ArchitectureQueryService($this->pdo, $this->clock))->checkArchitecture($projectId, $policies, limit: self::VIOLATIONS);
        } catch (InvalidArgumentException) {
            return $none;
        }
        // The list is a page; only a stop at the edge or time limit makes the total a floor.
        $reasons = array_values(array_diff($check->data['bounds']['truncation_reasons'], ['result_limit']));
        $places = [];
        foreach ($check->evidence as $place) {
            $places[(string) $place['edge_id']] = $place;
        }

        return [
            'status' => 'evaluated',
            'total' => (int) $check->data['bounds']['violation_count'],
            'truncated' => $reasons !== [],
            'truncation_reasons' => $reasons,
            'items' => array_map(static function (array $v) use ($places, $labels): array {
                $place = $places[(string) $v['relationship']['id']] ?? null;
                return [
                    'policy_id' => (string) $v['policy_id'],
                    'source' => (string) $v['source']['canonical_name'],
                    'source_kind' => (string) $v['source']['kind'],
                    'source_boundary' => $labels->of($v['source_boundaries']),
                    'target' => (string) $v['target']['canonical_name'],
                    'target_kind' => (string) $v['target']['kind'],
                    'target_boundary' => $labels->of($v['target_boundaries']),
                    'path' => $place === null ? null : (string) $place['path'],
                    'line' => isset($place['start_line']) ? (int) $place['start_line'] : null,
                ];
            }, $check->data['violations']),
        ];
    }

    /**
     * The findings of a path no scanned project contains: every count zero, every list empty.
     *
     * @return array<string, mixed>
     */
    public static function none(): array
    {
        return [
            'summary' => ['components' => 0, 'kinds' => [], 'kinds_truncated' => false, 'files' => 0, 'languages' => [], 'languages_truncated' => false],
            'boundaries' => ['items' => [], 'truncated' => false],
            'diagnostics' => ['total' => 0, 'errors' => 0, 'warnings' => 0, 'infos' => 0, 'items' => []],
            'largest_files' => [],
            'policy' => ['status' => 'not_evaluated', 'total' => 0, 'truncated' => false, 'truncation_reasons' => [], 'items' => []],
        ];
    }

    /**
     * A `name, n` grouping as name => count, the largest first, then by name.
     *
     * @return array<array-key, int>
     */
    private function grouped(string $sql, string $projectId): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(['project' => $projectId]);
        $counts = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $counts[(string) $row['name']] = (int) $row['n'];
        }
        uksort($counts, static fn(int|string $a, int|string $b): int => [$counts[$b], (string) $a] <=> [$counts[$a], (string) $b]);

        return $counts;
    }
}
