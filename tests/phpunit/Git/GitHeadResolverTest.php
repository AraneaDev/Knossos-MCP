<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Git;

use Knossos\Git\GitHeadResolver;
use Knossos\Git\GitProcessRunnerInterface;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

/**
 * The resolver decides whether a scan can ever be compared against git.
 * A wrong answer here is not a failed lookup, it is a graph that silently
 * falls back to the walk forever, so each branch is pinned.
 */
final class GitHeadResolverTest extends KnossosTestCase
{
    /** The stored value is what every later drift probe is compared against, so the trailing newline must not survive. */
    #[Group('git')]
    public function testItReturnsTheTrimmedHeadSha(): void
    {
        $resolver = new GitHeadResolver($this->runner("3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c\n"));

        self::assertSame('3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c', $resolver->resolve(sys_get_temp_dir()));
    }

    /** A project that is not a repository is ordinary, not exceptional: the walk oracle answers for it. */
    #[Group('git')]
    public function testItReturnsNullWhenGitFails(): void
    {
        $resolver = new GitHeadResolver($this->failingRunner());

        self::assertNull($resolver->resolve(sys_get_temp_dir()), 'A project that is not a repository must degrade, not throw.');
    }

    /** Git writes its diagnostics to stdout as readily as a sha, and a sentence is not a revision. */
    #[Group('git')]
    public function testItRejectsOutputThatIsNotASha(): void
    {
        $resolver = new GitHeadResolver($this->runner("fatal: not a git repository\n"));

        self::assertNull($resolver->resolve(sys_get_temp_dir()), 'Only a sha may be stored; anything else would be compared against later as if it were one.');
    }

    /** Git emits a SHA-1 or a SHA-256 and nothing between, so a 50-character hex string never came from git. */
    #[Group('git')]
    public function testItRejectsALengthGitNeverEmits(): void
    {
        $resolver = new GitHeadResolver($this->runner(str_repeat('a', 50) . "\n"));

        self::assertNull($resolver->resolve(sys_get_temp_dir()));
    }

    /**
     * The argv is the contract with git, and nothing else in the suite asserts
     * it: this runs on every scan, against a directory the server was pointed
     * at, so both the read-only flags and the pinned working directory are part
     * of the promise rather than incidental.
     */
    #[Group('git')]
    public function testItPinsTheCommandItIssues(): void
    {
        $runner = $this->capturingRunner();
        (new GitHeadResolver($runner))->resolve('/tmp/knossos-head-fixture');

        self::assertSame([
            ['git', '--no-optional-locks', '--no-pager', '-C', '/tmp/knossos-head-fixture', 'rev-parse', '--verify', 'HEAD'],
        ], $runner->commands);
        self::assertSame([2000], $runner->timeouts);
        self::assertSame(['scan head'], $runner->operations);
    }

    /**
     * A scan that records no head falls back to the walk for the rest of that
     * graph's life, and the fallback is invisible: no warning, no slow path,
     * only a probe that never gets cheaper. A timeout or an absent binary
     * therefore leaves a breadcrumb. A directory that is simply not a
     * repository does not, because every scan of every gitless project would
     * otherwise narrate itself into the server log.
     */
    #[Group('git')]
    public function testItRecordsWhyGitCouldNotAnswerWithoutNarratingAGitlessProject(): void
    {
        $log = sys_get_temp_dir() . '/knossos-head-breadcrumb-' . bin2hex(random_bytes(6)) . '.log';
        $previous = ini_set('error_log', $log);
        try {
            (new GitHeadResolver($this->failingRunner()))->resolve(sys_get_temp_dir());
            self::assertFalse(file_exists($log), 'A directory outside a repository is the ordinary case, not a fault worth logging.');

            (new GitHeadResolver($this->failingRunner('timed out after 2000 ms')))->resolve(sys_get_temp_dir());
            self::assertStringContainsString('timed out', (string) @file_get_contents($log), 'A git that was there and failed anyway has to be findable.');
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }

    /** A runner that answers with fixed output, standing in for a git binary CI does not have. */
    private function runner(string $output): GitProcessRunnerInterface
    {
        return new class ($output) implements GitProcessRunnerInterface {
            public function __construct(private readonly string $output) {}

            /** The canned output, whatever was asked. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->output;
            }
        };
    }

    /** A runner that records what it was asked to run, for pinning the argv. */
    private function capturingRunner(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            /** @var list<list<string>> */
            public array $commands = [];
            /** @var list<int> */
            public array $timeouts = [];
            /** @var list<string> */
            public array $operations = [];

            /** Records the call and answers with nothing. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                $this->commands[] = $command;
                $this->timeouts[] = $timeoutMs;
                $this->operations[] = $operation;

                return '';
            }
        };
    }

    /** A runner that fails every call, standing in for a directory that is not a repository. */
    private function failingRunner(string $message = 'fatal: not a git repository'): GitProcessRunnerInterface
    {
        return new class ($message) implements GitProcessRunnerInterface {
            public function __construct(private readonly string $message) {}

            /** Always throws, the way an absent repository, binary or timeout does. */
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                throw new RuntimeException($this->message);
            }
        };
    }
}
