<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Framework;

use Knossos\Scan\ProjectScanService;
use Knossos\Scanner\Protocol\EdgeFact;
use Knossos\Scanner\Protocol\NodeFact;
use Knossos\Scanner\Protocol\ScanContribution;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;

final class RustFrameworksTest extends KnossosTestCase
{
    #[Group('rust-frameworks')]
    public function testRustFrameworkEnrichmentFlowsFromCargoToPersistedClassifications(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-rust-frameworks-' . bin2hex(random_bytes(6));
        $database = tempnam(sys_get_temp_dir(), 'knossos-rust-frameworks-');
        if ($database === false) {
            throw new RuntimeException('Unable to allocate Rust framework database.');
        }
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/Cargo.toml', <<<'TOML'
[package]
name = "route-demo"
version = "0.1.0"

[dependencies]
axum = "0.7"
TOML);
        file_put_contents($root . '/src/lib.rs', <<<'RUST'
use axum::Router;
use axum::routing::get;

fn health() {}

fn app() -> Router {
    Router::new().route("/health", get(health))
}
RUST);

        try {
            $pdo = SqliteConnection::open($database);
            (new MigrationRunner($pdo, self::repositoryRoot() . '/migrations'))->migrate();
            $result = (new ProjectScanService($pdo, self::repositoryRoot(), [$root]))->scan($root, 'Rust Frameworks');

            assertSame(1, $result->data['parsed_files']);
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'package' AND canonical_name = 'route-demo'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM nodes WHERE kind = 'route' AND canonical_name = 'GET /health => crate::health'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM classifications WHERE role = 'rust.route_handler'")->fetchColumn());
            assertSame('axum', $pdo->query("SELECT json_extract(attributes_json, '$.framework') FROM nodes WHERE kind = 'route'")->fetchColumn());
            assertSame('1', (string) $pdo->query("SELECT COUNT(*) FROM edges WHERE kind = 'routes_to' AND target_id IN (SELECT id FROM nodes WHERE canonical_name = 'crate::health')")->fetchColumn());
        } finally {
            unset($pdo);
            @unlink($root . '/src/lib.rs');
            @unlink($root . '/Cargo.toml');
            @rmdir($root . '/src');
            @rmdir($root);
            foreach ([$database, $database . '-shm', $database . '-wal'] as $candidate) {
                @unlink($candidate);
            }
        }
    }

    #[Group('rust-frameworks')]
    public function testRustWorkerEmitsActixAndRocketRoutesWithGating(): void
    {
        if (!is_file(self::rustWorkerBinary())) {
            self::markTestSkipped('The Rust worker binary is not built.');
        }
        $root = sys_get_temp_dir() . '/knossos-rust-route-worker-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o700, true);
        file_put_contents($root . '/src/routes.rs', <<<'RUST'
#[get("/ping")]
fn ping() {}

#[get("/world")]
fn world() {}
RUST);

        $client = null;
        try {
            $client = $this->rustWorkerClient();
            $contributions = iterator_to_array($client->scan([
                'root' => $root,
                'files' => ['src/routes.rs'],
                'frameworks' => ['actix'],
            ]));

            $nodes = array_merge(...array_map(fn(ScanContribution $item): array => $item->nodes, $contributions));
            $routes = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'route'));
            assertSame(2, count($routes));
            assertSame(['GET /ping => crate::routes::ping', 'GET /world => crate::routes::world'], array_map(fn(NodeFact $node): string => $node->canonicalName, $routes));
            $roles = array_values(array_filter($nodes, fn(NodeFact $node): bool => $node->kind === 'function'));
            foreach ($roles as $handler) {
                assertSame(['rust.route_handler'], $handler->attributes['rust_framework_roles']);
            }
            $edges = array_merge(...array_map(fn(ScanContribution $item): array => $item->edges, $contributions));
            assertSame(2, count(array_filter($edges, fn(EdgeFact $edge): bool => $edge->kind === 'routes_to')));
        } finally {
            // A worker that outlives a throwing scan() would linger for the
            // rest of the suite, so the shutdown belongs here, not on the
            // success path.
            $client?->shutdown();
            @unlink($root . '/src/routes.rs');
            @rmdir($root . '/src');
            @rmdir($root);
        }
    }
}
