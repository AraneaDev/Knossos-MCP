<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use Knossos\Configuration\ProjectConfigurationLoader;
use PDO;
use Throwable;

/**
 * One-call architectural review of a change set: blast radius, boundary
 * policy violations touching the change, quality-gate delta, and cycles the
 * change participates in. Sections degrade to not_evaluated (with a reason)
 * instead of failing the whole call — a review with partial signal beats an
 * error.
 */
final readonly class ReviewDiffService extends AbstractArchitectureQueryService
{
    public function __construct(
        PDO $pdo,
        ?Closure $clock,
        private ChangeImpactQueryService $changeQueries,
        private ArchitecturePolicyQueryService $policyQueries,
        private ProjectCatalogQueryService $catalogQueries,
        private GraphTopologyQueryService $topologyQueries,
    ) {
        parent::__construct($pdo, $clock);
    }

    /**
     * @param list<string> $files
     * @param list<array<string, mixed>>|null $policies
     * @param array<string, int>|null $budgets
     */
    public function reviewDiff(string $projectId, ?string $baseRef = null, array $files = [], ?array $policies = null, ?array $budgets = null, ?string $baselineSnapshot = null, int $maxDepth = 4, int $limit = 100, string $minConfidence = 'possible', int $timeoutMs = 1000): ResultEnvelope
    {
        if ($files !== [] && $baseRef !== null) {
            throw new InvalidArgumentException('Provide either files or base_ref, not both.');
        }
        $project = $this->project($projectId);
        $change = $this->changeQueries->changedFilesImpact(
            $projectId,
            $files,
            $files === [],
            $files === [] ? $baseRef : null,
            $maxDepth,
            $limit,
            [],
            $minConfidence,
            $timeoutMs,
        );
        $touched = [];
        foreach ($change->data['direct_components'] as $component) {
            $touched[$component['id']] = true;
        }
        foreach ($change->data['impacted_components'] as $record) {
            $touched[$record['node']['id']] = true;
        }
        $warnings = $change->warnings;
        $truncated = $change->truncated;

        [$policies, $budgets, $configReason] = $this->withProjectConfig($project, $policies, $budgets);

        $policyCheck = ['status' => 'not_evaluated', 'reason' => $configReason ?? 'No boundary policies declared in knossos.json or supplied.'];
        if ($policies !== []) {
            try {
                $check = $this->policyQueries->checkArchitecture($projectId, $policies, $minConfidence, 100, 20_000, $timeoutMs);
                $touchingViolations = array_values(array_filter(
                    $check->data['violations'],
                    static fn(array $violation): bool => isset($touched[$violation['source']['id']]) || isset($touched[$violation['target']['id']]),
                ));
                $policyCheck = [
                    'status' => 'evaluated',
                    'policies_evaluated' => count($check->data['policies_evaluated']),
                    'total_violations' => count($check->data['violations']),
                    'violations_touching_change' => $touchingViolations,
                ];
                $warnings = [...$warnings, ...$check->warnings];
                $truncated = $truncated || $check->truncated;
            } catch (InvalidArgumentException $error) {
                $policyCheck = ['status' => 'not_evaluated', 'reason' => 'Policy check failed: ' . $error->getMessage()];
            }
        }

        $qualityGate = ['status' => 'not_evaluated', 'reason' => $configReason ?? 'No quality budgets declared in knossos.json or supplied.'];
        if ($budgets !== []) {
            $baselineSnapshot ??= $this->latestNonActiveSnapshot($projectId, (string) $project['active_scan_id']);
            if ($baselineSnapshot === null) {
                $qualityGate = ['status' => 'not_evaluated', 'reason' => 'No retained snapshot other than the active scan; scan again after the next change to establish a baseline.'];
            } else {
                try {
                    $gate = $this->catalogQueries->qualityGate($projectId, $baselineSnapshot, $budgets, $policies);
                    $qualityGate = [
                        'status' => 'evaluated',
                        'passed' => $gate->data['passed'],
                        'checks' => $gate->data['checks'],
                        'baseline_snapshot' => $gate->data['baseline_snapshot'],
                    ];
                    $warnings = [...$warnings, ...$gate->warnings];
                } catch (InvalidArgumentException $error) {
                    $qualityGate = ['status' => 'not_evaluated', 'reason' => 'Quality gate failed: ' . $error->getMessage()];
                }
            }
        }

        $cycleResult = $this->topologyQueries->dependencyCycles($projectId, [], $minConfidence, 100, 10_000, 20_000, $timeoutMs);
        $touchingCycles = array_values(array_filter(
            $cycleResult->data['cycles'],
            static function (array $cycle) use ($touched): bool {
                foreach ($cycle['members'] as $member) {
                    if (isset($touched[$member['id']])) {
                        return true;
                    }
                }
                return false;
            },
        ));
        $truncated = $truncated || $cycleResult->truncated;

        $summary = sprintf(
            '%d changed file%s, %d impacted component%s, %s, gate %s.',
            count($change->data['changed_files']),
            count($change->data['changed_files']) === 1 ? '' : 's',
            count($change->data['impacted_components']),
            count($change->data['impacted_components']) === 1 ? '' : 's',
            $policyCheck['status'] === 'evaluated'
                ? sprintf('%d policy violation%s touching the change', count($policyCheck['violations_touching_change']), count($policyCheck['violations_touching_change']) === 1 ? '' : 's')
                : 'policies not evaluated',
            $qualityGate['status'] === 'evaluated' ? ($qualityGate['passed'] ? 'passed' : 'FAILED') : 'not evaluated',
        );

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            $summary,
            [
                'change' => ['status' => 'evaluated'] + array_intersect_key($change->data, array_flip(['changed_files', 'unresolved_files', 'direct_components', 'impacted_components', 'git'])),
                'policy_check' => $policyCheck,
                'quality_gate' => $qualityGate,
                'cycles_touching_change' => ['status' => 'evaluated', 'cycles' => $touchingCycles],
                'bounds' => $change->data['bounds'] + ['cycle_scan_limit' => 100],
            ],
            $change->evidence,
            array_values(array_unique($warnings)),
            $truncated,
        );
    }

    /**
     * @param array<string, mixed> $project
     * @param list<array<string, mixed>>|null $policies
     * @param array<string, int>|null $budgets
     * @return array{0: list<array<string, mixed>>, 1: array<string, int>, 2: ?string}
     */
    private function withProjectConfig(array $project, ?array $policies, ?array $budgets): array
    {
        if ($policies !== null && $budgets !== null) {
            return [$policies, $budgets, null];
        }
        $reason = null;
        try {
            $root = (string) $project['root_realpath'];
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
            $policies ??= $configuration->policies;
            $budgets ??= $configuration->qualityBudgets;
        } catch (Throwable $error) {
            $reason = 'Project configuration unavailable: ' . substr($error->getMessage(), 0, 300);
            $policies ??= [];
            $budgets ??= [];
        }
        return [$policies, $budgets, $reason];
    }

    private function latestNonActiveSnapshot(string $projectId, string $activeScanId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT scan_id FROM scan_snapshots WHERE project_id = :project AND scan_id <> :active ' .
            'ORDER BY captured_at DESC, rowid DESC LIMIT 1',
        );
        $statement->execute(['project' => $projectId, 'active' => $activeScanId]);
        $scanId = $statement->fetchColumn();
        return is_string($scanId) ? $scanId : null;
    }
}
