<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Query;

use Knossos\Query\FileContextService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertSame;

/** One file's context for the model's tool: boundary, dependents, tests and latest commits, each cut short. */
final class FileContextServiceTest extends KnossosTestCase
{
    private const FIXTURE = 'turn-brief';

    #[Group('query')]
    public function testAFileIsAnsweredWithItsBoundaryDependentsTestsAndCommits(): void
    {
        [$pdo, , $root] = $this->scanTempFixture(self::FIXTURE);
        try {
            // Without git the commits are simply absent.
            $plain = (new FileContextService($pdo))->context($root . '/src/Core/Greeter.php');
            assertSame([], $plain['file']['commits']);
            $git = ['git', '-C', $root, '-c', 'user.name=Knossos Test', '-c', 'user.email=test@example.test', '-c', 'commit.gpgsign=false'];
            $this->runFixtureCommand(['git', 'init', '--quiet', $root]);
            $this->runFixtureCommand([...$git, 'add', '.']);
            $this->runFixtureCommand([...$git, 'commit', '--quiet', '-m', 'first']);
            $this->runFixtureCommand([...$git, 'commit', '--quiet', '--allow-empty', '-m', 'unrelated']);
            $context = (new FileContextService($pdo))->context($root . '/src/Core/Greeter.php');
            assertSame('ok', $context['status']);
            $file = $context['file'];
            assertSame(['src/Core/Greeter.php', 'php', 'Core', 2], [$file['path'], $file['language'], $file['boundary'], $file['components']]);
            assertSame(2, $file['dependents']['count']);
            assertSame(['src/Edge/Caller.php', 'tests/GreeterTest.php'], $file['dependents']['top']);
            assertSame(['tests/GreeterTest.php'], array_column($file['tests']['items'], 'path'));
            // The class and its method; only the commits that touched the file, newest first.
            assertSame(['first'], array_column($file['commits'], 'subject'));
            assertSame('not-found', (new FileContextService($pdo))->context($root . '/src/Nope.php')['status']);
        } finally {
            $this->removeTempTree($root);
        }
    }
}
