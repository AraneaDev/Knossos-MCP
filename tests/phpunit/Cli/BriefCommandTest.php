<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Scan\ProjectScanService;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliCommandRouter;
use Knossos\Cli\CliHelpRenderer;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\BriefCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertIsList;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The two commands a Claude Code mod calls after every turn: both exit 0
 * whatever happens, and a failure is a status in the JSON.
 */
final class BriefCommandTest extends KnossosTestCase
{
    private const TARGET = 'src/Core/Greeter.php';

    private string|false $dataDirectory = false;

    private string|false $allowedRoots = false;

    private string|false $rootsFile = false;

    /** Clears the variables that would redirect the database or the scan roots; restored in tearDown. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDirectory = getenv('KNOSSOS_DATA_DIR');
        $this->allowedRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        $this->rootsFile = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_ALLOWED_ROOTS=' . sys_get_temp_dir());
        putenv('KNOSSOS_ROOTS_FILE');
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->dataDirectory) ? 'KNOSSOS_DATA_DIR=' . $this->dataDirectory : 'KNOSSOS_DATA_DIR');
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        putenv(is_string($this->rootsFile) ? 'KNOSSOS_ROOTS_FILE=' . $this->rootsFile : 'KNOSSOS_ROOTS_FILE');
        parent::tearDown();
    }

    private function context(): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
    }

    /**
     * Runs one command in process and decodes its JSON output.
     *
     * @param list<string> $positionals
     * @param array<string, list<string>> $options
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function runJson(string $command, array $positionals, array $options): array
    {
        ob_start();
        $status = (new BriefCommand())->run($command, $positionals, $options + ['json' => ['true']], $this->context());
        $decoded = json_decode((string) ob_get_clean(), true, flags: JSON_THROW_ON_ERROR);

        return [$status, $decoded];
    }

    /** A copy of the fixture scanned into its own on-disk database, with a roots file allowing it. */
    private function scannedFixtureOnDisk(): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        file_put_contents($root . '/a, b.php', "<?php\n\ndeclare(strict_types=1);\n\nfinal class Comma {}\n");
        mkdir($root . '/.knossos', 0o777, true);
        file_put_contents(
            $root . '/.knossos/roots.json',
            json_encode(['roots' => [(string) realpath($root)]], JSON_THROW_ON_ERROR),
        );
        $pdo = SqliteConnection::open($root . '/.knossos/knossos.sqlite');
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        $this->backdateDirectories($root, 10);

        return $root;
    }

    /** Scans the on-disk fixture again, as `knossos scan` would. */
    private function rescanOnDisk(string $root): void
    {
        $pdo = SqliteConnection::open($root . '/.knossos/knossos.sqlite');
        (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
    }

    /** A temp directory named so removeTempTree() will accept it. */
    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        return $directory;
    }

    #[Group('cli')]
    public function testCommandIsRoutedAndChecksItsOwnOptions(): void
    {
        $command = new BriefCommand();

        assertSame(true, $command->supports('turn-brief'));
        assertSame(true, $command->supports('dashboard'));
        assertSame(true, $command->supports('component-detail'));
        assertSame(true, $command->supports('file-detail'));
        assertSame(true, $command->supports('rescan'));
        assertSame(true, $command->supports('session-changes'));
        assertSame(true, $command->supports('boundary-couplings'));
        assertSame(false, $command->supports('session-brief'));
        assertSame(false, $command->supports('scan'));
        foreach (['turn-brief', 'rescan', 'dashboard', 'component-detail', 'file-detail', 'session-changes', 'boundary-couplings'] as $name) {
            assertSame([CliOptionParser::ANY], $command->allowedOptions($name));
        }
    }

    /** Through the router, as the binary runs it: an unknown option is an error status, never a non-zero exit. */
    #[Group('cli')]
    public function testAnUnknownOptionIsAnErrorStatusNotAFailure(): void
    {
        $root = $this->scannedFixtureOnDisk();
        $router = new CliCommandRouter(self::repositoryRoot(), new CliOptionParser(), new CliHelpRenderer(), 'test');
        try {
            $known = [
                'turn-brief' => ['files', 'policies', 'no-policies'],
                'dashboard' => ['fan-in-threshold'],
                'component-detail' => [],
                'file-detail' => [],
            ];
            foreach ($known as $command => $own) {
                $positionals = match ($command) {
                    'component-detail' => [$root, 'Greeter'],
                    'file-detail' => [$root . '/src/Core/Greeter.php'],
                    default => [$root],
                };
                ob_start();
                $status = $router->route($command, $positionals, ['bogus' => ['1'], 'json' => ['true']]);
                assertSame(['status' => 'error'], json_decode((string) ob_get_clean(), true), $command);
                assertSame(0, $status, $command);
                // Another command's option is as unknown as a typo.
                $foreign = array_values(array_diff(['files', 'fan-in-threshold'], $own))[0];
                assertSame(['status' => 'error'], $this->runJson($command, $positionals, [$foreign => ['1']])[1], $command);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAStrayArgumentIsAnErrorStatus(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            assertSame(['status' => 'error'], $this->runJson('turn-brief', [$root, 'extra'], [])[1]);
            assertSame(['status' => 'error'], $this->runJson('dashboard', [$root, 'extra'], [])[1]);
            assertSame(['status' => 'error'], $this->runJson('component-detail', [$root, 'Greeter', 'extra'], [])[1]);
            assertSame(['status' => 'error'], $this->runJson('file-detail', [$root . '/src/Core/Greeter.php', 'extra'], [])[1]);
            assertSame('ok', $this->runJson('dashboard', [$root], [])[1]['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** With no threshold given, a file needs 20 dependent files to enter the fan-in map. */
    #[Group('cli')]
    public function testTheFanInThresholdDefaultsToTwenty(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            $greeter = static fn(array $out): ?int => array_column($out['fan_in'], 'dependent_files', 'path')[self::TARGET] ?? null;
            $existing = (int) $greeter($this->runJson('dashboard', [$root], ['fan-in-threshold' => ['1']])[1]);
            assertSame(true, $existing > 0 && $existing < 20);
            foreach (range(1, 20 - $existing) as $n) {
                file_put_contents($root . '/src/Edge/Extra' . $n . '.php', sprintf(
                    "<?php\n\ndeclare(strict_types=1);\n\nnamespace App;\n\nfinal class Extra%d\n{\n    public function run(): string\n    {\n        return (new \\App\\Greeter())->greet('x');\n    }\n}\n",
                    $n,
                ));
            }
            $this->rescanOnDisk($root);
            assertSame(20, $greeter($this->runJson('dashboard', [$root], [])[1]));
            unlink($root . '/src/Edge/Extra1.php');
            $this->rescanOnDisk($root);
            assertSame(null, $greeter($this->runJson('dashboard', [$root], [])[1]));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testTurnBriefPrintsTheEnvelopeAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('turn-brief', [$root], []);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testTurnBriefNeverCreatesADatabase(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            // The dotted form can only come back normalised if the path is resolved.
            [$status, $out] = $this->runJson('turn-brief', [$directory . '/.'], []);
            assertSame(0, $status);
            assertSame('unscanned', $out['status']);
            assertSame(realpath($directory), $out['path']);
            assertFalse(is_dir($directory . '/.knossos'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testDashboardNeverCreatesADatabase(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            [$status, $out] = $this->runJson('dashboard', [$directory], []);
            assertSame(0, $status);
            assertSame('unscanned', $out['status']);
            assertFalse(is_dir($directory . '/.knossos'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testRescanPrintsItsStatusEnvelopeAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            file_put_contents($root . '/' . self::TARGET, file_get_contents($root . '/' . self::TARGET) . "\n// touched\n");
            [$status, $out] = $this->runJson('rescan', [$root], []);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
            assertSame(realpath($root), $out['project_root']);
            assertSame(true, is_string($out['snapshot_id']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testRescanNeverCreatesADatabase(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            [$status, $out] = $this->runJson('rescan', [$directory], []);
            assertSame(0, $status);
            assertSame('unscanned', $out['status']);
            assertFalse(is_dir($directory . '/.knossos'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** Only the options the pane passes: a turn brief's are refused, still with exit 0. */
    #[Group('cli')]
    public function testRescanRefusesTheTurnBriefOptions(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('rescan', [$root], ['files' => ['a.php']]);
            assertSame(0, $status);
            assertSame(['status' => 'error'], $out);
            [$status, $out] = $this->runJson('rescan', [$root, 'extra'], []);
            assertSame(0, $status);
            assertSame(['status' => 'error'], $out);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testRepeatedFilesOptionsAreAllPassedThrough(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            // Nothing is edited: only the option can put these two in the list.
            [$status, $out] = $this->runJson('turn-brief', [$root], ['files' => [self::TARGET, 'a, b.php']]);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
            assertContains(self::TARGET, $out['changed_files']);
            assertContains('a, b.php', $out['changed_files']);
            assertSame(2, count($out['changed_files']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testNoPoliciesFlagSkipsPolicyEvaluation(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [, $out] = $this->runJson('turn-brief', [$root], ['no-policies' => ['true']]);
            assertSame('ok', $out['status']);
            assertSame('disabled', $out['policy']['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAMissingPoliciesFileIsAnErrorStatusNotAFailure(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('turn-brief', [$root], ['policies' => [$root . '/absent.json']]);
            assertSame(0, $status);
            assertSame(['status' => 'error'], $out);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testDashboardPrintsTheEnvelopeAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('dashboard', [$root], ['fan-in-threshold' => ['1']]);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
            assertIsList($out['fan_in']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testDashboardRejectsANonNumericThreshold(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('dashboard', [$root], ['fan-in-threshold' => ['abc']]);
            assertSame(0, $status);
            assertSame(['status' => 'error'], $out);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testDashboardThresholdBoundsAreEnforced(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            assertSame(['status' => 'error'], $this->runJson('dashboard', [$root], ['fan-in-threshold' => ['0']])[1]);
            assertSame(['status' => 'error'], $this->runJson('dashboard', [$root], ['fan-in-threshold' => ['100001']])[1]);
            assertSame('ok', $this->runJson('dashboard', [$root], ['fan-in-threshold' => ['100000']])[1]['status']);
            assertSame('ok', $this->runJson('dashboard', [$root], ['fan-in-threshold' => ['1']])[1]['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAFailureWithoutJsonPrintsNothing(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            ob_start();
            $status = (new BriefCommand())->run('dashboard', [$root], ['fan-in-threshold' => ['abc']], $this->context());
            assertSame('', (string) ob_get_clean());
            assertSame(0, $status);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testHelpListsBothCommands(): void
    {
        $stream = fopen('php://memory', 'w+');
        (new CliHelpRenderer($stream))->render();
        rewind($stream);
        $help = (string) stream_get_contents($stream);

        assertStringContainsString('knossos turn-brief [path]', $help);
        assertStringContainsString('knossos dashboard [path]', $help);
        assertStringContainsString('knossos component-detail [path] <name>', $help);
        assertStringContainsString('knossos rescan [path]', $help);
        assertStringContainsString('knossos file-detail <file>', $help);
        assertStringContainsString('knossos boundary-couplings [path] --from=BOUNDARY --to=BOUNDARY', $help);
    }

    /** One heat map cell spelled out: both boundaries are required, nothing else is taken, and the answer is JSON. */
    #[Group('cli')]
    public function testBoundaryCouplingsPrintsTheEnvelopeAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('boundary-couplings', [$root], ['from' => ['Edge'], 'to' => ['Core']]);
            assertSame([0, 'ok', 'Edge', 'Core'], [$status, $out['status'], $out['from'], $out['to']]);
            assertIsList($out['couplings']);
            foreach ([['from' => ['Edge']], ['to' => ['Core']], ['from' => ['Edge'], 'to' => ['Core'], 'since' => ['x']]] as $options) {
                [$status, $out] = $this->runJson('boundary-couplings', [$root], $options);
                assertSame([0, 'error'], [$status, $out['status']]);
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testGraphSearchFileContextAndBranchDiffPrintTheirEnvelopesAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('graph-search', [$root], ['query' => ['greeter']]);
            assertSame([0, 'ok', 'greeter'], [$status, $out['status'], $out['query']]);
            assertSame('Greeter', $out['results'][0]['name']);
            [$status, $out] = $this->runJson('file-context', [$root . '/src/Core/Greeter.php'], []);
            assertSame([0, 'ok', 'src/Core/Greeter.php'], [$status, $out['status'], $out['file']['path']]);
            // The fixture is no repository: nothing to compare a branch with.
            [$status, $out] = $this->runJson('branch-diff', [$root], []);
            assertSame([0, 'no-git'], [$status, $out['status']]);
            foreach ([['graph-search', [$root], ['since' => ['x']]], ['file-context', [], []], ['file-context', [$root . '/a.php', 'b'], []], ['branch-diff', [$root], ['query' => ['x']]]] as [$command, $positionals, $options]) {
                assertSame([0, ['status' => 'error']], $this->runJson($command, $positionals, $options));
            }
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Through the router, as the binary runs them: a directory never scanned is unscanned, and no database is created. */
    #[Group('cli')]
    public function testGraphSearchFileContextAndBranchDiffNeverCreateADatabase(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory . '/a.php', "<?php\n");
        $router = new CliCommandRouter(self::repositoryRoot(), new CliOptionParser(), new CliHelpRenderer(), 'test');
        try {
            foreach ([['graph-search', [$directory], ['query' => ['a']]], ['file-context', [$directory . '/a.php'], []], ['branch-diff', [$directory], []]] as [$command, $positionals, $options]) {
                ob_start();
                $status = $router->route($command, $positionals, $options + ['json' => ['true']]);
                $out = json_decode((string) ob_get_clean(), true);
                assertSame([0, 'unscanned'], [$status, $out['status']], $command);
            }
            assertFalse(is_dir($directory . '/.knossos'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testFileDetailPrintsTheEnvelopeAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('file-detail', [$root . '/src/Core/Greeter.php'], []);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
            assertSame('src/Core/Greeter.php', $out['file']['path']);
            assertSame(['src/Edge/Caller.php', 'tests/GreeterTest.php'], array_column($out['file']['dependents']['items'], 'path'));
            [, $missing] = $this->runJson('file-detail', [$root . '/src/Nope.php'], []);
            assertSame('not-found', $missing['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Through the router, as the binary runs it: the database comes from the file's own path, and a missing one is never created. */
    #[Group('cli')]
    public function testFileDetailNeverCreatesADatabase(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents($directory . '/a.php', "<?php
");
        $router = new CliCommandRouter(self::repositoryRoot(), new CliOptionParser(), new CliHelpRenderer(), 'test');
        try {
            ob_start();
            $status = $router->route('file-detail', [$directory . '/a.php'], ['json' => ['true']]);
            $out = json_decode((string) ob_get_clean(), true);
            assertSame(0, $status);
            assertSame('unscanned', $out['status']);
            assertFalse(is_dir($directory . '/.knossos'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testFileDetailWithoutAPathIsAnErrorStatus(): void
    {
        assertSame([0, ['status' => 'error']], $this->runJson('file-detail', [], []));
    }

    #[Group('cli')]
    public function testComponentDetailPrintsTheEnvelopeAsJson(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            [$status, $out] = $this->runJson('component-detail', [$root . '/src', 'Greeter'], []);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
            assertSame('App\Greeter', $out['component']['name']);
            [, $missing] = $this->runJson('component-detail', [$root, 'Nope'], []);
            assertSame('not-found', $missing['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testComponentDetailWithOneArgumentReadsTheWorkingDirectory(): void
    {
        $root = $this->scannedFixtureOnDisk();
        $previous = (string) getcwd();
        try {
            chdir($root);
            [$status, $out] = $this->runJson('component-detail', ['Greeter'], []);
            assertSame(0, $status);
            assertSame('ok', $out['status']);
        } finally {
            chdir($previous);
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testComponentDetailNeverCreatesADatabase(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            [$status, $out] = $this->runJson('component-detail', [$directory, 'Greeter'], []);
            assertSame(0, $status);
            assertSame('unscanned', $out['status']);
            assertFalse(is_dir($directory . '/.knossos'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testComponentDetailWithoutANameIsAnErrorStatus(): void
    {
        $root = $this->scannedFixtureOnDisk();
        try {
            assertSame(['status' => 'error'], $this->runJson('component-detail', [], [])[1]);
            assertSame(['status' => 'error'], $this->runJson('component-detail', [$root, '  '], [])[1]);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
