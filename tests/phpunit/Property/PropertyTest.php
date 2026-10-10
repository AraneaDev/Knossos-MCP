<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Property;

use Knossos\Configuration\ProjectConfigurationLoader;
use Knossos\Discovery\DiscoveryException;
use Knossos\Discovery\JsonConfig;
use Knossos\Maintenance\DatabaseMaintenanceService;
use Knossos\Mcp\McpDispatcher;
use Knossos\Mcp\ToolService;
use Knossos\Query\ArchitectureQueryService;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class PropertyTest extends KnossosTestCase
{
    #[Group('property')]
    public function testJsoncRandomizedStringsRetainCommentTokensAndTrailingCommaSemantics(): void
    {
        for ($case = 0; $case < 200; ++$case) {
            $token = sprintf('https://example.test/%d/*literal*/ // value', $case);
            $json = sprintf("{\n// generated comment\n\"token\":%s,\"values\":[%d,%d,],\n}\n", json_encode($token, JSON_THROW_ON_ERROR), $case, $case + 1);
            $decoded = JsonConfig::decode($json, true);
            assertSame($token, $decoded['token']);
            assertSame([$case, $case + 1], $decoded['values']);
        }
    }

    #[Group('property')]
    public function testSeededMalformedConfigurationAndContributionCorporaFailClosed(): void
    {
        $root = sys_get_temp_dir() . '/knossos-invalid-config-' . bin2hex(random_bytes(6));
        if (!mkdir($root, 0700)) {
            throw new RuntimeException('Unable to create fuzz configuration fixture.');
        }
        $invalidConfigurations = [
            ['version' => 2],
            ['version' => 1, 'unknown' => true],
            ['version' => 1, 'ignores' => ['../escape']],
            ['version' => 1, 'limits' => ['max_files' => 0]],
            ['version' => 1, 'boundaries' => [['name' => 'missing matcher']]],
            ['version' => 1, 'frameworks' => ['dynamic-framework']],
            ['version' => 1, 'quality_budgets' => ['new_cycles' => -1]],
        ];
        try {
            for ($case = 0; $case < 140; ++$case) {
                $configuration = $invalidConfigurations[$case % count($invalidConfigurations)];
                file_put_contents($root . '/knossos.json', json_encode($configuration, JSON_THROW_ON_ERROR));
                $error = captureThrows(fn() => ProjectConfigurationLoader::load($root, [$root]), DiscoveryException::class);
                assertSame(true, str_starts_with($error->getMessage(), 'PROJECT_CONFIG_'));

                $invalidContribution = [
                    'owner_key' => $case % 3 === 0 ? '' : 'owner',
                    'nodes' => $case % 3 === 1 ? 'not-a-list' : [],
                    'edges' => [],
                    'diagnostics' => $case % 3 === 2 ? [['severity' => 1]] : [],
                ];
                $workerError = captureThrows(
                    fn() => \Knossos\Scanner\Worker\ContributionDecoder::decode($invalidContribution),
                    WorkerException::class,
                );
                assertSame('WORKER_CONTRIBUTION_INVALID', $workerError->diagnosticCode);
            }
        } finally {
            @unlink($root . '/knossos.json');
            @rmdir($root);
        }
    }

    #[Group('property')]
    public function testStableIdentifierPropertiesAreDeterministicDomainSeparatedAndCollisionFree(): void
    {
        $seen = [];
        for ($case = 0; $case < 1000; ++$case) {
            $root = sprintf('/workspace/generated/%08x', $case * 2654435761 & 0xffffffff);
            $project = StableId::project($root);
            assertSame($project, StableId::project($root));
            assertSame(false, isset($seen[$project]));
            $seen[$project] = true;
            assertNotSame($project, StableId::scan($project, 'same-input'));
            assertNotSame(StableId::file($project, 'same-input'), StableId::symbol($project, 'php', 'file', 'same-input'));
        }
    }

    /**
     * A project scanned only incrementally after its first scan ends every
     * step with the graph a full scan of the same bytes produces, across PHP,
     * TypeScript, Python and Rust, including edits whose effect crosses files:
     * a re-export repointed, a module renamed, a dependency's declaration
     * changed, a file deleted and re-created, a probed path added, and one of
     * two duplicate declarations edited. A language whose runtime or worker
     * is missing is left out of the project.
     */
    #[Group('property')]
    public function testSeededEditSequencesKeepIncrementalAndFullGraphsEquivalent(): void
    {
        $languages = [
            'typescript' => trim((string) @shell_exec('command -v node 2>/dev/null')) !== '',
            'python' => trim((string) @shell_exec('command -v python3 2>/dev/null')) !== '',
            'rust' => is_file(self::rustWorkerBinary()),
        ];
        foreach ([0xC0FFEE, 0xBADC0DE, 0x5EED] as $seed) {
            $this->assertEditSequenceKeepsGraphsEquivalent($seed, 12, $languages);
        }
    }

    #[Group('property')]
    public function testSeededJsonRpcMessageShapesReturnBoundedProtocolResponses(): void
    {
        [$pdo] = $this->storeFixture();
        $tools = new ToolService(
            new ProjectScanService($pdo, self::repositoryRoot(), [self::repositoryRoot() . '/tests/Fixtures/mixed']),
            ArchitectureQueryService::forDatabase($pdo),
            new DatabaseMaintenanceService($pdo, ':memory:'),
            new \Knossos\Mcp\ResultEnricher(new \Knossos\Query\StalenessProbe($pdo), new \Knossos\Mcp\NextStepPlanner()),
        );
        $server = new McpDispatcher($tools);
        $templates = [
            [],
            ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 17],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'initialize', 'params' => []],
            ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping', 'params' => [1, 2]],
            ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => ['nested']]],
            ['jsonrpc' => '2.0', 'id' => 5, 'method' => str_repeat('x', 1000)],
        ];
        for ($case = 0; $case < 700; ++$case) {
            $message = $templates[$case % count($templates)];
            if (isset($message['id'])) {
                $message['id'] = $case;
            }
            $response = $server->handle($message);
            if ($response !== null) {
                assertSame('2.0', $response['jsonrpc']);
                assertSame(true, strlen(json_encode($response, JSON_THROW_ON_ERROR)) < 4096);
                assertSame(true, isset($response['error']) || isset($response['result']));
            }
        }
    }

    /**
     * Runs one seeded edit sequence over a fresh copy of the mixed fixture.
     *
     * Two databases see the same root, so both derive the same project id:
     * one is scanned incrementally after its first full scan, the other is
     * always scanned in full, and their graphs are compared after every step.
     *
     * @param array{typescript: bool, python: bool, rust: bool} $languages
     */
    private function assertEditSequenceKeepsGraphsEquivalent(int $seed, int $steps, array $languages): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-mixed-' . bin2hex(random_bytes(6));
        $this->copyMixedFixture($root, $languages);
        try {
            $incrementalDatabase = $this->freshTestDatabase();
            $fullDatabase = $this->freshTestDatabase();
            $incremental = new ProjectScanService($incrementalDatabase, self::repositoryRoot(), [$root]);
            $full = new ProjectScanService($fullDatabase, self::repositoryRoot(), [$root]);
            $incremental->scan($root, mode: 'full');
            $full->scan($root, mode: 'full');
            assertSame($this->graphSignature($fullDatabase), $this->graphSignature($incrementalDatabase), sprintf('seed 0x%X before any step', $seed));

            $state = $seed;
            $edits = ['deleted' => [], 'revision' => 0];
            for ($step = 1; $step <= $steps; ++$step) {
                $state = (int) (($state * 1664525 + 1013904223) & 0x7fffffff);
                $applicable = $this->applicableEdits($root, $languages, $edits);
                $edit = $applicable[$state % count($applicable)];
                $state = (int) (($state * 1664525 + 1013904223) & 0x7fffffff);
                $description = $this->applyEdit($edit, $root, $state, $step, $edits);

                $result = $incremental->scan($root, mode: 'incremental');
                $full->scan($root, mode: 'full');
                $label = sprintf('seed 0x%X step %d: %s', $seed, $step, $description);
                assertSame('incremental', $result->data['mode'], $label);
                assertSame($this->graphSignature($fullDatabase), $this->graphSignature($incrementalDatabase), $label);
            }
        } finally {
            unset($incremental, $full, $incrementalDatabase, $fullDatabase);
            $this->removeTempTree($root);
        }
    }

    /**
     * Copies the mixed fixture to `$root`, leaving out each language whose
     * runtime or worker is missing. The dependency package is stored under
     * `installed-packages/` in the fixture, because the repository ignores
     * `node_modules/`, and is copied to `node_modules/` here.
     *
     * @param array{typescript: bool, python: bool, rust: bool} $languages
     */
    private function copyMixedFixture(string $root, array $languages): void
    {
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/incremental-mixed', $root);
        rename($root . '/installed-packages', $root . '/node_modules');
        $omitted = [
            'typescript' => ['tsconfig.json', 'web', 'node_modules'],
            'python' => ['py'],
            'rust' => ['Cargo.toml', 'src/main.rs', 'src/engine.rs', 'src/engine'],
        ];
        foreach ($omitted as $language => $paths) {
            if ($languages[$language]) {
                continue;
            }
            foreach ($paths as $path) {
                is_dir($root . '/' . $path) ? $this->removeTempSubtree($root . '/' . $path) : unlink($root . '/' . $path);
            }
        }
    }

    /** Removes a directory below a temp fixture root, contents first. */
    private function removeTempSubtree(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeTempSubtree($path) : unlink($path);
        }
        rmdir($directory);
    }

    /**
     * The edits that make sense for the project as it stands now.
     *
     * @param array{typescript: bool, python: bool, rust: bool} $languages
     * @param array{deleted: array<string, string>, revision: int} $edits
     * @return non-empty-list<string>
     */
    private function applicableEdits(string $root, array $languages, array $edits): array
    {
        $applicable = ['edit-body', 'edit-duplicate'];
        if ($languages['typescript']) {
            $applicable[] = 'move-foo';
            $applicable[] = 'rename-dep';
        }
        if ($languages['python']) {
            $applicable[] = 'rename-python-module';
        }
        if (is_file($root . '/src/Helper.php')) {
            $applicable[] = 'delete-helper';
        }
        if ($edits['deleted'] !== []) {
            $applicable[] = 'recreate-deleted';
        }
        if ($languages['rust']) {
            $applicable[] = 'toggle-lib-rs';
        }

        return $applicable;
    }

    /**
     * Applies one edit to the project and describes it for a failure message.
     *
     * @param array{deleted: array<string, string>, revision: int} $edits
     */
    private function applyEdit(string $edit, string $root, int $state, int $step, array &$edits): string
    {
        switch ($edit) {
            case 'edit-body':
                $candidates = array_values(array_filter(
                    ['src/Service.php', 'src/Helper.php', 'web/main.ts', 'web/impl.ts', 'py/app.py', 'py/pkg/impl.py', 'py/pkg/impl_b.py', 'src/engine.rs', 'src/engine/sign.rs'],
                    static fn(string $path): bool => is_file($root . '/' . $path),
                ));
                $path = $candidates[$state % count($candidates)];
                $this->appendFunction($root . '/' . $path, $step);
                return 'append a function to ' . $path;
            case 'edit-duplicate':
                $path = $state % 2 === 0 ? 'src/Duplicate.php' : 'src/DuplicateToo.php';
                $this->appendFunction($root . '/' . $path, $step);
                return 'add a method to the duplicate declaration in ' . $path;
            case 'move-foo':
                $foo = "export function foo(): void {}\n";
                if (is_file($root . '/web/impl2.ts')) {
                    unlink($root . '/web/impl2.ts');
                    file_put_contents($root . '/web/impl.ts', $foo . (string) file_get_contents($root . '/web/impl.ts'));
                    $this->replaceIn($root . '/web/barrel.ts', "'./impl2'", "'./impl'");
                    return 'move foo back to web/impl.ts and repoint the barrel';
                }
                $this->replaceIn($root . '/web/impl.ts', $foo, '');
                file_put_contents($root . '/web/impl2.ts', $foo);
                $this->replaceIn($root . '/web/barrel.ts', "'./impl'", "'./impl2'");
                return 'move foo to web/impl2.ts and repoint the barrel';
            case 'rename-dep':
                $declaration = $root . '/node_modules/dep/index.d.ts';
                if (str_contains((string) file_get_contents($declaration), 'dep2(')) {
                    $this->replaceIn($declaration, 'dep2(', 'dep(');
                    return 'rename dep2 back to dep in node_modules/dep/index.d.ts';
                }
                $this->replaceIn($declaration, 'dep(', 'dep2(');
                return 'rename dep to dep2 in node_modules/dep/index.d.ts';
            case 'rename-python-module':
                [$from, $to] = is_file($root . '/py/pkg/impl.py') ? ['impl', 'impl_b'] : ['impl_b', 'impl'];
                rename(sprintf('%s/py/pkg/%s.py', $root, $from), sprintf('%s/py/pkg/%s.py', $root, $to));
                $this->replaceIn($root . '/py/pkg/__init__.py', sprintf('from .%s import', $from), sprintf('from .%s import', $to));
                return sprintf('rename py/pkg/%s.py to %s.py and fix its importer', $from, $to);
            case 'delete-helper':
                $edits['deleted']['src/Helper.php'] = (string) file_get_contents($root . '/src/Helper.php');
                unlink($root . '/src/Helper.php');
                return 'delete src/Helper.php';
            case 'recreate-deleted':
                $path = array_key_first($edits['deleted']);
                file_put_contents($root . '/' . $path, $edits['deleted'][$path]);
                unset($edits['deleted'][$path]);
                return 're-create ' . $path;
            default:
                if (is_file($root . '/src/lib.rs')) {
                    unlink($root . '/src/lib.rs');
                    return 'delete src/lib.rs';
                }
                file_put_contents($root . '/src/lib.rs', "pub fn library() {}\n");
                return 'add src/lib.rs, a crate root the Rust worker probes';
        }
    }

    /**
     * Adds a uniquely named function to a source file: a method inside a PHP
     * class, a top-level function in any other language.
     */
    private function appendFunction(string $path, int $step): void
    {
        $contents = (string) file_get_contents($path);
        $contents = match (pathinfo($path, PATHINFO_EXTENSION)) {
            'php' => substr_replace($contents, sprintf("    public function extra%d(): void\n    {\n    }\n}", $step), (int) strrpos($contents, '}'), 1),
            'ts' => $contents . sprintf("export function extra%d(): void {}\n", $step),
            'py' => $contents . sprintf("\n\ndef extra_%d():\n    return None\n", $step),
            default => $contents . sprintf("\npub fn extra_%d() {}\n", $step),
        };
        file_put_contents($path, $contents);
    }

    /** Replaces text in a fixture file, failing loudly when it is not there. */
    private function replaceIn(string $path, string $search, string $replace): void
    {
        $contents = (string) file_get_contents($path);
        if (!str_contains($contents, $search)) {
            throw new RuntimeException(sprintf('Expected %s in %s.', json_encode($search), $path));
        }
        file_put_contents($path, str_replace($search, $replace, $contents));
    }
}
