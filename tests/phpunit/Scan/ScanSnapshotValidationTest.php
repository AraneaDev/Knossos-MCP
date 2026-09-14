<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scan;

use Knossos\Discovery\DiscoveredFile;
use Knossos\Discovery\FileFingerprint;
use Knossos\Scan\CancellationToken;
use Knossos\Scan\ProjectScanService;
use Knossos\Scan\ScanSnapshotChangedException;
use Knossos\Scan\ScanSnapshotValidator;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the guard that stops a scan whose workers read different bytes than
 * discovery hashed.
 *
 * The end-to-end tests drive a real rewrite through the cancellation token's
 * poll closure. Nothing in them reaches into the validator to arrange the
 * mismatch — the file on disk genuinely differs from the recorded hash, so a
 * suite without the guard completes the scan and these tests fail.
 *
 * What they depend on, precisely, so a later change to the pipeline can see
 * what it is breaking: `ScanPlanner::prepare()` is not handed the token, so no
 * poll happens while discovery walks and hashes the tree. The scan's first poll
 * is therefore the checkpoint before prepare() and its second is the checkpoint
 * after it, which is the gap between discovery's hash and the workers' own read
 * of the same paths. A closure that writes from the second poll onward lands
 * its write in exactly that gap. Add a cancellation checkpoint inside discovery
 * and these two tests break — loudly, by no longer producing a mismatch, rather
 * than by passing hollowly — and the closure then has to skip one poll more.
 */
final class ScanSnapshotValidationTest extends KnossosTestCase
{
    /**
     * The central case: a file rewritten while the workers were parsing it must
     * abort the scan, because completing it would publish facts no stored hash
     * describes and every later drift probe would call that graph fresh.
     */
    #[Group('scan')]
    public function testScanAbortsWhenAFileIsRewrittenAfterDiscoveryHashedIt(): void
    {
        $root = $this->copyFixtureTree('mixed');
        try {
            $pdo = $this->freshTestDatabase();
            $file = $root . '/src/CheckoutService.php';
            $original = (string) file_get_contents($file);

            $error = captureThrows(
                fn() => (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))
                    ->scan($root, cancellation: $this->rewritingToken($file, $original . "\n// rewritten mid-scan\n")),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/CheckoutService.php', $error->getMessage());
            // The PHP worker now declares input_hashes as well as content_hash,
            // and reports every file it read (including this one) in both. The
            // core checks input_hashes first, so the mismatch is now caught
            // there (inputReadDifferently()) rather than from the contribution's
            // own content_hash (parsedDifferently()) or a core re-read
            // (contentChanged()) — a deliberate consequence of the worker
            // declaring the capability, not a change to what is detected or
            // when the scan aborts.
            assertContains('was read from different content than the scan hashed', $error->getMessage());
            // The seam actually fired: without this the test could pass on a
            // scan that failed for some unrelated reason.
            assertNotSame($original, (string) file_get_contents($file));
            // Nothing was published. A failed attempt may leave a scans row for
            // the reaper, but no graph row that a query could be answered from.
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A rewrite that lands after a hashing worker has already read the file is
     * invisible to that worker's hash, which matches discovery. Only the
     * validator's own re-read before anything is persisted can see it, so this
     * is the end-to-end case for contentChanged() on a scan that runs workers.
     *
     * The checkpoint it relies on: ProjectScanService::scan() consults the
     * token itself before prepare(), after prepare(), and immediately before
     * ScanSnapshotValidator::validate(), after every language worker has
     * returned. The closure counts only polls made from scan() itself, so
     * polls from inside the language runner and the worker clients do not
     * move it, and writes from the third one on. Add a checkpoint of scan()'s
     * own before validate() and this test stops producing a mismatch; it then
     * fails loudly and the count has to move with it.
     */
    #[Group('scan')]
    public function testScanAbortsWhenAFileIsRewrittenAfterTheWorkersReturned(): void
    {
        $root = $this->copyFixtureTree('mixed');
        try {
            $pdo = $this->freshTestDatabase();
            $file = $root . '/src/CheckoutService.php';
            $original = (string) file_get_contents($file);
            $scanPolls = 0;
            $token = new CancellationToken(function () use (&$scanPolls, $file, $original): bool {
                $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4)[3] ?? [];
                if (($caller['class'] ?? null) === ProjectScanService::class && ++$scanPolls >= 3) {
                    file_put_contents($file, $original . "\n// rewritten after the workers returned\n");
                }

                return false;
            });

            $error = captureThrows(
                fn() => (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, cancellation: $token),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/CheckoutService.php', $error->getMessage());
            assertContains('changed while the scan was running', $error->getMessage());
            assertSame(3, $scanPolls);
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
            assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The no-change fast path writes too — it refreshes stored mtimes and
     * restamps the active scan's completion — so it has to be validated as well.
     * Without that, the cheapest and most frequent scan is the one that can
     * restamp a graph as verified against content that moved underneath it.
     */
    #[Group('scan')]
    public function testFastPathRescanIsValidatedBeforeItRestampsTheGraph(): void
    {
        [$pdo, , $root] = $this->scanTempFixture('mixed');
        try {
            $service = new ProjectScanService($pdo, self::repositoryRoot(), [$root]);
            // Establishes that this fixture really does take the fast path, so
            // the failure below is the guard firing on that path rather than on
            // an ordinary incremental rebuild.
            assertSame('no_change', $service->scan($root)->data['fast_path']);

            $file = $root . '/src/CheckoutService.php';
            $original = (string) file_get_contents($file);
            $finishedAt = (string) $pdo->query(
                'SELECT s.finished_at FROM scans s JOIN projects p ON p.active_scan_id = s.id',
            )->fetchColumn();

            $error = captureThrows(
                fn() => $service->scan($root, cancellation: $this->rewritingToken($file, $original . "\n// rewritten mid-scan\n")),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/CheckoutService.php', $error->getMessage());
            // The fast path never invokes a worker, so this is the one
            // remaining end-to-end route to ScanSnapshotValidator's own
            // re-read (contentChanged()) rather than a worker-reported hash
            // mismatch (parsedDifferently()) — pin the exact wording so a
            // regression that routed this through a worker hash instead
            // would fail here rather than passing on the path substring alone.
            assertContains('changed while the scan was running', $error->getMessage());
            // The fast path's write never happened: the active scan still
            // carries the completion stamp the previous scan left on it.
            assertSame($finishedAt, (string) $pdo->query(
                'SELECT s.finished_at FROM scans s JOIN projects p ON p.active_scan_id = s.id',
            )->fetchColumn());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A tree nobody touched must validate silently, so an ordinary scan pays
     * the pass and nothing else.
     *
     * The mutation at the end is what keeps the silence meaningful: a validator
     * that never looked at anything would also be silent here, and the same
     * call throwing once one byte moves is the difference between the two.
     */
    #[Group('scan')]
    public function testUntouchedTreeValidatesWithoutComplaint(): void
    {
        $root = $this->tempRootWithFile('src/Kept.php', "<?php\n\necho 'kept';\n");
        try {
            $validator = new ScanSnapshotValidator();
            $discovered = [$this->discoveredFile($root, 'src/Kept.php')];

            // An untouched tree: any throw here fails the test.
            $validator->validate($discovered);

            file_put_contents($root . '/src/Kept.php', "<?php\n\necho 'kept?';\n");
            assertThrows(fn() => $validator->validate($discovered), ScanSnapshotChangedException::class);
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * The validator compares content, not metadata: a file whose bytes changed
     * fails even though it is still there and still readable.
     */
    #[Group('scan')]
    public function testChangedContentNamesTheOffendingFile(): void
    {
        $root = $this->tempRootWithFile('src/Rewritten.php', "<?php\n\necho 'before';\n");
        try {
            $discovered = $this->discoveredFile($root, 'src/Rewritten.php');
            file_put_contents($root . '/src/Rewritten.php', "<?php\n\necho 'after';\n");

            $error = captureThrows(
                fn() => (new ScanSnapshotValidator())->validate([$discovered]),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/Rewritten.php', $error->getMessage());
            assertContains('changed while the scan was running', $error->getMessage());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A file discovered and then deleted cannot be re-read, and that is not the
     * same fault as a rewrite — but it is not nothing either, because a worker
     * may have parsed it before it vanished. The scan fails, with its own
     * wording, rather than committing facts about content nothing can attest to.
     */
    #[Group('scan')]
    public function testDeletedFileAbortsTheScanWithItsOwnWording(): void
    {
        $root = $this->tempRootWithFile('src/Vanished.php', "<?php\n\necho 'here';\n");
        try {
            $discovered = $this->discoveredFile($root, 'src/Vanished.php');
            unlink($root . '/src/Vanished.php');

            $error = captureThrows(
                fn() => (new ScanSnapshotValidator())->validate([$discovered]),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/Vanished.php', $error->getMessage());
            assertContains('was removed while the scan was running', $error->getMessage());
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A path that still exists but can no longer be read is treated exactly as
     * severely, and told apart in the message because the operator's next step
     * differs: a path that stopped being a readable file is a condition to
     * correct, not a race to wait out.
     *
     * Staged with a Unix socket rather than a chmod. A chmod is a no-op for
     * root, so the branch would go unexercised wherever this suite runs
     * privileged — which includes half of this repository's own quality steps —
     * and an assertion that silently skips proves nothing. A socket fails the
     * same open() for every user, and quietly, so it also keeps the suite's
     * fail-on-warning contract intact.
     */
    #[Group('scan')]
    public function testUnreadablePathAbortsTheScanWithItsOwnWording(): void
    {
        $root = $this->tempRootWithFile('src/Unreadable.php', "<?php\n\necho 'here';\n");
        $socket = null;
        try {
            $path = $root . '/src/Unreadable.php';
            $discovered = $this->discoveredFile($root, 'src/Unreadable.php');
            unlink($path);
            // Bound by name from inside its directory: a socket path is capped
            // at 108 bytes, and an absolute one under a deep temp directory
            // (a mutation sandbox, say) is silently truncated to a different
            // path, which then reads as a removed file instead of an unreadable one.
            $cwd = (string) getcwd();
            chdir(dirname($path));
            try {
                $socket = stream_socket_server('unix://' . basename($path), $errorCode, $errorMessage);
            } finally {
                chdir($cwd);
            }
            if ($socket === false) {
                self::markTestSkipped(sprintf('Unix sockets unavailable here: %s (%d).', $errorMessage, $errorCode));
            }

            $error = captureThrows(
                fn() => (new ScanSnapshotValidator())->validate([$discovered]),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/Unreadable.php', $error->getMessage());
            assertContains('could not be re-read', $error->getMessage());
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            $this->removeTempTree($root);
        }
    }

    /**
     * The first offending file ends the pass: the scan is discarded either way,
     * and the files after it are work nobody will use.
     */
    #[Group('scan')]
    public function testValidationStopsAtTheFirstOffendingFile(): void
    {
        $root = $this->tempRootWithFile('src/First.php', "<?php\n\necho 'first';\n");
        try {
            file_put_contents($root . '/src/Second.php', "<?php\n\necho 'second';\n");
            $first = $this->discoveredFile($root, 'src/First.php');
            $second = $this->discoveredFile($root, 'src/Second.php');
            file_put_contents($root . '/src/First.php', "<?php\n\necho 'first changed';\n");
            unlink($root . '/src/Second.php');

            $error = captureThrows(
                fn() => (new ScanSnapshotValidator())->validate([$first, $second]),
                ScanSnapshotChangedException::class,
            );

            assertContains('src/First.php', $error->getMessage());
            assertSame(false, str_contains($error->getMessage(), 'src/Second.php'));
        } finally {
            $this->removeTempTree($root);
        }
    }

    /**
     * A token that rewrites the file from its second consultation onward.
     *
     * The scan polls once before prepare() and again after it, and nothing
     * polls in between because discovery is never handed the token, so skipping
     * the first poll is what puts the write between discovery's hash and the
     * workers' read. The same bytes are written on every later poll, which makes
     * the closure idempotent however often the pipeline consults the token.
     */
    private function rewritingToken(string $path, string $replacement): CancellationToken
    {
        $polls = 0;

        return new CancellationToken(function () use (&$polls, $path, $replacement): bool {
            ++$polls;
            if ($polls > 1) {
                file_put_contents($path, $replacement);
            }

            return false;
        });
    }

    /** A copy of a shipped fixture in a temp tree removeTempTree() will accept. */
    private function copyFixtureTree(string $fixture): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        $this->copyTree(self::repositoryRoot() . '/tests/Fixtures/' . $fixture, $root);

        return $root;
    }

    /** A temp tree holding one file, for the validator's own unit cases. */
    private function tempRootWithFile(string $relativePath, string $contents): string
    {
        $root = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir(dirname($root . '/' . $relativePath), 0o777, true);
        file_put_contents($root . '/' . $relativePath, $contents);

        return $root;
    }

    /**
     * The record discovery would have produced for a file as it stands now,
     * fingerprinted through the same class the walk uses so the test asserts
     * against a real hash rather than one it invented.
     */
    private function discoveredFile(string $root, string $relativePath): DiscoveredFile
    {
        $absolute = $root . '/' . $relativePath;
        $fingerprint = FileFingerprint::compute($absolute);
        self::assertNotNull($fingerprint, 'The fixture file must be readable before the test mutates it.');

        return new DiscoveredFile(
            $relativePath,
            $absolute,
            'php',
            (int) filesize($absolute),
            (int) filemtime($absolute),
            $fingerprint->contentHash,
            $fingerprint->lineCount,
            $fingerprint->gitBlobHash,
        );
    }
}
