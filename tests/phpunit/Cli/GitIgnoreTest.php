<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class GitIgnoreTest extends KnossosTestCase
{
    /** How long the probe gets before it is declared hung, in seconds. */
    private const PROBE_TIMEOUT_SECONDS = 60;

    /**
     * The shared git-ignore filter must not deadlock on a large path list.
     *
     * It talks to `git check-ignore --stdin`, which echoes back every path that
     * matched, so the reply grows with the request. Writing the whole request
     * before reading any of the reply wedges both processes once each exceeds a
     * pipe's 64 KiB: the parent blocks in fwrite() waiting for the child to
     * read, the child blocks writing a reply nobody is reading, and there is no
     * timeout to break the tie — tools/repository-check.php feeds it every file
     * in the tree, so this would hang the `quality` job until CI killed it.
     *
     * 6,000 ignored paths is ~252 KB in each direction, comfortably past the
     * buffer on both sides. The paths need not exist: `git check-ignore` answers
     * from .gitignore patterns, not from the filesystem.
     *
     * The probe runs in a subprocess against a deadline so that a regression
     * FAILS here rather than hanging the suite it is meant to protect.
     */
    #[Group('documentation')]
    public function testGitIgnoreFilterSurvivesAPathListLargerThanAPipe(): void
    {
        if (self::locateGit() === null) {
            self::markTestSkipped('git is unavailable here.');
        }
        $root = self::repositoryRoot();
        [$ignoreExit] = $this->runFixtureCommandOutput([
            'git', '-C', $root, 'check-ignore', '-q', $root . '/docs/superpowers/probe.md',
        ]);
        if ($ignoreExit !== 0) {
            self::markTestSkipped('git cannot report ignore status here.');
        }

        $probe = (string) tempnam(sys_get_temp_dir(), 'knossos-git-ignore-probe-');
        try {
            file_put_contents($probe, <<<'PHP'
                <?php
                require getenv('KNOSSOS_ROOT') . '/tools/lib/git-ignore.php';
                $paths = [];
                for ($i = 0; $i < 6000; ++$i) {
                    $paths[] = 'docs/superpowers/pipe-probe-' . $i . '.md';
                    $paths[] = 'docs/pipe-probe-' . $i . '.md';
                }
                echo count(gitIgnoredPaths(getenv('KNOSSOS_ROOT'), $paths)), "\n";
                PHP);

            $output = $this->runBounded([PHP_BINARY, $probe], ['KNOSSOS_ROOT' => $root]);

            // Only the /docs/superpowers/ half is ignored. An exact count also
            // rules out the quieter failure the deadlock fix could have caused:
            // giving up on a partly-written request and reporting fewer.
            assertSame('6000', trim($output));
        } finally {
            @unlink($probe);
        }
    }

    /**
     * Run a command to completion, or fail once the deadline passes.
     *
     * Deliberately not runFixtureCommandOutput(): that drains until EOF with no
     * bound, which is the exact behaviour under test here, so a regression would
     * hang the suite instead of reporting.
     *
     * @param non-empty-list<string> $command
     * @param array<string, string> $environment
     */
    private function runBounded(array $command, array $environment): string
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $environment + getenv());
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the probe.');
        }
        stream_set_blocking($pipes[1], false);
        $output = '';
        $deadline = microtime(true) + self::PROBE_TIMEOUT_SECONDS;
        while (true) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                proc_close($process);
                self::fail(sprintf('The git-ignore filter did not finish within %d seconds.', self::PROBE_TIMEOUT_SECONDS));
            }
            $read = [$pipes[1]];
            $write = $except = null;
            if (@stream_select($read, $write, $except, (int) $remaining, 0) === false) {
                break;
            }
            if ($read === []) {
                continue;
            }
            $chunk = fread($pipes[1], 65536);
            if ($chunk === false || ($chunk === '' && feof($pipes[1]))) {
                break;
            }
            $output .= $chunk;
        }
        fclose($pipes[1]);
        proc_close($process);

        return $output;
    }
}
