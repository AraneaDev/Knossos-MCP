<?php

declare(strict_types=1);

namespace Knossos\Cli\Command;

use Closure;
use InvalidArgumentException;
use Throwable;
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

    /** The whole candidate search's time budget by default, across every page, in milliseconds. */
    private const CANDIDATE_TIMEOUT_MS = 60_000;

    /**
     * @param Closure(): int|null $clock nanoseconds, monotonic; the query services' own clock unless a test stands in
     */
    public function __construct(private readonly ?Closure $clock = null) {}

    /** {@inheritDoc} */
    public function supports(string $command): bool
    {
        return in_array($command, ['dead-code', 'diagnostics', 'policies'], true);
    }

    /** {@inheritDoc} */
    public function allowedOptions(string $command): array
    {
        return match ($command) {
            'dead-code' => ['db', 'json', 'reachability', 'candidate-timeout'],
            'diagnostics' => ['db', 'json', 'severity', 'path'],
            default => ['db', 'json'],
        };
    }

    /** {@inheritDoc} */
    public function run(string $command, array $positionals, array $options, CliCommandContext $context): int
    {
        $argument = $positionals[0] ?? throw new InvalidArgumentException(sprintf('Usage: knossos %s <path|project-id> [options]', $command));
        $context = $context->forTarget($argument, $options);
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
        try {
            $configuration = ProjectConfigurationLoader::load($root, [$root]);
        } catch (Throwable $error) {
            throw new InvalidArgumentException(sprintf('%s/knossos.json (or knossos.jsonc) could not be read: %s', $root, $error->getMessage()), 0, $error);
        }
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
        // The query serves pages up to offset 100000; past the last of them the list stops and says so.
        do {
            $page = $queries->listDiagnostics($projectId, $severity, $prefix, 100, $offset);
            array_push($items, ...$page->data['diagnostics']);
            $offset = $page->data['pagination']['next_offset'];
        } while ($offset !== null && $offset <= 100_000);
        $total = (int) $page->data['total'];
        $lines = array_map(
            static fn(array $d): string => sprintf('%s%s %s %s %s', $d['path'] ?? '(project)', $d['line'] === null ? '' : ':' . $d['line'], $d['code'], $d['severity'], $d['message']),
            $items,
        );
        $lines[] = sprintf('%d diagnostic%s.', count($items), count($items) === 1 ? '' : 's');
        $incomplete = count($items) < $total;
        if ($incomplete) {
            $lines[] = sprintf('Incomplete: listed the first %d of %d; narrow with --severity or --path.', count($items), $total);
        }
        $context->output(['project_id' => $projectId, 'total' => $total, 'truncated' => $incomplete, 'diagnostics' => $items], $context->options->flag($options, 'json'), implode("\n", $lines));

        return $incomplete ? 2 : 0;
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
        $budget = $context->options->integer($options, 'candidate-timeout', self::CANDIDATE_TIMEOUT_MS, 1, 600_000);
        $queries = new ArchitectureQueryService($context->database(), $this->clock);
        // One budget for the whole list: each page searches again, so a per-page limit would multiply.
        $deadline = $this->now() + $budget * 1_000_000;
        $candidates = [];
        $offset = 0;
        $incomplete = false;
        do {
            $left = intdiv($deadline - $this->now(), 1_000_000);
            if ($left < 1) {
                $incomplete = true;
                break;
            }
            $page = $queries->architectureHealth($projectId, limit: self::PAGE, candidateOffset: $offset, candidateTimeoutMs: min($left, 60_000));
            $found = $page->data['dead_code_candidates'];
            array_push($candidates, ...$found);
            $offset += self::PAGE;
            $bounds = $page->data['bounds'];
            $incomplete = in_array('time_limit', $bounds['candidate_truncation_reasons'], true);
        } while (!$incomplete && count($found) === self::PAGE && $offset < (int) $bounds['candidates_total']);
        $bounds ??= ['annotated_false_positives' => 0, 'annotated_intentional' => 0, 'suppressed_candidates' => 0, 'excluded_convention_discovered' => 0];
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
        if ($incomplete) {
            $lines[] = sprintf('Incomplete: the search ran out of its %d ms before it saw every component; raise --candidate-timeout.', $budget);
        }
        $context->output(
            ['project_id' => $projectId, 'total' => count($candidates), 'truncated' => $incomplete, 'candidates' => $candidates, 'excluded' => $excluded],
            $context->options->flag($options, 'json'),
            implode("\n", $lines),
        );

        // 2: the list could not be evaluated whole, as a gate that could not run reports it.
        return $incomplete ? 2 : 0;
    }

    /** Nanoseconds on the injected clock, else the monotonic one. */
    private function now(): int
    {
        return $this->clock === null ? hrtime(true) : ($this->clock)();
    }
}
