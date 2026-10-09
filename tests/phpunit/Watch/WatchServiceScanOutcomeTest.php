<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Closure;
use Error;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\ScanTimeoutException;
use Knossos\Watch\WatchService;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * What a watcher does with each scan's outcome: a timeout is counted and
 * capped, and a success resets the count.
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

    /**
     * Runs a watch whose scanner answers each call from `$script` in turn,
     * repeating the last entry: `ok`, `ok+change` (succeeds and edits a file,
     * so another scan is due), `timeout`, `fail` (a retryable failure) or
     * `error` (a terminal one).
     *
     * @param list<string> $script
     * @return array{0: ResultEnvelope, 1: list<array<string, mixed>>}
     */
    private function watch(array $script, int $maxPolls): array
    {
        $events = [];
        $result = (new WatchService($this->scanner($script), [$this->root]))->run(
            $this->root,
            pollMs: 1,
            debounceMs: 0,
            maxQueue: 100,
            cancellation: new CancellationToken(),
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
