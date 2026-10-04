<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\LedgeredScanner;
use Knossos\Query\ScanLedger;
use Knossos\Query\SessionChangesService;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

/**
 * What changed in a project since a session began, whoever changed it, read
 * from the scan ledger: files with their dependents and the tests that reach
 * them, bounded, and nothing at all when the ledger cannot account for it.
 */
final class SessionChangesServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    /** A recorded scan, as the live watcher's. */
    private function ledgered(PDO $pdo, string $root): void
    {
        LedgeredScanner::local($pdo, self::repositoryRoot(), [(string) realpath($root)])->scan($root, mode: 'incremental');
    }

    /** @return array<string, mixed> */
    private static function changes(PDO $pdo, string $root, string $since): array
    {
        return json_decode((string) json_encode((new SessionChangesService($pdo))->changes($root, $since)), true);
    }

    #[Group('query')]
    public function testEveryRecordedChangeSinceTheSessionBeganIsListedWithItsReachAndTests(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Greeter.php', "\n// edited elsewhere\n", FILE_APPEND);
            file_put_contents($root . '/src/Core/Added.php', "<?php\nnamespace App;\nfinal class Added {}\n");
            $this->ledgered($pdo, $root);
            unlink($root . '/src/Edge/Caller.php');
            $this->ledgered($pdo, $root);
            $changes = self::changes($pdo, $root, $since);
            assertSame('ok', $changes['status']);
            assertSame(true, $changes['complete']);
            assertSame(['src/Core/Greeter.php', 'src/Core/Added.php', 'src/Edge/Caller.php'], array_keys($changes['files']));
            assertSame('changed', $changes['files']['src/Core/Greeter.php']['status']);
            assertSame('Core', $changes['files']['src/Core/Greeter.php']['boundary']);
            assertSame('added', $changes['files']['src/Core/Added.php']['status']);
            assertSame('deleted', $changes['files']['src/Edge/Caller.php']['status']);
            assertSame(['tests/GreeterTest.php'], array_column($changes['tests'], 'path'));
            assertSame(false, $changes['files_truncated']);
            // A file put back as it was is no change.
            file_put_contents($root . '/src/Core/Added.php', '');
            unlink($root . '/src/Core/Added.php');
            $this->ledgered($pdo, $root);
            assertSame(false, array_key_exists('src/Core/Added.php', self::changes($pdo, $root, $since)['files']));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testEachFileNamesTheSnapshotsOfTheScansThatChangedIt(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $ledger = new ScanLedger($pdo);
            $since = (string) $ledger->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Greeter.php', "\n// first\n", FILE_APPEND);
            $this->ledgered($pdo, $root);
            $first = (string) $ledger->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Greeter.php', "\n// second\n", FILE_APPEND);
            file_put_contents($root . '/src/Core/Added.php', "<?php\nnamespace App;\nfinal class Added {}\n");
            $this->ledgered($pdo, $root);
            $second = (string) $ledger->activeSnapshot($projectId);
            $changes = self::changes($pdo, $root, $since);
            $files = $changes['files'];
            assertSame([$first, $second], $files['src/Core/Greeter.php']['scans']);
            assertSame([$second], $files['src/Core/Added.php']['scans']);
            // The scans themselves, in the order they were recorded, each with when and how many files it changed.
            assertSame([[$first, 1], [$second, 2]], array_map(static fn(array $scan): array => [$scan['snapshot_id'], $scan['files']], $changes['scans']));
            assertTrue(is_int($changes['scans'][0]['at']) && $changes['scans'][0]['at'] <= $changes['scans'][1]['at']);
            assertFalse($changes['scans_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testOnlyTheNewestScansOfAFileAreNamed(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $ledger = new ScanLedger($pdo);
            $since = (string) $ledger->activeSnapshot($projectId);
            $hashes = $ledger->hashes($projectId);
            $from = $since;
            $pdo->exec('PRAGMA foreign_keys = OFF');
            for ($i = 0; $i < SessionChangesService::MAX_SCANS + 5; ++$i) {
                $after = ['src/Core/Greeter.php' => 'h' . $i] + $hashes;
                $ledger->record($projectId, $from, 's' . $i, $hashes, $after);
                $hashes = $after;
                $from = 's' . $i;
            }
            $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute([$from, $projectId]);
            $pdo->prepare('UPDATE files SET content_hash = ? WHERE project_id = ? AND relative_path = ?')->execute([$hashes['src/Core/Greeter.php'], $projectId, 'src/Core/Greeter.php']);
            $scans = self::changes($pdo, $root, $since)['files']['src/Core/Greeter.php']['scans'];
            assertCount(SessionChangesService::MAX_SCANS, $scans);
            assertSame('s' . (SessionChangesService::MAX_SCANS + 4), $scans[SessionChangesService::MAX_SCANS - 1]);
            assertSame('s5', $scans[0]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheTimelineKeepsTheNewestScansAndSaysItWasCut(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $ledger = new ScanLedger($pdo);
            $since = (string) $ledger->activeSnapshot($projectId);
            $hashes = $ledger->hashes($projectId);
            $from = $since;
            $pdo->exec('PRAGMA foreign_keys = OFF');
            for ($i = 0; $i < SessionChangesService::MAX_TIMELINE + 3; ++$i) {
                $after = ['src/Core/Greeter.php' => 'h' . $i] + $hashes;
                $ledger->record($projectId, $from, 's' . $i, $hashes, $after);
                $hashes = $after;
                $from = 's' . $i;
            }
            $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute([$from, $projectId]);
            $changes = self::changes($pdo, $root, $since);
            assertCount(SessionChangesService::MAX_TIMELINE, $changes['scans']);
            assertSame(['s3', 's' . (SessionChangesService::MAX_TIMELINE + 2)], [$changes['scans'][0]['snapshot_id'], $changes['scans'][SessionChangesService::MAX_TIMELINE - 1]['snapshot_id']]);
            assertTrue($changes['scans_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAScanTheLedgerNeverSawLeavesTheListIncompleteAndEmpty(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Greeter.php', "\n// edited\n", FILE_APPEND);
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'incremental');
            $changes = self::changes($pdo, $root, $since);
            assertSame('ok', $changes['status']);
            assertSame(false, $changes['complete']);
            assertSame([], $changes['files']);
            assertSame('unscanned', self::changes($pdo, sys_get_temp_dir() . '/knossos-stale-nowhere', $since)['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheFileListIsBoundedAndSaysSo(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $ledger = new ScanLedger($pdo);
            $since = (string) $ledger->activeSnapshot($projectId);
            $gone = [];
            for ($i = 0; $i < SessionChangesService::MAX_FILES + 50; ++$i) {
                $gone['src/Gone' . $i . '.php'] = 'h' . $i;
            }
            $ledger->record($projectId, $since, 's2', $gone + $ledger->hashes($projectId), $ledger->hashes($projectId));
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute(['s2', $projectId]);
            $changes = self::changes($pdo, $root, $since);
            assertCount(SessionChangesService::MAX_FILES, $changes['files']);
            assertSame(true, $changes['files_truncated']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
