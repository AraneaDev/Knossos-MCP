<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

final readonly class ArchitectureContextService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private GraphTopologyQueryService $topologyQueries, private ChangeImpactQueryService $changeQueries, private ComponentQueryService $componentQueries, private ArchitecturePolicyQueryService $policyQueries)
    {
        parent::__construct($pdo, $clock);
    }

    /** @param list<string> $files */
    public function architectureContext(string $projectId, string $taskDescription = '', array $files = [], int $maxChars = 30_000, int $timeoutMs = 1500): ResultEnvelope
    {
        $project = $this->project($projectId);
        $taskDescription = trim($taskDescription);
        if ($taskDescription === '' && $files === []) {
            throw new InvalidArgumentException('Provide task_description, files, or both.');
        }
        if (strlen($taskDescription) > 2000) {
            throw new InvalidArgumentException('task_description must not exceed 2000 bytes.');
        }
        if ($maxChars < 4000 || $maxChars > 100_000) {
            throw new InvalidArgumentException('max_chars must be between 4000 and 100000.');
        }
        if ($timeoutMs < 1 || $timeoutMs > 5000) {
            throw new InvalidArgumentException('timeout_ms must be between 1 and 5000.');
        }
        $allocations = [
            'summary' => (int) floor($maxChars * 0.20),
            'locations' => (int) floor($maxChars * 0.20),
            'change_impact' => (int) floor($maxChars * 0.30),
            'dossiers' => $maxChars - (int) floor($maxChars * 0.70),
        ];
        $summary = $this->topologyQueries->architectureSummary($projectId, 10);
        $locations = $taskDescription === '' ? null : $this->policyQueries->suggestLocation(
            $projectId,
            $taskDescription,
            3,
            5000,
            5000,
            $timeoutMs,
        );
        $change = $files === [] ? null : $this->changeQueries->changedFilesImpact(
            $projectId,
            $files,
            maxDepth: 3,
            limit: 25,
            timeoutMs: $timeoutMs,
        );
        $dossierIds = [];
        foreach ($change?->data['direct_components'] ?? [] as $component) {
            $dossierIds[$component['id']] = true;
        }
        foreach ($locations?->data['candidates'] ?? [] as $candidate) {
            foreach ($candidate['related_members'] ?? [] as $component) {
                $dossierIds[$component['id']] = true;
            }
        }
        $dossiers = [];
        foreach (array_slice(array_keys($dossierIds), 0, 3) as $componentId) {
            $dossiers[] = $this->componentQueries->inspectComponent($projectId, $componentId, 5, 5)->jsonSerialize();
        }
        $sections = [
            'summary' => $this->fitContextSection($summary->jsonSerialize(), $allocations['summary']),
            'locations' => $locations === null ? ['status' => 'not_requested'] : $this->fitContextSection($locations->jsonSerialize(), $allocations['locations']),
            'change_impact' => $change === null ? ['status' => 'not_requested'] : $this->fitContextSection($change->jsonSerialize(), $allocations['change_impact']),
            'dossiers' => $this->fitContextSection(['items' => $dossiers], $allocations['dossiers']),
        ];
        $context = [
            'task_description' => $taskDescription === '' ? null : $taskDescription,
            'files' => $files,
            'sections' => $sections,
        ];
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['dossiers', 'locations', 'change_impact'] as $sectionName) {
            if (strlen($encoded) <= $maxChars) {
                break;
            }
            $context['sections'][$sectionName] = ['status' => 'omitted', 'reason' => 'total_budget'];
            $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        $truncated = str_contains(json_encode($context, JSON_THROW_ON_ERROR), '"status":"truncated"')
            || str_contains(json_encode($context, JSON_THROW_ON_ERROR), '"status":"omitted"');

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Built bounded architecture context for %s.', $taskDescription === '' ? implode(', ', $files) : $taskDescription),
            ['context' => $context, 'budget' => [
                'max_chars' => $maxChars, 'actual_chars' => strlen($encoded), 'allocations' => $allocations,
                'dossier_candidates' => count($dossierIds), 'dossiers_included' => count($dossiers),
            ]],
            [],
            ['Context sections are bounded static evidence and may omit dynamic runtime behavior.'],
            $truncated,
        );
    }

    /** @param array<string, mixed> $section @return array<string, mixed> */
    private function fitContextSection(array $section, int $budget): array
    {
        $encoded = json_encode($section, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if (strlen($encoded) <= $budget) {
            return ['status' => 'included', 'chars' => strlen($encoded), 'content' => $section];
        }
        $data = is_array($section['data'] ?? null) ? $section['data'] : $section;
        return [
            'status' => 'truncated',
            'reason' => 'section_budget',
            'original_chars' => strlen($encoded),
            'summary' => is_string($section['summary'] ?? null) ? $section['summary'] : null,
            'available_keys' => array_keys($data),
        ];
    }
}
