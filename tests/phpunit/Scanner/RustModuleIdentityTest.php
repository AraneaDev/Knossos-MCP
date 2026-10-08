<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Scanner;

use Knossos\Scan\ProjectScanService;
use Knossos\Tests\Phpunit\KnossosTestCase;
use Knossos\Tests\Phpunit\Support\WorkerClients;
use PDO;

/**
 * The Rust worker names a module the way the file that holds it is named, so
 * every fact that points at a module, a function in it or a type it declares
 * lands on a node the graph holds, through the real worker and a real scan.
 *
 * Each case scans a small crate and asserts the edge that must exist and the
 * node that must not: a name no file declares, which the graph would
 * otherwise invent as an external symbol. Every test skips when the worker
 * binary is absent, as the other Rust tests do.
 */
final class RustModuleIdentityTest extends KnossosTestCase
{
    use WorkerClients;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $this->root = sys_get_temp_dir() . '/knossos-stale-rust-identity-' . bin2hex(random_bytes(6));
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
     * The binary, a second binary, an integration test and an example name
     * the library by its crate name, which is the root package's `crate`.
     */
    public function testCodeOutsideTheLibraryReachesItThroughItsCrateName(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"my-demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub mod util;\n\npub fn run() -> u32 {\n    util::helper()\n}\n");
        $this->write('src/util.rs', "pub fn helper() -> u32 {\n    1\n}\n");
        $this->write('src/main.rs', "fn main() {\n    my_demo::run();\n}\n");
        $this->write('src/bin/tool.rs', "use my_demo::util::helper;\n\nfn main() {\n    helper();\n}\n");
        $this->write('tests/it.rs', "use my_demo::run;\n\n#[test]\nfn it_runs() {\n    run();\n}\n");
        $this->write('examples/ex.rs', "fn main() {\n    ::my_demo::util::helper();\n}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('calls crate::main::main -> crate::run', $edges);
        self::assertContains('calls crate::bin::tool::main -> crate::util::helper', $edges);
        self::assertContains('calls tests::it::it_runs -> crate::run', $edges);
        self::assertContains('calls examples::ex::main -> crate::util::helper', $edges);
        self::assertContains('imports tests::it -> crate', $edges);
        self::assertSame([], $this->nodesStartingWith($pdo, 'my_demo'));
    }

    /** `[lib] name` renames the crate other targets write, whatever the package is called. */
    public function testALibraryNameInTheManifestIsTheNameOtherTargetsUse(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n\n[lib]\nname = \"engine_core\"\n");
        $this->write('src/lib.rs', "pub fn start() {}\n");
        $this->write('tests/it.rs', "#[test]\nfn starts() {\n    engine_core::start();\n}\n");
        $pdo = $this->scanned();

        self::assertContains('calls tests::it::starts -> crate::start', $this->edges($pdo));
        self::assertSame([], $this->nodesStartingWith($pdo, 'engine_core'));
    }

    /**
     * `mod cli;` in a binary beside a library, `mod common;` in an
     * integration test, and `#[path]` each name the module of the file they
     * load, not a child of the declaring file.
     */
    public function testAModDeclarationNamesTheModuleOfTheFileItLoads(): void
    {
        $this->writeLayoutCrate();
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('contains crate::main -> crate::cli', $edges);
        self::assertContains('calls crate::main::main -> crate::cli::start', $edges);
        self::assertContains('contains tests::it -> tests::common', $edges);
        self::assertContains('calls tests::it::it -> tests::common::setup', $edges);
        self::assertContains('contains crate -> crate::renamed_impl', $edges);
        self::assertContains('calls crate::run -> crate::renamed_impl::go', $edges);
        $nodes = $this->nodes($pdo);
        foreach (['module crate::main::cli', 'module crate::main::checks', 'module tests::it::common', 'module crate::renamed'] as $phantom) {
            self::assertNotContains($phantom, $nodes);
        }
    }

    /** Only `src/lib.rs` and `src/main.rs` are the crate root; a `lib.rs` deeper down is a module of its own. */
    public function testALibFileBelowTheCrateRootIsItsOwnModule(): void
    {
        $this->writeLayoutCrate();
        $pdo = $this->scanned();

        self::assertContains('function crate::nested::lib::deep', $this->nodes($pdo));
        self::assertContains('contains crate::nested -> crate::nested::lib', $this->edges($pdo));
        self::assertSame(['src/nested.rs'], $this->filesDeclaring($pdo, 'crate::nested'));
        self::assertContains('function crate::bin::tool::main::main', $this->nodes($pdo));
        self::assertContains('calls crate::bin::tool::main::main -> crate::bin::tool::helper::aid', $this->edges($pdo));
    }

    /** An out-of-line `#[cfg(test)]` module is test code where its file really is. */
    public function testAnOutOfLineTestModuleMarksTheFileItLoads(): void
    {
        $this->writeLayoutCrate();
        $pdo = $this->scanned();

        $tests = $this->testNodes($pdo);
        self::assertContains('crate::checks::check', $tests);
        self::assertContains('crate::lib_tests::probe', $tests);
        self::assertNotContains('crate::cli::start', $tests);
        self::assertNotContains('crate::renamed_impl::go', $tests);
    }

    /**
     * `Wrapper(1)` and `Error::Io(e)` build values: they reference the type,
     * and nothing is called. A rooted call to a name nothing declares is
     * dropped instead of becoming an external symbol.
     */
    public function testAConstructorReferencesItsTypeAndCallsNothing(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub mod errors;\n\nuse crate::errors::Error;\n\npub struct Wrapper(pub u32);\n\npub fn build() -> u32 {\n    let w = Wrapper(1);\n    w.0\n}\n\npub fn fail(e: std::io::Error) -> u32 {\n    let _ = Error::Io(e);\n    let _ = crate::errors::Error::Other(2);\n    let _ = Some(3);\n    crate::nowhere::gone();\n    crate::errors::make()\n}\n");
        $this->write('src/errors.rs', "pub enum Error {\n    Io(std::io::Error),\n    Other(u32),\n}\n\npub fn make() -> u32 {\n    1\n}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('references crate::build -> crate::Wrapper', $edges);
        self::assertContains('references crate::fail -> crate::errors::Error', $edges);
        self::assertContains('calls crate::fail -> crate::errors::make', $edges);
        foreach ($edges as $edge) {
            self::assertStringNotContainsString('calls crate::build', $edge);
            self::assertStringNotContainsString('-> crate::errors::Error::', $edge);
        }
        $nodes = $this->nodes($pdo);
        foreach (['function crate::Wrapper', 'method crate::errors::Error::Io', 'method crate::errors::Error::Other', 'function crate::nowhere::gone', 'function crate::Some'] as $phantom) {
            self::assertNotContains($phantom, $nodes);
        }
    }

    /** Only a predicate that holds solely under `test` makes code test code. */
    public function testOnlyACfgThatRequiresTestMarksTestCode(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "#[cfg(not(test))]\nmod real {\n    pub fn r() {}\n}\n\n#[cfg(any(test, feature = \"x\"))]\nmod shared {\n    pub fn s() {}\n}\n\n#[cfg(all(not(test), unix))]\nmod unixy {\n    pub fn u() {}\n}\n\n#[cfg(test)]\nmod tests {\n    fn t() {}\n}\n\n#[cfg(all(test, feature = \"x\"))]\nmod featured {\n    fn f() {}\n}\n\n#[cfg(not(not(test)))]\nmod doubled {\n    fn d() {}\n}\n\n#[cfg(test)]\nfn helper() {}\n\n#[cfg(not(test))]\n#[path = \"prod.rs\"]\nmod prod;\n");
        $this->write('src/prod.rs', "pub fn p() {}\n");
        $pdo = $this->scanned();

        $tests = $this->testNodes($pdo);
        foreach (['crate::real::r', 'crate::shared::s', 'crate::unixy::u', 'crate::prod::p'] as $production) {
            self::assertNotContains($production, $tests);
        }
        foreach (['crate::tests::t', 'crate::featured::f', 'crate::doubled::d', 'crate::helper'] as $test) {
            self::assertContains($test, $tests);
        }
    }

    /** `mod r#async;` is the module of `async.rs`, and a raw name is spelled without its prefix. */
    public function testARawIdentifierNamesTheModuleWithoutItsPrefix(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub mod r#async;\n\npub fn go() {\n    r#async::spawn();\n    r#async::r#try::inner();\n}\n");
        $this->write('src/async.rs', "pub fn spawn() {}\n\npub mod r#try {\n    pub fn inner() {}\n}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('contains crate -> crate::async', $edges);
        self::assertContains('calls crate::go -> crate::async::spawn', $edges);
        self::assertContains('calls crate::go -> crate::async::try::inner', $edges);
        self::assertSame([], array_values(array_filter($this->nodes($pdo), static fn(string $node): bool => str_contains($node, 'r#'))));
    }

    /**
     * A `#[path]` module is written by its declared name in other files too:
     * `crate::a::x` there is the function the loaded file declares, and
     * `crate::parse::discouraged` is the module `parse.rs` loads.
     */
    public function testAPathModuleIsReachedThroughItsDeclaredNameFromAnotherFile(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "#[path = \"impl_a.rs\"]\npub mod a;\npub mod other;\npub mod parse;\n");
        $this->write('src/impl_a.rs', "pub struct Thing;\n\npub fn x() -> u32 {\n    1\n}\n");
        $this->write('src/other.rs', "use crate::a::x;\nuse crate::a::Thing;\n\npub fn f(_t: Thing) -> u32 {\n    x() + crate::a::x() + crate::parse::discouraged::y()\n}\n");
        $this->write('src/parse.rs', "#[path = \"discouraged.rs\"]\npub mod discouraged;\n");
        $this->write('src/discouraged.rs', "pub fn y() -> u32 {\n    2\n}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('calls crate::other::f -> crate::impl_a::x', $edges);
        self::assertContains('calls crate::other::f -> crate::discouraged::y', $edges);
        self::assertContains('imports crate::other -> crate::impl_a', $edges);
        self::assertContains('references crate::other::f -> crate::impl_a::Thing', $edges);
        self::assertSame([], $this->nodesStartingWith($pdo, 'crate::a'));
        self::assertSame([], $this->nodesStartingWith($pdo, 'crate::parse::discouraged'));
    }

    /**
     * Two `#[path]` declarations of one name under different `cfg`s name two
     * modules, so a path through the name resolves to neither.
     */
    public function testTwoGatedPathDeclarationsOfOneNameResolveToNothing(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "#[cfg(unix)]\n#[path = \"sys/unix.rs\"]\nmod sys;\n#[cfg(windows)]\n#[path = \"sys/windows.rs\"]\nmod sys;\npub mod other;\n\npub fn run() {\n    sys::go();\n}\n");
        $this->write('src/sys/unix.rs', "pub fn go() {}\n");
        $this->write('src/sys/windows.rs', "pub fn go() {}\n");
        $this->write('src/other.rs', "pub fn o() {\n    crate::sys::go();\n}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('contains crate -> crate::sys::unix', $edges);
        self::assertContains('contains crate -> crate::sys::windows', $edges);
        foreach ($edges as $edge) {
            self::assertStringNotContainsString('calls crate::run', $edge);
            self::assertStringNotContainsString('calls crate::other::o', $edge);
        }
        self::assertSame([], $this->nodesStartingWith($pdo, 'crate::sys::go'));
    }

    /**
     * `crate::` in a binary, an integration test or an example names that
     * target's own crate, whose modules sit beside its root file.
     */
    public function testCrateInsideATargetRootNamesThatTarget(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub mod helper;\n");
        $this->write('src/helper.rs', "pub fn aid() -> u32 {\n    9\n}\n");
        $this->write('src/bin/tool.rs', "mod helper;\nmod lonely;\n\nfn main() {\n    crate::helper::aid();\n    crate::lonely::solo();\n}\n");
        $this->write('src/bin/helper.rs', "pub fn aid() -> u32 {\n    1\n}\n");
        $this->write('src/bin/lonely.rs', "pub fn solo() -> u32 {\n    1\n}\n");
        $this->write('src/bin/deep/main.rs', "mod part;\n\nfn main() {\n    crate::part::go();\n}\n");
        $this->write('src/bin/deep/part.rs', "pub fn go() {}\n");
        $this->write('tests/it.rs', "mod common;\n\n#[test]\nfn t() {\n    crate::common::setup();\n}\n");
        $this->write('tests/common/mod.rs', "pub fn setup() {}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('calls crate::bin::tool::main -> crate::bin::helper::aid', $edges);
        self::assertContains('calls crate::bin::tool::main -> crate::bin::lonely::solo', $edges);
        self::assertNotContains('calls crate::bin::tool::main -> crate::helper::aid', $edges);
        self::assertContains('calls crate::bin::deep::main::main -> crate::bin::deep::part::go', $edges);
        self::assertContains('calls tests::it::t -> tests::common::setup', $edges);
    }

    /**
     * A target root's `crate::` is the root file itself: `crate::own` is the
     * function it declares, also from its test module, while `crate::helper`
     * still reaches the module its `mod helper;` loads beside it.
     */
    public function testCrateInsideATargetRootNamesItsOwnItems(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub fn own() {}\n");
        $this->write('src/bin/tool.rs', "mod helper;\n\nfn own() {}\n\nfn main() {\n    crate::own();\n    crate::helper::aid();\n}\n\n#[cfg(test)]\nmod tests {\n    use crate::own;\n\n    fn t() {\n        own();\n    }\n}\n");
        $this->write('src/bin/helper.rs', "pub fn aid() {}\n");
        $this->write('tests/it.rs', "fn setup_here() {}\n\n#[test]\nfn t() {\n    crate::setup_here();\n}\n");
        $pdo = $this->scanned();

        $edges = $this->edges($pdo);
        self::assertContains('calls crate::bin::tool::main -> crate::bin::tool::own', $edges);
        self::assertContains('calls crate::bin::tool::main -> crate::bin::helper::aid', $edges);
        self::assertContains('calls crate::bin::tool::tests::t -> crate::bin::tool::own', $edges);
        self::assertContains('calls tests::it::t -> tests::it::setup_here', $edges);
        self::assertNotContains('calls crate::bin::tool::main -> crate::own', $edges);
        self::assertNotContains('imports crate::bin::tool -> crate::bin', $edges);
        self::assertNotContains('module crate::bin', $this->nodes($pdo));
    }

    /** A method compiled only for tests is test code, as a free function is. */
    public function testAMethodCompiledOnlyForTestsIsTestCode(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub struct S;\n\nimpl S {\n    #[cfg(test)]\n    pub fn in_tests(&self) {}\n    pub fn always(&self) {}\n}\n\npub trait T {\n    #[cfg(test)]\n    fn probe(&self) {}\n    fn shipped(&self) {}\n}\n");
        $pdo = $this->scanned();

        $tests = $this->testNodes($pdo);
        self::assertContains('crate::S::in_tests', $tests);
        self::assertContains('crate::T::probe', $tests);
        self::assertNotContains('crate::S::always', $tests);
        self::assertNotContains('crate::T::shipped', $tests);
    }

    /** `use crate::errors::Error::Io;` imports a variant of a type, not a module. */
    public function testImportingAVariantReferencesItsTypeNotAModule(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub mod errors;\n\nuse crate::errors::Error::Io;\nuse crate::errors::Error::*;\n\npub fn fail(e: std::io::Error) {\n    let _ = Io(e);\n}\n");
        $this->write('src/errors.rs', "pub enum Error {\n    Io(std::io::Error),\n}\n");
        $pdo = $this->scanned();

        self::assertContains('references crate -> crate::errors::Error', $this->edges($pdo));
        self::assertNotContains('module crate::errors::Error', $this->nodes($pdo));
    }

    private function writeLayoutCrate(): void
    {
        $this->write('Cargo.toml', "[package]\nname = \"demo\"\nversion = \"0.1.0\"\n");
        $this->write('src/lib.rs', "pub mod nested;\n#[path = \"renamed_impl.rs\"]\npub mod renamed;\n#[cfg(test)]\n#[path = \"lib_tests.rs\"]\nmod tests;\n\npub fn run() -> u32 {\n    renamed::go()\n}\n");
        $this->write('src/renamed_impl.rs', "pub fn go() -> u32 {\n    1\n}\n");
        $this->write('src/lib_tests.rs', "fn probe() {}\n");
        $this->write('src/main.rs', "mod cli;\n#[cfg(test)]\nmod checks;\n\nfn main() {\n    cli::start();\n}\n");
        $this->write('src/cli.rs', "pub fn start() {}\n");
        $this->write('src/checks.rs', "fn check() {}\n");
        $this->write('src/nested.rs', "pub mod lib;\n");
        $this->write('src/nested/lib.rs', "pub fn deep() {}\n");
        $this->write('src/bin/tool/main.rs', "mod helper;\n\nfn main() {\n    helper::aid();\n}\n");
        $this->write('src/bin/tool/helper.rs', "pub fn aid() {}\n");
        $this->write('tests/it.rs', "mod common;\n\n#[test]\nfn it() {\n    common::setup();\n}\n");
        $this->write('tests/common/mod.rs', "pub fn setup() {}\n");
    }

    private function scanned(): PDO
    {
        $pdo = $this->freshTestDatabase();
        (new ProjectScanService($pdo, self::repositoryRoot(), [$this->root]))->scan($this->root);

        return $pdo;
    }

    /** @return list<string> every edge as `kind source -> target`, by canonical name */
    private function edges(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT e.kind || ' ' || s.canonical_name || ' -> ' || t.canonical_name FROM edges e JOIN nodes s ON s.id = e.source_id JOIN nodes t ON t.id = e.target_id WHERE s.language = 'rust' ORDER BY 1");

        return array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> every Rust node as `kind canonical` */
    private function nodes(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT kind || ' ' || canonical_name FROM nodes WHERE language = 'rust' ORDER BY 1");

        return array_map('strval', $rows->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> the canonical names of Rust nodes under `$head`, by any kind */
    private function nodesStartingWith(PDO $pdo, string $head): array
    {
        $statement = $pdo->prepare("SELECT canonical_name FROM nodes WHERE language = 'rust' AND (canonical_name = ? OR canonical_name LIKE ?) ORDER BY 1");
        $statement->execute([$head, $head . '::%']);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<string> the files whose contribution declares the module `$module` */
    private function filesDeclaring(PDO $pdo, string $module): array
    {
        $statement = $pdo->prepare("SELECT DISTINCT n.owner_key FROM nodes n WHERE n.language = 'rust' AND n.kind = 'module' AND n.canonical_name = ? ORDER BY 1");
        $statement->execute([$module]);

        return array_map(
            static fn(mixed $owner): string => substr((string) $owner, strlen('knossos.rust:file:')),
            $statement->fetchAll(PDO::FETCH_COLUMN),
        );
    }

    /** @return list<string> the canonical names of Rust nodes the worker marked as test code */
    private function testNodes(PDO $pdo): array
    {
        $rows = $pdo->query("SELECT canonical_name, attributes_json FROM nodes WHERE language = 'rust' ORDER BY canonical_name")->fetchAll();
        $tests = [];
        foreach ($rows as $row) {
            $attributes = json_decode((string) $row['attributes_json'], true, 512, JSON_THROW_ON_ERROR);
            if (is_array($attributes) && ($attributes['test'] ?? false) === true) {
                $tests[] = (string) $row['canonical_name'];
            }
        }

        return $tests;
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
