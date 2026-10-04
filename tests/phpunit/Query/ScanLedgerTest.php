<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ScanLedger;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;

/** What each recorded scan changed, read back in order from the snapshot a turn began at. */
final class ScanLedgerTest extends KnossosTestCase
{
    /** Points the project's active snapshot at `$id`, as a finished scan would (no scan row stands behind it here). */
    private static function activate(PDO $pdo, string $projectId, string $id): void
    {
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute([$id, $projectId]);
    }

    private static function entries(PDO $pdo): int
    {
        return (int) $pdo->query('SELECT COUNT(*) FROM scan_ledger')->fetchColumn();
    }

    #[Group('query')]
    public function testScansSinceASnapshotRewindEachFileToBeforeTheFirstThatChangedIt(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $start = (string) $ledger->activeSnapshot($projectId);
            $violation = ['violations' => ['k' => ['policy_id' => 'p']], 'truncated' => false];
            $ledger->record($projectId, $start, 's1', ['a.php' => 'A0', 'b.php' => 'B0'], ['a.php' => 'A1', 'b.php' => 'B0', 'c.php' => 'C1'], ['a.php' => $violation]);
            $ledger->record($projectId, 's1', 's2', ['a.php' => 'A1', 'b.php' => 'B0', 'c.php' => 'C1'], ['a.php' => 'A2', 'c.php' => 'C1']);
            self::activate($pdo, $projectId, 's2');
            $since = $ledger->since($projectId, $start);
            // a.php as before the first scan, c.php added by it, b.php deleted by the second.
            assertSame(['a.php' => 'A0', 'c.php' => null, 'b.php' => 'B0'], $since['before'] ?? null);
            assertSame(['a.php' => $violation, 'c.php' => null, 'b.php' => null], $since['baselines'] ?? null);
            // Which scans changed each file, by the snapshot each produced.
            assertSame(['a.php' => ['s1', 's2'], 'c.php' => ['s1'], 'b.php' => ['s2']], $since['scans'] ?? null);
            // The scans themselves in recording order, each with how many files it changed.
            assertSame([['s1', 2], ['s2', 2]], array_map(static fn(array $scan): array => [$scan['snapshot_id'], $scan['files']], $since['chain'] ?? []));
            // From the middle, only the second scan counts.
            assertSame(['a.php' => 'A1', 'b.php' => 'B0'], $ledger->since($projectId, 's1')['before'] ?? null);
            // Nothing since the active snapshot.
            assertSame(['before' => [], 'baselines' => [], 'scans' => [], 'chain' => []], $ledger->since($projectId, 's2'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testTheLedgerSaysNothingWhenItCannotAccountForEveryScan(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $start = (string) $ledger->activeSnapshot($projectId);
            $ledger->record($projectId, $start, 's1', ['a.php' => 'A0'], ['a.php' => 'A1']);
            // An unrecorded scan moved the project on to s9.
            self::activate($pdo, $projectId, 's9');
            assertNull($ledger->since($projectId, $start));
            // A gap in the chain.
            $ledger->record($projectId, 's5', 's9', ['a.php' => 'A2'], ['a.php' => 'A3']);
            assertNull($ledger->since($projectId, $start));
            // A snapshot no recorded scan starts at.
            assertNull($ledger->since($projectId, 'unknown'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    #[Group('query')]
    public function testAScanThatChangedNothingIsNotRecordedAndOldEntriesArePruned(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $ledger->record($projectId, 's1', 's1', ['a.php' => 'A'], ['a.php' => 'A']);
            assertSame(0, self::entries($pdo));
            for ($i = 0; $i < 205; ++$i) {
                $ledger->record($projectId, 's' . $i, 's' . ($i + 1), ['a.php' => 'A' . $i], ['a.php' => 'A' . ($i + 1)]);
            }
            assertSame(200, self::entries($pdo));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** Two writers that read the graph before either scanned both start at one snapshot: the chain that reaches the active one is taken, whichever comes first. */
    #[Group('query')]
    public function testEntriesStartingAtTheSameSnapshotResolveToTheChainThatReachesTheActiveOne(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $start = (string) $ledger->activeSnapshot($projectId);
            $ledger->record($projectId, $start, 's2', ['a.php' => 'A0'], ['a.php' => 'A1']);
            $ledger->record($projectId, $start, 's3', ['a.php' => 'A0', 'b.php' => 'B0'], ['a.php' => 'A1', 'b.php' => 'B1']);
            self::activate($pdo, $projectId, 's3');
            assertSame(['a.php' => 'A0', 'b.php' => 'B0'], $ledger->since($projectId, $start)['before'] ?? null);
            // And recorded the other way round, the same answer.
            $pdo->exec('DELETE FROM scan_ledger');
            $ledger->record($projectId, $start, 's3', ['a.php' => 'A0', 'b.php' => 'B0'], ['a.php' => 'A1', 'b.php' => 'B1']);
            $ledger->record($projectId, $start, 's2', ['a.php' => 'A0'], ['a.php' => 'A1']);
            assertSame(['a.php' => 'A0', 'b.php' => 'B0'], $ledger->since($projectId, $start)['before'] ?? null);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A scan that changed thousands of files (a branch switch) is kept small, and a turn it spans gets no verdict. */
    #[Group('query')]
    public function testAnEntryForMoreFilesThanItListsIsCutAndSpansNoVerdict(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $start = (string) $ledger->activeSnapshot($projectId);
            $before = [];
            $after = [];
            for ($i = 0; $i < ScanLedger::MAX_FILES + 500; ++$i) {
                $before['src/F' . $i . '.php'] = str_repeat('a', 64);
                $after['src/F' . $i . '.php'] = str_repeat('b', 64);
            }
            $ledger->record($projectId, $start, 's1', $before, $after, ['src/F1.php' => ['violations' => [], 'truncated' => false]]);
            $size = (int) $pdo->query('SELECT MAX(LENGTH(changes_json)) FROM scan_ledger')->fetchColumn();
            assertLessThan(200, $size);
            self::activate($pdo, $projectId, 's1');
            assertNull($ledger->since($projectId, $start));
        } finally {
            $this->removeTempTree($root);
        }
    }
}
