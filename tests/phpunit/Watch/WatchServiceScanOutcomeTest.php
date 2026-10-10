<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Closure;
use Error;
use Knossos\Cancellation\CancellationToken;
use Knossos\Cancellation\ScanCancelledException;
use Knossos\Query\ResultEnvelope;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\ScanTimeoutException;
use Knossos\Watch\WatchHooks;
use Knossos\Watch\WatchService;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * What a watcher does with each scan's outcome: a timeout is counted and
 * capped, a success resets the count, and the initial scan is classified like
 * every later one.
 */
#[Group('watch')]
final class WatchServiceScanOutcomeTest extends KnossosTestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/knossos-stale-watch-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o700, true);
        file_put_contents($this->root . '/src/A.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        $this->removeTempTree($this->root);
        parent::tearDown();
    }

    /**
     * A timeout was a plain RuntimeException, retried forever with a 30 s cap:
     * a scan too large for its limit never converged and never stopped.
     */
    public function testThreeConsecutiveTimeoutsStopTheWatch(): void
    {
        [$result, $events] = $this->watch(['ok+change', 'timeout'], maxPolls: 5_000);

        $errors = self::named($events, 'error');
        assertSame(3, count($errors));
        assertSame(['scan_timeout', 'scan_timeout', 'scan_timeout'], array_column($errors, 'code'));
        assertSame([true, true, false], array_column($errors, 'retryable'));
        assertSame(['event' => 'stopped', 'reason' => 'error'], end($events));
        assertSame(3, $result->data['scan_errors']);
    }

    public function testASuccessResetsTheTimeoutCount(): void
    {
        [, $events] = $this->watch(['ok+change', 'timeout', 'timeout', 'ok+change', 'timeout', 'timeout', 'ok'], maxPolls: 400);

        $errors = self::named($events, 'error');
        assertSame(4, count($errors));
        assertSame([true, true, true, true], array_column($errors, 'retryable'));
        assertSame(['event' => 'stopped', 'reason' => 'poll_limit'], end($events));
    }

    /** A failure that is no timeout neither counts toward the cap nor carries the code. */
    public function testOtherRetryableFailuresAreNotCountedAsTimeouts(): void
    {
        [, $events] = $this->watch(['ok+change', 'timeout', 'timeout', 'fail', 'fail', 'ok'], maxPolls: 400);

        $errors = self::named($events, 'error');
        assertSame(['scan_timeout', 'scan_timeout', null, null], array_map(static fn(array $e): mixed => $e['code'] ?? null, $errors));
        assertSame([true, true, true, true], array_column($errors, 'retryable'));
        assertSame('poll_limit', end($events)['reason']);
    }

    /** The initial scan ran outside the attempt classifier: one transient fault killed the watcher before it had started. */
    public function testATransientInitialFailureIsRetriedAndReadyFollowsTheFirstSuccess(): void
    {
        [$result, $events] = $this->watch(['fail', 'ok+change', 'ok'], maxPolls: 200);

        assertSame(['error', true, 1], [$events[0]['event'], $events[0]['retryable'], $events[0]['attempt']]);
        $names = array_column($events, 'event');
        $ready = array_search('ready', $names, true);
        assertSame(true, is_int($ready));
        assertSame(true, $events[$ready]['scanned']);
        assertSame('snapshot-2', $events[$ready]['snapshot_id']);
        assertSame('watch-project', $events[$ready]['project_id']);
        assertSame('scan_completed', $names[$ready + 1]);
        assertSame(1, count(array_keys($names, 'ready', true)));
        // The retry announces itself only by `ready`, as the first attempt does: no scan_started before it.
        assertSame(false, in_array('scan_started', array_slice($names, 0, $ready), true));
        // Once ready, the edit the initial scan made is scanned as any later change: announced, incremental, no second `ready`.
        $later = array_slice($events, $ready + 2);
        assertSame([['scan_started', 'incremental'], ['scan_completed', 'incremental']], array_map(static fn(array $e): array => [$e['event'], $e['mode'] ?? null], array_values(array_filter($later, static fn(array $e): bool => str_starts_with($e['event'], 'scan_')))));
        assertSame([2, 1, 1, 'poll_limit'], [$result->data['scans'], $result->data['incremental_scans'], $result->data['scan_errors'], $result->data['stopped_reason']]);
        assertSame('snapshot-3', $result->snapshotId);
    }

    public function testATerminalInitialFailureStopsWithAnErrorReason(): void
    {
        [$result, $events] = $this->watch(['error'], maxPolls: 200);

        assertSame(['error', false], [$events[0]['event'], $events[0]['retryable']]);
        assertSame(['event' => 'stopped', 'reason' => 'error'], $events[1]);
        assertSame(2, count($events));
        assertSame(['error', 0, 0], [$result->data['stopped_reason'], $result->data['polls'], $result->data['scans']]);
    }

    /** The timeout cap holds for the initial scan too: it never reaches `ready`. */
    public function testAnInitialScanThatKeepsTimingOutStops(): void
    {
        [$result, $events] = $this->watch(['timeout'], maxPolls: 5_000);

        assertSame([true, true, false], array_column(self::named($events, 'error'), 'retryable'));
        assertSame([], self::named($events, 'ready'));
        assertSame('error', $result->data['stopped_reason']);
    }

    /** A watch cancelled before its first scan stops at once, says so, and never scans. */
    public function testAWatchCancelledBeforeItsInitialScanStopsAsCancelled(): void
    {
        $cancellation = new CancellationToken();
        $cancellation->cancel();
        [$result, $events] = $this->watch(['ok'], maxPolls: 200, cancellation: $cancellation);

        assertSame([['event' => 'stopped', 'reason' => 'cancelled']], $events);
        assertSame(['cancelled', 0], [$result->data['stopped_reason'], $result->data['scans']]);
    }

    /** The result's stop reason is the one the `stopped` event carried. */
    public function testTheResultCarriesTheStopReason(): void
    {
        [$result, $events] = $this->watch(['ok'], maxPolls: 3);

        assertSame(['event' => 'stopped', 'reason' => 'poll_limit'], end($events));
        assertSame('poll_limit', $result->data['stopped_reason']);
    }

    /**
     * A scan stopped because whoever started the watcher is gone ends the
     * watch as `orphaned`; it read as `poll_limit`, a limit that was never set.
     */
    public function testAScanStoppedBecauseTheStarterIsGoneEndsTheWatchAsOrphaned(): void
    {
        $alive = true;
        $scanner = static function () use (&$alive): ResultEnvelope {
            $alive = false;
            throw new ScanCancelledException('The scan was cancelled.');
        };
        $events = [];
        $result = (new WatchService($scanner, [$this->root]))->run(
            $this->root,
            pollMs: 1,
            observer: static function (array $event) use (&$events): void {
                $events[] = $event;
            },
            maxPolls: 50,
            hooks: new WatchHooks(alive: static function () use (&$alive): bool {
                return $alive;
            }),
        );

        assertSame([['event' => 'stopped', 'reason' => 'orphaned']], $events);
        assertSame('orphaned', $result->data['stopped_reason']);
    }

    /** A scan that reports itself cancelled, with no token cancelled and no starter gone, still ends the watch at once, as cancelled. */
    public function testAScanCancelledFromWithinStopsTheWatchAsCancelled(): void
    {
        [$result, $events] = $this->watch(['ok+change', 'cancelled'], maxPolls: 400);

        assertSame(['event' => 'stopped', 'reason' => 'cancelled'], end($events));
        assertSame(true, $result->data['polls'] < 400);
        assertSame(0, $result->data['scan_errors']);
    }

    /** A watch that took in another writer's graph, which has no snapshot yet, still returns a result. */
    public function testAWatchWithNoSnapshotKnownReturnsEmptyIds(): void
    {
        $result = (new WatchService(static fn(): never => throw new Error('No scan was due.'), [$this->root]))->run(
            $this->root,
            pollMs: 1,
            maxPolls: 1,
            hooks: new WatchHooks(current: static fn(): bool => true, activeSnapshot: static fn(): ?string => null),
        );

        assertSame(['', '', 'poll_limit', 0], [$result->projectId, $result->snapshotId, $result->data['stopped_reason'], $result->data['scans']]);
    }

    /**
     * Runs a watch whose scanner answers each call from `$script` in turn,
     * repeating the last entry: `ok`, `ok+change` (succeeds and edits a file,
     * so another scan is due), `timeout`, `fail` (a retryable failure),
     * `cancelled` (the scan says it was cancelled) or `error` (a terminal one).
     *
     * @param list<string> $script
     * @return array{0: ResultEnvelope, 1: list<array<string, mixed>>}
     */
    private function watch(array $script, int $maxPolls, ?CancellationToken $cancellation = null): array
    {
        $events = [];
        $result = (new WatchService($this->scanner($script), [$this->root]))->run(
            $this->root,
            pollMs: 1,
            debounceMs: 0,
            maxQueue: 100,
            cancellation: $cancellation ?? new CancellationToken(),
            observer: static function (array $event) use (&$events): void {
                $events[] = $event;
            },
            maxPolls: $maxPolls,
        );

        return [$result, $events];
    }

    /**
     * @param list<string> $script
     * @return Closure(string, ?string, CancellationToken): ResultEnvelope
     */
    private function scanner(array $script): Closure
    {
        $root = $this->root;
        $calls = 0;

        return static function () use ($script, $root, &$calls): ResultEnvelope {
            $step = $script[min($calls, count($script) - 1)];
            ++$calls;
            if ($step === 'ok+change') {
                file_put_contents($root . '/src/Edit' . $calls . '.php', "<?php\n");
            }

            return match ($step) {
                'ok', 'ok+change' => new ResultEnvelope('watch-project', 'snapshot-' . $calls, 'ok', ['parsed_files' => 1]),
                'timeout' => throw new ScanTimeoutException('The scan ran past its 300 s limit and was stopped.'),
                'fail' => throw new RuntimeException('The write lease is busy.'),
                'cancelled' => throw new ScanCancelledException('The scan was cancelled.'),
                default => throw new Error('A defect.'),
            };
        };
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<array<string, mixed>>
     */
    private static function named(array $events, string $name): array
    {
        return array_values(array_filter($events, static fn(array $event): bool => $event['event'] === $name));
    }
}
