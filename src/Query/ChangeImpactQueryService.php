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

final readonly class ChangeImpactQueryService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private GraphTopologyQueryService $topologyQueries, private ?GitHistoryProvider $gitHistory = null, private ?GitWorkingTreeProvider $gitWorkingTree = null)
    {
        parent::__construct($pdo, $clock);
    }

    /** @param list<string> $edgeKinds */
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
            return new ResultEnvelope(
                $projectId,
                $project['active_scan_id'],
                'Change-aware impact requires one unambiguous component.',
                ['impact' => $impact->data, 'git' => ['available' => false, 'reason' => 'ambiguous_target'], 'risk_ranking' => []],
                $impact->evidence,
                $impact->warnings,
                $impact->truncated,
            );
        }
        $components = [$target['id'] => ['node' => $target, 'distance' => 0, 'path_confidence' => 'certain']];
        foreach ($impact->data['by_distance'] ?? [] as $group) {
            foreach ($group['dependants'] as $record) {
                $components[$record['node']['id']] = [
                    'node' => $record['node'], 'distance' => $record['distance'], 'path_confidence' => $record['path_confidence'],
                ];
            }
        }
        $paths = $this->nodePaths(array_keys($components));
        $gitMetadata = ['available' => false, 'reason' => 'provider_unavailable', 'since_days' => $sinceDays, 'max_commits' => $maxCommits];
        $history = ['files' => [], 'commits_examined' => 0, 'truncated' => false];
        $warnings = $impact->warnings;
        if ($this->gitHistory !== null) {
            try {
                $history = $this->gitHistory->history($project['root_realpath'], $sinceDays, $maxCommits, $timeoutMs);
                $gitMetadata = [
                    'available' => true, 'reason' => null, 'since_days' => $sinceDays, 'max_commits' => $maxCommits,
                    'commits_examined' => $history['commits_examined'], 'truncated' => $history['truncated'],
                ];
            } catch (Throwable $error) {
                $gitMetadata['reason'] = substr($error->getMessage(), 0, 500);
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
            $staticWeight = max(0, $maxDepth + 1 - $component['distance']);
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

    /** @param list<string> $files @param list<string> $edgeKinds */
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
        if (count($files) > 50) {
            throw new InvalidArgumentException('files must contain at most 50 paths.');
        }
        $git = ['used' => false, 'base_ref' => $baseRef, 'renames' => [], 'truncated' => false];
        if ($workingTree) {
            if ($this->gitWorkingTree === null) {
                throw new InvalidArgumentException('Working-tree change discovery is unavailable.');
            }
            try {
                $changes = $this->gitWorkingTree->changes($project['root_realpath'], $baseRef, 50, $timeoutMs);
            } catch (Throwable $error) {
                throw new InvalidArgumentException('Working-tree change discovery failed: ' . substr($error->getMessage(), 0, 500), previous: $error);
            }
            $files = $changes['paths'];
            $git = ['used' => true, 'base_ref' => $baseRef, 'renames' => $changes['renames'], 'truncated' => $changes['truncated']];
        } elseif ($baseRef !== null) {
            throw new InvalidArgumentException('base_ref requires working_tree.');
        }
        $normalized = [];
        foreach ($files as $path) {
            if (!is_string($path)) {
                throw new InvalidArgumentException('files must contain project-relative strings.');
            }
            RelativePath::assertValid($path, 'Changed file');
            $normalized[$path] = true;
        }
        $files = array_keys($normalized);
        sort($files, SORT_STRING);
        $direct = [];
        if ($files !== []) {
            $placeholders = implode(',', array_fill(0, count($files), '?'));
            $statement = $this->pdo->prepare(
                'SELECT n.id, n.kind, n.canonical_name, n.display_name, n.confidence, f.relative_path, n.start_line, n.end_line ' .
                'FROM nodes n JOIN files f ON f.id = n.file_id WHERE n.project_id = ? AND f.relative_path IN (' . $placeholders . ') ' .
                'ORDER BY f.relative_path, n.canonical_name, n.id LIMIT 1001',
            );
            $statement->execute([$projectId, ...$files]);
            $direct = $statement->fetchAll();
        }
        $resolvedPaths = array_fill_keys(array_column($direct, 'relative_path'), true);
        $unresolved = array_values(array_filter($files, static fn(string $path): bool => !isset($resolvedPaths[$path])));
        $impacted = [];
        $entryPoints = [];
        $warnings = [];
        $truncated = $git['truncated'] || count($direct) > 1000;
        // One deadline shared across the whole fan-out bounds the entire request,
        // instead of each per-component analysis resetting its own timeout (which
        // could otherwise multiply into minutes of wall time for a single call).
        $deadline = $this->now() + ($timeoutMs * 1_000_000);
        foreach (array_slice($direct, 0, 1000) as $node) {
            $impact = $this->topologyQueries->impactAnalysis($projectId, $node['id'], $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs, $deadline);
            foreach ($impact->data['by_distance'] ?? [] as $group) {
                foreach ($group['dependants'] as $record) {
                    $id = $record['node']['id'];
                    if (!isset($impacted[$id]) || $record['distance'] < $impacted[$id]['distance']) {
                        $impacted[$id] = $record;
                    }
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
        ], array_slice($direct, 0, 100));

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Mapped %d changed file%s to %d direct and %d impacted component%s.', count($files), count($files) === 1 ? '' : 's', count($direct), count($impacted), count($impacted) === 1 ? '' : 's'),
            ['changed_files' => $files, 'unresolved_files' => $unresolved, 'direct_components' => array_slice($direct, 0, 1000),
                'impacted_components' => $impacted, 'entry_points' => array_values($entryPoints), 'git' => $git,
                'bounds' => ['max_files' => 50, 'max_direct_components' => 1000, 'limit' => $limit, 'max_depth' => $maxDepth]],
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
        $impact = $this->changedFilesImpact($projectId, $files, $workingTree, $baseRef, $maxDepth, $limit, $edgeKinds, $minConfidence, $timeoutMs);
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
                'bounds' => $impact->data['bounds'] + ['impacted_scan_limit' => $limit],
            ],
            array_slice(array_map(static fn(array $entry): array => ['path' => $entry['path'], 'start_line' => null, 'end_line' => null], $testFiles), 0, 100),
            array_values(array_unique($warnings)),
            $impact->truncated,
        );
    }

    /** @param list<string> $nodeIds @return array<string, string> */
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
