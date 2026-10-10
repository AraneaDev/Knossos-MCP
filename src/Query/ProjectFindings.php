<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * The counts and the short lists the architecture pane's header and Issues
 * tab draw: what the project holds, its scan diagnostics, its complexity
 * hotspots (size times dependents), the files over its maintainability
 * budget, and its boundary-policy violations.
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
    private const DIAGNOSTICS = 20;

    /** Complexity hotspots listed: as many as a tall pane shows. */
    private const HOTSPOTS = 30;

    /** Files over the maintainability budget listed; `total` counts them all. */
    private const OVER_BUDGET = 30;

    /** Where a project declares its maintainability budgets, at its root. */
    public const BUDGETS_FILE = 'maintainability-budgets.json';

    /** Policy violations listed; the total counts them all. */
    private const VIOLATIONS = 20;

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
     * The files a change is riskiest in: each file's size (lines) times how
     * many other files depend on it, the highest first, ties by path. A
     * large file nothing uses and a small one everything uses both rank
     * low; a large file many depend on ranks high. Dependents are counted
     * as {@see FileFanInQuery} counts them (distinct files over the impact
     * edges, external symbols left out), in one grouped pass over the edges.
     *
     * @return list<array{path: string, language: string, lines: int, dependent_files: int, score: int}>
     */
    public function complexityHotspots(string $projectId): array
    {
        $kinds = implode(',', array_fill(0, count(AbstractArchitectureQueryService::IMPACT_EDGE_KINDS), '?'));
        $statement = $this->pdo->prepare(
            'SELECT tf.relative_path, tf.language, tf.line_count, COUNT(DISTINCT sn.file_id) AS dependents FROM edges e '
            . 'JOIN nodes tn ON tn.id = e.target_id JOIN files tf ON tf.id = tn.file_id JOIN nodes sn ON sn.id = e.source_id '
            . "WHERE e.project_id = ? AND e.kind IN ({$kinds}) AND sn.file_id IS NOT NULL AND sn.file_id <> tn.file_id "
            . "AND tn.kind NOT LIKE 'external\\_%' ESCAPE '\\' AND tf.line_count > 0 "
            . 'GROUP BY tf.id ORDER BY tf.line_count * COUNT(DISTINCT sn.file_id) DESC, tf.relative_path LIMIT ?',
        );
        $position = 1;
        $statement->bindValue($position++, $projectId);
        foreach (AbstractArchitectureQueryService::IMPACT_EDGE_KINDS as $kind) {
            $statement->bindValue($position++, $kind);
        }
        $statement->bindValue($position, self::HOTSPOTS, PDO::PARAM_INT);
        $statement->execute();

        return array_map(static fn(array $row): array => [
            'path' => (string) $row['relative_path'],
            'language' => (string) $row['language'],
            'lines' => (int) $row['line_count'],
            'dependent_files' => (int) $row['dependents'],
            'score' => (int) $row['line_count'] * (int) $row['dependents'],
        ], $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * The files over the project's own maintainability budget, as
     * `maintainability-budgets.json` at its root declares it: those holding a
     * PHP function or method longer than `max_php_function_lines`, the file
     * with the longest first. Each says how many of its functions are over
     * and where the longest starts. Only that budget is read: the graph
     * holds every function's span, but not the complexity or the import
     * count the other budgets measure, and a figure it cannot measure the
     * way the budget does would flag files the budget passes.
     *
     * Null when the project declares no such budget (no file, an unreadable
     * one, or no line budget in it).
     *
     * @param string $root the project root, where the budgets file is read
     * @return array{source: string, max_function_lines: int, total: int, files: list<array{path: string, functions: int, longest: int, line: int|null}>}|null
     */
    public function overBudget(string $projectId, string $root): ?array
    {
        $max = self::functionLineBudget($root);
        if ($max === null) {
            return null;
        }
        $over = "FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = :project AND n.language = 'php' "
            . "AND n.kind IN ('function', 'method') AND n.start_line IS NOT NULL AND n.end_line IS NOT NULL AND n.end_line - n.start_line + 1 > :max";
        $count = $this->pdo->prepare("SELECT COUNT(DISTINCT f.id) {$over}");
        $count->bindValue(':project', $projectId);
        // Bound as an integer: SQLite ranks any number below a text value, so a string budget would match nothing.
        $count->bindValue(':max', $max, PDO::PARAM_INT);
        $count->execute();
        $statement = $this->pdo->prepare(
            'SELECT f.relative_path, COUNT(*) AS functions, MAX(n.end_line - n.start_line + 1) AS longest, '
            . "(SELECT m.start_line FROM nodes m WHERE m.file_id = f.id AND m.language = 'php' AND m.kind IN ('function', 'method') "
            . 'AND m.start_line IS NOT NULL AND m.end_line IS NOT NULL ORDER BY m.end_line - m.start_line DESC, m.start_line LIMIT 1) AS line '
            . "{$over} GROUP BY f.id ORDER BY longest DESC, f.relative_path LIMIT :limit",
        );
        $statement->bindValue(':project', $projectId);
        $statement->bindValue(':max', $max, PDO::PARAM_INT);
        $statement->bindValue(':limit', self::OVER_BUDGET, PDO::PARAM_INT);
        $statement->execute();

        return [
            'source' => self::BUDGETS_FILE,
            'max_function_lines' => $max,
            'total' => (int) $count->fetchColumn(),
            'files' => array_map(static fn(array $row): array => [
                'path' => (string) $row['relative_path'],
                'functions' => (int) $row['functions'],
                'longest' => (int) $row['longest'],
                'line' => $row['line'] === null ? null : (int) $row['line'],
            ], $statement->fetchAll(PDO::FETCH_ASSOC)),
        ];
    }

    /** The project's `max_php_function_lines` budget, or null when its budgets file declares none (or cannot be read). */
    private static function functionLineBudget(string $root): ?int
    {
        $file = rtrim($root, '/') . '/' . self::BUDGETS_FILE;
        if (!is_file($file)) {
            return null;
        }
        $budgets = json_decode((string) file_get_contents($file), true);
        $max = is_array($budgets) ? ($budgets['max_php_function_lines'] ?? null) : null;

        return is_int($max) && $max >= 1 ? $max : null;
    }

    /**
     * The project's boundary-policy violations against the policies its
     * `knossos.json` declares: the total and the first few, each with the
     * file and line of the offending reference. `not_evaluated` when the
     * project declares no policy or its policies cannot be compiled.
     *
     * Beside them, the rules themselves and the files they bind
     * ({@see PolicyScope}): what an agent reads before it edits such a file,
     * so it can keep to a rule instead of being told it broke one.
     *
     * @param string $root the project root, where `knossos.json` is read
     * @return array<string, mixed>
     */
    public function policy(string $projectId, string $root, BoundaryLabels $labels): array
    {
        $none = ['status' => 'not_evaluated', 'total' => 0, 'truncated' => false, 'truncation_reasons' => [], 'items' => [], 'rules' => [], 'boundaries' => [], 'files' => [], 'files_truncated' => false];
        $policies = FileViolationQuery::policies($root, null);
        if ($policies === []) {
            return $none;
        }
        try {
            // The whole project's edges: on a graph of several thousand components
            // a one-second budget ran out on a cold read and flagged a clean project.
            $check = ArchitectureQueryService::forDatabase($this->pdo, $this->clock)->checkArchitecture($projectId, $policies, limit: self::VIOLATIONS, timeoutMs: 5000);
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
        ] + (new PolicyScope($this->pdo))->build($projectId, $policies, $labels);
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
            'boundaries' => ['items' => [], 'truncated' => false, 'declared' => [], 'declared_truncated' => false],
            'diagnostics' => ['total' => 0, 'errors' => 0, 'warnings' => 0, 'infos' => 0, 'items' => []],
            'complexity_hotspots' => [],
            'over_budget' => null,
            'policy' => ['status' => 'not_evaluated', 'total' => 0, 'truncated' => false, 'truncation_reasons' => [], 'items' => [], 'rules' => [], 'boundaries' => [], 'files' => [], 'files_truncated' => false],
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
