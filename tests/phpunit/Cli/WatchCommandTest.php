<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\WatchCommand;
use Knossos\Result\ResultEnvelope;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

use function PHPUnit\Framework\assertContains;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;

/** `watch --shared`: the mod's live watcher answers every refusal as an event, exits 0 and never creates a database. */
final class WatchCommandTest extends KnossosTestCase
{
    #[Group('cli')]
    public function testTheSharedWatcherIsAnOptionOfWatch(): void
    {
        assertContains('shared', (new WatchCommand())->allowedOptions('watch'));
    }

    #[Group('cli')]
    public function testASharedWatcherWithNoDatabaseIsRefusedAndCreatesNone(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-stale-' . bin2hex(random_bytes(6));
        mkdir($directory);
        try {
            $context = new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), ':memory:');
            ob_start();
            $status = (new WatchCommand())->run('watch', [$directory], ['shared' => ['true'], 'db' => [$directory . '/knossos.sqlite']], $context);
            $lines = array_values(array_filter(explode("\n", (string) ob_get_clean())));
            assertSame(0, $status);
            assertSame([['event' => 'refused', 'status' => 'unscanned']], array_map(static fn(string $l): mixed => json_decode($l, true), $lines));
            assertFalse(file_exists($directory . '/knossos.sqlite'));
        } finally {
            $this->removeTempTree($directory);
        }
    }

    /**
     * A watch that stopped on a failure no retry could fix exited 0, or (when
     * the initial scan failed) 2 before it emitted anything: the exit code now
     * follows the stop reason the result carries.
     */
    #[Group('cli')]
    public function testAWatchThatStoppedOnAnErrorExitsWithTheErrorCode(): void
    {
        $result = static fn(string $reason): ResultEnvelope => new ResultEnvelope('p', 's', 'Watch stopped.', ['stopped_reason' => $reason]);

        assertSame(2, WatchCommand::exitCode($result('error')));
        foreach (['cancelled', 'poll_limit', 'orphaned'] as $reason) {
            assertSame(0, WatchCommand::exitCode($result($reason)));
        }
        assertSame(0, WatchCommand::exitCode(new ResultEnvelope('p', 's', 'Watch stopped.', [])));
    }
}
