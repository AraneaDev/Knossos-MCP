<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectReference;
use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Query\ArchitectureQueryService;

/**
 * The lists behind the pane's counts, whole: every dead-code candidate, every
 * diagnostic, every declared policy. Each takes a path or a project id.
 */
final class ProjectListCommand implements CliCommand
{
    /** Candidates asked for per page: the most `architectureHealth` returns. */
    private const PAGE = 100;

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, ['dead-code', 'diagnostics', 'policies'], true);
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return match ($command) {
            'dead-code' => ['db', 'json', 'reachability'],
            'diagnostics' => ['db', 'json', 'severity', 'path'],
            default => ['db', 'json'],
        };
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $argument = $positionals[0] ?? throw new InvalidArgumentException(sprintf('Usage: knossos %s <path|project-id> [options]', $command));
        $project = (new ProjectReference($context->database(), $context->databasePath()))->resolve($argument);

        return match ($command) {
            'dead-code' => $this->deadCode($project['id'], $options, $context),
            'diagnostics' => $this->diagnostics($project['id'], $options, $context),
            'policies' => $this->policies($project['root'], $project['id'], $options, $context),
            default => throw new InvalidArgumentException(sprintf('Unknown command: %s', $command)),
        };
    }

    /**
     * The boundaries and policies the project declares, read live.
     *
     * @param array<string, list<string>> $options
     */
    private function policies(string $root, string $projectId, array $options, CliCommandContext $context): int
    {
        $configuration = ProjectConfigurationLoader::load($root, [$root]);
        $file = $configuration->path ?? $root . '/knossos.json';
        $lines = [];
        foreach ($configuration->policies as $policy) {
            $deny = $policy['deny_targets'] ?? [];
            $allow = $policy['allow_targets'] ?? [];
            $lines[] = sprintf(
                '%s: %s%s%s',
                $policy['id'],
                $policy['from_boundary'],
                $deny === [] ? '' : ' may not depend on ' . implode(', ', $deny),
                $allow === [] ? '' : ' may depend only on ' . implode(', ', $allow),
            );
        }
        if ($lines === []) {
            $lines[] = 'No policies declared in ' . $file . '.';
        }
        $context->output(
            ['project_id' => $projectId, 'file' => $file, 'boundaries' => $configuration->boundaries, 'policies' => $configuration->policies],
            $context->options->flag($options, 'json'),
            implode("\n", $lines),
        );

        return 0;
    }

    /**
     * Every diagnostic, paged through.
     *
     * @param array<string, list<string>> $options
     */
    private function diagnostics(string $projectId, array $options, CliCommandContext $context): int
    {
        $queries = new ArchitectureQueryService($context->database());
        $severity = $context->options->single($options, 'severity');
        $prefix = $context->options->single($options, 'path');
        $items = [];
        $offset = 0;
        do {
            $page = $queries->listDiagnostics($projectId, $severity, $prefix, 100, $offset);
            array_push($items, ...$page->data['diagnostics']);
            $offset = $page->data['pagination']['next_offset'];
        } while ($offset !== null);
        $lines = array_map(
            static fn(array $d): string => sprintf('%s%s %s %s %s', $d['path'] ?? '(project)', $d['line'] === null ? '' : ':' . $d['line'], $d['code'], $d['severity'], $d['message']),
            $items,
        );
        $lines[] = sprintf('%d diagnostic%s.', count($items), count($items) === 1 ? '' : 's');
        $context->output(['project_id' => $projectId, 'total' => count($items), 'diagnostics' => $items], $context->options->flag($options, 'json'), implode("\n", $lines));

        return 0;
    }

    /**
     * Every candidate, paged through until the total is reached.
     *
     * @param array<string, list<string>> $options
     */
    private function deadCode(string $projectId, array $options, CliCommandContext $context): int
    {
        $reachability = $context->options->single($options, 'reachability');
        if ($reachability !== null && !in_array($reachability, ['unreferenced', 'test-only'], true)) {
            throw new InvalidArgumentException('--reachability must be unreferenced or test-only.');
        }
        $queries = new ArchitectureQueryService($context->database());
        $candidates = [];
        $offset = 0;
        do {
            $page = $queries->architectureHealth($projectId, limit: self::PAGE, candidateOffset: $offset, candidateTimeoutMs: 60_000);
            $found = $page->data['dead_code_candidates'];
            array_push($candidates, ...$found);
            $offset += self::PAGE;
            $bounds = $page->data['bounds'];
        } while (count($found) === self::PAGE && $offset < (int) $bounds['candidates_total']);
        if ($reachability !== null) {
            $wanted = $reachability === 'test-only' ? 'test_only' : 'unreferenced';
            $candidates = array_values(array_filter($candidates, static fn(array $c): bool => $c['reachability'] === $wanted));
        }
        $excluded = [
            'annotated_false_positives' => (int) $bounds['annotated_false_positives'],
            'annotated_intentional' => (int) $bounds['annotated_intentional'],
            'suppressed' => (int) $bounds['suppressed_candidates'],
            'convention_discovered' => (int) $bounds['excluded_convention_discovered'],
        ];
        $lines = array_map(
            static fn(array $c): string => sprintf('%-12s %-9s %s', $c['reachability'], $c['component']['kind'], $c['component']['canonical_name']),
            $candidates,
        );
        $lines[] = sprintf(
            '%d candidate%s; left out: %d annotated false positive%s, %d annotated intentional, %d suppressed, %d reached by convention.',
            count($candidates),
            count($candidates) === 1 ? '' : 's',
            $excluded['annotated_false_positives'],
            $excluded['annotated_false_positives'] === 1 ? '' : 's',
            $excluded['annotated_intentional'],
            $excluded['suppressed'],
            $excluded['convention_discovered'],
        );
        $context->output(
            ['project_id' => $projectId, 'total' => count($candidates), 'candidates' => $candidates, 'excluded' => $excluded],
            $context->options->flag($options, 'json'),
            implode("\n", $lines),
        );

        return 0;
    }
}
