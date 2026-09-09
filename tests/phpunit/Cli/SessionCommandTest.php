<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\SessionCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class SessionCommandTest extends KnossosTestCase
{
    /** @var string|false */
    private string|false $dataDirectory = false;
    /** @var string|false */
    private string|false $allowedRoots = false;

    /**
     * Two environment variables decide what these tests are actually testing.
     *
     * KNOSSOS_DATA_DIR wins over the path-derived database, so a machine that
     * happens to set it would send every test below at one shared graph;
     * clearing it is what pins the derivation these tests exist to exercise.
     * KNOSSOS_ALLOWED_ROOTS decides which of a verdict's two forms is rendered,
     * and the temp trees below lie outside any root a developer's roots.json
     * would name, so it is set to the temp directory to keep the asserted
     * strings from depending on whose machine runs them. Both are restored
     * afterwards.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->dataDirectory = getenv('KNOSSOS_DATA_DIR');
        $this->allowedRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        putenv('KNOSSOS_DATA_DIR');
        putenv('KNOSSOS_ALLOWED_ROOTS=' . sys_get_temp_dir());
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->dataDirectory) ? 'KNOSSOS_DATA_DIR=' . $this->dataDirectory : 'KNOSSOS_DATA_DIR');
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        parent::tearDown();
    }

    private function context(?string $databasePath = ':memory:'): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            $databasePath,
        );
    }

    #[Group('cli')]
    public function testCommandIsRoutedAndAcceptsOnlyItsOwnOptions(): void
    {
        $command = new SessionCommand();

        assertSame(true, $command->supports('session-brief'));
        assertSame(false, $command->supports('export-agent-brief'));
        assertSame(['db', 'json'], $command->allowedOptions('session-brief'));
    }

    #[Group('cli')]
    public function testUnscannedPathStillExitsZeroWithABrief(): void
    {
        $directory = $this->temporaryDirectory();
        try {
            ob_start();
            $status = (new SessionCommand())->run('session-brief', [$directory], [], $this->context());
            $output = (string) ob_get_clean();

            assertSame(0, $status);
            assertSame(true, str_contains($output, 'NOT SCANNED.'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testTheReadOnlyPathLeavesTheTargetDirectoryUntouched(): void
    {
        // The guarantee this command exists under: the session-start path never
        // scans, writes, installs or executes. It used to open the database the
        // way every other command does, which creates the data directory and
        // runs every migration, so a session started anywhere at all left a
        // migrated 290 KB SQLite file behind. Snapshotting the whole directory
        // rather than only probing for `.knossos` keeps this honest about any
        // other artefact a future change might drop.
        $directory = $this->temporaryDirectory();
        try {
            $before = $this->treeSnapshot($directory);

            ob_start();
            $status = (new SessionCommand())->run('session-brief', [$directory], [], $this->context());
            $output = (string) ob_get_clean();

            assertSame(0, $status);
            assertSame(true, str_contains($output, 'NOT SCANNED.'));
            assertSame($before, $this->treeSnapshot($directory));
            assertSame(false, is_dir($directory . '/.knossos'));
            assertSame(false, file_exists($directory . '/.knossos/knossos.sqlite'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    #[Group('cli')]
    public function testASubdirectoryOfAScannedProjectResolvesToThatProject(): void
    {
        // The hook passes a project directory while the process keeps whatever
        // working directory the session started in. When the database came from
        // the working directory, starting a session in a subdirectory read an
        // empty graph, reported a fully scanned project as NOT SCANNED, and
        // dropped an untracked `.knossos/` in that subdirectory, which
        // .gitignore's root-anchored `/.knossos/` does not cover.
        [$root, $projectId] = $this->scannedProjectOnDisk();
        $nested = $root . '/src/nested';
        try {
            $before = $this->treeSnapshot($nested);

            ob_start();
            $status = (new SessionCommand())->run('session-brief', [$nested], [], $this->context());
            $output = (string) ob_get_clean();

            assertSame(0, $status);
            assertSame(true, str_contains($output, $projectId));
            assertSame(false, str_contains($output, 'NOT SCANNED'));
            assertSame($before, $this->treeSnapshot($nested));
            assertSame(false, is_dir($nested . '/.knossos'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAnExplicitDatabaseOptionStillWinsOverTheDerivedPath(): void
    {
        // A container installation and every scripted invocation depend on this:
        // deriving from the target path must not quietly override a path the
        // caller named. The project below is reachable only through --db, since
        // the target has no `.knossos` of its own.
        [$root, $projectId] = $this->scannedProjectOnDisk();
        $elsewhere = $this->temporaryDirectory();
        $databasePath = $root . '/.knossos/knossos.sqlite';
        try {
            ob_start();
            $status = (new SessionCommand())->run(
                'session-brief',
                [$root],
                ['db' => [$databasePath]],
                $this->context($databasePath),
            );
            $output = (string) ob_get_clean();

            assertSame(0, $status);
            assertSame(true, str_contains($output, $projectId));
            assertSame(false, is_dir($elsewhere . '/.knossos'));
        } finally {
            $this->removeTempTree($elsewhere);
            $this->removeTempTree($root);
        }
    }

    #[Group('cli')]
    public function testAnUnusableDatabaseIsSilentAndStillExitsZero(): void
    {
        // The governing rule for this command: it may fail to help, and may
        // never cost anything. A session start must not inherit a stack trace.
        //
        // The database has to exist for this to test what it says: an absent one
        // is now answered from nothing at all, as the unscanned verdict, rather
        // than by opening anything. So the file is present and is not a
        // database, which fails at the first query instead of at the open.
        $directory = $this->temporaryDirectory();
        $databasePath = $directory . '/knossos.sqlite';
        file_put_contents($databasePath, "this is not a SQLite database\n");
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            $databasePath,
        );

        try {
            ob_start();
            $status = (new SessionCommand())->run('session-brief', ['/tmp'], ['db' => [$databasePath]], $context);
            $output = (string) ob_get_clean();

            assertSame(0, $status);
            assertSame('', trim($output));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /** A temp directory named so removeTempTree() will accept it. */
    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        return $directory;
    }

    /**
     * Every path under $directory, sorted, so "nothing appeared" is an equality
     * rather than a list of things somebody remembered to check for.
     *
     * @return list<string>
     */
    private function treeSnapshot(string $directory): array
    {
        $paths = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($items as $item) {
            $paths[] = $item->getPathname();
        }
        sort($paths);

        return $paths;
    }

    /**
     * A scanned project whose graph lives on disk at `<root>/.knossos/knossos.sqlite`,
     * with a nested source directory to start a session from and a roots file
     * covering the root, so the verdict takes its ordinary form.
     *
     * Built through SqliteGraphRepository rather than by running a real scan:
     * what is under test here is which database the command opens, and a real
     * scan would spend seconds proving nothing extra about that.
     *
     * @return array{0: string, 1: string} [root, projectId]
     */
    private function scannedProjectOnDisk(): array
    {
        $root = $this->temporaryDirectory();
        mkdir($root . '/src/nested', 0o777, true);
        file_put_contents($root . '/src/nested/Thing.php', "<?php\n");
        mkdir($root . '/.knossos', 0o777, true);
        $databasePath = $root . '/.knossos/knossos.sqlite';
        file_put_contents(
            $root . '/.knossos/roots.json',
            json_encode(['roots' => [(string) realpath($root)]], JSON_THROW_ON_ERROR),
        );

        $pdo = SqliteConnection::open($databasePath);
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $repository = new SqliteGraphRepository($pdo);
        $projectId = StableId::project('session-cli-' . bin2hex(random_bytes(4)));
        $scanId = StableId::scan($projectId, 'scan-1');
        $repository->saveProject($projectId, 'Session CLI Fixture', (string) realpath($root));
        $repository->createScan($scanId, $projectId, 'full', hash('sha256', 'session-cli'));
        $repository->saveFile(
            StableId::file($projectId, 'src/nested/Thing.php'),
            $projectId,
            'src/nested/Thing.php',
            hash('sha256', (string) file_get_contents($root . '/src/nested/Thing.php')),
            (int) filesize($root . '/src/nested/Thing.php'),
            (int) filemtime($root . '/src/nested/Thing.php'),
            'php',
            '0.1.0',
            $scanId,
        );
        $repository->completeScan($projectId, $scanId);

        return [$root, $projectId];
    }
}
