<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Cli;

use InvalidArgumentException;
use Knossos\Cli\CliCommandContext;
use Knossos\Cli\CliInputLoader;
use Knossos\Cli\CliOptionParser;
use Knossos\Cli\Command\RootsCommand;
use Knossos\Runtime\RuntimeFactory;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

final class RootsCommandTest extends KnossosTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-roots-' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
        parent::tearDown();
    }

    private function context(): CliCommandContext
    {
        return new CliCommandContext(
            new CliOptionParser(),
            new CliInputLoader(),
            new RuntimeFactory(self::repositoryRoot()),
            $this->tempDir . '/knossos.sqlite',
        );
    }

    private function rootsFile(): string
    {
        return $this->tempDir . '/roots.json';
    }

    #[Group('cli')]
    public function testCommandIsRoutedAndAcceptsOnlyItsOwnOptions(): void
    {
        $command = new RootsCommand();

        assertSame(true, $command->supports('allow-root'));
        assertSame(false, $command->supports('scan'));
        assertSame(['db', 'json', 'execute'], $command->allowedOptions('allow-root'));
    }

    #[Group('cli')]
    public function testPreviewNamesTheRootsFileAndWritesNothing(): void
    {
        $target = $this->tempDir . '/project';
        mkdir($target);

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$target], [], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, $this->rootsFile()));
        assertSame(true, str_contains($output, $target));
        // The whole point of preview-by-default: nothing was written at all.
        assertSame(false, is_file($this->rootsFile()));
    }

    #[Group('cli')]
    public function testExecuteCreatesTheFile(): void
    {
        $target = $this->tempDir . '/project';
        mkdir($target);

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
        ob_get_clean();

        assertSame(0, $status);
        assertSame(true, is_file($this->rootsFile()));
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        assertSame(['roots' => [$target]], $decoded);
    }

    #[Group('cli')]
    public function testAppendingPreservesExistingEntriesInInsertionOrder(): void
    {
        $first = $this->tempDir . '/first';
        $second = $this->tempDir . '/second';
        mkdir($first);
        mkdir($second);
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$first]], JSON_PRETTY_PRINT) . PHP_EOL);

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$second], ['execute' => ['true']], $this->context());
        ob_get_clean();

        assertSame(0, $status);
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        assertSame(['roots' => [$first, $second]], $decoded);
    }

    #[Group('cli')]
    public function testAddingARootTwiceIsANoOpOnTheSecondRun(): void
    {
        $target = $this->tempDir . '/project';
        mkdir($target);

        ob_start();
        (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
        ob_get_clean();

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, 'already'));
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        // Exactly one entry: the second run did not duplicate it.
        assertSame(['roots' => [$target]], $decoded);
    }

    #[Group('cli')]
    public function testRelativePathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absolute');

        (new RootsCommand())->run('allow-root', ['relative/path'], [], $this->context());
    }

    #[Group('cli')]
    public function testMissingPathIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Usage:');

        (new RootsCommand())->run('allow-root', [], [], $this->context());
    }

    #[Group('cli')]
    public function testNonExistentDirectoryIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RootsCommand())->run('allow-root', [$this->tempDir . '/does-not-exist'], [], $this->context());
    }

    #[Group('cli')]
    public function testMalformedRootsFileThrowsInsteadOfBeingOverwritten(): void
    {
        $target = $this->tempDir . '/project';
        mkdir($target);
        file_put_contents($this->rootsFile(), 'not json');
        $before = (string) file_get_contents($this->rootsFile());

        try {
            (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
            self::fail('Expected an InvalidArgumentException.');
        } catch (InvalidArgumentException) {
            // Expected: a hand-edited file that is not valid JSON must not be
            // silently replaced by a fresh one holding only this call's root.
        }

        assertSame($before, (string) file_get_contents($this->rootsFile()));
    }
}
