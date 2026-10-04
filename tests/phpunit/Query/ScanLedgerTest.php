<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\ScanLedger;
use Knossos\Query\ScanLedgerSpan;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertLessThan;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

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
            assertSame(['before' => [], 'baselines' => [], 'scans' => [], 'chain' => [], 'approximate' => false], $ledger->since($projectId, 's2'));
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

    /** Records scan `$to` from the active snapshot, changing `$path` from `$was` to `$now` (null: absent), and makes it the active one. */
    private static function scan(PDO $pdo, ScanLedger $ledger, string $projectId, string $to, string $path, ?string $was, ?string $now): void
    {
        $from = $ledger->activeSnapshot($projectId);
        self::activate($pdo, $projectId, $to);
        $ledger->record($projectId, $from, $to, $was === null ? [] : [$path => $was], $now === null ? [] : [$path => $now]);
    }

    /** A snapshot id as a scan makes one: `scan_` and a hash. */
    private static function id(int $n): string
    {
        return 'scan_' . hash('sha256', (string) $n);
    }

    #[Group('query')]
    public function testAScanThatChangedNothingIsNotRecordedAndOldEntriesAreMergedNotDropped(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $ledger->record($projectId, 's1', 's1', ['a.php' => 'A'], ['a.php' => 'A']);
            assertSame(0, self::entries($pdo));
            $start = (string) $ledger->activeSnapshot($projectId);
            for ($i = 1; $i <= ScanLedger::KEPT + 5; ++$i) {
                self::scan($pdo, $ledger, $projectId, 's' . $i, 'f' . ($i % 7) . '.php', 'H' . ($i - 1), 'H' . $i);
            }
            // Past the bound the oldest entries became one span, then four more were recorded: the start is still answered.
            assertSame(ScanLedger::COMPACTED + 4, self::entries($pdo));
            $since = $ledger->since($projectId, $start);
            assertSame(ScanLedger::KEPT + 5, array_sum(array_map(static fn(array $scan): int => $scan['merged'] ?? 1, $since['chain'] ?? [])));
            assertSame(['f1.php' => 'H0', 'f2.php' => 'H1', 'f3.php' => 'H2', 'f4.php' => 'H3', 'f5.php' => 'H4', 'f6.php' => 'H5', 'f0.php' => 'H6'], $since['before'] ?? null);
            assertFalse($since['approximate'] ?? true);
            assertSame(['snapshot_id' => $start], array_intersect_key((array) $ledger->reach($projectId), ['snapshot_id' => true]));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** A day of watcher scans: every start in it is still answered, from the first, inside the merged scans and among the newest, in bounded space. */
    #[Group('query')]
    public function testALongSessionStillAnswersSinceItsStartInBoundedSpace(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $start = (string) $ledger->activeSnapshot($projectId);
            $hashes = [];
            // Early files change in the first thousand scans only, late ones after; one file is added at scan 2,000 and one deleted at 2,500.
            for ($i = 1; $i <= 3000; ++$i) {
                $path = $i === 2000 ? 'added.php' : ($i === 2500 ? 'early/E3.php' : ($i < 1000 ? 'early/E' . ($i % 20) . '.php' : 'late/L' . ($i % 30) . '.php'));
                $was = $hashes[$path] ?? ($i === 2000 ? null : 'X' . $path);
                $now = $i === 2500 ? null : sprintf('scan_%064d', $i);
                self::scan($pdo, $ledger, $projectId, self::id($i), $path, $was, $now);
                if ($now === null) {
                    unset($hashes[$path]);
                } else {
                    $hashes[$path] = $now;
                }
            }
            assertLessThan(ScanLedger::KEPT + 1, self::entries($pdo));
            // Bounded: the span holds 3,000 scans' keys and 51 files in well under a few hundred kilobytes.
            assertLessThan(150_000, (int) $pdo->query('SELECT MAX(LENGTH(changes_json)) FROM scan_ledger')->fetchColumn());

            $whole = $ledger->since($projectId, $start);
            assertSame(3000, array_sum(array_map(static fn(array $scan): int => $scan['merged'] ?? 1, $whole['chain'] ?? [])));
            assertCount(51, $whole['before'] ?? []);
            assertSame('Xearly/E1.php', $whole['before']['early/E1.php'] ?? null);
            assertSame('Xearly/E3.php', $whole['before']['early/E3.php'] ?? null);
            assertTrue(array_key_exists('added.php', $whole['before'] ?? []) && $whole['before']['added.php'] === null);
            assertFalse($whole['approximate'] ?? true);

            // A session that began at scan 1,500, merged long ago: only what changed after it, said to be approximate.
            $middle = $ledger->since($projectId, self::id(1500));
            assertTrue($middle['approximate'] ?? false);
            $paths = array_keys($middle['before'] ?? []);
            sort($paths);
            assertSame(['added.php', 'early/E3.php', ...array_map(static fn(int $n): string => 'late/L' . $n . '.php', [0, 1, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 2, 20, 21, 22, 23, 24, 25, 26, 27, 28, 29, 3, 4, 5, 6, 7, 8, 9])], $paths);
            assertSame(1500, array_sum(array_map(static fn(array $scan): int => $scan['merged'] ?? 1, $middle['chain'] ?? [])));
            // Each file still names the scans that changed it after the start, the newest last and in full.
            $named = $middle['scans']['late/L0.php'] ?? [];
            assertSame(self::id(3000), $named[count($named) - 1] ?? null);
            // The merged ones by their key, the newest the span keeps per file.
            assertSame(ScanLedgerSpan::key((string) ($named[0] ?? '')), $named[0] ?? null);
            assertSame(17, strlen((string) ($named[0] ?? '')));
            assertLessThan(ScanLedgerSpan::SCANS_PER_FILE + 1, count(array_filter($named, static fn(string $id): bool => strlen($id) === 17)));

            // Among the newest entries the answer is exact.
            $recent = $ledger->since($projectId, self::id(2990));
            assertFalse($recent['approximate'] ?? true);
            assertSame(10, count($recent['chain'] ?? []));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /** The same entries always merge into the same span, whatever the clock, and a writer's repeated entry is taken in once. */
    #[Group('query')]
    public function testMergedEntriesAreDeterministicAndTakeARepeatedEntryInOnce(): void
    {
        $spans = [];
        foreach ([0, 1] as $run) {
            [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
            try {
                $start = (string) (new ScanLedger($pdo))->activeSnapshot($projectId);
                $insert = $pdo->prepare('INSERT INTO scan_ledger(project_id, from_snapshot, to_snapshot, recorded_at, changes_json) VALUES (?, ?, ?, ?, ?)');
                $from = $start;
                for ($i = 1; $i <= ScanLedger::KEPT; ++$i) {
                    $json = (string) json_encode(['before' => ['f' . ($i % 3) . '.php' => 'H' . $i, 'g.php' => null], 'baselines' => null]);
                    $insert->execute([$projectId, $from, 's' . $i, 1_000 + $i, $json]);
                    // The second entry is written twice, as a writer that also recorded its child's scan did.
                    if ($i === 2) {
                        $insert->execute([$projectId, $from, 's' . $i, 1_000 + $i, $json]);
                    }
                    $from = 's' . $i;
                }
                self::activate($pdo, $projectId, 'last');
                (new ScanLedger($pdo))->record($projectId, $from, 'last', ['f0.php' => 'Y'], ['f0.php' => 'Z']);
                $spans[$run] = $pdo->query('SELECT to_snapshot, recorded_at, changes_json FROM scan_ledger ORDER BY id LIMIT 1')->fetch(PDO::FETCH_ASSOC);
                $since = (new ScanLedger($pdo))->since($projectId, $start);
                assertSame(ScanLedger::KEPT + 1, array_sum(array_map(static fn(array $scan): int => $scan['merged'] ?? 1, $since['chain'] ?? [])));
                assertSame(['f1.php' => 'H1', 'g.php' => null, 'f2.php' => 'H2', 'f0.php' => 'H3'], $since['before'] ?? null);
            } finally {
                $this->removeTempTree($root);
            }
            usleep(1_100_000 * $run);
        }
        assertSame($spans[0], $spans[1]);
        $span = json_decode((string) $spans[0]['changes_json'], true);
        assertSame(1001, $span['span']['began']);
        assertSame(ScanLedger::KEPT + 1 - ScanLedger::COMPACTED + 1, $span['span']['steps']);
        assertSame([0, 0, 50], [$span['span']['files']['f1.php'][0], $span['span']['files']['g.php'][0], count($span['span']['files']['g.php']) === ScanLedgerSpan::SCANS_PER_FILE + 1 ? 50 : -1]);
    }

    /** An entry no chain to the active snapshot passes through goes first; a cut one ends what the merge can account for. */
    #[Group('query')]
    public function testEntriesThatLeadNowhereGoAndACutEntryEndsTheReach(): void
    {
        [$pdo, $projectId, $root] = $this->scanTempFixture('turn-brief');
        try {
            $ledger = new ScanLedger($pdo);
            $start = (string) $ledger->activeSnapshot($projectId);
            for ($i = 1; $i <= 100; ++$i) {
                self::scan($pdo, $ledger, $projectId, 's' . $i, 'a.php', 'A' . ($i - 1), 'A' . $i);
            }
            // A branch switch, recorded cut.
            $before = [];
            $after = [];
            for ($f = 0; $f < ScanLedger::MAX_FILES + 1; ++$f) {
                $before['src/F' . $f . '.php'] = 'x';
                $after['src/F' . $f . '.php'] = 'y';
            }
            self::activate($pdo, $projectId, 'switched');
            $ledger->record($projectId, 's100', 'switched', $before, $after);
            for ($i = 101; $i <= ScanLedger::KEPT + 10; ++$i) {
                self::scan($pdo, $ledger, $projectId, 's' . $i, 'b.php', 'B' . ($i - 1), 'B' . $i);
            }
            // Everything up to and including the cut entry led nowhere and went; the rest is answered.
            assertNull($ledger->since($projectId, $start));
            assertSame(['snapshot_id' => 'switched'], array_intersect_key((array) $ledger->reach($projectId), ['snapshot_id' => true]));
            assertSame(['b.php' => 'B100'], $ledger->since($projectId, 'switched')['before'] ?? null);
            assertSame(ScanLedger::KEPT + 10 - 100, self::entries($pdo));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * Two writers recording at once, each under the project's write lease as
     * every recording writer is: the merges their recordings set off never
     * lose or tangle a scan.
     */
    #[Group('query')]
    public function testConcurrentRecordersCannotCorruptAMerge(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root);
        $database = $root . '/knossos.sqlite';
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $pdo->exec('PRAGMA foreign_keys = OFF');
            $pdo->prepare("INSERT INTO projects(id, name, root_realpath, config_json, active_scan_id, created_at, updated_at) VALUES ('p', 'p', ?, '{}', 'start', 'now', 'now')")->execute([$root]);
            $script = <<<'PHP'
                require $argv[1] . '/vendor/autoload.php';
                [, , $database, $writer, $count] = $argv;
                $pdo = Knossos\Store\SqliteConnection::open($database);
                $pdo->exec('PRAGMA foreign_keys = OFF');
                $ledger = new Knossos\Query\ScanLedger($pdo);
                for ($i = 1; $i <= (int) $count; ++$i) {
                    for (;;) {
                        try {
                            $lease = (new Knossos\Scan\ProjectWriterLock($pdo))->acquire('p');
                            break;
                        } catch (Knossos\Scan\ScanBusyException) {
                            usleep(random_int(100, 2000));
                        }
                    }
                    $from = $ledger->activeSnapshot('p');
                    $to = $writer . '-' . $i;
                    $pdo->prepare('UPDATE projects SET active_scan_id = ? WHERE id = ?')->execute([$to, 'p']);
                    $ledger->record('p', $from, $to, [$writer . '.php' => $writer . ($i - 1)], [$writer . '.php' => $writer . $i]);
                    $lease->release();
                }
                PHP;
            $processes = [];
            foreach (['w1', 'w2'] as $writer) {
                $processes[] = proc_open([PHP_BINARY, '-r', $script, self::repositoryRoot(), $database, $writer, '300'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $outputs[] = $pipes;
            }
            foreach ($processes as $n => $process) {
                assertNotFalse($process);
                $errors = stream_get_contents($outputs[$n][2]);
                stream_get_contents($outputs[$n][1]);
                assertSame(0, proc_close($process), (string) $errors);
            }
            $ledger = new ScanLedger($pdo);
            assertLessThan(ScanLedger::KEPT + 1, self::entries($pdo));
            $since = $ledger->since('p', 'start');
            assertSame(600, array_sum(array_map(static fn(array $scan): int => $scan['merged'] ?? 1, $since['chain'] ?? [])));
            $before = $since['before'] ?? [];
            ksort($before);
            assertSame(['w1.php' => 'w10', 'w2.php' => 'w20'], $before);
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
