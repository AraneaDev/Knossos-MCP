<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Runtime;

use Knossos\Runtime\WorkerSourceHash;
use Knossos\Scanner\Worker\ProcessScannerClient;
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
     * The installed binary's embedded hash and this class agree on the real
     * tree. Fails on a checkout whose binary predates its source, which is the
     * condition `doctor` reports: run `tools/install` to rebuild it.
     */
    public function testTheInstalledRustWorkerWasBuiltFromThisCheckoutsSource(): void
    {
        $binary = self::repositoryRoot() . '/workers/rust/bin/knossos-rust-worker';
        if (!is_file($binary)) {
            self::markTestSkipped('The Rust worker is not installed.');
        }
        $client = new ProcessScannerClient([$binary]);
        try {
            $embedded = $client->initialize()->sourceHash;
        } finally {
            $client->shutdown();
        }

        assertSame(
            WorkerSourceHash::of(self::repositoryRoot() . '/workers/rust'),
            $embedded,
            'The installed Rust worker was built from other source; run tools/install.',
        );
    }
}
