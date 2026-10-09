<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Watch;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanner;
use Knossos\Watch\WatchService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[Group('watch-service')]
final class WatchServiceFingerprintOrderingTest extends TestCase
{
    public function testInitialFingerprintIsCapturedBeforeInitialScan(): void
    {
        $root = sys_get_temp_dir() . '/knossos-watch-order-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src', 0o700, true)) {
            throw new RuntimeException('Unable to create watch fixture.');
        }
        file_put_contents($root . '/src/A.php', "<?php\nfinal class A {}\n");

        // The scanner mutates the tree DURING the initial scan (adding a file).
        // Only if the baseline fingerprint is taken BEFORE that scan will the
        // injected file register as a change on the first poll and trigger a
        // rescan. If it were taken afterwards the file would already be baked
        // into the baseline and be missed forever.
        $scanner = new class ($root) implements ProjectScanner {
            public int $calls = 0;

            public function __construct(private string $root) {}

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
                ?int $workerMemoryMb = null,
            ): ResultEnvelope {
                ++$this->calls;
                if ($this->calls === 1) {
                    file_put_contents($this->root . '/src/Injected.php', "<?php\nfinal class Injected {}\n");
                }
                return new ResultEnvelope('watch-project', 'snapshot-' . $this->calls, 'ok', ['parsed_files' => 1]);
            }
        };

        try {
            $watcher = new WatchService($scanner, [$root]);
            $events = [];
            $result = $watcher->run(
                $root,
                pollMs: 1,
                debounceMs: 0,
                maxQueue: 10,
                observer: static function (array $event) use (&$events): void {
                    $events[] = $event;
                },
                maxPolls: 2,
            );

            // Baseline 'ready' saw only the pre-scan file.
            $ready = $events[0];
            assertSame('ready', $ready['event']);
            assertSame(1, $ready['files']);

            // The mid-scan injection was detected and rescanned.
            assertSame(2, $result->data['scans']);
            $scanStarts = array_values(array_filter($events, static fn(array $e): bool => $e['event'] === 'scan_started'));
            assertSame(true, $scanStarts !== []);
            assertSame(true, $scanStarts[0]['changes'] >= 1);
        } finally {
            @unlink($root . '/src/A.php');
            @unlink($root . '/src/Injected.php');
            @rmdir($root . '/src');
            @rmdir($root);
        }
    }

    /**
     * A file created in a directory that held no discovered file is seen on
     * the next poll. The gate tracked only the directories of discovered
     * files, so such a file moved nothing it stat'ed and waited for the
     * periodic full fingerprint, up to a minute later.
     *
     * The clock places every walk ten seconds after the fixture was written,
     * so no ctime is racy and only a tracked directory can open the gate. One
     * file lands during the initial scan (seen through the directories the
     * initial walk passed) and one during the second scan (seen through the
     * directories the poll's walk passed), so each call site is pinned.
     */
    public function testAFileCreatedInAnEmptyDirectoryIsSeenOnTheNextPoll(): void
    {
        $root = sys_get_temp_dir() . '/knossos-watch-order-' . bin2hex(random_bytes(6));
        if (!mkdir($root . '/src/new', 0o700, true) || !mkdir($root . '/src/later', 0o700, true)) {
            throw new RuntimeException('Unable to create watch fixture.');
        }
        file_put_contents($root . '/src/A.php', "<?php\nfinal class A {}\n");
        foreach (['', '/src', '/src/new', '/src/later', '/src/A.php'] as $path) {
            touch($root . $path, time() - 10);
        }
        $scanner = new class ($root) implements ProjectScanner {
            public int $calls = 0;

            public function __construct(private string $root) {}

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
                ?int $workerMemoryMb = null,
            ): ResultEnvelope {
                // Written during a scan, with an old mtime, so only the
                // directory holding it shows the change.
                $file = [1 => '/src/new/B.php', 2 => '/src/later/C.php'][++$this->calls] ?? null;
                if ($file !== null) {
                    file_put_contents($this->root . $file, "<?php\n");
                    touch($this->root . $file, time() - 10);
                }
                return new ResultEnvelope('watch-project', 'snapshot-' . $this->calls, 'ok', ['parsed_files' => 1]);
            }
        };

        try {
            $watcher = new WatchService($scanner, [$root], static fn(): int => time() + 10);
            $result = $watcher->run($root, pollMs: 1, debounceMs: 0, maxQueue: 10, maxPolls: 3);

            assertSame(3, $result->data['scans']);
        } finally {
            @unlink($root . '/src/new/B.php');
            @unlink($root . '/src/later/C.php');
            @rmdir($root . '/src/later');
            @unlink($root . '/src/A.php');
            @rmdir($root . '/src/new');
            @rmdir($root . '/src');
            @rmdir($root);
        }
    }
}
