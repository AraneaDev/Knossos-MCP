<?php

declare(strict_types=1);

namespace Knossos\Tests\Phpunit\Store;

use Knossos\Discovery\FileFingerprint;
use Knossos\Store\MigrationRunner;
use Knossos\Store\SqliteConnection;
use Knossos\Store\SqliteGraphRepository;
use Knossos\Store\StableId;
use Knossos\Tests\Phpunit\KnossosTestCase;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use RuntimeException;

final class StoreTest extends KnossosTestCase
{
    #[Group('store')]
    public function testMigrationsAreVersionedAndIdempotent(): void
    {
        $pdo = SqliteConnection::open(':memory:');
        $runner = new MigrationRunner($pdo, self::repositoryRoot() . '/migrations');

        assertSame(['001_initial_graph', '002_classifications', '003_boundary_memberships', '004_contribution_cache', '005_scan_locks', '006_http_sessions', '007_scan_snapshots', '008_occurrence_edges', '009_file_line_count'], $runner->migrate());
        assertSame([], $runner->migrate());
        assertSame('1', (string) $pdo->query('PRAGMA foreign_keys')->fetchColumn());
        $edgeSchema = (string) $pdo->query("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'edges'")->fetchColumn();
        assertSame(false, str_contains($edgeSchema, 'UNIQUE (project_id, kind, source_id, target_id, owner_key)'));
    }

    #[Group('store')]
    public function testOccurrenceEdgeMigrationPreservesLegacyRowsAndPermitsRepeatedRelations(): void
    {
        $directory = sys_get_temp_dir() . '/knossos-edge-migration-' . bin2hex(random_bytes(6));
        mkdir($directory, 0700);
        copy(self::repositoryRoot() . '/migrations/001_initial_graph.sql', $directory . '/001_initial_graph.sql');
        // The fixture writer persists line_count, so the baseline includes migration 009.
        copy(self::repositoryRoot() . '/migrations/009_file_line_count.sql', $directory . '/009_file_line_count.sql');

        try {
            [$pdo, $repository, $ids] = $this->storeFixture($directory);
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());

            copy(self::repositoryRoot() . '/migrations/008_occurrence_edges.sql', $directory . '/008_occurrence_edges.sql');
            assertSame(['008_occurrence_edges'], (new MigrationRunner($pdo, $directory))->migrate());
            assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());

            $repository->saveEdge(
                StableId::edge($ids['project'], 'calls', $ids['checkout'], $ids['invoice'], 'src/Checkout.php:13'),
                $ids['project'],
                'calls',
                $ids['checkout'],
                $ids['invoice'],
                $ids['file'],
                13,
                13,
                'ast',
                'certain',
                [],
                'php:file:src/Checkout.php',
                $ids['scan'],
            );
            assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM edges')->fetchColumn());
            assertSame([], $pdo->query('PRAGMA foreign_key_check')->fetchAll());
        } finally {
            unset($repository, $pdo);
            foreach (glob($directory . '/*.sql') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[Group('store')]
    public function testFileSqliteConnectionsEnableWal(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'knossos-store-');
        if ($path === false) {
            throw new RuntimeException('Unable to allocate temporary database path.');
        }

        try {
            $pdo = SqliteConnection::open($path);
            assertSame('wal', strtolower((string) $pdo->query('PRAGMA journal_mode')->fetchColumn()));
            unset($pdo);
        } finally {
            foreach ([$path, $path . '-shm', $path . '-wal'] as $candidate) {
                if (is_file($candidate)) {
                    unlink($candidate);
                }
            }
        }
    }

    #[Group('store')]
    public function testGraphRepositoryPersistsAndTraversesFacts(): void
    {
        [$pdo, $repository, $ids] = $this->storeFixture();

        $byCanonical = $repository->findNodesByName($ids['project'], 'App\\Checkout');
        assertSame($ids['checkout'], $byCanonical[0]['id']);
        $byDisplay = $repository->findNodesByName($ids['project'], 'InvoiceService');
        assertSame($ids['invoice'], $byDisplay[0]['id']);

        $outgoing = $repository->outgoing($ids['project'], $ids['checkout'], 'calls');
        assertSame($ids['invoice'], $outgoing[0]['target_id']);
        $incoming = $repository->incoming($ids['project'], $ids['invoice']);
        assertSame($ids['checkout'], $incoming[0]['source_id']);

        $repository->completeScan($ids['project'], $ids['scan']);
        assertSame($ids['scan'], $repository->findProject($ids['project'])['active_scan_id']);

        $repository->deleteFactsByOwner($ids['project'], 'php:file:src/Checkout.php');
        assertSame([], $repository->findNodesByName($ids['project'], 'App\\Checkout'));
        assertSame([], $repository->incoming($ids['project'], $ids['invoice']));
        assertSame('1', (string) $pdo->query('SELECT COUNT(*) FROM nodes')->fetchColumn());
    }

    #[Group('store')]
    public function testRepositoryHotWritesReusePreparedStatements(): void
    {
        [, $repository, $ids] = $this->storeFixture();
        $property = new ReflectionProperty($repository, 'statements');
        $before = $property->getValue($repository);
        $repository->saveFile(
            $ids['file'],
            $ids['project'],
            'src/Checkout.php',
            hash('sha256', 'checkout-updated'),
            43,
            2,
            'php',
            '1.0.0',
            $ids['scan'],
        );
        $after = $property->getValue($repository);
        assertSame(count($before), count($after));
        assertSame(1, count(array_filter(array_keys($after), fn(string $sql): bool => str_starts_with($sql, 'INSERT INTO files'))));
    }

    #[Group('store')]
    public function testRepositoryTransactionRollsBackAllWrites(): void
    {
        [, $repository, $ids] = $this->storeFixture();

        assertThrows(
            function () use ($repository, $ids): void {
                $repository->transaction(function (SqliteGraphRepository $store) use ($ids): void {
                    $id = StableId::symbol($ids['project'], 'php', 'class', 'App\\RolledBack');
                    $store->saveNode(
                        $id,
                        $ids['project'],
                        'class',
                        'App\\RolledBack',
                        'RolledBack',
                        null,
                        $ids['file'],
                        20,
                        30,
                        'ast',
                        'certain',
                        [],
                        'php:file:src/RolledBack.php',
                        $ids['scan'],
                    );
                    throw new RuntimeException('force rollback');
                });
            },
            RuntimeException::class,
        );

        assertSame([], $repository->findNodesByName($ids['project'], 'App\\RolledBack'));
    }

    #[Group('store')]
    public function testGraphLookupAndTraversalQueriesUseIndexes(): void
    {
        [$pdo, , $ids] = $this->storeFixture();

        $nodePlan = $pdo->query(
            "EXPLAIN QUERY PLAN SELECT * FROM nodes WHERE project_id = '" . $ids['project'] . "' " .
            "AND canonical_name = 'App\\Checkout'",
        )->fetchAll();
        $edgePlan = $pdo->query(
            "EXPLAIN QUERY PLAN SELECT * FROM edges WHERE project_id = '" . $ids['project'] . "' " .
            "AND source_id = '" . $ids['checkout'] . "' AND kind = 'calls'",
        )->fetchAll();

        assertContains('nodes_project_canonical_idx', implode(' ', array_column($nodePlan, 'detail')));
        assertContains('edges_project_source_idx', implode(' ', array_column($edgePlan, 'detail')));
    }

    #[Group('store')]
    public function testStableIdsAreDeterministicAndRouteMethodsAreOrderIndependent(): void
    {
        $project = StableId::project('shop');
        assertSame($project, StableId::project('shop'));
        assertSame(
            StableId::route($project, ['POST', 'GET'], '/checkout', 'CheckoutController'),
            StableId::route($project, ['GET', 'POST'], '/checkout', 'CheckoutController'),
        );
        assertNotSame(
            StableId::symbol($project, 'php', 'class', 'Checkout'),
            StableId::symbol($project, 'typescript', 'class', 'Checkout'),
        );
    }

    #[Group('store')]
    public function testFileFingerprintCountsPhysicalLinesAndPreservesTheContentHash(): void
    {
        $cases = [
            ['', 0],
            ["line\n", 1],
            ['line', 1],
            ["a\nb", 2],
            ["a\nb\n", 2],
            ["a\r\nb\r\n", 2],
            ["\n", 1],
            ["\n\n\n", 3],
        ];
        foreach ($cases as [$content, $expected]) {
            $path = tempnam(sys_get_temp_dir(), 'knossos-fp-');
            if ($path === false) {
                throw new RuntimeException('Unable to allocate fingerprint fixture.');
            }
            try {
                file_put_contents($path, $content);
                $fingerprint = FileFingerprint::compute($path);
                assertSame($expected, $fingerprint?->lineCount);
                assertSame(hash('sha256', $content), $fingerprint?->contentHash);
            } finally {
                @unlink($path);
            }
        }
        // Large bounded file: exactly N newline-terminated physical lines.
        $large = tempnam(sys_get_temp_dir(), 'knossos-fp-large-');
        if ($large === false) {
            throw new RuntimeException('Unable to allocate large fingerprint fixture.');
        }
        try {
            file_put_contents($large, str_repeat("x\n", 5000));
            assertSame(5000, FileFingerprint::compute($large)?->lineCount);
        } finally {
            @unlink($large);
        }
        // An unreadable path returns null rather than throwing mid-discovery.
        assertSame(null, FileFingerprint::compute(sys_get_temp_dir() . '/knossos-missing-' . bin2hex(random_bytes(6))));
    }
}
