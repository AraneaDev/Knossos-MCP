<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Reconciliation;

use InvalidArgumentException;
use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Worker\WorkerException;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Watch\WatchService;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Throwable;
use TypeError;

final class WatchTest extends KnossosTestCase
{
    #[Group('watch')]
    public function testWatchOrchestrationDebouncesIncrementalChangesRecoversOverflowAndCancels(): void
    {
        $root = sys_get_temp_dir() . '/knossos-watch-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o700, true)) {
            throw new RuntimeException('Unable to create watch fixture.');
        }
        file_put_contents($root . '/src/A.php', "<?php\nfinal class A {}\n");
        $database = tempnam(sys_get_temp_dir(), 'knossos-watch-db-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate watch database.');
        }
        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $scanner = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            $watcher = new WatchService($scanner, [$root]);
            $changed = false;
            $overflow = $watcher->run($root, 1, 0, 1, observer: function (array $event) use ($root, &$changed): void {
                if ($event['event'] === 'ready' && !$changed) {
                    $changed = true;
                    file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function changed(): void {} }\n");
                    file_put_contents($root . '/src/B.php', "<?php\nfinal class B {}\n");
                    file_put_contents($root . '/src/C.php', "<?php\nfinal class C {}\n");
                }
            }, maxPolls: 2);
            assertSame(1, $overflow->data['queue_overflows']);
            assertSame(1, $overflow->data['full_scans']);
            assertSame(2, $overflow->data['scans']);

            $changed = false;
            $incremental = $watcher->run($root, 1, 0, 10, observer: function (array $event) use ($root, &$changed): void {
                if ($event['event'] === 'ready' && !$changed) {
                    $changed = true;
                    file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function twice(): void {} }\n");
                }
            }, maxPolls: 2);
            assertSame(1, $incremental->data['incremental_scans']);
            assertSame(0, $incremental->data['queue_overflows']);

            $token = new CancellationToken();
            $cancelled = $watcher->run($root, 1, 0, 10, $token, function (array $event) use ($token): void {
                if ($event['event'] === 'ready') {
                    $token->cancel();
                }
            }, 1);
            assertSame('cancelled', $cancelled->data['events'][array_key_last($cancelled->data['events'])]['reason']);
            assertThrows(fn() => $watcher->run($root, 0), InvalidArgumentException::class);
        } finally {
            unset($watcher, $scanner, $pdo);
            foreach (['src/A.php', 'src/B.php', 'src/C.php'] as $relative) {
                @unlink($root . '/' . $relative);
            }
            @rmdir($root . '/src');
            @rmdir($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('watch')]
    public function testWatchModeRetriesTransientScanFailuresAndStopsCleanlyOnFatalOnes(): void
    {
        $root = sys_get_temp_dir() . '/knossos-watch-fail-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o700, true)) {
            throw new RuntimeException('Unable to create watch fixture.');
        }
        file_put_contents($root . '/src/A.php', "<?php\nfinal class A {}\n");

        // Scripted scanner: the first call is the baseline scan, later calls follow the
        // provided outcome list (a Throwable is raised, anything else scans cleanly).
        $scriptedScanner = function (array $outcomes) use ($root): ProjectScanner {
            return new class ($outcomes) implements ProjectScanner {
                private int $calls = 0;

                /** @param list<mixed> $outcomes */
                public function __construct(private array $outcomes) {}

                public function scan(
                    string $root,
                    ?string $name = null,
                    ?int $maxFiles = null,
                    ?int $maxFileBytes = null,
                    ?array $explicitBoundaries = null,
                    ?string $mode = null,
                    ?CancellationToken $cancellation = null,
                    ?int $snapshotRetention = null,
                    ?int $workerTimeoutMs = null,
                ): ResultEnvelope {
                    $index = $this->calls;
                    ++$this->calls;
                    $outcome = $this->outcomes[$index] ?? $this->outcomes[array_key_last($this->outcomes)];
                    if ($outcome instanceof Throwable) {
                        throw $outcome;
                    }
                    return new ResultEnvelope('watch-project', 'snapshot-' . $this->calls, 'ok', ['parsed_files' => 1]);
                }
            };
        };

        $touch = 0;
        $drive = function (ProjectScanner $scanner) use ($root, &$touch): ResultEnvelope {
            $watcher = new WatchService($scanner, [$root]);
            return $watcher->run($root, 1, 0, 10, observer: function (array $event) use ($root, &$touch): void {
                if ($event['event'] === 'ready') {
                    ++$touch;
                    file_put_contents($root . '/src/A.php', "<?php\nfinal class A { public function v{$touch}(): void {} }\n");
                }
            }, maxPolls: 8);
        };

        try {
            // Worker timeout: baseline scan succeeds, the rescan times out once, then recovers.
            $recovered = $drive($scriptedScanner([
                'ok',
                new WorkerException('WORKER_TIMEOUT', 'Scanner worker request timed out.'),
                'ok',
            ]));
            $events = $recovered->data['events'];
            $errors = array_values(array_filter($events, fn(array $e): bool => $e['event'] === 'error'));
            assertSame(1, count($errors));
            assertSame(true, $errors[0]['retryable']);
            assertSame(1, $recovered->data['scan_errors']);
            assertSame(1, count(array_filter($events, fn(array $e): bool => $e['event'] === 'scan_completed')));
            assertSame(0, $recovered->data['pending_changes']);
            assertSame('poll_limit', $events[array_key_last($events)]['reason']);

            // Transient storage failure surfaces as a runtime exception and is also retried.
            $storage = $drive($scriptedScanner([
                'ok',
                new RuntimeException('database is locked'),
                'ok',
            ]));
            assertSame(1, $storage->data['scan_errors']);
            assertSame(1, count(array_filter($storage->data['events'], fn(array $e): bool => $e['event'] === 'scan_completed')));
            assertSame(0, $storage->data['pending_changes']);

            // Engine-level fault is terminal: emit a non-retryable error and stop without recovery.
            $fatal = $drive($scriptedScanner([
                'ok',
                new TypeError('Return value must be of type int, string returned.'),
            ]));
            $fatalEvents = $fatal->data['events'];
            $fatalErrors = array_values(array_filter($fatalEvents, fn(array $e): bool => $e['event'] === 'error'));
            assertSame(1, count($fatalErrors));
            assertSame(false, $fatalErrors[0]['retryable']);
            assertSame('error', $fatalEvents[array_key_last($fatalEvents)]['reason']);
            assertSame(0, count(array_filter($fatalEvents, fn(array $e): bool => $e['event'] === 'scan_completed')));
        } finally {
            @unlink($root . '/src/A.php');
            @rmdir($root . '/src');
            @rmdir($root);
        }
    }
}
