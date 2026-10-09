<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\WorkerSourceHash;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;

#[Group('doctor-service')]
final class WorkerSourceHashTest extends KnossosTestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/knossos-source-hash-' . uniqid('', true);
        mkdir($this->root . '/src/nested', 0777, true);
        file_put_contents($this->root . '/Cargo.toml', "[package]\n");
        file_put_contents($this->root . '/src/lib.rs', "pub mod nested;\n");
        file_put_contents($this->root . '/src/nested/mod.rs', "pub fn deep() {}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testTheSharedFixtureHashesToTheGoldenValueTheRustWorkerAlsoChecks(): void
    {
        $fixtures = self::repositoryRoot() . '/tests/Fixtures';

        assertSame(
            trim((string) file_get_contents($fixtures . '/worker-source-hash.sha256')),
            WorkerSourceHash::of($fixtures . '/worker-source-hash'),
        );
    }

    public function testATreeWithoutSrcHasNoHash(): void
    {
        assertSame(null, WorkerSourceHash::of($this->root . '/missing'));
        exec('rm -rf ' . escapeshellarg($this->root . '/src'));
        assertSame(null, WorkerSourceHash::of($this->root));
    }

    public function testOneChangedSourceByteChangesTheHash(): void
    {
        $before = WorkerSourceHash::of($this->root);
        file_put_contents($this->root . '/src/nested/mod.rs', "pub fn deep() {}\n\n");

        self::assertNotSame($before, WorkerSourceHash::of($this->root));
    }

    public function testEveryRootInputCounts(): void
    {
        foreach (['Cargo.toml', 'Cargo.lock', 'build.rs'] as $input) {
            $before = WorkerSourceHash::of($this->root);
            file_put_contents($this->root . '/' . $input, $input . " changed\n");
            self::assertNotSame($before, WorkerSourceHash::of($this->root), $input . ' must feed the hash');
        }
    }

    public function testFilesTheBuildDoesNotReadAreIgnored(): void
    {
        $before = WorkerSourceHash::of($this->root);
        mkdir($this->root . '/tests');
        mkdir($this->root . '/bin');
        file_put_contents($this->root . '/tests/it.rs', "#[test]\nfn it() {}\n");
        file_put_contents($this->root . '/bin/knossos-rust-worker', 'binary');
        file_put_contents($this->root . '/README.md', "readme\n");

        assertSame($before, WorkerSourceHash::of($this->root));
    }

    public function testARenamedFileChangesTheHashEvenWithTheSameBytes(): void
    {
        $before = WorkerSourceHash::of($this->root);
        rename($this->root . '/src/nested/mod.rs', $this->root . '/src/nested/other.rs');

        self::assertNotSame($before, WorkerSourceHash::of($this->root));
    }

    /**
     * The real worker crate hashes exactly its build inputs: a copy holding
     * only src/, Cargo.toml, Cargo.lock and build.rs hashes the same as the
     * whole directory with its tests, binary and target. No binary needed, so
     * a stale local build cannot fail this; doctor reports that instead.
     */
    public function testTheRealWorkerCrateHashesOnlyItsBuildInputs(): void
    {
        $crate = self::repositoryRoot() . '/workers/rust';
        $hash = WorkerSourceHash::of($crate);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', (string) $hash);
        self::assertFileExists($crate . '/build.rs', 'build.rs embeds the hash, so it must be one of the inputs');

        exec('rm -rf ' . escapeshellarg($this->root));
        mkdir($this->root);
        exec(sprintf('cp -R %s %s', escapeshellarg($crate . '/src'), escapeshellarg($this->root . '/src')));
        foreach (['Cargo.toml', 'Cargo.lock', 'build.rs'] as $input) {
            copy($crate . '/' . $input, $this->root . '/' . $input);
        }
        assertSame($hash, WorkerSourceHash::of($this->root));

        unlink($this->root . '/build.rs');
        self::assertNotSame($hash, WorkerSourceHash::of($this->root));
    }
}
