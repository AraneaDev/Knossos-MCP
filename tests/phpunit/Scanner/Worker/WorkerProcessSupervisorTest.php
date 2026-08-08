<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner\Worker;

use Knossos\Scanner\Worker\WorkerException;
use Knossos\Scanner\Worker\WorkerProcessSupervisor;
use Knossos\Tests\Phpunit\Support\Processes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('scanner-worker')]
final class WorkerProcessSupervisorTest extends TestCase
{
    use Processes;

    /** Read one newline-terminated line from a non-blocking stream within a deadline. */
    private function readLine($stream, float $timeoutSeconds = 5.0): string
    {
        $buffer = '';
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            $read = [$stream];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 0, 100_000) > 0) {
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && feof($stream))) {
                    break;
                }
                $buffer .= $chunk;
                if (str_contains($buffer, "\n")) {
                    return substr($buffer, 0, strpos($buffer, "\n"));
                }
            }
        }
        return $buffer;
    }

    public function testStartFailsWithDiagnosticForUnrunnableCommand(): void
    {
        $supervisor = new WorkerProcessSupervisor(['/nonexistent/knossos-worker-binary-xyz']);

        $error = captureThrows(
            static fn () => $supervisor->start(),
            WorkerException::class,
        );

        assertSame('WORKER_START_FAILED', $error->diagnosticCode);
    }

    public function testStdinIsNonBlockingAfterStart(): void
    {
        $supervisor = new WorkerProcessSupervisor([PHP_BINARY, '-r', 'sleep(2);']);
        $stdin = $supervisor->stdin();

        $meta = stream_get_meta_data($stdin);
        assertSame(false, $meta['blocked']);

        $supervisor->close(true);
    }

    public function testWorkerReceivesMinimalEnvironmentWithoutInheritedSecrets(): void
    {
        putenv('KNOSSOS_LEAK_MARKER=super-secret');
        try {
            $supervisor = new WorkerProcessSupervisor([
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, (getenv("KNOSSOS_LEAK_MARKER") === false ? "ABSENT" : "LEAKED") . "|" . (getenv("PATH") ? "HASPATH" : "NOPATH") . "\n"); fflush(STDOUT);',
            ]);
            $line = $this->readLine($supervisor->stdout());
            $supervisor->close(true);
        } finally {
            putenv('KNOSSOS_LEAK_MARKER');
        }

        assertSame('ABSENT|HASPATH', $line);
    }

    public function testExplicitEnvironmentIsHonouredVerbatim(): void
    {
        $supervisor = new WorkerProcessSupervisor(
            [
                PHP_BINARY,
                '-r',
                'fwrite(STDOUT, (getenv("KNOSSOS_EXPLICIT") ?: "NONE") . "\n"); fflush(STDOUT);',
            ],
            ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'KNOSSOS_EXPLICIT' => 'provided'],
        );

        $line = $this->readLine($supervisor->stdout());
        $supervisor->close(true);

        assertSame('provided', $line);
    }

    public function testWorkerRunsInNeutralWorkingDirectory(): void
    {
        $supervisor = new WorkerProcessSupervisor([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, getcwd() . "\n"); fflush(STDOUT);',
        ]);

        $line = $this->readLine($supervisor->stdout());
        $supervisor->close(true);

        assertSame(realpath(sys_get_temp_dir()), realpath($line));
    }

    public function testCloseTerminatesRunningChild(): void
    {
        $supervisor = new WorkerProcessSupervisor([PHP_BINARY, '-r', 'sleep(30);']);
        $status = $supervisor->status();
        // start() lazily runs via stdin(); trigger it and capture the pid.
        $supervisor->stdin();
        $pid = $supervisor->status()['pid'];
        assertSame(true, $pid > 0);

        $supervisor->close(true);

        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('posix_kill unavailable; cannot assert termination.');
        }
        // The direct child must be gone.
        assertSame(false, @posix_kill($pid, 0));
    }

    public function testCloseReapsGrandchildProcessTree(): void
    {
        if (!function_exists('posix_kill') || PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Process-tree termination requires POSIX signals.');
        }

        // Child spawns a long-lived grandchild, prints its pid, then blocks.
        $childScript = '$p = proc_open(["sleep", "30"], [["pipe","r"],["pipe","w"],["pipe","w"]], $pipes);'
            . '$st = proc_get_status($p); fwrite(STDOUT, $st["pid"] . "\n"); fflush(STDOUT); sleep(30);';
        $supervisor = new WorkerProcessSupervisor([PHP_BINARY, '-r', $childScript]);

        $grandchildPid = (int) trim($this->readLine($supervisor->stdout()));
        assertSame(true, $grandchildPid > 0);
        // Confirm the grandchild is genuinely alive before we tear the tree down.
        assertSame(true, @posix_kill($grandchildPid, 0));

        $supervisor->close(true);

        // Poll: the grandchild must be reaped, not orphaned to init.
        $alive = true;
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            if (!@posix_kill($grandchildPid, 0)) {
                $alive = false;
                break;
            }
            usleep(20_000);
        }
        if ($alive) {
            @posix_kill($grandchildPid, 9); // avoid leaking a real process
        }

        assertSame(false, $alive);
    }

    /**
     * The worker leads its own process group, which is what makes a
     * group-directed kill both safe and complete.
     *
     * Placing it there from the parent after `proc_open()` cannot work: the
     * child reaches `execve()` first and `setpgid()` is then refused with
     * EACCES — measured at 40 out of 40 spawns on this host, so the group was
     * never established at all and every termination fell back to walking
     * `/proc/<worker>/task/<worker>/children`. That walk is a point-in-time
     * snapshot and misses anything spawned or orphaned around it. Asserting the
     * group directly is the only thing that tells a working mechanism from one
     * that has silently degraded to the fallback.
     */
    public function testWorkerLeadsItsOwnProcessGroup(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || !function_exists('posix_getpgid') || !self::hasSetsid()) {
            $this->markTestSkipped('A dedicated process group needs POSIX and setsid.');
        }
        $supervisor = new WorkerProcessSupervisor([PHP_BINARY, '-r', 'sleep(5);']);
        $supervisor->start();
        $pid = (int) $supervisor->status()['pid'];

        assertSame($pid, @posix_getpgid($pid));

        $supervisor->close(true);
    }

    /**
     * A descendant survives the worker that spawned it, and must not survive
     * the supervisor.
     *
     * The moment the worker dies its children are re-parented to init, which
     * makes them unreachable from its pid: the descendant walk returns an empty
     * list and the analyser keeps its memory for as long as it likes. This is
     * not hypothetical tidiness — the worker crashing mid-scan is exactly when
     * something it spawned is most likely to be left holding a compiler's worth
     * of heap. A process group outlives its leader for as long as any member
     * remains, so the escalation still reaches them.
     *
     * The fixture kills itself with SIGKILL rather than returning: a clean exit
     * would let PHP's shutdown wait on the grandchild and defeat the setup.
     */
    public function testCloseReapsDescendantsOrphanedByAnAlreadyExitedWorker(): void
    {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_kill') || !self::hasSetsid()) {
            $this->markTestSkipped('Reaping an orphaned descendant needs Linux, POSIX signals, and setsid.');
        }
        $childScript = '$p = proc_open(["sleep", "30"], [["pipe","r"],["pipe","w"],["pipe","w"]], $pipes);'
            . '$st = proc_get_status($p); fwrite(STDOUT, $st["pid"] . "\n"); fflush(STDOUT);'
            . 'posix_kill(posix_getpid(), 9);';
        $supervisor = new WorkerProcessSupervisor([PHP_BINARY, '-r', $childScript]);

        $grandchildPid = (int) trim($this->readLine($supervisor->stdout()));
        assertSame(true, $grandchildPid > 0);
        assertSame(true, self::processState($grandchildPid) !== 'gone');

        $supervisor->close(true);

        $deadline = microtime(true) + 3.0;
        while (self::processState($grandchildPid) !== 'gone' && microtime(true) < $deadline) {
            usleep(20_000);
        }
        $state = self::processState($grandchildPid);
        @posix_kill($grandchildPid, 9); // Never leak a real process out of a test.

        assertSame('gone', $state);
    }

    public function testStatusReturnsInertShapeBeforeStart(): void
    {
        $supervisor = new WorkerProcessSupervisor([PHP_BINARY, '-r', 'sleep(1);']);
        $status = $supervisor->status();

        assertSame(0, $status['pid']);
        assertSame(false, $status['running']);
    }
}
