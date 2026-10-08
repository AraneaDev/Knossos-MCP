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
