<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Git\GitHistoryProvider;
use Knossos\Git\GitWorkingTreeProvider;
use Knossos\Scanner\Protocol\RelativePath;
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
    /**
     * How wide test_impact's underlying blast-radius scan runs, independent of
     * the result cap the caller asks for. Test files are filtered out of the
     * impacted set by role, so the search has to be wide enough that production
     * dependants sorting ahead of them cannot crowd them out. Pinned to the
     * per-query maximum the topology service accepts, so narrowing the answer
     * never narrows the search.
     */
    private const TEST_IMPACT_SCAN_LIMIT = 100;

    /** Most changed files one request may name, and most a working tree may contribute. */
    private const MAX_FILES = 50;

    /** Most components the changed files map to directly, each of which fans out into its own impact search. */
    private const MAX_DIRECT_COMPONENTS = 1000;

    /** Most evidence records returned, so a wide change cannot bury the answer in citations. */
    private const MAX_EVIDENCE = 100;

    /** Most bytes of a Git failure quoted back, which is plenty to name the cause. */
    private const MAX_REASON_BYTES = 500;

    public function __construct(PDO $pdo, ?Closure $clock, private GraphTopologyQueryService $topologyQueries, private ?GitHistoryProvider $gitHistory = null, private ?GitWorkingTreeProvider $gitWorkingTree = null)
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
        $impact = $this->topologyQueries->impactAnalysis($projectId, $symbol, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
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
        $impacted = [];
        $entryPoints = [];
        $warnings = [];
        $truncated = $git['truncated'] || count($direct) > self::MAX_DIRECT_COMPONENTS;
        // One deadline shared across the whole fan-out bounds the entire request,
        // instead of each per-component analysis resetting its own timeout (which
        // could otherwise multiply into minutes of wall time for a single call).
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        $direct = array_slice($direct, 0, self::MAX_DIRECT_COMPONENTS);
        foreach ($direct as $node) {
            $impact = $this->topologyQueries->impactAnalysis($projectId, $node['id'], $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs, $deadline);
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
     * Project the changed-files blast radius onto test files: which tests
     * (statically) reach the changed code. A lower bound, never a guarantee —
     * data-driven tests and glob-only discovery are invisible to the graph.
     *
     * @param list<string> $files @param list<string> $edgeKinds
     */
    public function testImpact(string $projectId, array $files = [], bool $workingTree = false, ?string $baseRef = null, int $maxDepth = 4, int $limit = 100, array $edgeKinds = [], string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        // The caller's limit caps the answer, not the search. Handing it to the
        // blast-radius scan let production dependants that sort ahead of a test
        // file consume the whole window, so narrowing the result set turned a
        // truncation into "0 test files statically exercise the change" — a false
        // negative for the one tool whose output decides which tests get run.
        $impact = $this->changedFilesImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, self::TEST_IMPACT_SCAN_LIMIT, $edgeKinds, $minConfidence, $timeoutMs);
        $distances = [];
        foreach ($impact->data['direct_components'] as $component) {
            $distances[$component['id']] = 0;
        }
        foreach ($impact->data['impacted_components'] as $record) {
            $id = $record['node']['id'];
            $distances[$id] = min($distances[$id] ?? PHP_INT_MAX, $record['distance']);
        }
        $displayNames = [];
        foreach ($impact->data['direct_components'] as $component) {
            $displayNames[$component['id']] = $component['display_name'];
        }
        foreach ($impact->data['impacted_components'] as $record) {
            $displayNames[$record['node']['id']] ??= $record['node']['display_name'];
        }
        $roles = $this->roles(array_keys($distances));
        $testNodeIds = [];
        foreach ($distances as $id => $distance) {
            foreach ($roles[$id] ?? [] as $role) {
                if ($role['role'] === 'quality.test_module') {
                    $testNodeIds[] = $id;
                    break;
                }
            }
        }
        $paths = $this->nodePaths($testNodeIds);
        $byPath = [];
        foreach ($testNodeIds as $id) {
            $path = $paths[$id] ?? null;
            if ($path === null) {
                continue;
            }
            $byPath[$path] ??= ['path' => $path, 'distance' => PHP_INT_MAX, 'via' => []];
            $byPath[$path]['distance'] = min($byPath[$path]['distance'], $distances[$id]);
            $byPath[$path]['via'][] = (string) $displayNames[$id];
        }
        $testFiles = [];
        foreach ($byPath as $entry) {
            sort($entry['via'], SORT_STRING);
            $entry['via'] = array_slice(array_values(array_unique($entry['via'])), 0, 3);
            $testFiles[] = $entry;
        }
        usort($testFiles, static fn(array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: ($a['path'] <=> $b['path']));
        $truncated = $impact->truncated || count($testFiles) > $limit;
        $testFiles = array_slice($testFiles, 0, $limit);
        $warnings = [
            ...$impact->warnings,
            'Test impact is a static lower bound: run these first, not only these. Data-driven tests, fixtures, and glob-only discovery are not visible to the graph, and the per-component dependant scan is bounded.',
        ];

        return new ResultEnvelope(
            $projectId,
            $impact->snapshotId,
            sprintf('%d test file%s statically exercise the change.', count($testFiles), count($testFiles) === 1 ? '' : 's'),
            [
                'changed_files' => $impact->data['changed_files'],
                'unresolved_files' => $impact->data['unresolved_files'],
                'test_files' => $testFiles,
                // `limit` is what the caller asked for; `impacted_scan_limit` is
                // how wide the search actually ran, so a caller can tell a genuine
                // "nothing found" from a bounded one.
                'bounds' => array_merge($impact->data['bounds'], [
                    'limit' => $limit,
                    'impacted_scan_limit' => self::TEST_IMPACT_SCAN_LIMIT,
                ]),
            ],
            array_slice(array_map(static fn(array $entry): array => ['path' => $entry['path'], 'start_line' => null, 'end_line' => null], $testFiles), 0, self::MAX_EVIDENCE),
            array_values(array_unique($warnings)),
            $truncated,
        );
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
        foreach (array_chunk($nodeIds, 500) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $placeholders = implode(',', array_fill(0, count($chunk), '?'));
            $statement = $this->pdo->prepare(
                'SELECT n.id, f.relative_path FROM nodes n JOIN files f ON f.id = n.file_id ' .
                sprintf('WHERE n.id IN (%s) ORDER BY n.id', $placeholders),
            );
            $statement->execute($chunk);
            foreach ($statement->fetchAll() as $row) {
                $paths[$row['id']] = $row['relative_path'];
            }
        }
        return $paths;
    }
}
