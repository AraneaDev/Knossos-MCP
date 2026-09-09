<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\SessionCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class SessionCommandTest extends KnossosTestCase
{
    private function context(): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            ':memory:',
        );
    }

    #[Group('cli')]
    public function testCommandIsRoutedAndAcceptsOnlyItsOwnOptions(): void
    {
        $command = new SessionCommand();

        assertSame(true, $command->supports('session-brief'));
        assertSame(false, $command->supports('export-agent-brief'));
        assertSame(['db', 'json'], $command->allowedOptions('session-brief'));
    }

    #[Group('cli')]
    public function testUnscannedPathStillExitsZeroWithABrief(): void
    {
        ob_start();
        $status = (new SessionCommand())->run('session-brief', [sys_get_temp_dir()], [], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, 'NOT SCANNED.'));
    }

    #[Group('cli')]
    public function testAnUnusableDatabaseIsSilentAndStillExitsZero(): void
    {
        // The governing rule for this command: it may fail to help, and may
        // never cost anything. A session start must not inherit a stack trace.
        $context = new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            '/proc/definitely-not-writable/knossos.sqlite',
        );

        ob_start();
        $status = (new SessionCommand())->run('session-brief', ['/tmp'], [], $context);
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame('', trim($output));
    }
}
