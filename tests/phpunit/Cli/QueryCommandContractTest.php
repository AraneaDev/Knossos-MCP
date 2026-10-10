<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\QueryCommand;
use Knossos\Mcp\ToolCatalog;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use Throwable;

/**
 * The CLI's argument contract, checked across every query command at once.
 *
 * Two gaps let most of QueryCommand's mutants survive. The positional guards
 * were tested only by exception class, and each command has several guards
 * throwing the same class, so deleting the first one left the second to throw
 * an equally acceptable exception. And the CLI carries its own copy of every
 * integer bound, a third copy after the MCP schema and ToolService, which
 * nothing compared with the other two.
 */
final class QueryCommandContractTest extends KnossosTestCase
{
    /** Every query command, as pinned by CommandsTest's supports() invariant. */
    private const COMMANDS = [
        'list-projects', 'list-snapshots', 'snapshot-diff', 'quality-gate', 'architecture-trends',
        'find-component', 'inspect-component', 'list-usages', 'architecture-summary', 'file-metrics', 'explain-flow', 'impact-analysis',
        'dependency-cycles', 'architecture-health', 'check-architecture', 'suggest-location', 'change-impact',
        'changed-files-impact', 'test-impact', 'review-diff', 'architecture-context', 'export-diagram', 'export-agent-brief', 'list-boundaries',
        'search-architecture', 'annotate-component', 'list-annotations',
    ];

    /**
     * A command missing its first argument explains its own usage.
     *
     * The message, not the exception class, is what tells the guards apart: a
     * command whose first guard was deleted still throws the same class, from
     * the next guard along, with the next guard's message.
     */
    #[Group('cli')]
    public function testEveryCommandMissingItsFirstArgumentPrintsItsOwnUsage(): void
    {
        $checked = 0;
        foreach (self::COMMANDS as $command) {
            $error = self::errorFrom($command, []);
            if ($error === null) {
                continue; // needs no positional argument at all
            }
            assertSame(
                true,
                str_starts_with($error, 'Usage: knossos ' . $command),
                sprintf('%s with no arguments must print its own usage, got: %s', $command, $error),
            );
            ++$checked;
        }
        assertSame(true, $checked >= 20, sprintf('Expected most commands to require an argument, checked %d.', $checked));
    }

    /**
     * Every integer bound the CLI enforces is the bound the MCP schema advertises.
     *
     * The option names are the schema's property names in kebab case, and the
     * probe values come from the schema, so this states the rule rather than
     * today's numbers and catches the CLI's copy moving away from the other two.
     */
    #[Group('cli')]
    public function testEveryCliIntegerBoundMatchesTheAdvertisedSchema(): void
    {
        $schemas = [];
        foreach (ToolCatalog::definitions(false) as $definition) {
            $schemas[$definition['name']] = (array) ($definition['inputSchema']['properties'] ?? []);
        }
        // Some commands refuse a missing file option before they read any
        // integer, so supply the ones a command accepts, with valid content.
        $policies = self::temporaryJson([['id' => 'p', 'from_boundary' => 'core', 'deny_targets' => ['tests']]]);
        $budgets = self::temporaryJson(['new_cycles' => 0]);
        // The project argument is resolved before any option is read, so it
        // has to name a project; one row is enough, with no snapshot behind it.
        $database = sys_get_temp_dir() . '/knossos-stale-bounds-' . bin2hex(random_bytes(4)) . '.sqlite';
        (new RuntimeFactory(self::repositoryRoot()))->database($database)
            ->exec("INSERT INTO projects (id, name, root_realpath, created_at, updated_at) VALUES ('p1', 'p1', '/p1', 'now', 'now')");
        $checked = 0;
        try {
            foreach (self::COMMANDS as $command) {
                $properties = $schemas[str_replace('-', '_', $command)] ?? [];
                $allowed = (new QueryCommand())->allowedOptions($command);
                $files = array_filter(
                    ['policies' => [$policies], 'budgets' => [$budgets]],
                    static fn(string $option): bool => in_array($option, $allowed, true),
                    ARRAY_FILTER_USE_KEY,
                );
                foreach ($allowed as $option) {
                    $spec = $properties[self::schemaKey($option)] ?? null;
                    if (!is_array($spec) || ($spec['type'] ?? null) !== 'integer' || !isset($spec['minimum'], $spec['maximum'])) {
                        continue;
                    }
                    [$minimum, $maximum] = [(int) $spec['minimum'], (int) $spec['maximum']];
                    $expected = sprintf('--%s must be between %d and %d.', $option, $minimum, $maximum);
                    foreach ([$maximum + 1, $minimum - 1] as $outside) {
                        assertSame(
                            $expected,
                            self::errorFrom($command, ['p1', 'p2', 'p3'], [...$files, $option => [(string) $outside]], $database),
                            sprintf('%s --%s=%d must be refused with the advertised bounds.', $command, $option, $outside),
                        );
                    }
                    foreach ([$maximum, $minimum] as $inside) {
                        assertNotSame(
                            $expected,
                            self::errorFrom($command, ['p1', 'p2', 'p3'], [...$files, $option => [(string) $inside]], $database),
                            sprintf('%s --%s=%d is advertised as legal and must not be refused as out of range.', $command, $option, $inside),
                        );
                    }
                    ++$checked;
                }
            }
        } finally {
            @unlink($policies);
            @unlink($budgets);
            // WAL mode leaves -wal and -shm beside the database.
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                @unlink($file);
            }
        }
        assertSame(true, $checked >= 25, sprintf('Expected to check at least 25 bounded CLI options, checked %d.', $checked));
    }

    /**
     * Every integer default the CLI applies is the default the MCP schema advertises.
     *
     * Omitting an option must do what passing its advertised default does. The
     * CLI keeps a third copy of each default, and nothing compared it with the
     * schema, so a default could move on one side only.
     */
    #[Group('cli')]
    public function testEveryCliIntegerDefaultIsTheAdvertisedDefault(): void
    {
        $schemas = [];
        foreach (ToolCatalog::definitions(false) as $definition) {
            $schemas[$definition['name']] = (array) ($definition['inputSchema']['properties'] ?? []);
        }
        $database = sys_get_temp_dir() . '/knossos-stale-defaults-' . bin2hex(random_bytes(4)) . '.sqlite';
        [, $repository, $ids] = $this->storeFixture(null, (new RuntimeFactory(self::repositoryRoot()))->database($database));
        $repository->completeScan($ids['project'], $ids['scan']);
        $positionals = [$ids['project'], 'App\\Checkout', 'App\\InvoiceService'];
        $checked = 0;
        try {
            foreach (self::COMMANDS as $command) {
                $properties = $schemas[str_replace('-', '_', $command)] ?? [];
                foreach ((new QueryCommand())->allowedOptions($command) as $option) {
                    $spec = $properties[self::schemaKey($option)] ?? null;
                    if (!is_array($spec) || ($spec['type'] ?? null) !== 'integer' || !array_key_exists('default', $spec)) {
                        continue;
                    }
                    // The file commands take files after the project, not component names.
                    $arguments = in_array($command, ['changed-files-impact', 'test-impact', 'architecture-context'], true)
                        ? [$ids['project'], 'src/Checkout.php']
                        : $positionals;
                    assertSame(
                        self::outcomeOf($command, $arguments, [$option => [(string) $spec['default']]], $database),
                        self::outcomeOf($command, $arguments, [], $database),
                        sprintf('%s: omitting --%s must do what its advertised default (%d) does.', $command, $option, $spec['default']),
                    );
                    ++$checked;
                }
            }
        } finally {
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                @unlink($file);
            }
        }
        assertSame(true, $checked >= 40, sprintf('Expected to check at least 40 CLI defaults, checked %d.', $checked));
    }

    /**
     * Each value option on the graph commands reaches the query: an invalid
     * value is refused by the service, which an option the command dropped
     * (or replaced by its default) would never be.
     *
     * @return iterable<string, array{string, list<string>, array<string, list<string>>, string}>
     */
    public static function invalidValueOptions(): iterable
    {
        foreach (['list-usages' => 2, 'explain-flow' => 3, 'impact-analysis' => 2, 'dependency-cycles' => 1, 'architecture-health' => 1, 'export-diagram' => 1] as $command => $arity) {
            yield $command . ' --min-confidence' => [$command, $arity, ['min-confidence' => ['bogus']], 'min_confidence must be possible, probable, or certain.'];
        }
        yield 'architecture-health --candidate-confidence' => ['architecture-health', 1, ['candidate-confidence' => ['bogus']], 'candidate_confidence must be probable or possible.'];
        yield 'export-diagram --format' => ['export-diagram', 1, ['format' => ['bogus']], 'format must be mermaid or plantuml.'];
        yield 'export-diagram --direction' => ['export-diagram', 1, ['direction' => ['bogus']], 'direction must be LR or TB.'];
        yield 'list-usages --edge-kind' => ['list-usages', 2, ['edge-kind' => ['bogus']], 'edge_kinds contains an unsupported dependency relationship.'];
    }

    #[Group('cli')]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidValueOptions')]
    public function testEachValueOptionReachesTheQuery(string $command, int $arity, array $options, string $message): void
    {
        $database = sys_get_temp_dir() . '/knossos-stale-values-' . bin2hex(random_bytes(4)) . '.sqlite';
        [, $repository, $ids] = $this->storeFixture(null, (new RuntimeFactory(self::repositoryRoot()))->database($database));
        $repository->completeScan($ids['project'], $ids['scan']);
        try {
            $positionals = array_slice([$ids['project'], 'App\\Checkout', 'App\\InvoiceService'], 0, $arity);

            assertSame($message, self::errorFrom($command, $positionals, $options, $database));
        } finally {
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * What one command produces: its data as JSON with timestamps blanked, or the error it fails with.
     *
     * @param list<string> $positionals @param array<string, list<string>> $options
     */
    private static function outcomeOf(string $command, array $positionals, array $options, string $database): string
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $database);
        ob_start();
        try {
            (new QueryCommand())->run($command, $positionals, ['json' => ['']] + $options, $context);
            $output = json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
            $encoded = json_encode($output['data'] ?? $output, JSON_THROW_ON_ERROR);

            return preg_replace('/\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z/', '<time>', $encoded) ?? $encoded;
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        } finally {
            ob_end_clean();
        }
    }

    /** The schema property a CLI option mirrors: kebab case to snake case, plus the one option named differently. */
    private static function schemaKey(string $option): string
    {
        return $option === 'candidate-timeout' ? 'candidate_timeout_ms' : str_replace('-', '_', $option);
    }

    /** The documented annotation mutation flags must reach QueryCommand's executor. */
    #[Group('cli')]
    public function testAnnotateComponentAllowsMutationFlags(): void
    {
        assertSame(
            ['db', 'json', 'remove', 'execute'],
            (new QueryCommand())->allowedOptions('annotate-component'),
        );
    }

    /** The help and the MCP tool both offer a ranking mode: the CLI must accept it, validate it and forward it to the service. */
    #[Group('cli')]
    public function testSuggestLocationForwardsTheRankingModeToTheService(): void
    {
        assertSame(true, in_array('ranking-mode', (new QueryCommand())->allowedOptions('suggest-location'), true));

        $base = sys_get_temp_dir() . '/knossos-stale-ranking-' . bin2hex(random_bytes(6));
        $root = $base . '/project';
        $database = $base . '/data/knossos.sqlite';

        try {
            $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/mixed', $root);
            $runtime = new RuntimeFactory(self::repositoryRoot());
            $pdo = $runtime->database($database);
            $projectId = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root)->projectId;
            unset($pdo);
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), $runtime, $database);
            $run = static function (?string $mode) use ($context, $projectId): string {
                ob_start();
                try {
                    (new QueryCommand())->run('suggest-location', [$projectId, 'build a billing workflow'], ['json' => ['true']] + ($mode === null ? [] : ['ranking-mode' => [$mode]]), $context);

                    return (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }
            };

            $semantic = json_decode($run('semantic_if_available'), true, 64, JSON_THROW_ON_ERROR);
            assertSame('semantic_if_available', $semantic['data']['ranking']['requested_mode']);
            $absent = json_decode($run(null), true, 64, JSON_THROW_ON_ERROR);
            assertSame('deterministic', $absent['data']['ranking']['requested_mode']);
            $default = json_decode($run('deterministic'), true, 64, JSON_THROW_ON_ERROR);
            assertSame('deterministic', $default['data']['ranking']['requested_mode']);

            try {
                $run('bogus');
                self::fail('A bogus ranking mode must be rejected.');
            } catch (InvalidArgumentException $error) {
                assertSame('ranking_mode must be deterministic or semantic_if_available.', $error->getMessage());
            }
        } finally {
            $this->removeTempTree($base);
        }
    }

    /** isset() saw the key, so --execute=false performed the write it was meant to refuse. */
    #[Group('cli')]
    public function testExecuteFalseOnlyPreviewsAnAnnotation(): void
    {
        $this->withScannedFixture(function (\Closure $run, string $project): void {
            $preview = $run('annotate-component', [$project, 'Fixture\\CheckoutService', 'note', 'x'], ['execute' => ['false']]);

            assertSame(false, $preview['data']['executed']);
            assertSame([], $run('list-annotations', [$project], [])['data']['annotations'], 'Nothing was written.');
        });
    }

    /** --remove=no removed the annotation; it is an upsert like any other write. */
    #[Group('cli')]
    public function testRemoveNoUpsertsInsteadOfRemoving(): void
    {
        $this->withScannedFixture(function (\Closure $run, string $project): void {
            $run('annotate-component', [$project, 'Fixture\\CheckoutService', 'note', 'first'], ['execute' => ['']]);
            $run('annotate-component', [$project, 'Fixture\\CheckoutService', 'note', 'second'], ['execute' => [''], 'remove' => ['no']]);

            assertSame(['second'], array_column($run('list-annotations', [$project], [])['data']['annotations'], 'value'));
        });
    }

    /** A removal carrying an over-long value is refused before anything is resolved, as an upsert is. */
    #[Group('cli')]
    public function testRemoveRefusesAnOverLongValue(): void
    {
        $this->withScannedFixture(function (\Closure $run, string $project): void {
            $run('annotate-component', [$project, 'Fixture\\CheckoutService', 'note', 'kept'], ['execute' => ['']]);
            try {
                $run('annotate-component', [$project, 'Fixture\\CheckoutService', 'note', str_repeat('x', 2001)], ['execute' => [''], 'remove' => ['']]);
                self::fail('An over-long value must be refused on removal too.');
            } catch (InvalidArgumentException $error) {
                assertSame('value must not exceed 2000 characters.', $error->getMessage());
            }

            assertSame(['kept'], array_column($run('list-annotations', [$project], [])['data']['annotations'], 'value'));
        });
    }

    /** --working-tree=off read the working tree anyway, which with explicit files is refused. */
    #[Group('cli')]
    public function testWorkingTreeOffReadsTheGivenFiles(): void
    {
        $this->withScannedFixture(function (\Closure $run, string $project): void {
            $result = $run('test-impact', [$project, 'src/CheckoutService.php'], ['working-tree' => ['off']]);

            assertSame(['src/CheckoutService.php'], $result['data']['changed_files']);
        });
    }

    /** The files given after the project are the change set, and the project argument is not one of them. */
    #[Group('cli')]
    public function testChangedFilesImpactReadsTheFilesAfterTheProject(): void
    {
        $this->withScannedFixture(function (\Closure $run, string $project): void {
            $result = $run('changed-files-impact', [$project, 'src/CheckoutService.php'], []);

            assertSame(['src/CheckoutService.php'], $result['data']['changed_files']);
        });
    }

    /** check-architecture exits 1 only when a policy is violated, and 0 when none is. */
    #[Group('cli')]
    public function testCheckArchitectureExitsOneOnlyOnAViolation(): void
    {
        $database = sys_get_temp_dir() . '/knossos-stale-check-exit-' . bin2hex(random_bytes(4)) . '.sqlite';
        $pdo = (new RuntimeFactory(self::repositoryRoot()))->database($database);
        [, $repository, $ids] = $this->storeFixture(null, $pdo);
        $boundary = \Knossos\Store\StableId::boundary($ids['project'], 'Core', 'explicit');
        $repository->saveBoundary($boundary, $ids['project'], 'Core', ['path_prefix' => 'src'], 'explicit', $ids['scan']);
        $repository->saveBoundaryMembership($boundary, $ids['project'], $ids['checkout'], $ids['scan']);
        $repository->completeScan($ids['project'], $ids['scan']);
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $database);
        $exit = static function (array $policy) use ($context, $ids): int {
            $file = self::temporaryJson([['id' => 'p', 'from_boundary' => 'Core', ...$policy]]);
            ob_start();
            try {
                return (new QueryCommand())->run('check-architecture', [$ids['project']], ['policies' => [$file]], $context);
            } finally {
                ob_end_clean();
                @unlink($file);
            }
        };
        try {
            // Checkout (in Core) calls InvoiceService, which is in no boundary.
            assertSame(1, $exit(['deny_targets' => ['@unassigned']]));
            assertSame(0, $exit(['allow_targets' => ['@unassigned']]));
        } finally {
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                @unlink($file);
            }
        }
    }

    /** --include-source=0 read source excerpts from the working tree anyway. */
    #[Group('cli')]
    public function testIncludeSourceZeroReadsNoSource(): void
    {
        $this->withScannedFixture(function (\Closure $run, string $project): void {
            $result = $run('architecture-context', [$project], ['task' => ['checkout'], 'include-source' => ['0']]);

            assertSame(['Context sections are bounded static evidence and may omit dynamic runtime behavior.'], $result['warnings']);
        });
    }

    /**
     * Run $test with a runner over the mixed fixture scanned into a temporary
     * database: $run(command, positionals, options) returns the decoded --json output.
     *
     * @param \Closure(\Closure(string, list<string>, array<string, list<string>>): array<string, mixed>, string): void $test
     */
    private function withScannedFixture(\Closure $test): void
    {
        $base = sys_get_temp_dir() . '/knossos-stale-switches-' . bin2hex(random_bytes(6));
        $root = $base . '/project';
        $database = $base . '/data/knossos.sqlite';
        try {
            $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/mixed', $root);
            $runtime = new RuntimeFactory(self::repositoryRoot());
            $projectId = (new ProjectScanService($runtime->database($database), self::repositoryRoot(), [$root]))->scan($root)->projectId;
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), $runtime, $database);
            $run = static function (string $command, array $positionals, array $options) use ($context): array {
                ob_start();
                try {
                    (new QueryCommand())->run($command, $positionals, ['json' => ['true']] + $options, $context);

                    return json_decode((string) ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
                } finally {
                    ob_end_clean();
                }
            };
            $test($run, $projectId);
        } finally {
            $this->removeTempTree($base);
        }
    }

    /** Write a JSON value to a temporary file and return its path. */
    private static function temporaryJson(mixed $value): string
    {
        $path = sys_get_temp_dir() . '/knossos-contract-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR));

        return $path;
    }

    /**
     * With nothing pinned, a project's own `.knossos` is found from the
     * argument or, for an id, from the working directory, as the briefs find
     * it. A command that finds no graph says so; it never creates one.
     */
    #[Group('cli')]
    public function testAnUnpinnedQueryFindsTheGraphNearestItsArgumentAndCreatesNone(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-nearest-' . bin2hex(random_bytes(4));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        $root = (string) realpath($root);
        mkdir($root . '/.knossos', 0700);
        mkdir($root . '/src/Core/deep', 0700);
        $runtime = new RuntimeFactory(self::repositoryRoot());
        $projectId = (new ProjectScanService($runtime->database($root . '/.knossos/knossos.sqlite'), self::repositoryRoot(), [$root]))->scan($root)->projectId;
        $dataDir = getenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_DATA_DIR');
        $cwd = (string) getcwd();
        chdir($root . '/src/Core/deep');
        try {
            foreach (['../..', $projectId] as $argument) {
                $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), $runtime, null);
                ob_start();
                try {
                    (new QueryCommand())->run('architecture-summary', [$argument], ['json' => ['1']], $context);
                } finally {
                    $out = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
                }
                assertSame($projectId, $out['project_id'], $argument);
            }
            assertSame(false, is_dir($root . '/src/Core/deep/.knossos'));
            chdir(sys_get_temp_dir());
            $unpinned = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), $runtime, null);
            try {
                (new QueryCommand())->run('architecture-summary', [$projectId], [], $unpinned);
                self::fail('A graph that is not there answered.');
            } catch (InvalidArgumentException $error) {
                assertSame(true, str_starts_with($error->getMessage(), 'Project not found: ' . $projectId), $error->getMessage());
            }
            assertSame(false, is_file(sys_get_temp_dir() . '/.knossos/knossos.sqlite'));
        } finally {
            chdir($cwd);
            putenv($dataDir === false ? 'KNOSSOS_DATA_DIR' : 'KNOSSOS_DATA_DIR=' . $dataDir);
            $this->removeTempTree($root);
        }
    }

    /** The project argument is a path as readily as an id; an unknown one names the database read. */
    #[Group('cli')]
    public function testAQueryCommandTakesAPathAsWellAsAnId(): void
    {
        [, $projectId, $root] = $this->scanTempFixture('turn-brief');
        $database = sys_get_temp_dir() . '/knossos-stale-cli-' . bin2hex(random_bytes(4)) . '.sqlite';
        try {
            (new ProjectScanService((new RuntimeFactory(self::repositoryRoot()))->database($database), self::repositoryRoot(), [$root]))->scan($root);
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $database);
            ob_start();
            (new QueryCommand())->run('architecture-summary', [$root . '/src'], ['json' => ['1']], $context);
            $out = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            assertSame($projectId, $out['project_id']);
            assertSame('Project not found: /nowhere (database: ' . $database . ')', self::errorFrom('architecture-summary', ['/nowhere'], [], $database));
        } finally {
            // WAL mode leaves -wal and -shm beside the database.
            foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
                @unlink($file);
            }
            $this->removeTempTree($root);
        }
    }

    /**
     * The message a command fails with, or null when it completes.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     */
    private static function errorFrom(string $command, array $positionals, array $options = [], string $database = ':memory:'): ?string
    {
        $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $database);
        ob_start();
        try {
            (new QueryCommand())->run($command, $positionals, $options, $context);

            return null;
        } catch (InvalidArgumentException $error) {
            return $error->getMessage();
        } catch (Throwable $error) {
            return $error::class . ': ' . $error->getMessage();
        } finally {
            ob_end_clean();
        }
    }
}
