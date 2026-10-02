<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Scan\ProjectScanService;
use Knossos\Cli\CliCommandContext;
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

    /** A temp directory named so removeTempTree() will accept it. */
    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        return $directory;
    }

    private function touchFile(string $root, string $relative): void
    {
        $file = $root . '/' . $relative;
        file_put_contents($file, file_get_contents($file) . "\n// touched\n");
    }

    #[Group('cli')]
    public function testCommandIsRoutedAndAcceptsOnlyItsOwnOptions(): void
    {
        $command = new BriefCommand();

        assertSame(true, $command->supports('turn-brief'));
        assertSame(true, $command->supports('dashboard'));
        assertSame(false, $command->supports('session-brief'));
        assertSame(['db', 'json', 'files', 'policies', 'no-policies'], $command->allowedOptions('turn-brief'));
        assertSame(['db', 'json', 'fan-in-threshold'], $command->allowedOptions('dashboard'));
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
    }
}
