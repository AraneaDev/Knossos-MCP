<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\RescanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertIsInt;
use function PHPUnit\Framework\assertNotSame;
use function PHPUnit\Framework\assertSame;

/**
 * The pane's rescan: an incremental scan of an existing project inside an
 * allowed root, or a status saying why it did not run.
 */
final class RescanServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    private string|false $allowedRoots = false;

    private string|false $rootsFile = false;

    /** The temp directory is allowed so the fixture copies are scannable; restored in tearDown. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->allowedRoots = getenv('KNOSSOS_ALLOWED_ROOTS');
        $this->rootsFile = getenv('KNOSSOS_ROOTS_FILE');
        putenv('KNOSSOS_ALLOWED_ROOTS=' . sys_get_temp_dir());
        putenv('KNOSSOS_ROOTS_FILE');
    }

    protected function tearDown(): void
    {
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        putenv(is_string($this->rootsFile) ? 'KNOSSOS_ROOTS_FILE=' . $this->rootsFile : 'KNOSSOS_ROOTS_FILE');
        parent::tearDown();
    }

    private function service(PDO $pdo, string $databasePath = ':memory:'): RescanService
    {
        return new RescanService($pdo, $databasePath, self::repositoryRoot());
    }

    private function scans(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM scans')->fetchColumn();
    }

    /** A temp directory named so removeTempTree() will accept it. */
    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory, 0o777, true);

        return $directory;
    }

    #[Group('query')]
    public function testAnEditedProjectIsRescannedIntoANewSnapshot(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $before = (string) $pdo->query('SELECT active_scan_id FROM projects')->fetchColumn();
            $file = $root . '/src/Core/Greeter.php';
            file_put_contents($file, file_get_contents($file) . "\n// touched\n");
            $result = $this->service($pdo)->rescan($root . '/src');

            assertSame('ok', $result['status']);
            assertSame(realpath($root . '/src'), $result['path']);
            assertSame(realpath($root), $result['project_root']);
            assertSame((string) $pdo->query('SELECT id FROM projects')->fetchColumn(), $result['project_id']);
            assertNotSame($before, $result['snapshot_id']);
            assertSame($result['snapshot_id'], (string) $pdo->query('SELECT active_scan_id FROM projects')->fetchColumn());
            assertIsInt($result['scanned_at']);
            assertIsInt($result['scan_ms']);
            assertSame(null, $result['reason']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPathNoProjectContainsIsUnscannedAndNothingIsWritten(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $elsewhere = $this->temporaryDirectory();
        try {
            $scans = $this->scans($pdo);
            $result = $this->service($pdo)->rescan($elsewhere);
            assertSame('unscanned', $result['status']);
            assertSame(realpath($elsewhere), $result['path']);
            assertSame(null, $result['project_id']);
            assertSame($scans, $this->scans($pdo));
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM projects')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($elsewhere);
        }
    }

    #[Group('query')]
    public function testAPathOutsideTheAllowedRootsIsRefusedWithoutScanning(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        $data = $this->temporaryDirectory();
        try {
            putenv('KNOSSOS_ALLOWED_ROOTS=/nonexistent-root');
            $scans = $this->scans($pdo);
            $result = $this->service($pdo, $data . '/knossos.sqlite')->rescan($root);
            assertSame('not-allowed', $result['status']);
            assertSame($data . '/roots.json', $result['roots_file']);
            assertSame(realpath($root), $result['refused_root']);
            assertSame(null, $result['snapshot_id']);
            assertSame($scans, $this->scans($pdo));
        } finally {
            $this->removeTempTree($root);
            $this->removeTempTree($data);
        }
    }

    /** The path itself may be allowed while the ancestor root that would be scanned is not. */
    #[Group('query')]
    public function testAnAncestorProjectRootOutsideTheAllowedRootsIsRefused(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $real = (string) realpath($root);
            putenv('KNOSSOS_ALLOWED_ROOTS=' . $real . '/src');
            $scans = $this->scans($pdo);
            $result = $this->service($pdo)->rescan($real . '/src/Core');
            assertSame('not-allowed', $result['status']);
            assertSame($real, $result['refused_root']);
            assertSame($scans, $this->scans($pdo));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAPathThatIsNotThereIsMissing(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $result = $this->service($pdo)->rescan($root . '/no-such-directory');
            assertSame('missing', $result['status']);
            assertSame(null, $result['project_id']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAScanThatThrowsIsScanFailedWithItsReason(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $file = $root . '/src/Core/Greeter.php';
            file_put_contents($file, file_get_contents($file) . "\n// touched\n");
            // No scanner workers under this installation root, so the scan cannot run.
            $result = (new RescanService($pdo, ':memory:', $root . '/no-installation'))->rescan($root);
            assertSame('scan-failed', $result['status']);
            assertSame(realpath($root), $result['project_root']);
            assertSame(true, is_string($result['reason']) && $result['reason'] !== '');
        } finally {
            $this->removeTempTree($root);
        }
    }
}
