<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Scanner\Protocol\RelativePath;
use Knossos\Store\ChunkedInQuery;
use PDO;
use Throwable;

/**
 * Answers what a change can affect, from the graph and from Git.
 *
 * Impact is a conservative static blast radius, deliberately over- rather than
 * under-inclusive, and every answer says so: a dependant listed here may not
 * actually break. Git churn weights the result so the components that also change
 * often surface first.
 */
final readonly class ChangeImpactQueryService extends AbstractArchitectureQueryService
{
    /** Most changed files one request may name, and most a working tree may contribute. */
    public const MAX_FILES = 50;

    /** Most components the changed files map to directly, each of which fans out into its own impact search. */
    private const MAX_DIRECT_COMPONENTS = 1000;

    /** Most evidence records returned, so a wide change cannot bury the answer in citations. */
    private const MAX_EVIDENCE = 100;

    /** Most bytes of a Git failure quoted back, which is plenty to name the cause. */
    private const MAX_REASON_BYTES = 500;

    public function __construct(PDO $pdo, ?Closure $clock, private ImpactAnalysisQuery $impactQueries, private ?GitHistoryProvider $gitHistory = null, private ?GitWorkingTreeProvider $gitWorkingTree = null)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * Static blast radius weighted by recent Git churn.
     *
     * @param list<string> $edgeKinds
     */
    public function changeImpact(string $projectId, string $symbol, int $sinceDays = 90, int $maxCommits = 500, int $maxDepth = 4, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        if ($sinceDays < 1 || $sinceDays > 3650) {
            throw new InvalidArgumentException('since_days must be between 1 and 3650.');
        }
        if ($maxCommits < 1 || $maxCommits > 5000) {
            throw new InvalidArgumentException('max_commits must be between 1 and 5000.');
        }
        $project = $this->project($projectId);
        $impact = $this->impactQueries->impactAnalysis($projectId, $symbol, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
        $target = $impact->data['target'] ?? null;
        if (!is_array($target)) {
            // Carry through which failure the underlying resolution hit, so the
            // machine-readable reason does not report ambiguity for a name that
            // matched nothing.
            $unmatched = ($impact->data['candidates'] ?? null) === [];
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                $unmatched ? sprintf('No component matched "%s".', $symbol) : 'Change-aware impact requires one unambiguous component.',
                ['impact' => $impact->data, 'git' => ['available' => false, 'reason' => $unmatched ? 'unmatched_target' : 'ambiguous_target'], 'risk_ranking' => []],
                $impact->evidence,
                $impact->warnings,
                $impact->truncated,
            );
        }
        $components = [$target['id'] => ['node' => $target, 'distance' => 0, 'path_confidence' => 'certain']];
        foreach ($impact->data['dependants'] ?? [] as $record) {
            $components[$record['node']['id']] = [
                'node' => $record['node'], 'distance' => $record['distance'], 'path_confidence' => $record['path_confidence'],
            ];
        }
        $paths = $this->nodePaths(array_keys($components));
        $gitMetadata = ['available' => false, 'reason' => 'provider_unavailable', 'since_days' => $sinceDays, 'max_commits' => $maxCommits];
        $history = ['files' => [], 'truncated' => false];
        $warnings = $impact->warnings;
        if ($this->gitHistory !== null) {
            try {
                $history = $this->gitHistory->history($project['root_realpath'], $sinceDays, $maxCommits, $timeoutMs);
                $gitMetadata = [
                    'available' => true, 'reason' => null, 'since_days' => $sinceDays, 'max_commits' => $maxCommits,
                    'commits_examined' => $history['commits_examined'], 'truncated' => $history['truncated'],
                ];
            } catch (Throwable $error) {
                $gitMetadata['reason'] = substr($error->getMessage(), 0, self::MAX_REASON_BYTES);
            }
        }
        if (!$gitMetadata['available']) {
            $warnings[] = 'Git change signals were unavailable; static impact is returned with zero change scores: ' . $gitMetadata['reason'];
        }
        $warnings[] = 'Change frequency and authorship are historical signals, not proof of risk or ownership.';
        $ranking = [];
        foreach ($components as $id => $component) {
            $path = $paths[$id] ?? null;
            $signal = is_string($path) && isset($history['files'][$path])
                ? $history['files'][$path]
                : ['commit_count' => 0, 'authors' => [], 'last_changed_at' => null];
            // At least 1: impact_analysis stops at max_depth, so the farthest
            // dependant scores 1 and the target itself max_depth + 1.
            $staticWeight = $maxDepth + 1 - $component['distance'];
            $score = ($signal['commit_count'] * 3) + count($signal['authors']) + $staticWeight;
            $ranking[] = [
                'component' => $component['node'], 'relative_path' => $path, 'distance' => $component['distance'],
                'path_confidence' => $component['path_confidence'], 'change_signals' => $signal,
                'score' => $score,
                'factors' => ['commit_weight' => $signal['commit_count'] * 3, 'author_weight' => count($signal['authors']), 'static_proximity_weight' => $staticWeight],
            ];
        }
        usort($ranking, static fn(array $a, array $b): int => ($b['score'] <=> $a['score'])
            ?: ($a['distance'] <=> $b['distance'])
            ?: ($a['component']['canonical_name'] <=> $b['component']['canonical_name']));
        $evidence = $impact->evidence;
        foreach ($ranking as $index => $record) {
            if ($record['relative_path'] !== null) {
                $evidence[] = [
                    'risk_index' => $index, 'component_id' => $record['component']['id'], 'path' => $record['relative_path'],
                    'change_signals' => $record['change_signals'],
                ];
            }
        }
        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Ranked %d statically impacted component%s with recent Git change signals.', count($ranking), count($ranking) === 1 ? '' : 's'),
            ['target' => $target, 'git' => $gitMetadata, 'risk_ranking' => $ranking, 'static_impact' => $impact->data],
            $evidence,
            $warnings,
            $impact->truncated || $history['truncated'],
        );
    }

    /**
     * What a changed file set touches, directly and transitively.
     *
     * @param list<string> $files @param list<string> $edgeKinds
     */
    public function changedFilesImpact(string $projectId, array $files = [], bool $workingTree = false, ?string $baseRef = null, int $maxDepth = 4, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        $changeSet = $this->changeSet($projectId, $files, $workingTree, $baseRef, $timeoutMs);
        ['project' => $project, 'files' => $files, 'git' => $git, 'direct' => $direct, 'unresolved' => $unresolved] = $changeSet;
        $impacted = [];
        $entryPoints = [];
        $warnings = [];
        $truncated = $changeSet['truncation_reasons'] !== [];
        // One deadline shared across the whole fan-out bounds the entire request,
        // instead of each per-component analysis resetting its own timeout (which
        // could otherwise multiply into minutes of wall time for a single call).
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        foreach ($direct as $node) {
            $impact = $this->impactQueries->impactAnalysis($projectId, $node['id'], $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs, $deadline);
            foreach ($impact->data['dependants'] ?? [] as $record) {
                $id = $record['node']['id'];
                if (!isset($impacted[$id]) || self::nearerOrSurer($record, $impacted[$id])) {
                    $impacted[$id] = $record;
                }
            }
            foreach ($impact->data['entry_points'] ?? [] as $entry) {
                $entryPoints[$entry['node']['id']] = $entry;
            }
            $warnings = [...$warnings, ...$impact->warnings];
            $truncated = $truncated || $impact->truncated;
        }
        uasort($impacted, static fn(array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: ($a['node']['canonical_name'] <=> $b['node']['canonical_name']));
        if (count($impacted) > $limit) {
            $truncated = true;
        }
        $impacted = array_slice(array_values($impacted), 0, $limit);
        ksort($entryPoints, SORT_STRING);
        $evidence = array_map(static fn(array $node): array => [
            'component_id' => $node['id'], 'path' => $node['relative_path'],
            'start_line' => $node['start_line'], 'end_line' => $node['end_line'],
        ], array_slice($direct, 0, self::MAX_EVIDENCE));

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Mapped %d changed file%s to %d direct and %d impacted component%s.', count($files), count($files) === 1 ? '' : 's', count($direct), count($impacted), count($impacted) === 1 ? '' : 's'),
            ['changed_files' => $files, 'unresolved_files' => $unresolved, 'direct_components' => $direct,
                'impacted_components' => $impacted, 'entry_points' => array_values($entryPoints), 'git' => $git,
                'bounds' => ['max_files' => self::MAX_FILES, 'max_direct_components' => self::MAX_DIRECT_COMPONENTS, 'limit' => $limit, 'max_depth' => $maxDepth]],
            $evidence,
            array_values(array_unique($warnings)),
            $truncated,
        );
    }

    /**
     * Which test files statically reach a changed file set: a lower bound,
     * never a guarantee. Data-driven tests and glob-only discovery are
     * invisible to the graph.
     *
     * The search is {@see TestReachSearch}, with bounds of its own. It used to
     * be an impact analysis capped at 100 dependants per changed component,
     * with test roles filtered out afterwards, so a hub's production callers
     * filled the window before any test was met and the answer read "0 test
     * files". Every bound that cuts the search or the list is named in
     * `bounds.truncation_reasons` and in the summary.
     *
     * @param list<string> $files @param list<string> $edgeKinds
     */
    public function testImpact(string $projectId, array $files = [], bool $workingTree = false, ?string $baseRef = null, int $maxDepth = 4, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        if ($maxDepth < 1 || $maxDepth > 8) {
            throw new InvalidArgumentException('max_depth must be between 1 and 8.');
        }
        self::assertLimit($limit);
        $minimumRank = $this->confidenceThreshold($timeoutMs, $minConfidence)[$minConfidence];
        $edgeKinds = self::selectedEdgeKinds($edgeKinds, self::IMPACT_EDGE_KINDS, 'impact');
        // One deadline for the whole request, Git included.
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $changeSet = $this->changeSet($projectId, $files, $workingTree, $baseRef, $timeoutMs);
        $search = (new TestReachSearch($this->pdo, $this->clock))
            ->search($projectId, array_column($changeSet['direct'], 'id'), $maxDepth, $edgeKinds, $minimumRank, $deadline);
        $testFiles = $this->testFiles($search['tests']);
        $found = count($testFiles);
        $reasons = [...$changeSet['truncation_reasons'], ...$search['truncation_reasons']];
        $summary = sprintf('%d test file%s statically exercise the change.', min($found, $limit), min($found, $limit) === 1 ? '' : 's');
        if ($reasons !== []) {
            $summary .= sprintf(' The search was truncated (%s), so test files beyond that bound are not listed.', implode(', ', $reasons));
        }
        if ($found > $limit) {
            $reasons[] = 'result_limit';
            $summary .= sprintf(' The list was truncated to the first %d of %d test files.', $limit, $found);
            $testFiles = array_slice($testFiles, 0, $limit);
        }

        return new ResultEnvelope(
            $projectId,
            $changeSet['project']['active_scan_id'],
            $summary,
            [
                'changed_files' => $changeSet['files'],
                'unresolved_files' => $changeSet['unresolved'],
                'test_files' => $testFiles,
                'bounds' => [
                    'max_files' => self::MAX_FILES, 'max_direct_components' => self::MAX_DIRECT_COMPONENTS, 'limit' => $limit, 'max_depth' => $maxDepth,
                    'max_visited' => TestReachSearch::MAX_VISITED, 'max_edges' => TestReachSearch::MAX_EDGES,
                    'visited_nodes' => $search['visited'], 'edges_examined' => $search['edges_examined'],
                    'test_files_found' => $found, 'truncation_reasons' => $reasons,
                ],
            ],
            array_slice(array_map(static fn(array $entry): array => ['path' => $entry['path'], 'start_line' => null, 'end_line' => null], $testFiles), 0, self::MAX_EVIDENCE),
            ['Test impact is a static lower bound: run these first, not only these. Data-driven tests, fixtures, and glob-only discovery are not visible to the graph, and the test search is bounded; see bounds.truncation_reasons.'],
            $reasons !== [],
        );
    }

    /**
     * Group test components by file: nearest distance first, then path, with up to three class names each.
     *
     * @param array<string, int> $tests test node id => shortest distance
     * @return list<array{path: string, distance: int, via: list<string>}>
     */
    private function testFiles(array $tests): array
    {
        $ids = array_map('strval', array_keys($tests));
        $paths = $this->nodePaths($ids);
        $names = $this->displayNames($ids);
        $byPath = [];
        foreach ($tests as $id => $distance) {
            $path = $paths[$id] ?? null;
            if ($path === null) {
                continue;
            }
            $byPath[$path] ??= ['path' => $path, 'distance' => PHP_INT_MAX, 'via' => []];
            $byPath[$path]['distance'] = min($byPath[$path]['distance'], $distance);
            $byPath[$path]['via'][] = $names[$id] ?? '';
        }
        $testFiles = [];
        foreach ($byPath as $entry) {
            sort($entry['via'], SORT_STRING);
            $entry['via'] = array_slice(array_values(array_unique($entry['via'])), 0, 3);
            $testFiles[] = $entry;
        }
        usort($testFiles, static fn(array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: ($a['path'] <=> $b['path']));
        return $testFiles;
    }

    /**
     * Display names for a node set, one query per 500 ids.
     *
     * @param list<string> $nodeIds @return array<string, string>
     */
    private function displayNames(array $nodeIds): array
    {
        $names = [];
        foreach (ChunkedInQuery::rows($this->pdo, 'SELECT id, display_name FROM nodes WHERE id IN (%s)', $nodeIds) as $row) {
            $names[(string) $row['id']] = (string) $row['display_name'];
        }
        return $names;
    }

    /**
     * The changed files, Git context and directly changed components both impact tools start from.
     *
     * Validates the file arguments, asks Git for the working tree when told to,
     * and maps each file to the components it declares (at most
     * MAX_DIRECT_COMPONENTS, in path, name and id order). A cut list or change
     * set is named in `truncation_reasons`.
     *
     * @param list<string> $files
     * @return array{project: array<string, mixed>, files: list<string>, git: array<string, mixed>, direct: list<array<string, mixed>>, unresolved: list<string>, truncation_reasons: list<string>}
     */
    private function changeSet(string $projectId, array $files, bool $workingTree, ?string $baseRef, int $timeoutMs): array
    {
        $project = $this->project($projectId);
        // A lone base_ref (no working_tree, no files) gets the specific coupling
        // message rather than the generic mutual-exclusion error.
        if (!$workingTree && $baseRef !== null && $files === []) {
            throw new InvalidArgumentException('base_ref requires working_tree.');
        }
        if ($workingTree === ($files !== [])) {
            throw new InvalidArgumentException('Provide either files or working_tree, but not both.');
        }
        if (count($files) > self::MAX_FILES) {
            throw new InvalidArgumentException(sprintf('files must contain at most %d paths.', self::MAX_FILES));
        }
        $git = ['used' => false, 'base_ref' => $baseRef, 'renames' => [], 'truncated' => false];
        if ($workingTree) {
            if ($this->gitWorkingTree === null) {
                throw new InvalidArgumentException('Working-tree change discovery is unavailable.');
            }
            try {
                $changes = $this->gitWorkingTree->changes($project['root_realpath'], $baseRef, self::MAX_FILES, $timeoutMs);
            } catch (Throwable $error) {
                throw new InvalidArgumentException('Working-tree change discovery failed: ' . substr($error->getMessage(), 0, self::MAX_REASON_BYTES), previous: $error);
            }
            $files = $changes['paths'];
            $git = ['used' => true, 'base_ref' => $baseRef, 'renames' => $changes['renames'], 'truncated' => $changes['truncated']];
        } elseif ($baseRef !== null) {
            throw new InvalidArgumentException('base_ref requires working_tree.');
        }
        foreach ($files as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('files must contain project-relative strings.');
            }
            RelativePath::assertValid($path, 'Changed file');
        }
        $files = array_values(array_unique($files));
        sort($files, SORT_STRING);
        $direct = [];
        if ($files !== []) {
            $placeholders = implode(',', array_fill(0, count($files), '?'));
            $statement = $this->pdo->prepare(
                'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence, f.relative_path, n.start_line, n.end_line ' .
                'FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = ? AND f.relative_path IN (' . $placeholders . ') ' .
                sprintf('ORDER BY f.relative_path, n.canonical_name, n.id LIMIT %d', self::MAX_DIRECT_COMPONENTS + 1),
            );
            $statement->execute([$projectId, ...$files]);
            $direct = $statement->fetchAll();
        }
        $resolvedPaths = array_fill_keys(array_column($direct, 'relative_path'), true);
        $unresolved = array_values(array_filter($files, static fn(string $path): bool => !isset($resolvedPaths[$path])));
        $reasons = [];
        if ($git['truncated']) {
            $reasons[] = 'changed_file_limit';
        }
        if (count($direct) > self::MAX_DIRECT_COMPONENTS) {
            $reasons[] = 'direct_component_limit';
            $direct = array_slice($direct, 0, self::MAX_DIRECT_COMPONENTS);
        }

        return ['project' => $project, 'files' => $files, 'git' => $git, 'direct' => $direct, 'unresolved' => $unresolved, 'truncation_reasons' => $reasons];
    }

    /**
     * Whether a dependant reached from one changed component beats the record
     * already kept for it from another.
     *
     * The nearer path wins, and at the same distance the surer one, which is
     * the rule impact_analysis applies within a single search. Keeping the
     * first record found at equal distance reported a dependant as `possible`
     * whenever the alphabetically first changed component reached it that way,
     * even when another changed component reached it for certain.
     *
     * @param array{distance: int, path_confidence: string} $candidate
     * @param array{distance: int, path_confidence: string} $kept
     */
    private static function nearerOrSurer(array $candidate, array $kept): bool
    {
        return $candidate['distance'] < $kept['distance']
            || ($candidate['distance'] === $kept['distance']
                && self::CONFIDENCE_RANK[$candidate['path_confidence']] > self::CONFIDENCE_RANK[$kept['path_confidence']]);
    }

    /**
     * Evidence paths for a node set, used to map files back to components.
     *
     * @param list<string> $nodeIds @return array<string, string>
     */
    private function nodePaths(array $nodeIds): array
    {
        $paths = [];
        foreach (ChunkedInQuery::rows($this->pdo, 'SELECT n.id, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.id IN (%s) ORDER BY n.id', $nodeIds) as $row) {
            $paths[$row['id']] = $row['relative_path'];
        }
        return $paths;
    }
}
