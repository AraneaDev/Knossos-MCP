<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectReference;
use Knossos\Git\ProcessGitHistoryProvider;
use Knossos\Git\ProcessGitWorkingTreeProvider;
use Knossos\Query\ArchitecturePolicyQueryService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Result\ResultEnvelope;

/**
 * Every read-only query as a CLI command.
 *
 * One command class for all of them because they share option parsing, envelope
 * rendering, and the `--json` contract; splitting them would duplicate that three
 * dozen times.
 */
final class QueryCommand implements CliCommand
{
    private const COMMANDS = [
        'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
        'find-component', 'inspect-component', 'list-usages', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
        'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
        'changed-files-impact', 'test-impact', 'review-diff', 'architecture-context', 'export-diagram', 'export-agent-brief', 'list-boundaries',
        'search-architecture', 'annotate-component', 'list-annotations',
    ];

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, self::COMMANDS, true);
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return match ($command) {
            'list-projects' => ['db', 'json', 'limit', 'offset', 'include-roots'],
            'list-snapshots' => ['db', 'json', 'limit', 'offset'],
            'snapshot-diff' => ['db', 'json', 'max-changes'],
            'quality-gate' => ['db', 'json', 'budgets', 'policies', 'sarif', 'propose-baseline'],
            'architecture-trends' => ['db', 'json', 'limit', 'release-from'],
            'find-component' => ['db', 'json', 'limit'],
            'inspect-component' => ['db', 'json', 'max-relationships', 'max-children', 'min-confidence'],
            'list-usages' => ['db', 'json', 'edge-kind', 'min-confidence', 'limit'],
            'architecture-summary' => ['db', 'json', 'limit'],
            'file-metrics' => ['db', 'json', 'path', 'language', 'sort-by', 'order', 'limit', 'offset'],
            'explain-flow' => ['db', 'json', 'max-depth', 'max-paths', 'edge-kind', 'min-confidence', 'timeout-ms'],
            'impact-analysis' => ['db', 'json', 'max-depth', 'limit', 'edge-kind', 'min-confidence', 'timeout-ms'],
            'dependency-cycles' => ['db', 'json', 'edge-kind', 'min-confidence', 'limit', 'max-nodes', 'max-edges', 'timeout-ms', 'include-self-loops'],
            'architecture-health' => ['db', 'json', 'edge-kind', 'min-confidence', 'limit', 'max-nodes', 'max-edges', 'timeout-ms', 'include-external', 'include-tests', 'candidate-confidence', 'candidate-offset', 'candidate-timeout'],
            'check-architecture' => ['db', 'json', 'policies', 'min-confidence', 'limit', 'max-edges', 'timeout-ms'],
            'suggest-location' => ['db', 'json', 'limit', 'max-members', 'max-edges', 'timeout-ms', 'ranking-mode'],
            'change-impact' => ['db', 'json', 'since-days', 'max-commits', 'max-depth', 'limit', 'edge-kind', 'min-confidence', 'timeout-ms'],
            'changed-files-impact' => ['db', 'json', 'working-tree', 'base-ref', 'max-depth', 'limit', 'edge-kind', 'min-confidence', 'timeout-ms'],
            'test-impact' => ['db', 'json', 'working-tree', 'base-ref', 'max-depth', 'limit', 'edge-kind', 'min-confidence', 'timeout-ms'],
            'review-diff' => ['db', 'json', 'base-ref', 'policies', 'budgets', 'baseline-snapshot', 'max-depth', 'limit', 'min-confidence', 'timeout-ms'],
            'architecture-context' => ['db', 'json', 'task', 'max-chars', 'timeout-ms', 'include-source'],
            'export-diagram' => ['db', 'json', 'format', 'boundary', 'edge-kind', 'min-confidence', 'direction', 'max-nodes', 'max-edges'],
            'export-agent-brief' => ['db', 'json', 'max-chars', 'out'],
            'list-boundaries' => ['db', 'json', 'source', 'limit', 'offset'],
            'annotate-component' => ['db', 'json', 'remove', 'execute'],
            'search-architecture' => ['db', 'json', 'kind', 'role', 'boundary', 'confidence', 'limit', 'offset'],
            'list-annotations' => ['db', 'json', 'component', 'kind', 'limit', 'offset'],
            // Unreachable through the router, which asks only for supported
            // commands: a new command without an arm fails its first test
            // instead of borrowing another command's list.
            default => throw new \LogicException(sprintf('No option allowlist for %s.', $command)),
        };
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        // Every command but list-projects names its project first: read the graph that holds it.
        if ($command !== 'list-projects' && isset($positionals[0])) {
            $context = $context->forTarget($positionals[0], $options);
        }
        return match ($command) {
            'list-projects' => $this->listProjects($options, $context),
            'list-snapshots' => $this->listSnapshots($positionals, $options, $context),
            'snapshot-diff' => $this->snapshotDiff($positionals, $options, $context),
            'quality-gate' => $this->qualityGate($positionals, $options, $context),
            'architecture-trends' => $this->architectureTrends($positionals, $options, $context),
            'find-component' => $this->findComponent($positionals, $options, $context),
            'inspect-component' => $this->inspectComponent($positionals, $options, $context),
            'list-usages' => $this->listUsages($positionals, $options, $context),
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
            'test-impact' => $this->testImpact($positionals, $options, $context),
            'review-diff' => $this->reviewDiff($positionals, $options, $context),
            'architecture-context' => $this->architectureContext($positionals, $options, $context),
            'export-diagram' => $this->exportDiagram($positionals, $options, $context),
            'export-agent-brief' => $this->exportAgentBrief($positionals, $options, $context),
            'list-boundaries' => $this->listBoundaries($positionals, $options, $context),
            'annotate-component' => $this->annotateComponent($positionals, $options, $context),
            'list-annotations' => $this->listAnnotations($positionals, $options, $context),
            default => $this->searchArchitecture($positionals, $options, $context),
        };
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::listProjects()}.
     *
     * @param array<string, list<string>> $options
     */
    private function listProjects(array $options, CliCommandContext $context): int
    {
        $result = $this->queries($context)->listProjects(
            $context->options->integer($options, 'limit', 50, 1, 100),
            $context->options->integer($options, 'offset', 0, 0, 100_000),
            $context->options->flag($options, 'include-roots'),
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
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $text);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::listSnapshots()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listSnapshots(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-snapshots <path|project-id> [--limit=N] [--offset=N]'), $c);
        $result = $this->queries($c)->listSnapshots($project, $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::snapshotDiff()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function snapshotDiff(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos snapshot-diff <path|project-id> <from-snapshot> [to-snapshot]'), $c);
        $from = $p[1] ?? throw new InvalidArgumentException('A source snapshot is required.');
        $result = $this->queries($c)->snapshotDiff($project, $from, $p[2] ?? 'active', $c->options->integer($o, 'max-changes', 25, 1, 1000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::qualityGate()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function qualityGate(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos quality-gate <path|project-id> <baseline-snapshot> --budgets=FILE'), $c);
        $baseline = $p[1] ?? throw new InvalidArgumentException('A baseline snapshot is required.');
        $budget = $c->options->single($o, 'budgets') ?? throw new InvalidArgumentException('--budgets=FILE is required.');
        $policies = $c->options->single($o, 'policies');
        $result = $this->queries($c)->qualityGate($project, $baseline, $c->input->jsonObject($budget), $policies === null ? [] : $c->input->policies($policies), $c->options->flag($o, 'sarif'), $c->options->flag($o, 'propose-baseline'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->summary);
        return $result->data['passed'] ? CliCommand::EXIT_OK : CliCommand::EXIT_GATE_FAILED;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::architectureTrends()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureTrends(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-trends <path|project-id> [options]'), $c);
        $result = $this->queries($c)->architectureTrends($project, $c->options->integer($o, 'limit', 10, 2, 20), $c->options->single($o, 'release-from'));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->data['release_notes']['markdown'] ?? $result->summary);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::findComponent()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function findComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos find-component <path|project-id> <name> [--limit=N] [--json]'), $c);
        $name = $p[1] ?? throw new InvalidArgumentException('A component name is required.');
        return $this->result($this->queries($c)->findComponent($project, $name, $c->options->integer($o, 'limit', 20, 1, 100)), $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::inspectComponent()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function inspectComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos inspect-component <path|project-id> <component> [options]'), $c);
        $component = $p[1] ?? throw new InvalidArgumentException('A component ID or name is required.');
        $result = $this->queries($c)->inspectComponent($project, $component, $c->options->integer($o, 'max-relationships', 25, 1, 100), $c->options->integer($o, 'max-children', 25, 1, 100), $c->options->single($o, 'min-confidence') ?? 'possible');
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::listUsages()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listUsages(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-usages <path|project-id> <symbol> [--edge-kind=K]... [--min-confidence=L] [--limit=N] [--json]'), $c);
        $symbol = $p[1] ?? throw new InvalidArgumentException('A symbol is required.');
        $result = $this->queries($c)->listUsages($project, $symbol, $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 100, 1, 500));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::architectureSummary()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureSummary(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-summary <path|project-id> [--json]'), $c);
        $result = $this->queries($c)->architectureSummary($project, $c->options->integer($o, 'limit', 50, 1, 100));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->summary);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::explainFlow()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function explainFlow(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos explain-flow <path|project-id> <from> <to> [options]'), $c);
        $from = $p[1] ?? throw new InvalidArgumentException('A flow source is required.');
        $to = $p[2] ?? throw new InvalidArgumentException('A flow target is required.');
        $result = $this->queries($c)->explainFlow($project, $from, $to, $c->options->integer($o, 'max-depth', 6, 1, 8), $c->options->integer($o, 'max-paths', 5, 1, 20), $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::impactAnalysis()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function impactAnalysis(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos impact-analysis <path|project-id> <symbol> [options]'), $c);
        $symbol = $p[1] ?? throw new InvalidArgumentException('An impact target is required.');
        $result = $this->queries($c)->impactAnalysis($project, $symbol, $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::dependencyCycles()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function dependencyCycles(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos dependency-cycles <path|project-id> [options]'), $c);
        $result = $this->queries($c)->dependencyCycles($project, $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'max-nodes', 50_000, 1, 50_000), $c->options->integer($o, 'max-edges', 100_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000), $c->options->flag($o, 'include-self-loops'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::architectureHealth()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureHealth(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-health <path|project-id> [options]'), $c);
        $result = $this->queries($c)->architectureHealth($project, $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'max-nodes', 50_000, 1, 50_000), $c->options->integer($o, 'max-edges', 100_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000), $c->options->flag($o, 'include-external'), $c->options->flag($o, 'include-tests'), $c->options->single($o, 'candidate-confidence') ?? 'possible', $c->options->integer($o, 'candidate-offset', 0, 0, 100_000), $c->options->integer($o, 'candidate-timeout', 5000, 1, 60_000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::checkArchitecture()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function checkArchitecture(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos check-architecture <path|project-id> [--policies=FILE] [options]'), $c);
        // Without a file, the policies the project declares in knossos.json.
        $path = $c->options->single($o, 'policies');
        $result = $this->queries($c)->checkArchitecture($project, $path === null ? null : $c->input->policies($path), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'limit', 100, 1, 100), $c->options->integer($o, 'max-edges', ArchitecturePolicyQueryService::DEFAULT_MAX_EDGES, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->summary);
        // Exit non-zero when declared-policy violations exist so the "check"
        // command can gate CI on its own result, mirroring quality-gate. The
        // authoritative count is the (possibly larger) bounds.violation_count.
        return ($result->data['bounds']['violation_count'] ?? count($result->data['violations'])) > 0 ? CliCommand::EXIT_GATE_FAILED : CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::suggestLocation()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function suggestLocation(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos suggest-location <path|project-id> <feature-description> [options]'), $c);
        $description = $p[1] ?? throw new InvalidArgumentException('A feature description is required.');
        $result = $this->queries($c)->suggestLocation($project, $description, $c->options->integer($o, 'limit', 5, 1, 20), $c->options->integer($o, 'max-members', 20_000, 1, 50_000), $c->options->integer($o, 'max-edges', 100_000, 1, 100_000), $c->options->integer($o, 'timeout-ms', 1000, 1, 5000), $c->options->single($o, 'ranking-mode') ?? 'deterministic');
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the Git-weighted blast radius.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function changeImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos change-impact <path|project-id> <symbol> [options]'), $c);
        $symbol = $p[1] ?? throw new InvalidArgumentException('An impact target is required.');
        $queries = new ArchitectureQueryService($c->database(), gitHistory: new ProcessGitHistoryProvider());
        $result = $queries->changeImpact($project, $symbol, $c->options->integer($o, 'since-days', 90, 1, 3650), $c->options->integer($o, 'max-commits', 500, 1, 5000), $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the impact of a changed file set.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function changedFilesImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos changed-files-impact <path|project-id> [files...] [options]'), $c);
        $queries = new ArchitectureQueryService($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider());
        $result = $queries->changedFilesImpact($project, array_slice($p, 1), $c->options->flag($o, 'working-tree'), $c->options->single($o, 'base-ref'), $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the tests exercising a change.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function testImpact(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos test-impact <path|project-id> [files...] [options]'), $c);
        $queries = new ArchitectureQueryService($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider());
        $result = $queries->testImpact($project, array_slice($p, 1), $c->options->flag($o, 'working-tree'), $c->options->single($o, 'base-ref'), $c->options->integer($o, 'max-depth', 4, 1, 8), $c->options->integer($o, 'limit', 100, 1, 100), $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->integer($o, 'timeout-ms', 1000, 1, 5000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders the one-call architectural review.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function reviewDiff(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos review-diff <path|project-id> [FILE...] [options]'), $c);
        $policies = $c->options->single($o, 'policies');
        $budgets = $c->options->single($o, 'budgets');
        $queries = new ArchitectureQueryService($c->database(), gitWorkingTree: new ProcessGitWorkingTreeProvider());
        $result = $queries->reviewDiff(
            $project,
            $c->options->single($o, 'base-ref'),
            array_slice($p, 1),
            $policies === null ? null : $c->input->policies($policies),
            $budgets === null ? null : $c->input->jsonObject($budgets),
            $c->options->single($o, 'baseline-snapshot'),
            $c->options->integer($o, 'max-depth', 4, 1, 8),
            $c->options->integer($o, 'limit', 100, 1, 100),
            $c->options->single($o, 'min-confidence') ?? 'possible',
            $c->options->integer($o, 'timeout-ms', 1000, 1, 5000),
        );
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::architectureContext()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function architectureContext(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos architecture-context <path|project-id> [files...] --task=TEXT [options]'), $c);
        $result = $this->queries($c)->architectureContext($project, $c->options->single($o, 'task') ?? '', array_slice($p, 1), $c->options->integer($o, 'max-chars', 30_000, 4000, 100_000), $c->options->integer($o, 'timeout-ms', 1500, 1, 5000), $c->options->flag($o, 'include-source'));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::exportDiagram()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function exportDiagram(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos export-diagram <path|project-id> [options]'), $c);
        $result = $this->queries($c)->exportDiagram($project, $c->options->single($o, 'format') ?? 'mermaid', $c->options->single($o, 'boundary'), $c->options->values($o, 'edge-kind'), $c->options->single($o, 'min-confidence') ?? 'possible', $c->options->single($o, 'direction') ?? 'LR', $c->options->integer($o, 'max-nodes', 200, 1, 400), $c->options->integer($o, 'max-edges', 500, 1, 1000));
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->data['diagram']);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::exportAgentBrief()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function exportAgentBrief(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos export-agent-brief <path|project-id> [--max-chars=N] [--out=FILE] [--json]'), $c);
        $result = $this->queries($c)->exportAgentBrief($project, $c->options->integer($o, 'max-chars', 4000, 1000, 20_000));
        $out = $c->options->single($o, 'out');
        if ($out !== null && file_put_contents($out, $result->data['markdown']) === false) {
            throw new InvalidArgumentException(sprintf('Unable to write brief to %s.', $out));
        }
        $c->output($result->jsonSerialize(), $c->options->flag($o, 'json'), $result->data['markdown']);
        return CliCommand::EXIT_OK;
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::listBoundaries()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listBoundaries(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-boundaries <path|project-id> [options]'), $c);
        $result = $this->queries($c)->listBoundaries($project, $c->options->single($o, 'source'), $c->options->integer($o, 'limit', 50, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::searchArchitecture()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function searchArchitecture(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos search-architecture <path|project-id> <query> [options]'), $c);
        $query = $p[1] ?? throw new InvalidArgumentException('A search query is required.');
        $result = $this->queries($c)->searchArchitecture($project, $query, $c->options->values($o, 'kind'), $c->options->values($o, 'role'), $c->options->values($o, 'boundary'), $c->options->values($o, 'confidence'), $c->options->integer($o, 'limit', 20, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::fileMetrics()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function fileMetrics(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos file-metrics <path|project-id> [--path=SUBSTR] [--language=LANG] [--sort-by=path|line_count] [--order=asc|desc] [--limit=N] [--offset=N]'), $c);
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

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::removeAnnotation()}
     * under `--remove`, else {@see \Knossos\Query\ArchitectureQueryService::upsertAnnotation()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function annotateComponent(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos annotate-component <path|project-id> <component> <kind> [value] [--remove] [--execute] [--json]'), $c);
        $component = $p[1] ?? throw new InvalidArgumentException('A component is required.');
        $kind = $p[2] ?? throw new InvalidArgumentException('A kind is required.');
        $value = $p[3] ?? '';
        $execute = $c->options->flag($o, 'execute');
        $result = $c->options->flag($o, 'remove')
            ? $this->queries($c)->removeAnnotation($project, $component, $kind, $value, $execute)
            : $this->queries($c)->upsertAnnotation($project, $component, $kind, $value, $execute);
        return $this->result($result, $o, $c);
    }

    /**
     * Parses the CLI arguments and renders {@see \Knossos\Query\ArchitectureQueryService::listAnnotations()}.
     *
     * @param list<string> $p @param array<string, list<string>> $o
     */
    private function listAnnotations(array $p, array $o, CliCommandContext $c): int
    {
        $project = $this->project($p[0] ?? throw new InvalidArgumentException('Usage: knossos list-annotations <path|project-id> [--component=NAME] [--kind=KIND] [--limit=N] [--offset=N] [--json]'), $c);
        $result = $this->queries($c)->listAnnotations($project, $c->options->single($o, 'component'), $c->options->single($o, 'kind'), $c->options->integer($o, 'limit', 100, 1, 100), $c->options->integer($o, 'offset', 0, 0, 100_000));
        return $this->result($result, $o, $c);
    }
    /** The project id a `<path|project-id>` argument names, in this invocation's database. */
    private function project(string $argument, CliCommandContext $context): string
    {
        return (new ProjectReference($context->database(), $context->databasePath()))->resolve($argument)['id'];
    }

    /** The query facade for this invocation. */
    private function queries(CliCommandContext $context): ArchitectureQueryService
    {
        return new ArchitectureQueryService($context->database());
    }

    /**
     * Render a result envelope as JSON or text, per the --json flag.
     *
     * @param array<string, list<string>> $options
     */
    private function result(ResultEnvelope $result, array $options, CliCommandContext $context): int
    {
        $context->output($result->jsonSerialize(), $context->options->flag($options, 'json'), $result->summary);
        return CliCommand::EXIT_OK;
    }
}
