<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query\Drift;

use Knossos\Discovery\FileFingerprint;
use Knossos\Filesystem\RegularFileOpener;
use Knossos\Git\DirtyPathSet;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * A tracked path that has become a named pipe must read as deleted, the way
 * discovery treats it. Opening the pipe for reading blocks until a writer
 * shows up, so a probe that hashes it with a plain read never returns, and the
 * probe runs on every enriched tool result.
 *
 * The count runs in a child process under a deadline: a regression has to fail
 * this test, not hang the suite.
 */
final class DriftOracleSpecialFileTest extends KnossosTestCase
{
    private const HEAD = '3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c';
    private const DEADLINE_SECONDS = 5.0;

    #[Group('query')]
    public function testTheWalkCountsATrackedPathThatBecameAPipeAsDeleted(): void
    {
        $result = $this->countWithPipeInPlaceOfTrackedFile(
            '(new Knossos\Query\Drift\WalkDriftOracle($pdo))->drift($project, $scan, $root, $finished)',
        );

        self::assertSame(['changed' => 0, 'deleted' => 1], $result);
    }

    #[Group('git')]
    public function testTheGitProbeCountsATrackedPathThatBecameAPipeAsDeleted(): void
    {
        $runner = <<<'PHP'
            new class implements Knossos\Git\GitProcessRunnerInterface {
                public function run(array $command, int $timeoutMs, string $operation): string
                {
                    return match (true) {
                        in_array('rev-parse', $command, true) => "3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c\n",
                        in_array('--others', $command, true) => '',
                        default => "src/a.php\0",
                    };
                }
            }
            PHP;
        $result = $this->countWithPipeInPlaceOfTrackedFile(
            '(new Knossos\Query\Drift\GitDriftOracle($pdo, ' . $runner . '))->drift($project, $scan, $root, $finished)',
        );

        self::assertSame(['changed' => 0, 'deleted' => 1], $result);
    }

    /** Without the FFI opener a regular file still hashes as discovery hashes it, in-process. */
    #[Group('query')]
    public function testTheHelperPathHashesARegularFileLikeDiscoveryDoes(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'knossos-stale-');
        self::assertIsString($file);
        file_put_contents($file, "<?php\necho 1;\n");
        try {
            $expected = FileFingerprint::contentHashOf($file);
            self::assertNotNull($expected);

            $actual = null;
            $this->throughTheHelper(static function () use ($file, &$actual): void {
                $actual = FileFingerprint::probeHashOf($file);
            });

            self::assertSame($expected, $actual);
        } finally {
            @unlink($file);
        }
    }

    /** Without the FFI opener a pipe must still read as gone rather than block the probe. */
    #[Group('query')]
    public function testTheHelperPathReadsAPipeAsDeletedWithoutHanging(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is not available.');
        }
        $fifo = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        if (!posix_mkfifo($fifo, 0o644)) {
            self::markTestSkipped('The filesystem cannot hold a named pipe.');
        }
        try {
            $script = 'require $argv[1] . "/vendor/autoload.php";'
                . '$o = new ReflectionClass(Knossos\Filesystem\RegularFileOpener::class);'
                . '$o->getProperty("ffiAttempted")->setValue(null, true);'
                . '$o->getProperty("libc")->setValue(null, null);'
                . 'echo json_encode(Knossos\Discovery\FileFingerprint::probeHashOf($argv[2]));';
            $output = $this->runWithDeadline([PHP_BINARY, '-r', $script, '--', self::repositoryRoot(), $fifo]);

            self::assertNotNull($output, 'The probe did not return: it blocked reading the pipe.');
            self::assertSame('null', $output);
        } finally {
            @unlink($fifo);
        }
    }

    /** Run the callback with the FFI binding marked unavailable, so the opener reports the helper. */
    private function throughTheHelper(callable $run): void
    {
        $opener = new \ReflectionClass(RegularFileOpener::class);
        $attempted = $opener->getProperty('ffiAttempted');
        $libc = $opener->getProperty('libc');
        $before = [$attempted->getValue(), $libc->getValue()];
        $attempted->setValue(null, true);
        $libc->setValue(null, null);
        try {
            self::assertTrue(RegularFileOpener::usesHelper());
            $run();
        } finally {
            $attempted->setValue(null, $before[0]);
            $libc->setValue(null, $before[1]);
        }
    }

    /** @return array{changed: int, deleted: int} */
    private function countWithPipeInPlaceOfTrackedFile(string $probeExpression): array
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is not available.');
        }
        [$pdo, $projectId, $root] = $this->seedProjectWithFiles(['src/a.php']);
        $database = $root . '.sqlite';
        try {
            $scanId = (string) $pdo->query('SELECT active_scan_id FROM projects')->fetchColumn();
            $pdo->prepare('UPDATE scans SET git_head = :head, dirty_paths_json = :dirty WHERE id = :id')
                ->execute(['head' => self::HEAD, 'dirty' => DirtyPathSet::of([])->encode(), 'id' => $scanId]);
            $finished = (string) $pdo->query('SELECT finished_at FROM scans')->fetchColumn();
            $pdo->exec("VACUUM INTO '" . $database . "'");

            unlink($root . '/src/a.php');
            if (!posix_mkfifo($root . '/src/a.php', 0o644)) {
                self::markTestSkipped('The filesystem cannot hold a named pipe.');
            }

            $script = 'require $argv[1] . "/vendor/autoload.php";'
                . '$pdo = Knossos\Store\SqliteConnection::open($argv[2]);'
                . '[$project, $scan, $root, $finished] = [$argv[3], $argv[4], $argv[5], $argv[6]];'
                . '$drift = ' . $probeExpression . ';'
                . 'echo json_encode($drift === null ? null : ["changed" => $drift->changed, "deleted" => $drift->deleted]);';
            $output = $this->runWithDeadline(
                [PHP_BINARY, '-r', $script, '--', self::repositoryRoot(), $database, $projectId, $scanId, $root, $finished],
            );

            self::assertNotNull($output, 'The probe did not return: it blocked reading the pipe.');
            $decoded = json_decode($output, true);
            self::assertIsArray($decoded, 'The probe declined or failed: ' . $output);

            return ['changed' => (int) $decoded['changed'], 'deleted' => (int) $decoded['deleted']];
        } finally {
            @unlink($database);
            $this->removeTempTree($root);
        }
    }

    /**
     * @param list<string> $command
     * @return ?string stdout, or null when the deadline passed and the child was killed
     */
    private function runWithDeadline(array $command): ?string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + self::DEADLINE_SECONDS;
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            if (!proc_get_status($process)['running']) {
                $output .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (microtime(true) > $deadline) {
                // A child stuck in open(2) on a pipe ignores SIGTERM only in
                // uninterruptible states; SIGKILL is certain.
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                return null;
            }
            usleep(20_000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return $output;
    }
}
