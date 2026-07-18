<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Query\ResultEnvelope;

final class QueryCommand implements CliCommand
{
    private const COMMANDS = [
        'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
        'find-component', 'inspect-component', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
        'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
        'changed-files-impact', 'architecture-context', 'export-diagram', 'list-boundaries', 'search-architecture',
    ];

    public function supports(string $command): bool
    {
        return in_array($command, self::COMMANDS, true);
    }

    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        return match ($command) {
            'list-projects' => $this->listProjects($options, $context),
            'list-snapshots' => $this->listSnapshots($positionals, $options, $context),
            'snapshot-diff' => $this->snapshotDiff($positionals, $options, $context),
            'quality-gate' => $this->qualityGate($positionals, $options, $context),
            'architecture-trends' => $this->architectureTrends($positionals, $options, $context),
            'find-component' => $this->findComponent($positionals, $options, $context),
            'inspect-component' => $this->inspectComponent($positionals, $options, $context),
            'architecture-summary' => $this->architectureSummary($positionals, $options, $context),
            'file-metrics' => $this->fileMetrics($positionals, $options, $context),
            'explain-flow' => $this->explainFlow($positionals, $options, $context),
            'impact-analysis' => $this->impactAnalysis($positionals, $options, $context),
            'dependency-cycles' => $this->dependencyCycles($positionals, $options, $context),
            'architecture-health' => $this->architectureHealth($positionals, $options, $context),
            'check-architecture' => $this->checkArchitecture($positionals, $options, $context),
            'suggest-location' => $this->suggestLocation($positionals, $options, $context),
            'change-impact' => $this->changeImpact($positionals, $options, $context),
            'changed-files-impact' => $this->changedFilesImpact($positionals, $options, $context),
            'architecture-context' => $this->architectureContext($positionals, $options, $context),
            'export-diagram' => $this->exportDiagram($positionals, $options, $context),
            'list-boundaries' => $this->listBoundaries($positionals, $options, $context),
            default => $this->searchArchitecture($positionals, $options, $context),
        };
    }

    /** @param array<string, list<string>> $options */
    private function listProjects(array $options, CliCommandContext $context): int
    {
        $result = $this->queries($context)->listProjects(
            $context->options->integer($options, 'limit', 50, 1, 100),
            $context->options->integer($options, 'offset', 0, 0, 100_000),
            isset($options['include-roots']),
        );
        $text = $result->summary;
        foreach ($result->data['projects'] as $project) {
            $text .= sprintf(
                "\n%s  %s  %s  files=%d nodes=%d edges=%d%s",
                $project['id'],
                $project['name'],
                $project['freshness'],
                $project['counts']['files'],
                $project['counts']['nodes'],
                $project['counts']['edges'],
                isset($project['root']) ? '  root=' . $project['root'] : '',
            );
        }
        $context->output($result->jsonSerialize(), isset($options['json']), $text);
        return 0;
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function listSnapshots(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos list-snapshots <project-id> [--limit=N] [--offset=N]');
        $result = $this->queries($c)->listSnapshots($project, $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function snapshotDiff(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos snapshot-diff <project-id> <from-snapshot> [to-snapshot]');
        $from = $p[1] ?? throw new InvalidArgumentException('A source snapshot is required.');
        $result = $this->queries($c)->snapshotDiff($project, $from, $p[2] ?? 'active', $c->options->integer($o, 'max-changes', 200, 1, 1000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function qualityGate(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos quality-gate <project-id> <baseline-snapshot> --budgets=FILE');
        $baseline = $p[1] ?? throw new InvalidArgumentException('A baseline snapshot is required.');
        $budget = $c->options->single($o, 'budgets') ?? throw new InvalidArgumentException('--budgets=FILE is required.');
        $policies = $c->options->single($o, 'policies');
        $result = $this->queries($c)->qualityGate($project, $baseline, $c->input->jsonObject($budget), $policies === null ? [] : $c->input->policies($policies), isset($o['sarif']), isset($o['propose-baseline']));
        $c->output($result->jsonSerialize(), isset($o['json']), $result->summary);
        return $result->data['passed'] ? 0 : 1;
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function architectureTrends(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-trends <project-id> [options]');
        $result = $this->queries($c)->architectureTrends($project, $c->options->integer($o, 'limit', 10, 2, 20), $c->options->single($o, 'release-from'));
        $c->output($result->jsonSerialize(), isset($o['json']), $result->data['release_notes']['markdown'] ?? $result->summary);
        return 0;
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function findComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos find-component <project-id> <name> [--limit=N] [--json]');
        $name = $p[1] ?? throw new InvalidArgumentException('A component name is required.');
        return $this->result($this->queries($c)->findComponent($project, $name, $c->options->integer($o, 'limit', 20, 1, 100)), $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function inspectComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos inspect-component <project-id> <component> [options]');
        $component = $p[1] ?? throw new InvalidArgumentException('A component ID or name is required.');
        $result = $this->queries($c)->inspectComponent($project, $component, $c->options->integer($o, 'max-relationships', 25, 1, 100), $c->options->integer($o, 'max-children', 25, 1, 100), $c->options->single($o, 'min-confidence') ?? 'possible');
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function architectureSummary(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-summary <project-id> [--json]');
        $result = $this->queries($c)->architectureSummary($project, $c->options->integer($o, 'limit', 50, 1, 100));
        $c->output($result->jsonSerialize(), isset($o['json']), $result->summary);
        $c->output($result->jsonSerialize(), isset($o['json']), $result->summary);
        return 0;
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function explainFlow(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos explain-flow <project-id> <from> <to> [options]');
        $from = $p[1] ?? throw new InvalidArgumentException('A flow source is required.');
        $to = $p[2] ?? throw new InvalidArgumentException('A flow target is required.');
        $result = $this->queries($c)->explainFlow($project, $from, $to, $c->options->integer($o, 'max-depth', 6, 1, 8), $c->options->integer($o, 'max-paths', 5, 1, 20), $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function impactAnalysis(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos impact-analysis <project-id> <symbol> [options]');
        $symbol = $p[1] ?? throw new InvalidArgumentException('An impact target is required.');
        $result = $this->queries($c)->impactAnalysis($project, $symbol, $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function dependencyCycles(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos dependency-cycles <project-id> [options]');
        $result = $this->queries($c)->dependencyCycles($project, $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'max-nodes', 10_000, 1, 50_000), $c->options->integer($o, 'max-edges', 20_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function architectureHealth(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-health <project-id> [options]');
        $result = $this->queries($c)->architectureHealth($project, $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'max-nodes', 10_000, 1, 50_000), $c->options->integer($o, 'max-edges', 20_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function checkArchitecture(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos check-architecture <project-id> --policies=FILE [options]');
        $path = $c->options->single($o, 'policies') ?? throw new InvalidArgumentException('--policies=FILE is required.');
        $result = $this->queries($c)->checkArchitecture($project, $c->input->policies($path), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 100, 1, 100), $c->options->integer($o, 'max-edges', 20_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function suggestLocation(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos suggest-location <project-id> <feature-description> [options]');
        $description = $p[1] ?? throw new InvalidArgumentException('A feature description is required.');
        $result = $this->queries($c)->suggestLocation($project, $description, $c->options->integer($o, 'limit', 5, 1, 20), $c->options->integer($o, 'max-members', 20_000, 1, 50_000), $c->options->integer($o, 'max-edges', 20_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function changeImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos change-impact <project-id> <symbol> [options]');
        $symbol = $p[1] ?? throw new InvalidArgumentException('An impact target is required.');
        $queries = new ArchitectureQueryService($c->database(), gitHistory: new ProcessGitHistoryProvider());
        $result = $queries->changeImpact($project, $symbol, $c->options->integer($o, 'since-days', 90, 1, 3650), $c->options->integer($o, 'max-commits', 500, 1, 5000), $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function changedFilesImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos changed-files-impact <project-id> [files...] [options]');
        $queries = new ArchitectureQueryService($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider());
        $result = $queries->changedFilesImpact($project, array_slice($p, 1), isset($o['working-tree']), $c->options->single($o, 'base-ref'), $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function architectureContext(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-context <project-id> [files...] --task=TEXT [options]');
        $result = $this->queries($c)->architectureContext($project, $c->options->single($o, 'task') ?? '', array_slice($p, 1), $c->options->integer($o, 'max-chars', 30_000, 4000, 100_000), $c->options->integer($o, 'timeout-ms', 1500, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function exportDiagram(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos export-diagram <project-id> [options]');
        $result = $this->queries($c)->exportDiagram($project, $c->options->single($o, 'format') ?? 'mermaid', $c->options->single($o, 'boundary'), $o['edge-kind'] ?? [], $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->single($o, 'direction') ?? 'LR', $c->options->integer($o, 'max-nodes', 200, 1, 400), $c->options->integer($o, 'max-edges', 500, 1, 1000));
        $c->output($result->jsonSerialize(), isset($o['json']), $result->data['diagram']);
        return 0;
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function listBoundaries(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos list-boundaries <project-id> [options]');
        $result = $this->queries($c)->listBoundaries($project, $c->options->single($o, 'source'), $c->options->integer($o, 'limit', 50, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function searchArchitecture(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos search-architecture <project-id> <query> [options]');
        $query = $p[1] ?? throw new InvalidArgumentException('A search query is required.');
        $result = $this->queries($c)->searchArchitecture($project, $query, $o['kind'] ?? [], $o['role'] ?? [], $o['boundary'] ?? [], $o['confidence'] ?? [], $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }

    /** @param list<string> $p @param array<string, list<string>> $o */
    private function fileMetrics(array $p, array $o, CliCommandContext $c): int
    {
        $project = $p[0] ?? throw new InvalidArgumentException('Usage: knossos file-metrics <project-id> [--path=SUBSTR] [--language=LANG] [--sort-by=path|line_count] [--order=asc|desc] [--limit=N] [--offset=N]');
        $result = $this->queries($c)->fileMetrics(
            $project,
            $c->options->single($o, 'path'),
            $c->options->single($o, 'language'),
            $c->options->single($o, 'sort-by') ?? 'line_count',
            $c->options->single($o, 'order') ?? 'desc',
            $c->options->integer($o, 'limit', 50, 1, 100),
            $c->options->integer($o, 'offset', 0, 0, 100_000),
        );
        return $this->result($result, $o, $c);
    }

    private function queries(CliCommandContext $context): ArchitectureQueryService
    {
        return new ArchitectureQueryService($context->database());
    }

    /** @param array<string, list<string>> $options */
    private function result(ResultEnvelope $result, array $options, CliCommandContext $context): int
    {
        $context->output($result->jsonSerialize(), isset($options['json']), $result->summary);
        return 0;
    }
}
