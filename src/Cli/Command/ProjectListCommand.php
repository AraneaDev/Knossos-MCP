<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use InvalidArgumentException;
use Knossos\Cli\CliCommand;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\ProjectReference;
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
            default => throw new InvalidArgumentException(sprintf('Unknown command: %s', $command)),
        };
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
