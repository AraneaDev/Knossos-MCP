<?php

declare(strict_types=1);

namespace Knossos\Query;

use Closure;
use InvalidArgumentException;
use PDO;

/**
 * Assembles a bounded evidence bundle for one coding task.
 *
 * Built for a caller with a context budget: it selects what is relevant to the
 * task and stops at a byte ceiling, reporting what it omitted rather than
 * truncating quietly. Optionally includes short source excerpts, read through the
 * same root guard as scanning.
 */
final readonly class ArchitectureContextService extends AbstractArchitectureQueryService
{
    public function __construct(PDO $pdo, ?Closure $clock, private GraphTopologyQueryService $topologyQueries, private ChangeImpactQueryService $changeQueries, private ComponentQueryService $componentQueries, private LocationSuggestionService $locationQueries)
    {
        parent::__construct($pdo, $clock);
    }

    /**
     * A bounded, task-shaped evidence bundle for one coding task.
     *
     * @param list<string> $files
     */
    public function architectureContext(string $projectId, string $taskDescription = '', array $files = [], int $maxChars = 30_000, int $timeoutMs = 1500, bool $includeSource = false): ResultEnvelope
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
        $locations = $taskDescription === '' ? null : $this->locationQueries->suggestLocation(
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
        $reader = $includeSource ? new SourceExcerptReader() : null;
        foreach (array_slice(array_keys($dossierIds), 0, 3) as $componentId) {
            $dossier = $this->componentQueries->inspectComponent($projectId, $componentId, 5, 5)->jsonSerialize();
            if ($reader !== null) {
                $evidence = $dossier['evidence'][0] ?? null;
                $dossier['snippet'] = $reader->read(
                    (string) $project['root_realpath'],
                    is_array($evidence) ? ($evidence['path'] ?? null) : null,
                    is_array($evidence) ? ($evidence['start_line'] ?? null) : null,
                    is_array($evidence) ? ($evidence['end_line'] ?? null) : null,
                );
            }
            $dossiers[] = $dossier;
        }
        $payloads = [
            'summary' => $summary->jsonSerialize(),
            'locations' => $locations?->jsonSerialize(),
            'change_impact' => $change?->jsonSerialize(),
            'dossiers' => ['items' => $dossiers],
        ];
        $sections = [];
        foreach ($payloads as $name => $payload) {
            $sections[$name] = $payload === null
                ? ['status' => 'not_requested']
                : $this->fitContextSection($payload, $allocations[$name]);
        }
        $context = [
            'task_description' => $taskDescription === '' ? null : $taskDescription,
            'files' => $files,
            'sections' => $sections,
        ];
        // A section is fitted against its own share first, so the proportions
        // hold whenever everything fits. What the fixed shares got wrong was the
        // case where they do not: a section over its slice was dropped whole
        // while the budget its neighbours left unspent went nowhere, so a caller
        // asking for 12,000 characters could be handed 2,481 with three of four
        // sections empty. Anything still dropped is offered the remainder, most
        // task-specific section first. The remainder is measured against the
        // whole context, not just the sections, so reclaiming can never overrun
        // the total and force the backstop below to drop a section that fitted.
        foreach (['change_impact', 'locations', 'dossiers'] as $name) {
            if (($context['sections'][$name]['status'] ?? null) !== 'truncated') {
                continue;
            }
            $spare = $maxChars - self::encodedLength($context);
            if ($spare <= $allocations[$name]) {
                continue;
            }
            $reclaimed = $this->fitContextSection($payloads[$name], $spare);
            $candidate = $context;
            $candidate['sections'][$name] = $reclaimed;
            if (self::encodedLength($candidate) <= $maxChars) {
                $context = $candidate;
            }
        }
        $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        foreach (['dossiers', 'locations', 'change_impact'] as $sectionName) {
            if (strlen($encoded) <= $maxChars) {
                break;
            }
            $context['sections'][$sectionName] = ['status' => 'omitted', 'reason' => 'total_budget'];
            $encoded = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        }
        // Track truncation structurally from each section's own status rather
        // than substring-matching serialized JSON, which a scanned attribute
        // containing "status":"truncated" would otherwise trip.
        $truncated = false;
        foreach ($context['sections'] as $section) {
            if (in_array($section['status'] ?? null, ['truncated', 'omitted'], true)) {
                $truncated = true;
                break;
            }
        }

        return new ResultEnvelope(
            $projectId,
            $project['active_scan_id'],
            sprintf('Built bounded architecture context for %s.', $taskDescription === '' ? implode(', ', $files) : $taskDescription),
            ['context' => $context, 'budget' => [
                'max_chars' => $maxChars, 'actual_chars' => strlen($encoded), 'allocations' => $allocations,
                'dossier_candidates' => count($dossierIds), 'dossiers_included' => count($dossiers),
            ]],
            [],
            $includeSource
                ? ['Context sections are bounded static evidence and may omit dynamic runtime behavior.', 'Source snippets are read from the working tree now and may differ from the scanned graph.']
                : ['Context sections are bounded static evidence and may omit dynamic runtime behavior.'],
            $truncated,
        );
    }

    /**
     * Serialized size of the context assembled so far, which is what the
     * remaining budget is measured against.
     *
     * @param array<string, mixed> $context
     */
    private static function encodedLength(array $context): int
    {
        return strlen(json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Fit one section inside the remaining byte budget, reporting what it dropped.
     *
     * @param array<string, mixed> $section @return array<string, mixed>
     */
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
