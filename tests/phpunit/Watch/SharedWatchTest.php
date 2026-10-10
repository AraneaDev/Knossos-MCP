<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Cancellation\CancellationToken;
use Knossos\Discovery\AllowedRoots;
use Knossos\Query\ScanLedger;
use Knossos\Scan\ProjectScanService;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\SharedWatch;
use Knossos\Watch\WatchLock;
use PDO;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertNotNull;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;

/**
 * The live watcher the Claude Code mod starts: only an existing project in
 * an allowed root, one leader per project, followers that never scan, and a
 * watcher that stops once whoever started it is gone.
 */
final class SharedWatchTest extends KnossosTestCase
{
    private string|false $allowedRoots = false;

    private string|false $rootsFile = false;

    /** @var list<string> */
    private array $trees = [];

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
        foreach ($this->trees as $tree) {
            $this->removeTempTree($tree);
        }
        putenv(is_string($this->allowedRoots) ? 'KNOSSOS_ALLOWED_ROOTS=' . $this->allowedRoots : 'KNOSSOS_ALLOWED_ROOTS');
        putenv(is_string($this->rootsFile) ? 'KNOSSOS_ROOTS_FILE=' . $this->rootsFile : 'KNOSSOS_ROOTS_FILE');
        parent::tearDown();
    }

    /**
     * A scanned copy of the turn-brief fixture and a data directory holding its graph.
     *
     * @return array{0: PDO, 1: string, 2: string, 3: string} [pdo, database path, project root, project id]
     */
    private function project(): array
    {
        $data = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($data, 0o700);
        $this->trees[] = $data;
        $this->trees[] = $root;
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/turn-brief', $root);
        $database = $data . '/knossos.sqlite';
        $pdo = SqliteConnection::open($database);
        (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
        $scan = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root);
        return [$pdo, $database, (string) realpath($root), $scan->projectId];
    }

    /**
     * Runs the watcher for `$polls` polls of 1 ms and returns its events.
     *
     * @param (\Closure(array<string, mixed>): void)|null $react called with each event as it comes
     * @return list<array<string, mixed>>
     */
    private function watch(PDO $pdo, string $database, string $path, int $polls, ?\Closure $alive = null, ?\Closure $react = null): array
    {
        $events = [];
        $emit = static function (array $event) use (&$events, $react): void {
            $events[] = $event;
            if ($react !== null) {
                $react($event);
            }
        };
        (new SharedWatch($pdo, $database, self::repositoryRoot()))->run($path, 1, 0, new CancellationToken(), $emit, $alive, $polls);
        return $events;
    }

    /** @param list<array<string, mixed>> $events @return list<string> */
    private static function names(array $events): array
    {
        return array_map(static fn(array $e): string => (string) $e['event'], $events);
    }

    #[Group('watch')]
    public function testAnUpToDateProjectIsWatchedWithoutAScanAndTheLockIsLetGo(): void
    {
        [$pdo, $database, $root, $projectId] = $this->project();
        $events = $this->watch($pdo, $database, $root, 2);
        assertSame('ready', $events[0]['event']);
        assertSame(false, $events[0]['scanned']);
        assertSame((new ScanLedger($pdo))->activeSnapshot($projectId), $events[0]['snapshot_id']);
        assertSame('stopped', $events[array_key_last($events)]['event']);
        // Let go: the next session leads at once.
        $lock = WatchLock::acquire(dirname($database) . '/watch', $projectId);
        assertNotNull($lock);
        $lock->release();
    }

    #[Group('watch')]
    public function testARootOutsideTheAllowedRootsIsRefusedWithoutWatching(): void
    {
        [$pdo, $database, $root] = $this->project();
        putenv('KNOSSOS_ALLOWED_ROOTS=' . $root . '/src');
        $events = $this->watch($pdo, $database, $root, 1);
        assertSame(['refused'], self::names($events));
        assertSame('not-allowed', $events[0]['status']);
    }

    #[Group('watch')]
    public function testAnUnscannedDirectoryIsRefused(): void
    {
        [$pdo, $database] = $this->project();
        $other = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($other);
        $this->trees[] = $other;
        $events = $this->watch($pdo, $database, $other, 1);
        assertSame(['refused'], self::names($events));
        assertSame('unscanned', $events[0]['status']);
    }

    #[Group('watch')]
    public function testASecondSessionFollowsNeverScansAndLeadsOnceTheLeadIsFree(): void
    {
        [$pdo, $database, $root, $projectId] = $this->project();
        $held = WatchLock::acquire(dirname($database) . '/watch', $projectId);
        assertNotNull($held);
        $held->write(['project_id' => $projectId, 'snapshot_id' => null, 'phase' => 'idle']);
        $polls = 0;
        $events = $this->watch($pdo, $database, $root, 6, null, function (array $event) use (&$polls, $held, $pdo, $root): void {
            if ($event['event'] !== 'following') {
                return;
            }
            // Another writer scans while this session follows; then the leader goes.
            file_put_contents($root . '/src/Core/Greeter.php', "\n// touched\n", FILE_APPEND);
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'incremental');
            $held->release();
        });
        $names = self::names($events);
        assertSame('following', $names[0]);
        assertSame(getmypid(), $events[0]['owner_pid']);
        assertContains('snapshot', $names);
        assertContains('leading', $names);
        assertSame(false, in_array('scan_started', array_slice($names, 0, (int) array_search('leading', $names, true)), true));
    }

    #[Group('watch')]
    public function testAChangeAnotherWriterAlreadyScannedIsAbsorbedWithoutAScan(): void
    {
        [$pdo, $database, $root] = $this->project();
        $touched = false;
        $events = $this->watch($pdo, $database, $root, 4, null, function (array $event) use (&$touched, $pdo, $root): void {
            if ($event['event'] !== 'ready' || $touched) {
                return;
            }
            $touched = true;
            file_put_contents($root . '/src/Core/Greeter.php', "\n// touched\n", FILE_APPEND);
            (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, mode: 'incremental');
        });
        $names = self::names($events);
        assertContains('absorbed', $names);
        assertSame(false, in_array('scan_started', $names, true));
    }

    #[Group('watch')]
    public function testAnEditIsScannedAndRecordedInTheLedger(): void
    {
        [$pdo, $database, $root, $projectId] = $this->project();
        $since = (new ScanLedger($pdo))->activeSnapshot($projectId);
        $touched = false;
        $events = $this->watch($pdo, $database, $root, 4, null, function (array $event) use (&$touched, $root): void {
            if ($event['event'] === 'ready' && !$touched) {
                $touched = true;
                file_put_contents($root . '/src/Core/Greeter.php', "\n// touched\n", FILE_APPEND);
            }
        });
        assertContains('scan_completed', self::names($events));
        $absorbed = (new ScanLedger($pdo))->since($projectId, (string) $since);
        assertNotNull($absorbed);
        assertSame(['src/Core/Greeter.php'], array_keys($absorbed['before']));
    }

    #[Group('watch')]
    public function testTheWatcherStopsOnceWhoeverStartedItIsGone(): void
    {
        [$pdo, $database, $root] = $this->project();
        $events = $this->watch($pdo, $database, $root, 50, static fn(): bool => false);
        assertSame(['ready', 'stopped'], self::names($events));
        assertSame('orphaned', $events[1]['reason']);
    }

    #[Group('watch')]
    public function testALockHeldElsewhereIsRefusedAndItsHolderNamed(): void
    {
        $dir = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->trees[] = $dir;
        $first = WatchLock::acquire($dir, 'project-a');
        assertNotNull($first);
        $first->write(['phase' => 'idle']);
        assertNull(WatchLock::acquire($dir, 'project-a'));
        assertSame(getmypid(), WatchLock::owner($dir, 'project-a')['pid'] ?? null);
        $other = WatchLock::acquire($dir, 'project-b');
        assertNotNull($other);
        $other->release();
        $first->release();
        assertNull(WatchLock::owner($dir, 'project-a'));
        $again = WatchLock::acquire($dir, 'project-a');
        assertNotNull($again);
        $again->release();
    }

    /** Where the lock's state for `$projectId` lives beside `$database`. */
    private static function statePath(string $database, string $projectId): string
    {
        return dirname($database) . '/watch/' . substr(hash('sha256', $projectId), 0, 24) . '.json';
    }

    /** A session after a /clear meets its own earlier watcher (same parent process): that is no other session. */
    #[Group('watch')]
    public function testAFollowerTellsItsOwnProcesssEarlierWatcherFromAnotherSessions(): void
    {
        [$pdo, $database, $root, $projectId] = $this->project();
        $held = WatchLock::acquire(dirname($database) . '/watch', $projectId);
        assertNotNull($held);
        $held->write(['project_id' => $projectId, 'snapshot_id' => null, 'phase' => 'idle']);
        $events = $this->watch($pdo, $database, $root, 1);
        assertSame('following', $events[0]['event']);
        assertSame(true, $events[0]['same_process']);
        assertSame(getmypid(), $events[0]['pid']);
        // Started by another process: another session.
        file_put_contents(self::statePath($database, $projectId), (string) json_encode(['pid' => 7, 'parent_pid' => 1, 'heartbeat' => time()]));
        $events = $this->watch($pdo, $database, $root, 1);
        assertSame(false, $events[0]['same_process']);
        $held->release();
    }

    /** A leader that stops beating is said at once, and so is its return. */
    #[Group('watch')]
    public function testAFollowerSaysWhenTheLeaderStopsAnsweringAndWhenItIsBack(): void
    {
        [$pdo, $database, $root, $projectId] = $this->project();
        $held = WatchLock::acquire(dirname($database) . '/watch', $projectId);
        assertNotNull($held);
        $state = self::statePath($database, $projectId);
        file_put_contents($state, (string) json_encode(['pid' => 7, 'parent_pid' => 1, 'heartbeat' => time()]));
        $polls = 0;
        $events = $this->watch($pdo, $database, $root, 4, null, function (array $event) use (&$polls, $state): void {
            if ($event['event'] !== 'following') {
                return;
            }
            ++$polls;
            file_put_contents($state, (string) json_encode(['pid' => 7, 'parent_pid' => 1, 'heartbeat' => $polls === 1 ? time() - 120 : time()]));
        });
        $following = array_values(array_filter($events, static fn(array $e): bool => $e['event'] === 'following'));
        assertSame([false, true, false], array_map(static fn(array $e): bool => $e['stale'], $following));
        $held->release();
    }

    /** Each scan the leader starts is said once, when the follower first sees it, so the session behind it knows when it began. */
    #[Group('watch')]
    public function testAFollowerSaysWhenTheLeaderStartsAScan(): void
    {
        [$pdo, $database, $root, $projectId] = $this->project();
        $held = WatchLock::acquire(dirname($database) . '/watch', $projectId);
        assertNotNull($held);
        $state = self::statePath($database, $projectId);
        $phases = ['scanning', 'scanning', 'idle', 'scanning', 'idle'];
        file_put_contents($state, (string) json_encode(['pid' => 7, 'parent_pid' => 1, 'heartbeat' => time(), 'phase' => 'idle']));
        $polls = 0;
        $alive = static function () use (&$polls, $phases, $state): bool {
            file_put_contents($state, (string) json_encode(['pid' => 7, 'parent_pid' => 1, 'heartbeat' => time(), 'phase' => $phases[$polls] ?? 'idle']));
            ++$polls;
            return true;
        };
        $events = $this->watch($pdo, $database, $root, count($phases), $alive);
        assertSame(['following', 'leader_scanning', 'leader_scanning', 'stopped'], self::names($events));
        $held->release();
    }

    /**
     * The leader's scan limit was fixed at 300 s. It is now asked for before
     * each scan (the project's `limits.watch_scan_timeout_ms` by default, see
     * the next test), and a scan past it is reported as a timeout.
     */
    #[Group('watch')]
    public function testTheLeaderAsksForItsScanLimitBeforeEachScan(): void
    {
        [$pdo, $database, $root] = $this->project();
        $asked = [];
        $limit = static function (string $root) use (&$asked): int {
            $asked[] = $root;

            return 200;
        };
        $cancellation = new CancellationToken();
        $events = [];
        $emit = static function (array $event) use (&$events, $cancellation, $root): void {
            $events[] = $event;
            if ($event['event'] === 'ready') {
                // An edit while the watcher runs: a scan is due, and the scan the slow installation runs outlives its limit.
                file_put_contents($root . '/src/Core/Greeter.php', "\n// touched\n", FILE_APPEND);
            }
            if (count(array_filter($events, static fn(array $e): bool => $e['event'] === 'error')) === 2) {
                $cancellation->cancel();
            }
        };
        (new SharedWatch($pdo, $database, self::repositoryRoot() . '/tests/Fixtures/slow-scan', $limit))->run($root, 1, 0, $cancellation, $emit, null, 20_000);

        $errors = array_values(array_filter($events, static fn(array $e): bool => $e['event'] === 'error'));
        assertSame([['scan_timeout', true], ['scan_timeout', true]], array_map(static fn(array $e): array => [$e['code'], $e['retryable']], $errors));
        assertStringContainsString('ran past its', (string) $errors[0]['message']);
        assertSame([$root, $root], $asked);
    }

    /** The limit a project sets, else the default: also when the configuration cannot be read, since the scan reports that itself. */
    #[Group('watch')]
    public function testTheScanLimitFallsBackToTheDefault(): void
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($root, 0o700);
        $this->trees[] = $root;
        $allowed = AllowedRoots::of([$root]);
        assertSame(SharedWatch::DEFAULT_SCAN_TIMEOUT_MS, SharedWatch::scanTimeoutMs($root, $allowed));
        file_put_contents($root . '/knossos.json', '{"version":1,"limits":{"watch_scan_timeout_ms":20000}}');
        assertSame(20_000, SharedWatch::scanTimeoutMs($root, $allowed));
        file_put_contents($root . '/knossos.json', '{"version":1,"limits":{"watch_scan_timeout_ms":1}}');
        assertSame(SharedWatch::DEFAULT_SCAN_TIMEOUT_MS, SharedWatch::scanTimeoutMs($root, $allowed));
        assertSame(300_000, SharedWatch::DEFAULT_SCAN_TIMEOUT_MS);
    }
}
