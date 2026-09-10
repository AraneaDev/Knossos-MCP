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
        // Readable by its owner regardless of umask. A regression that forced
        // mode 0000 onto a newly created file (the fileperms()-returns-false
        // coercion this command must never fall into) would fail this even
        // though the content assertion above still passed.
        assertSame(true, (fileperms($this->rootsFile()) & 0o400) !== 0);
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
    public function testATrailingSlashIsTheSameRootRatherThanASecondEntry(): void
    {
        // The command compared raw strings, so `/p` then `/p/` wrote two lines
        // and reported "already present" for neither. AllowedRoots::normalise()
        // folds them back together on read, so the grant was never doubled;
        // what was doubled was the file, which is one a person edits by hand.
        $target = $this->tempDir . '/project';
        mkdir($target);

        ob_start();
        (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
        ob_get_clean();

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$target . '/'], ['execute' => ['true']], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, 'already'));
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        assertSame(['roots' => [$target]], $decoded);
    }

    #[Group('cli')]
    public function testATrailingSlashIsStrippedFromTheEntryItWrites(): void
    {
        // The other order, on an empty file: the entry that lands on disk is
        // already in the reader's own spelling, so a later `allow-root /p` sees
        // it as present instead of appending the twin this fix just prevented.
        $target = $this->tempDir . '/project';
        mkdir($target);

        ob_start();
        (new RootsCommand())->run('allow-root', [$target . '/'], ['execute' => ['true']], $this->context());
        ob_get_clean();

        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        assertSame(['roots' => [$target]], $decoded);

        ob_start();
        (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
        $output = (string) ob_get_clean();

        assertSame(true, str_contains($output, 'already'));
    }

    #[Group('cli')]
    public function testAHandEditedTrailingSlashEntryIsMatchedAndLeftAlone(): void
    {
        // A file written before this fix, or by hand, can already hold `/p/`.
        // That entry is a grant the server honours, so a call for `/p` must
        // recognise it rather than append a duplicate, and must not quietly
        // rewrite a line it did not add.
        $target = $this->tempDir . '/project';
        mkdir($target);
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$target . '/']], JSON_PRETTY_PRINT) . PHP_EOL);

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$target], ['execute' => ['true']], $this->context());
        $output = (string) ob_get_clean();

        assertSame(0, $status);
        assertSame(true, str_contains($output, 'already'));
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        assertSame(['roots' => [$target . '/']], $decoded);
    }

    #[Group('cli')]
    public function testBareArrayFileIsAcceptedAppendedAndNormalisedToCanonicalShape(): void
    {
        // AllowedRoots::parse() tolerates a bare [...] as well as
        // {"roots": [...]}, and a running server honours a file in that shape.
        // This command must not call such a file corrupt.
        $existing = $this->tempDir . '/existing';
        $added = $this->tempDir . '/added';
        mkdir($existing);
        mkdir($added);
        file_put_contents($this->rootsFile(), json_encode([$existing]) . PHP_EOL);

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$added], ['execute' => ['true']], $this->context());
        ob_get_clean();

        assertSame(0, $status);
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        // Normalised to the canonical shape, with the pre-existing path first
        // and the newly added one appended after it.
        assertSame(['roots' => [$existing, $added]], $decoded);
    }

    #[Group('cli')]
    public function testExecutePreservesThePreExistingFilesPermissions(): void
    {
        // The atomic rename swaps in a freshly-created temporary file, whose
        // mode comes from the umask default rather than the target's. A file
        // an operator deliberately locked down must not silently widen just
        // because a root was added to it.
        $existing = $this->tempDir . '/existing';
        $added = $this->tempDir . '/added';
        mkdir($existing);
        mkdir($added);
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$existing]], JSON_PRETTY_PRINT) . PHP_EOL);
        chmod($this->rootsFile(), 0o600);

        ob_start();
        $status = (new RootsCommand())->run('allow-root', [$added], ['execute' => ['true']], $this->context());
        ob_get_clean();

        assertSame(0, $status);
        assertSame('0600', substr(sprintf('%o', fileperms($this->rootsFile())), -4));
        // Asserted alongside the mode so a fix that preserved permissions
        // while corrupting the content could not pass.
        $decoded = json_decode((string) file_get_contents($this->rootsFile()), true);
        assertSame(['roots' => [$existing, $added]], $decoded);
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
