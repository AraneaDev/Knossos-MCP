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
            // Each file still there says how many tests reach it: none reaches the added class.
            assertSame(1, $changes['files']['src/Core/Greeter.php']['tests']);
            assertSame(0, $changes['files']['src/Core/Added.php']['tests']);
            assertSame(false, array_key_exists('tests', $changes['files']['src/Edge/Caller.php']));
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
            assertSame(null, $changes['reached']);
            assertSame('unscanned', self::changes($pdo, sys_get_temp_dir() . '/knossos-stale-nowhere', $since)['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A ledger that lost the session's start (an older knossos dropped its
     * oldest entries) still lists what it can account for: the changes since
     * the oldest snapshot it reaches, named with when, and not called complete.
     */
    #[Group('query')]
    public function testASessionOlderThanTheLedgerGetsTheChangesSinceTheOldestPointItReaches(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Greeter.php', "\n// first\n", FILE_APPEND);
            $this->ledgered($pdo, $root);
            $reached = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            file_put_contents($root . '/src/Core/Added.php', "<?php\nnamespace App;\nfinal class Added {}\n");
            $this->ledgered($pdo, $root);
            // The first entry dropped, as an older ledger's pruning did.
            $pdo->exec('DELETE FROM scan_ledger WHERE id = (SELECT MIN(id) FROM scan_ledger)');
            $changes = self::changes($pdo, $root, $since);
            assertSame('ok', $changes['status']);
            assertFalse($changes['complete']);
            assertSame($reached, $changes['reached']['snapshot_id'] ?? null);
            assertTrue(is_int($changes['reached']['at'] ?? null));
            assertSame(['src/Core/Added.php'], array_keys($changes['files']));
            // Nothing reachable at all: no list, and nothing named.
            $pdo->exec('DELETE FROM scan_ledger');
            $none = self::changes($pdo, $root, $since);
            assertSame([[], null], [$none['files'], $none['reached']]);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A session that began among scans the ledger merged lists what changed after it, and says its start is approximate. */
    #[Group('query')]
    public function testASessionThatBeganAmongMergedScansIsListedWithAnApproximateStart(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $ledger = new ScanLedger($pdo);
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $hashes = $ledger->hashes($projectId);
            $session = null;
            for ($i = 1; $i <= ScanLedger::KEPT + 1; ++$i) {
                $from = (string) $ledger->activeSnapshot($projectId);
                $path = $i < 10 ? 'src/Core/Greeter.php' : 'src/Edge/Caller.php';
                $next = [$path => 'scan-' . $i] + $hashes;
                $to = 'scan_' . hash('sha256', (string) $i);
                $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute([$to, $projectId]);
                $ledger->record($projectId, $from, $to, $hashes, $next);
                $hashes = $next;
                $session = $i === 20 ? $to : $session;
            }
            // The graph as those scans left it: both files differ from what the ledger says they began as.
            $set = $pdo->prepare('UPDATE files SET content_hash = ? WHERE project_id = ? AND relative_path = ?');
            $set->execute(['scan-9', $projectId, 'src/Core/Greeter.php']);
            $set->execute(['scan-' . (ScanLedger::KEPT + 1), $projectId, 'src/Edge/Caller.php']);
            $changes = self::changes($pdo, $root, (string) $session);
            assertTrue($changes['complete']);
            assertTrue($changes['start_approximate']);
            assertSame(['src/Edge/Caller.php'], array_keys($changes['files']));
            // The merged scans are one item of the timeline, which keeps the newest.
            assertTrue($changes['scans_truncated']);
            assertSame('scan_' . hash('sha256', (string) (ScanLedger::KEPT + 1)), $changes['scans'][count($changes['scans']) - 1]['snapshot_id']);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** More changed files than one test search takes: the tests are still found, a search per batch, rather than the whole read failing. */
    #[Group('query')]
    public function testMoreChangedFilesThanOneTestSearchTakesStillFindTheirTests(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            $since = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
            for ($i = 0; $i < 60; ++$i) {
                file_put_contents($root . "/src/Core/Many{$i}.php", "<?php\nnamespace App;\nfinal class Many{$i} {}\n");
            }
            // The greeter sorts after the many new files: its test is found by the second batch.
            file_put_contents($root . '/src/Core/Greeter.php', "\n// edited\n", FILE_APPEND);
            $this->ledgered($pdo, $root);
            $changes = self::changes($pdo, $root, $since);
            assertSame('ok', $changes['status']);
            assertSame(61, count($changes['files']));
            assertSame(['tests/GreeterTest.php'], array_column($changes['tests'], 'path'));
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
