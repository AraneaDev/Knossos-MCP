<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Query\ResultEnvelope;
use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;

/**
 * The Rust worker builds its declaration index from every Rust file of the
 * project and says which files each contribution read, so an incremental scan
 * rescans only the files a change reaches, through the real worker, and still
 * ends with the graph a full scan of the same bytes gives.
 *
 * Each case changes the tree in one way a Rust file's facts can depend on and
 * compares the incremental graph with a full scan of the changed tree. Every
 * test skips when the worker binary is absent, as the other Rust tests do.
 */
final class RustReadAttributionTest extends KnossosTestCase
{
    use WorkerClients;

    /** A cache row an incremental scan reused keeps this stamp; a rescanned one is rewritten. */
    private const UNTOUCHED = 'untouched';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $this->root = sys_get_temp_dir() . '/knossos-stale-rust-reads-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->root)) {
            $this->removeTempTree($this->root);
        }
        parent::tearDown();
    }

    /**
     * `engine.rs` calls `sign::any()` and `app.rs` reaches `any()` through a
     * glob import and attaches methods to `Signer`: both found them in
     * `sign.rs` through the declaration index. `lib.rs` and `other.rs` never
     * looked inside the `sign` module.
     */
    public function testEditingAModuleRescansTheFilesThatLookedInsideIt(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        $this->write('src/engine/sign.rs', "pub struct Signer;\n\npub fn any() -> u32 {\n    5\n}\n\npub fn more() -> u32 {\n    6\n}\n");
        $incremental = $this->scan($pdo);

        assertSame('incremental', $incremental->data['mode']);
        assertSame(['src/app.rs', 'src/engine.rs', 'src/engine/sign.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A file rescanned on its own still resolves names declared in files the
     * request did not name, and still names them: a later edit reaches it.
     */
    public function testAFileRescannedAloneStillResolvesThroughTheWholeCrate(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        $this->write('src/app.rs', self::APP . "\npub fn added() -> u32 {\n    any()\n}\n");
        $this->scan($pdo);
        assertSame(['src/app.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/engine/sign.rs', "pub struct Signer;\n\npub fn any() -> u32 {\n    7\n}\n");
        $this->scan($pdo);
        assertSame(['src/app.rs', 'src/engine.rs', 'src/engine/sign.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** An inline `mod inner { }` in `engine.rs` declares what `app.rs` globs in. */
    public function testEditingAnInlineModuleRescansItsReadersAndItsChildModules(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        $this->write('src/engine.rs', "pub mod sign;\n\npub mod inner {\n    pub fn other_deep() -> u32 {\n        4\n    }\n}\n\npub fn start() -> u32 {\n    sign::any()\n}\n");
        $this->scan($pdo);

        assertSame(['src/app.rs', 'src/engine.rs', 'src/engine/sign.rs', 'src/lib.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** `lib.rs` looked for `crate::helper::assist`, which no file declared yet. */
    public function testCreatingAModuleALookupProbedRescansTheFileThatLooked(): void
    {
        $this->writeCrate();
        $this->write('src/lib.rs', self::LIB . "\npub fn later() -> u32 {\n    helper::assist()\n}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('src/helper.rs', "pub fn assist() -> u32 {\n    9\n}\n");
        $this->scan($pdo);

        assertSame(['src/helper.rs', 'src/lib.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** `sign/mod.rs` is the other file `mod sign;` could mean, and declares into the same module. */
    public function testAddingTheOtherFileOfAModuleRescansItsReaders(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        $this->write('src/engine/sign/mod.rs', "pub fn any() -> u32 {\n    8\n}\n");
        $this->scan($pdo);

        assertSame(['src/app.rs', 'src/engine.rs', 'src/engine/sign.rs', 'src/engine/sign/mod.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testDeletingOrRenamingAModuleRescansItsReaders(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        rename($this->root . '/src/engine/sign.rs', $this->root . '/src/engine/signer.rs');
        $this->scan($pdo);
        assertSame(['src/app.rs', 'src/engine.rs', 'src/engine/signer.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        unlink($this->root . '/src/engine/signer.rs');
        $this->scan($pdo);
        assertSame([], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * Whether `tests.rs` is test code is decided by the `#[cfg(test)] mod
     * tests;` in `engine.rs`, which `tests.rs` itself never names.
     */
    public function testATestModuleDeclarationReachesTheFileItDeclares(): void
    {
        $this->writeCrate();
        $this->write('src/engine.rs', "pub mod sign;\n\n#[cfg(test)]\nmod tests;\n\npub fn start() -> u32 {\n    sign::any()\n}\n");
        // No name in it asks the index anything, so only the test module
        // declaration ties it to `engine.rs`.
        $this->write('src/engine/tests.rs', "pub fn check() {}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('src/engine.rs', "pub mod sign;\n\nmod tests;\n\npub fn start() -> u32 {\n    sign::any()\n}\n");
        $this->scan($pdo);
        self::assertContains('src/engine/tests.rs', $this->rescannedFiles($pdo));
        self::assertNotContains('src/other.rs', $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/engine.rs', "pub mod sign;\n\n#[cfg(test)]\nmod tests;\n\npub fn start() -> u32 {\n    sign::any()\n}\n");
        $this->scan($pdo);
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        unlink($this->root . '/src/engine.rs');
        $this->scan($pdo);
        self::assertContains('src/engine/tests.rs', $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * `src/lib.rs` loads `lib_checks.rs` through `#[path]` as test code, and
     * `src/main.rs` declares `checks.rs` as test code, though neither file
     * is a child of its declarer's module path.
     */
    public function testATestModuleLoadedBesideItsDeclarerFollowsTheDeclaration(): void
    {
        $this->writeCrate();
        $this->write('src/lib.rs', self::LIB . "\n#[cfg(test)]\n#[path = \"lib_checks.rs\"]\nmod checks;\n");
        $this->write('src/lib_checks.rs', "pub fn probe() {}\n");
        $this->write('src/main.rs', "#[cfg(test)]\nmod bin_checks;\n\nfn main() {}\n");
        $this->write('src/bin_checks.rs', "pub fn probe() {}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('src/lib.rs', self::LIB . "\n#[path = \"lib_checks.rs\"]\nmod checks;\n");
        $this->write('src/main.rs', "mod bin_checks;\n\nfn main() {}\n");
        $this->scan($pdo);

        self::assertContains('src/lib_checks.rs', $this->rescannedFiles($pdo));
        self::assertContains('src/bin_checks.rs', $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * `tests/it.rs` loads a member's file through `#[path]`, so the module it
     * names follows the member's crate roots, which it never looked inside.
     */
    public function testAModLoadedFromAnotherPackageFollowsThatPackagesRoots(): void
    {
        $this->writeCrate();
        $this->write('crates/late/Cargo.toml', "[package]\nname = \"late\"\nversion = \"0.1.0\"\n");
        $this->write('crates/late/src/util.rs', "pub fn run() {}\n");
        $this->write('tests/it.rs', "#[path = \"../crates/late/src/util.rs\"]\nmod util;\n\n#[test]\nfn runs() {}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('crates/late/src/lib.rs', "pub mod util;\n");
        $this->scan($pdo);

        assertSame(['crates/late/src/lib.rs', 'crates/late/src/util.rs', 'tests/it.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A file outside any `src/` and any Cargo target directory names no
     * module a path can reach, and a `lib.rs` below the crate root is a
     * module of its own: adding either reaches no other file.
     */
    public function testAFileNoLookupCanReachRescansOnlyItself(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        $this->write('crate/engine.rs', "pub fn start() -> u32 {\n    3\n}\n");
        $this->write('src/engine/lib.rs', "pub fn any() -> u32 {\n    3\n}\n");
        $this->scan($pdo);

        assertSame(['crate/engine.rs', 'src/engine/lib.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** `src/lib.rs` turns `src/main.rs` from the crate root into a binary beside a library. */
    public function testAddingALibraryRootRescansTheFilesOfItsCrate(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"tool\"\nversion = \"0.1.0\"\n");
        $this->write('src/main.rs', "mod util;\n\nfn main() {\n    util::run();\n}\n");
        $this->write('src/util.rs', "pub fn run() {}\n");
        $this->write('tests/smoke.rs', "fn smoke() {}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('src/lib.rs', "pub fn shared() {}\n");
        $this->scan($pdo);

        assertSame(['src/lib.rs', 'src/main.rs', 'src/util.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        unlink($this->root . '/src/lib.rs');
        $this->scan($pdo);
        assertSame(['src/main.rs', 'src/util.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** A workspace member's crate is named by its package, and another crate globs it in. */
    public function testEditingAWorkspaceMemberRescansTheCratesThatLookedInsideIt(): void
    {
        $this->writeCrate();
        $this->write('crates/core-lib/Cargo.toml', "[package]\nname = \"core-lib\"\nversion = \"0.1.0\"\n");
        $this->write('crates/core-lib/src/lib.rs', "pub fn shared() -> u32 {\n    1\n}\n");
        $this->write('crates/core-lib/src/extra.rs', "pub fn more() -> u32 {\n    2\n}\n");
        $this->write('src/uses_core.rs', "use core_lib::*;\n\npub fn c() -> u32 {\n    shared()\n}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('crates/core-lib/src/lib.rs', "pub fn shared() -> u32 {\n    10\n}\n\npub fn shared_too() -> u32 {\n    11\n}\n");
        $this->scan($pdo);

        assertSame(['crates/core-lib/src/extra.rs', 'crates/core-lib/src/lib.rs', 'src/uses_core.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * A package without a crate root is no crate: its files keep their
     * directory chain until `src/lib.rs` appears and renames every one of
     * them, though none of them asked about the crate's names.
     */
    public function testAddingAMembersLibraryRootMakesItsFilesACrate(): void
    {
        $this->writeCrate();
        $this->write('crates/late/Cargo.toml', "[package]\nname = \"late\"\nversion = \"0.1.0\"\n");
        $this->write('crates/late/src/util.rs', "pub fn run() {}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('crates/late/src/lib.rs', "pub mod util;\n");
        $this->scan($pdo);

        assertSame(['crates/late/src/lib.rs', 'crates/late/src/util.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /** A module file no `mod` declares is still indexed, as a full scan indexes it. */
    public function testAModuleNoModDeclaresIsStillIndexed(): void
    {
        $this->writeCrate();
        $this->write('src/orphan.rs', "pub fn lonely() -> u32 {\n    0\n}\n");
        $this->write('src/uses_orphan.rs', "use crate::orphan::*;\n\npub fn u() -> u32 {\n    lonely()\n}\n");
        $pdo = $this->scannedAndStamped();

        $this->write('src/uses_orphan.rs', "use crate::orphan::*;\n\npub fn u() -> u32 {\n    lonely() + 1\n}\n");
        $this->scan($pdo);
        assertSame(['src/uses_orphan.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
        $this->stampCacheRows($pdo);

        $this->write('src/orphan.rs', "pub fn renamed() -> u32 {\n    0\n}\n");
        $this->scan($pdo);
        assertSame(['src/orphan.rs', 'src/uses_orphan.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    /**
     * An ignored file at a path `lib.rs` probed was read by its bytes but not
     * indexed. Once discovery stops ignoring it, the same bytes now declare
     * `assist`, so `lib.rs` must be rescanned though no read of it changed.
     */
    public function testAnIgnoredModuleThatBecomesDiscoveredRescansTheFileThatLooked(): void
    {
        $this->writeCrate();
        $this->write('src/lib.rs', self::LIB . "\npub mod helper;\n\npub fn later() -> u32 {\n    helper::assist()\n}\n");
        $this->write('src/helper.rs', "pub fn assist() -> u32 {\n    9\n}\n");
        $this->write('.gitignore', "src/helper.rs\n");
        $pdo = $this->scannedAndStamped();

        unlink($this->root . '/.gitignore');
        $this->scan($pdo);

        assertSame(['src/helper.rs', 'src/lib.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEditingTheManifestRebuildsEveryRustFile(): void
    {
        $this->writeCrate();
        $pdo = $this->scannedAndStamped();

        $this->write('Cargo.toml', "[package]\nname = \"renamed\"\nversion = \"0.1.0\"\n");
        $this->scan($pdo);

        assertSame(['src/app.rs', 'src/engine.rs', 'src/engine/sign.rs', 'src/lib.rs', 'src/other.rs'], $this->rescannedFiles($pdo));
        $this->assertMatchesAFullScan($pdo);
    }

    public function testEveryContributionIsStoredAsAttributed(): void
    {
        $this->writeCrate();
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);

        $statement = $pdo->query("SELECT DISTINCT read_attribution FROM contribution_cache WHERE scanner_id = 'knossos.rust'");
        assertSame([1], array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
        $reads = $this->storedReads($pdo, 'src/app.rs');
        self::assertContains('src/engine.rs', $reads);
        self::assertContains('src/engine/sign.rs', $reads);
        self::assertContains('src/engine/sign/mod.rs', $reads);
        self::assertNotContains('src/other.rs', $reads);
        self::assertNotContains('src/app.rs', $reads);
    }

    private const LIB = "pub mod app;\npub mod engine;\npub mod other;\n\npub fn top() -> u32 {\n    engine::start()\n}\n";

    private const APP = "use crate::engine::inner::*;\nuse crate::engine::sign::*;\nuse crate::engine::sign::Signer;\n\nimpl Signer {\n    pub fn extra(&self) -> u32 {\n        any() + deep()\n    }\n}\n";

    private function writeCrate(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', self::LIB);
        $this->write('src/engine.rs', "pub mod sign;\n\npub mod inner {\n    pub fn deep() -> u32 {\n        4\n    }\n}\n\npub fn start() -> u32 {\n    sign::any()\n}\n");
        $this->write('src/engine/sign.rs', "pub struct Signer;\n\npub fn any() -> u32 {\n    1\n}\n");
        $this->write('src/app.rs', self::APP);
        $this->write('src/other.rs', "pub fn alone() -> u32 {\n    2\n}\n");
    }

    private function scannedAndStamped(): PDO
    {
        $pdo = $this->freshTestDatabase();
        $this->scan($pdo);
        $this->stampCacheRows($pdo);

        return $pdo;
    }

    private function scan(PDO $pdo): ResultEnvelope
    {
        return (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);
    }

    private function stampCacheRows(PDO $pdo): void
    {
        $pdo->prepare('UPDATE contribution_cache SET updated_at = ?')->execute([self::UNTOUCHED]);
    }

    /** @return list<string> the Rust files whose contribution the last scan rewrote */
    private function rescannedFiles(PDO $pdo): array
    {
        $statement = $pdo->prepare("SELECT file_path FROM contribution_cache WHERE scanner_id = 'knossos.rust' AND updated_at <> ? ORDER BY file_path");
        $statement->execute([self::UNTOUCHED]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> the paths one file's stored contribution read */
    private function storedReads(PDO $pdo, string $file): array
    {
        $statement = $pdo->prepare('SELECT read_path FROM contribution_reads WHERE owner_key = ? ORDER BY read_path');
        $statement->execute(['knossos.rust:file:' . $file]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private function assertMatchesAFullScan(PDO $incremental): void
    {
        $full = $this->freshTestDatabase();
        $this->scan($full);
        assertSame($this->graphSignature($full), $this->graphSignature($incremental));
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }
        file_put_contents($path, $contents);
    }
}
