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
    #[Group('git')]
    public function testItReturnsTheTrimmedHeadSha(): void
    {
        $resolver = new GitHeadResolver(self::runner("3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c\n"));

        self::assertSame('3f1a9c2b4d5e6f708192a3b4c5d6e7f8091a2b3c', $resolver->resolve(sys_get_temp_dir()));
    }

    #[Group('git')]
    public function testItReturnsNullWhenGitFails(): void
    {
        $resolver = new GitHeadResolver(self::failingRunner());

        self::assertNull($resolver->resolve(sys_get_temp_dir()), 'A project that is not a repository must degrade, not throw.');
    }

    #[Group('git')]
    public function testItRejectsOutputThatIsNotASha(): void
    {
        $resolver = new GitHeadResolver(self::runner("fatal: not a git repository\n"));

        self::assertNull($resolver->resolve(sys_get_temp_dir()), 'Only a sha may be stored; anything else would be compared against later as if it were one.');
    }

    private static function runner(string $output): GitProcessRunnerInterface
    {
        return new class($output) implements GitProcessRunnerInterface {
            public function __construct(private readonly string $output) {}

            public function run(array $command, int $timeoutMs, string $operation): string
            {
                return $this->output;
            }
        };
    }

    private static function failingRunner(): GitProcessRunnerInterface
    {
        return new class implements GitProcessRunnerInterface {
            public function run(array $command, int $timeoutMs, string $operation): string
            {
                throw new RuntimeException('not a git repository');
            }
        };
    }
}
