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

/**
 * What `allow-root --json` reports, and the exact file it writes.
 *
 * The command's tests read its prose. Mutation testing showed the structured
 * report could claim a preview added the root, lose the preview flag, swap
 * where the roots file came from, or print nothing after a write, and that the
 * written file could lose its trailing newline, its indentation or its
 * unescaped slashes, all with those tests green. An agent reads the JSON, so
 * the JSON is what gets pinned here.
 */
final class RootsCommandReportTest extends KnossosTestCase
{
    private string $tempDir;
    private string $target;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir() . '/knossos-roots-report-' . bin2hex(random_bytes(4));
        $this->target = $this->tempDir . '/project';
        mkdir($this->target, 0o755, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->tempDir));
        parent::tearDown();
    }

    #[Group('cli')]
    public function testAPreviewReportsWhatWouldBeAddedAndThatNothingWas(): void
    {
        assertSame(
            ['path' => $this->target, 'roots_file' => $this->rootsFile(), 'roots_file_source' => 'named', 'added' => false, 'preview' => true],
            $this->allowRoot(),
        );
        assertSame(false, is_file($this->rootsFile()));
    }

    #[Group('cli')]
    public function testAWriteReportsTheAdditionAndLeavesACanonicalFile(): void
    {
        file_put_contents($this->rootsFile(), json_encode(['roots' => ['/srv/one', '/srv/two']]));

        assertSame(
            ['path' => $this->target, 'roots_file' => $this->rootsFile(), 'roots_file_source' => 'named', 'added' => true],
            $this->allowRoot(execute: true),
        );
        // Indented, slashes unescaped, every existing root kept in order, and
        // a trailing newline: a file people are expected to read and edit.
        assertSame(
            "{\n    \"roots\": [\n        \"/srv/one\",\n        \"/srv/two\",\n        \"{$this->target}\"\n    ]\n}\n",
            (string) file_get_contents($this->rootsFile()),
        );
    }

    #[Group('cli')]
    public function testARootAlreadyPresentIsReportedAsNotAdded(): void
    {
        file_put_contents($this->rootsFile(), json_encode(['roots' => [$this->target]]));

        assertSame(['path' => $this->target, 'roots_file' => $this->rootsFile(), 'added' => false], $this->allowRoot(execute: true));
    }

    /**
     * Where the roots file came from is reported as named when an environment
     * variable names it, and as the working directory when nothing does. An
     * empty variable names nothing.
     */
    #[Group('cli')]
    public function testTheRootsFileSourceFollowsWhatNamedIt(): void
    {
        foreach (['KNOSSOS_ROOTS_FILE' => $this->rootsFile(), 'KNOSSOS_DATA_DIR' => $this->tempDir] as $variable => $value) {
            assertSame('named', $this->runWithoutDatabase([$variable => $value])['roots_file_source'], $variable);
        }
        assertSame('working-directory', $this->runWithoutDatabase(['KNOSSOS_ROOTS_FILE' => '', 'KNOSSOS_DATA_DIR' => ''])['roots_file_source']);
    }

    /** A new roots file's directory is created readable by everyone and writable by its owner. */
    #[Group('cli')]
    public function testANewRootsDirectoryIsCreatedWithOrdinaryPermissions(): void
    {
        $nested = $this->tempDir . '/fresh/place/roots.json';

        $this->runWithoutDatabase(['KNOSSOS_ROOTS_FILE' => $nested], execute: true);

        clearstatcache();
        assertSame(0o755, fileperms(dirname($nested)) & 0o777);
        assertSame(true, is_file($nested));
    }

    /** Every permission bit of an existing roots file survives the rewrite, the others' execute bit included. */
    #[Group('cli')]
    public function testEveryPermissionBitOfAnExistingFileSurvives(): void
    {
        file_put_contents($this->rootsFile(), json_encode(['roots' => []]));
        chmod($this->rootsFile(), 0o605);

        $this->allowRoot(execute: true);

        clearstatcache();
        assertSame(0o605, fileperms($this->rootsFile()) & 0o777);
    }

    /** A roots file nested seven levels deep is read; eight is refused as invalid rather than rewritten. */
    #[Group('cli')]
    public function testTheRootsFileIsReadToSevenLevelsOfNesting(): void
    {
        file_put_contents($this->rootsFile(), '{"roots":[[[[[["x"]]]]]]}');
        assertSame(true, $this->allowRoot(execute: true)['added']);

        file_put_contents($this->rootsFile(), '{"roots":[[[[[[["x"]]]]]]]}');
        $error = captureThrows(fn() => $this->allowRoot(execute: true), InvalidArgumentException::class);
        assertSame(true, str_contains($error->getMessage(), 'is not valid JSON'));
    }

    private function rootsFile(): string
    {
        return $this->tempDir . '/roots.json';
    }

    /** @return array<string, mixed> */
    private function allowRoot(bool $execute = false): array
    {
        return $this->invoke(
            new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), $this->tempDir . '/knossos.sqlite'),
            $execute,
        );
    }

    /**
     * The same command with no --db, so only the environment can name the roots file.
     *
     * @param array<string, string> $environment
     * @return array<string, mixed>
     */
    private function runWithoutDatabase(array $environment, bool $execute = false): array
    {
        $saved = [];
        foreach (['KNOSSOS_ROOTS_FILE', 'KNOSSOS_DATA_DIR'] as $variable) {
            $saved[$variable] = getenv($variable);
            putenv($variable);
        }
        foreach ($environment as $variable => $value) {
            putenv($variable . '=' . $value);
        }
        $cwd = (string) getcwd();
        chdir($this->tempDir);
        try {
            return $this->invoke(new CliCommandContext(new CliOptionParser(), new CliInputLoader(), new RuntimeFactory(self::repositoryRoot()), null), $execute);
        } finally {
            chdir($cwd);
            foreach ($saved as $variable => $value) {
                putenv(is_string($value) ? $variable . '=' . $value : $variable);
            }
        }
    }

    /** @return array<string, mixed> */
    private function invoke(CliCommandContext $context, bool $execute): array
    {
        $options = ['json' => ['true']] + ($execute ? ['execute' => ['true']] : []);
        ob_start();
        try {
            (new RootsCommand())->run('allow-root', [$this->target], $options, $context);
        } finally {
            $output = (string) ob_get_clean();
        }

        return json_decode($output, true, 512, JSON_THROW_ON_ERROR);
    }
}
